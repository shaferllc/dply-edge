<?php

namespace App\Models;

use App\Enums\ServerProvider;
use App\Support\Hosts\HostCapabilities;
use Database\Factories\ServerFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * The owner record an Edge site hangs off. dply-edge provisions no machines —
 * {@see \App\Modules\Edge\Actions\CreateEdgeSite} mints one of these rows with
 * `meta.host_kind = dply_edge_delivery` per site so the workspace routes and
 * the org/team scoping keep the shape they were built on. Everything that
 * spoke SSH to a real box left with the VM platform.
 *
 * @property string $id
 * @property ?string $health_status
 * @property string|null $hosting_backend
 * @property ?Carbon $last_health_check_at
 * @property string|null $logo_path
 * @property ?array<string, mixed> $meta
 * @property string $name
 * @property ?string $organization_id
 * @property ServerProvider $provider
 * @property ?string $provider_credential_id
 * @property ?string $provider_id
 * @property ?string $region
 * @property ?Carbon $scheduled_deletion_at
 * @property ?string $size
 * @property string $status
 * @property ?string $team_id
 * @property ?string $user_id
 * @property ?string $workspace_id
 * @property-read ?User $user
 * @property-read ?Organization $organization
 * @property-read ?Workspace $workspace
 * @property-read ?Team $team
 * @property-read ?ProviderCredential $providerCredential
 * @property-read Collection<int, Site> $sites
 * @property-read Collection<int, NotificationSubscription> $notificationSubscriptions
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Server extends Model
{
    /** @use HasFactory<ServerFactory> */
    use HasFactory, HasUlids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROVISIONING = 'provisioning';

    public const STATUS_READY = 'ready';

    public const STATUS_ERROR = 'error';

    public const STATUS_DISCONNECTED = 'disconnected';

    public const HOST_KIND_DPLY_EDGE = 'dply_edge_delivery';

    public const HOSTING_BACKEND_BYO = 'byo';

    public const HOSTING_BACKEND_DPLY = 'dply_managed';

    public const HEALTH_REACHABLE = 'reachable';

    public const HEALTH_UNREACHABLE = 'unreachable';

    protected $fillable = [
        'user_id',
        'organization_id',
        'workspace_id',
        'team_id',
        'provider_credential_id',
        'name',
        'logo_path',
        'provider',
        'hosting_backend',
        'provider_id',
        'status',
        'region',
        'size',
        'meta',
        'last_health_check_at',
        'health_status',
        'scheduled_deletion_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'provider' => ServerProvider::class,
            'meta' => 'array',
            'last_health_check_at' => 'datetime',
            'scheduled_deletion_at' => 'datetime',
            'comped_until' => 'datetime',
        ];
    }

    /**
     * Public URL of the custom logo, or null when none is set — callers fall
     * back to the generated gradient + initials avatar. Stored on the durable
     * `site_assets` disk so it survives a redeploy. Mirrors {@see Site::logoUrl()}.
     */
    public function logoUrl(): ?string
    {
        if (blank($this->logo_path)) {
            return null;
        }

        return Storage::disk('site_assets')->url($this->logo_path);
    }

    public function hasLogo(): bool
    {
        return filled($this->logo_path);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return BelongsTo<ProviderCredential, $this> */
    public function providerCredential(): BelongsTo
    {
        return $this->belongsTo(ProviderCredential::class);
    }

    /** @return HasMany<Site, $this> */
    public function sites(): HasMany
    {
        return $this->hasMany(Site::class, 'server_id');
    }

    /** @return MorphMany<NotificationSubscription, $this> */
    public function notificationSubscriptions(): MorphMany
    {
        return $this->morphMany(NotificationSubscription::class, 'subscribable');
    }

    private const SITES_COUNT_CACHE_TTL_SECONDS = 60;

    public function cachedSitesCount(): int
    {
        return (int) Cache::remember(
            $this->sitesCountCacheKey(),
            self::SITES_COUNT_CACHE_TTL_SECONDS,
            fn (): int => $this->sites()->count(),
        );
    }

    public function flushCachedSitesCount(): void
    {
        Cache::forget($this->sitesCountCacheKey());
    }

    private function sitesCountCacheKey(): string
    {
        return 'server:'.$this->id.':sites_count';
    }

    public function hostKind(): string
    {
        return self::HOST_KIND_DPLY_EDGE;
    }

    public function hostCapabilities(): HostCapabilities
    {
        return new HostCapabilities($this);
    }

    public function isDplyEdgeHost(): bool
    {
        return true;
    }

    public function isReady(): bool
    {
        return $this->status === self::STATUS_READY;
    }

    public function isDeletionProtected(): bool
    {
        return (($this->meta ?? [])['self_managed'] ?? false) === true;
    }

    /*
     * Host-kind vocabulary kept for shared code that still branches on it
     * (HostCapabilities, quota surfaces, billing analytics). dply-edge only
     * ever mints HOST_KIND_DPLY_EDGE rows, so every predicate below is a
     * constant `false` — the machine host kinds left with the VM platform.
     */

    public const HOST_KIND_VM = 'vm';

    public const HOST_KIND_DOCKER = 'docker';

    public const HOST_KIND_KUBERNETES = 'kubernetes';

    public const HOST_KIND_DIGITALOCEAN_FUNCTIONS = 'digitalocean_functions';

    public const HOST_KIND_AWS_LAMBDA = 'aws_lambda';

    public const HOST_KIND_DPLY_CLOUD = 'dply_cloud';

    public function isVmHost(): bool
    {
        return false;
    }

    public function isDockerHost(): bool
    {
        return false;
    }

    public function isKubernetesCluster(): bool
    {
        return false;
    }

    public function isDigitalOceanFunctionsHost(): bool
    {
        return false;
    }

    public function isAwsLambdaHost(): bool
    {
        return false;
    }

    public function isServerlessHost(): bool
    {
        return false;
    }

    public function isDplyCloudHost(): bool
    {
        return false;
    }

    public function isWorkerHost(): bool
    {
        return false;
    }

    public function isManagedVm(): bool
    {
        return false;
    }

    public function providerDisplayLabel(): string
    {
        return 'Edge';
    }
}
