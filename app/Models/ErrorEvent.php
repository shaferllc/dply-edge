<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Errors\ErrorEventRecorder;
use App\Support\Errors\ErrorRetryRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * @property string $id
 *                      One row in the dedicated error stream surfaced on the site/server "Errors"
 *                      views. Written by {@see ErrorEventRecorder} from failed
 *                      ConsoleActions and SiteDeployments. Append-only; triage is a shared
 *                      {@see $dismissed_at}.
 * @property string $category
 * @property string|null $detail
 * @property ?Carbon $dismissed_at
 * @property ?string $dismissed_by
 * @property ?string $link_url
 * @property ?Carbon $occurred_at
 * @property ?string $organization_id
 * @property string|null $reference
 * @property string|null $remediation_code
 * @property ?string $server_id
 * @property ?string $site_id
 * @property ?string $source_id
 * @property string $source_type
 * @property string $title
 * @property-read ?Organization $organization
 * @property-read ?Server $server
 * @property-read ?Site $site
 * @property-read ?User $dismisser
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class ErrorEvent extends Model
{
    use HasUlids;

    protected $fillable = [
        'organization_id',
        'server_id',
        'site_id',
        'source_type',
        'source_id',
        'category',
        'remediation_code',
        'reference',
        'title',
        'detail',
        'link_url',
        'occurred_at',
        'dismissed_at',
        'dismissed_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'dismissed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** @return MorphTo<Model, $this> */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<User, $this> */
    public function dismisser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dismissed_by');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForServer(Builder $query, string $serverId): Builder
    {
        return $query->where('server_id', $serverId);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForSite(Builder $query, string $siteId): Builder
    {
        return $query->where('site_id', $siteId);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeUndismissed(Builder $query): Builder
    {
        return $query->whereNull('dismissed_at');
    }

    /**
     * Request-scoped memo of the undismissed error count per server. The server
     * workspace nav badge ({@see server-workspace-shell}) and the Errors stream
     * both want this count; sharing it here keeps the same `count(*)` from
     * running twice on a page render. Primed by the stream's paginator when it's
     * unfiltered (see {@see WorkspaceErrors::shareStreamTotal});
     * otherwise computed on first read. Keyed per server, reset each request.
     *
     * @var array<string, int>
     */
    protected static array $undismissedServerCountMemo = [];

    public static function undismissedCountForServer(string $serverId): int
    {
        return static::$undismissedServerCountMemo[$serverId] ??= static::query()
            ->where('server_id', $serverId)
            ->whereNull('dismissed_at')
            ->count();
    }

    /**
     * How long the nav badge may serve a stale count. Matched to the sites-count
     * cache on {@see \App\Models\Server::cachedSitesCount} — shorter than this
     * and the entry keeps lapsing between page loads, so it stops earning its
     * keep as a cache.
     */
    private const BADGE_CACHE_TTL_SECONDS = 60;

    /**
     * Count for the server-workspace nav badge specifically. Same number as
     * {@see undismissedCountForServer}, but allowed to be up to
     * {@see BADGE_CACHE_TTL_SECONDS} stale — the badge renders on every page in
     * the workspace, and this count(*) was one of the slower queries on each of
     * them. Anything that needs the exact figure (the Errors stream, dismissal
     * flows) must keep calling {@see undismissedCountForServer}.
     *
     * Deliberately a separate memo: writing a possibly-stale value into the
     * exact memo would silently degrade those callers.
     *
     * @var array<string, int>
     */
    protected static array $undismissedBadgeCountMemo = [];

    public static function undismissedBadgeCountForServer(string $serverId): int
    {
        // The exact count already resolved this request (the Errors stream
        // primes it) — prefer it over anything cached.
        if (array_key_exists($serverId, static::$undismissedServerCountMemo)) {
            return static::$undismissedServerCountMemo[$serverId];
        }

        return static::$undismissedBadgeCountMemo[$serverId] ??= (int) Cache::remember(
            'server:'.$serverId.':undismissed-error-badge',
            self::BADGE_CACHE_TTL_SECONDS,
            fn (): int => static::query()
                ->where('server_id', $serverId)
                ->whereNull('dismissed_at')
                ->count(),
        );
    }

    /** Seed the memo from a count computed elsewhere (e.g. the stream paginator). */
    public static function primeUndismissedCountForServer(string $serverId, int $count): void
    {
        static::$undismissedServerCountMemo[$serverId] = $count;
    }

    /**
     * Request-scoped memo of the undismissed error count per site — the site
     * mirror of {@see $undismissedServerCountMemo}. The site settings sidebar
     * "Errors" badge and the Errors stream both want this; sharing it keeps the
     * same `count(*)` from running twice on a render. Primed by the stream's
     * paginator when unfiltered (see {@see Errors::shareStreamTotal}).
     *
     * @var array<string, int>
     */
    protected static array $undismissedSiteCountMemo = [];

    public static function undismissedCountForSite(string $siteId): int
    {
        return static::$undismissedSiteCountMemo[$siteId] ??= static::query()
            ->where('site_id', $siteId)
            ->whereNull('dismissed_at')
            ->count();
    }

    /** Seed the memo from a count computed elsewhere (e.g. the stream paginator). */
    public static function primeUndismissedCountForSite(string $siteId, int $count): void
    {
        static::$undismissedSiteCountMemo[$siteId] = $count;
    }

    public function isDismissed(): bool
    {
        return $this->dismissed_at !== null;
    }

    /** Whether the feed can re-run this error's origin in place. */
    public function isRetryable(): bool
    {
        return app(ErrorRetryRegistry::class)->isRetryable((string) $this->category);
    }

}
