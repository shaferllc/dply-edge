<?php

declare(strict_types=1);

namespace App\Modules\Edge\Support;

use App\Models\EdgeDeployment;
use App\Models\Site;

/**
 * Compute the effective Cloudflare binding list for an Edge site at a given
 * deployment. Bindings originate from two sources:
 *
 *   1. `wrangler.toml` in the repo, extracted at build time and snapshotted
 *      onto `edge_deployments.repo_config['bindings']`
 *   2. Resources-page rows on `site.meta.edge.connections` (key-value,
 *      object storage, SQL, queue)
 *
 * Same contract as {@see EdgeEffectiveCrons}: the repo file is the primary
 * source of truth and dashboard rows are purely *additive*. On a name
 * collision the repo wins, so a committed `wrangler.toml` can never be
 * silently overridden by something a teammate clicked in the dashboard —
 * the deploy stays reproducible from the repo alone.
 *
 * The four `kind` values map onto Cloudflare binding types:
 *   kv    -> kv_namespace (value = namespace id)
 *   r2    -> r2_bucket    (value = bucket name)
 *   d1    -> d1           (value = database id)
 *   queue -> queue        (value = queue name)
 */
final class EdgeEffectiveBindings
{
    public const KINDS = ['kv', 'r2', 'd1', 'queue'];

    /**
     * Names the platform Worker already injects. A user binding may never
     * shadow one of these — the upload would either be rejected or would
     * break the runtime. Single source of truth: the translator drops them at
     * deploy time, the dashboard refuses to add them, and this class hides any
     * that somehow made it into meta, so the UI never shows a binding that
     * would silently fail to ship.
     */
    public const RESERVED_NAMES = [
        'HOST_MAP',
        'ASSETS',
        'DEPLOYMENT_ID',
        'SITE_ID',
        'STORAGE_PREFIX',
        'EDGE_CACHE',
        'DISPATCHER',
    ];

    /** Repo `bindings:` buckets are plural for queues; dashboard rows are singular. */
    private const REPO_BUCKET_FOR_KIND = [
        'kv' => 'kv',
        'r2' => 'r2',
        'd1' => 'd1',
        'queue' => 'queues',
    ];

    /**
     * @return list<array{name: string, kind: string, value: string, source: 'repo'|'dashboard'}>
     */
    public static function for(Site $site, ?EdgeDeployment $deployment): array
    {
        $repo = self::repoBindings($deployment);
        $taken = array_map(static fn (array $b): string => $b['name'], $repo);

        $dashboard = [];
        foreach (self::dashboardOverrides($site) as $entry) {
            // Repo wins on collision — drop the dashboard duplicate rather than
            // emitting two bindings with the same name (Cloudflare would reject
            // the upload, failing an otherwise-good deploy).
            if (in_array($entry['name'], $taken, true)) {
                continue;
            }
            $taken[] = $entry['name'];
            $dashboard[] = $entry;
        }

        return array_merge($repo, $dashboard);
    }

    /**
     * Dashboard rows only, normalized. Used by the translator to append to the
     * repo-derived list it already resolves through EdgeBindingsAutoResolver.
     *
     * @return list<array{name: string, kind: string, value: string, source: 'dashboard'}>
     */
    public static function dashboardOverrides(Site $site): array
    {
        $out = [];
        foreach (EdgeContainerConnections::for($site) as $connection) {
            $kind = self::KIND_FOR_CONNECTION[$connection['kind']] ?? null;
            if ($kind === null || $connection['asleep'] || $connection['target'] === '') {
                continue;
            }
            $out[] = ['name' => $connection['name'], 'kind' => $kind, 'value' => $connection['target'], 'source' => 'dashboard'];
        }

        return $out;
    }

    /**
     * Resources-page names the repo's wrangler.toml also declares. The repo
     * wins at deploy; the Resources card and the deploy log say so.
     *
     * @return list<string>
     */
    public static function overriddenByRepo(Site $site, ?EdgeDeployment $deployment): array
    {
        $repo = array_column(self::repoBindings($deployment), 'name');

        return array_values(array_filter(
            array_column(EdgeContainerConnections::for($site), 'name'),
            static fn (string $name): bool => in_array($name, $repo, true),
        ));
    }

    /** Connection kinds (Resources page) that are plain Cloudflare bindings. */
    public const KIND_FOR_CONNECTION = [
        'key_value' => 'kv',
        'object_storage' => 'r2',
        'sql' => 'd1',
        'queue' => 'queue',
    ];

    /**
     * @return list<array{name: string, kind: string, value: string, source: 'repo'}>
     */
    private static function repoBindings(?EdgeDeployment $deployment): array
    {
        $config = is_array($deployment?->repo_config) ? $deployment->repo_config : [];
        $declared = is_array($config['bindings'] ?? null) ? $config['bindings'] : [];

        $out = [];
        foreach (self::KINDS as $kind) {
            $bucket = $declared[self::REPO_BUCKET_FOR_KIND[$kind]] ?? null;
            if (! is_array($bucket)) {
                continue;
            }
            foreach ($bucket as $name => $value) {
                if (! is_string($name) || $name === '' || ! is_string($value) || $value === '') {
                    continue;
                }
                $out[] = ['name' => $name, 'kind' => $kind, 'value' => $value, 'source' => 'repo'];
            }
        }

        return $out;
    }
}
