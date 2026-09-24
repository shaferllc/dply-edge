<?php

declare(strict_types=1);

namespace App\Models;

use App\Modules\Edge\Services\EdgeArtifactPublisher;
use App\Modules\Edge\Services\EdgeDeliveryContextResolver;
use App\Modules\Edge\Support\EdgeLiveBuildLog;
use App\Modules\Edge\Support\EdgeLogCopy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property array<string, mixed>|null $aliases
 * @property ?string $build_log_path
 * @property ?Carbon $build_started_at
 * @property ?int $build_seconds
 * @property int $cf_kv_version
 * @property ?Carbon $failed_at
 * @property ?string $failure_reason
 * @property ?string $git_branch
 * @property ?string $git_commit
 * @property array<string, mixed>|null $meta
 * @property ?string $organization_id
 * @property ?Carbon $pruned_at
 * @property ?Carbon $published_at
 * @property array<string, mixed>|null $repo_config
 * @property ?string $site_id
 * @property string $status
 * @property ?string $storage_prefix
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 * @property-read ?Site $site
 * @property-read ?Organization $organization
 */
class EdgeDeployment extends Model
{
    use HasUlids;

    public const STATUS_BUILDING = 'building';

    public const STATUS_PUBLISHING = 'publishing';

    public const STATUS_LIVE = 'live';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SUPERSEDED = 'superseded';

    protected $fillable = [
        'site_id',
        'organization_id',
        'status',
        'git_commit',
        'git_branch',
        'storage_prefix',
        'build_log_path',
        'cf_kv_version',
        'aliases',
        'repo_config',
        'published_at',
        'failed_at',
        'failure_reason',
        'pruned_at',
        'meta',
        'build_started_at',
        'build_seconds',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'aliases' => 'array',
            'repo_config' => 'array',
            'published_at' => 'datetime',
            'failed_at' => 'datetime',
            'pruned_at' => 'datetime',
            'build_started_at' => 'datetime',
            'build_seconds' => 'integer',
            'cf_kv_version' => 'integer',
        ];
    }

    /**
     * Stable per-deployment alias hostnames. Each one resolves to this
     * deployment via the KV host map so operators can deep-link any
     * historical build, even when production has moved on.
     *
     * @return list<string>
     */
    public function aliasHostnames(): array
    {
        return array_values(array_filter(array_map(
            static fn ($value): string => is_string($value) ? strtolower(trim($value)) : '',
            $this->aliases ?? [],
        ), static fn (string $value): bool => $value !== ''));
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function isLive(): bool
    {
        return $this->status === self::STATUS_LIVE;
    }

    /**
     * Operator cancelled this deploy from the Build Journey UI. Jobs must
     * exit without flipping status back to building/publishing.
     */
    public function wasCancelledByOperator(): bool
    {
        if (($this->meta['cancelled'] ?? false) === true) {
            return true;
        }

        return $this->status === self::STATUS_FAILED
            && is_string($this->failure_reason)
            && str_contains(strtolower($this->failure_reason), 'cancelled');
    }

    /**
     * Mark this in-flight deployment cancelled. Idempotent if already terminal.
     */
    public function markCancelledByOperator(string $reason = 'Cancelled by user.'): void
    {
        if (in_array($this->status, [self::STATUS_LIVE, self::STATUS_SUPERSEDED], true)) {
            return;
        }

        $meta = is_array($this->meta) ? $this->meta : [];
        $meta['cancelled'] = true;
        $meta['cancelled_at'] = now()->toIso8601String();

        $this->update([
            'status' => self::STATUS_FAILED,
            'failed_at' => now(),
            'failure_reason' => $reason,
            'meta' => $meta,
        ]);
    }

    /**
     * Atomically set status only when the operator has not cancelled.
     * Prevents Build/Publish jobs from resurrecting a cancelled deploy.
     */
    public function trySetStatusUnlessCancelled(string $status): bool
    {
        $affected = static::query()
            ->whereKey($this->getKey())
            ->where(function ($query): void {
                $query->whereNull('meta->cancelled')
                    ->orWhere('meta->cancelled', false);
            })
            ->update(['status' => $status]);

        $this->refresh();

        return $affected > 0 && ! $this->wasCancelledByOperator();
    }

    /**
     * Live-tail helper for the in-flight build log. While the build is
     * still running the log is on the queue worker's local filesystem
     * (path stashed in meta.local_build_log_path); after publish, the
     * runner deletes the local copy and persists to the remote disk
     * — at which point this method just returns an empty body so the
     * Livewire poller stops appending.
     *
     * Returns the new bytes since `$offset` (capped at `$maxBytes`) plus
     * the new offset the caller should use on the next poll.
     *
     * @return array{body: string, offset: int, exists: bool}
     */
    public function readLocalBuildLogSince(int $offset, int $maxBytes = 32_000): array
    {
        $path = $this->resolveLocalBuildLogPath();
        if ($path !== null && is_readable($path)) {
            $size = @filesize($path);
            if ($size !== false && $size > $offset) {
                $bytesAvailable = $size - $offset;
                $bytesToRead = (int) min($bytesAvailable, max(1, $maxBytes));
                $handle = @fopen($path, 'rb');
                if ($handle !== false) {
                    try {
                        if (@fseek($handle, $offset) === 0) {
                            $body = (string) @fread($handle, $bytesToRead);

                            return [
                                'body' => $body,
                                'offset' => $offset + strlen($body),
                                'exists' => true,
                            ];
                        }
                    } finally {
                        @fclose($handle);
                    }
                }
            } elseif ($size !== false) {
                return ['body' => '', 'offset' => $offset, 'exists' => true];
            }
        }

        // Web tier on a split runtime cannot see the worker's local
        // build.log — fall back to the Redis mirror written by EdgeBuildRunner.
        return EdgeLiveBuildLog::readSince((string) $this->id, $offset, $maxBytes);
    }

    public function readBuildLog(?Site $site = null): ?string
    {
        if (! blank($this->build_log_path)) {
            $site ??= $this->site;
            if ($site !== null) {
                try {
                    $context = app(EdgeDeliveryContextResolver::class)->forSite($site);
                    $body = app(EdgeArtifactPublisher::class)->readFile($this->build_log_path, $context->diskName);
                    if (is_string($body) && $body !== '') {
                        return EdgeLogCopy::forCustomer($body);
                    }
                } catch (\Throwable) {
                    try {
                        $body = app(EdgeArtifactPublisher::class)->readFile(
                            $this->build_log_path,
                            (string) config('edge.disk.name', 'edge_r2'),
                        );
                        if (is_string($body) && $body !== '') {
                            return EdgeLogCopy::forCustomer($body);
                        }
                    } catch (\Throwable) {
                        // Fall through to local file.
                    }
                }
            }
        }

        // Failed builds that never reached R2 (or lost the remote object) can
        // still expose the in-flight log while it remains on the build host.
        $local = $this->resolveLocalBuildLogPath();
        if ($local === null || ! is_readable($local)) {
            return null;
        }

        $body = @file_get_contents($local);

        return is_string($body) && $body !== '' ? EdgeLogCopy::forCustomer($body) : null;
    }

    /**
     * Absolute path to the in-flight build.log on the queue worker host.
     * Prefers meta.local_build_log_path, then the conventional workdir layout.
     */
    public function resolveLocalBuildLogPath(): ?string
    {
        $path = $this->meta['local_build_log_path'] ?? null;
        if (is_string($path) && $path !== '' && is_file($path)) {
            return $path;
        }

        $candidate = rtrim((string) config('edge.build.work_root', storage_path('app/edge-builds')), '/')
            .'/dply-edge-build-'.$this->id.'/build.log';

        return is_file($candidate) ? $candidate : null;
    }
}
