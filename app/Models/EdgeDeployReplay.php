<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property ?string $error_message
 * @property ?Carbon $finished_at
 * @property ?string $organization_id
 * @property ?string $parent_site_id
 * @property ?string $preview_deployment_id
 * @property ?string $preview_site_id
 * @property array<string, mixed> $results
 * @property string $sample_limit
 * @property array<string, mixed> $samples
 * @property ?Carbon $started_at
 * @property string $status
 * @property array<string, mixed> $summary
 * @property ?string $triggered_by_user_id
 * @property string $window_minutes
 * @property-read ?Site $parentSite
 * @property-read ?Site $previewSite
 * @property-read ?User $triggeredBy
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class EdgeDeployReplay extends Model
{
    use HasUlids;

    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'organization_id',
        'parent_site_id',
        'preview_site_id',
        'preview_deployment_id',
        'triggered_by_user_id',
        'status',
        'sample_limit',
        'window_minutes',
        'samples',
        'results',
        'summary',
        'error_message',
        'started_at',
        'finished_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'samples' => 'array',
            'results' => 'array',
            'summary' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Site, $this> */
    public function parentSite(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'parent_site_id');
    }

    /** @return BelongsTo<Site, $this> */
    public function previewSite(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'preview_site_id');
    }

    /** @return BelongsTo<User, $this> */
    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by_user_id');
    }
}
