<?php

declare(strict_types=1);

namespace App\Modules\Edge\Services;

use App\Models\EdgeSiteEnvVar;
use App\Models\Site;
use App\Support\Sites\LinkedOrganizationSecrets;

/**
 * Production env for Edge build + Worker bundles: linked org vault secrets
 * under per-site EdgeSiteEnvVar rows (site keys win). A preview starts from
 * its parent's env. Reserved names stay
 * filtered by {@see EdgeSiteEnvVar::keyIsValid()}.
 */
final class EdgeProductionEnv
{
    public function __construct(
        private readonly LinkedOrganizationSecrets $linkedSecrets,
    ) {}

    /**
     * @return array<string, string>
     */
    public function forSite(Site $site): array
    {
        // Previews inherit the parent's env + linked secrets at build and
        // runtime; the preview's own rows (API/CLI) override per key.
        $env = [];
        if ($site->isEdgePreview()) {
            $parent = Site::query()
                ->where('organization_id', $site->organization_id)
                ->find($site->edgeMeta()['preview_parent_site_id']);
            if ($parent !== null && ! $parent->isEdgePreview()) {
                // Never production's database or Redis: a pull request's
                // code (and its migrations) must not touch live data. The
                // preview's own rows below can still set them explicitly.
                $env = array_filter(
                    $this->forSite($parent),
                    static fn (string $key): bool => ! self::isDataConnectionKey($key),
                    ARRAY_FILTER_USE_KEY,
                );
            }
        }

        foreach ($this->linkedSecrets->valuesForSite($site) as $key => $value) {
            if (! EdgeSiteEnvVar::keyIsValid($key)) {
                continue;
            }
            $env[$key] = $value;
        }

        foreach ($site->edgeEnvVars()->where('scope', 'production')->get() as $envVar) {
            if (! EdgeSiteEnvVar::keyIsValid($envVar->key)) {
                continue;
            }
            $env[$envVar->key] = (string) $envVar->value;
        }

        return $env;
    }

    /** Keys that point an app at a database or Redis (Laravel, Rails, Node conventions). */
    public static function isDataConnectionKey(string $key): bool
    {
        return in_array($key, ['DATABASE_URL', 'DB_URL', 'REDIS_URL', 'MONGODB_URI', 'MONGO_URL'], true)
            || str_starts_with($key, 'DB_')
            || str_starts_with($key, 'REDIS_')
            // Another attached database's connection (DplyDatabases): ANALYTICS_DB_HOST, ANALYTICS_DATABASE_URL …
            || preg_match('/^[A-Z][A-Z0-9_]*_(DB_[A-Z_]+|DATABASE_URL|MONGODB_URI|MONGO_URL|MONGODB_DATABASE|MYSQL_ATTR_SSL_CA)$/', $key) === 1;
    }
}
