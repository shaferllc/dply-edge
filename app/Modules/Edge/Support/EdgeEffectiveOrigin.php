<?php

declare(strict_types=1);

namespace App\Modules\Edge\Support;

use App\Models\EdgeDeployment;
use App\Models\Site;

/**
 * Resolves the effective origin proxy config for a hybrid Edge site
 * by merging dply.yaml-declared values with dashboard-managed ones.
 * Dashboard wins for `url` and `failover_html` (often contain secrets
 * or environment-specific values); `routes` are unioned.
 */
final class EdgeEffectiveOrigin
{
    /**
     * @return array{
     *     url: ?string,
     *     routes: list<string>,
     *     failover_html: ?string,
     *     auth_secret: ?string,
     *     access_client_id: ?string,
     *     access_client_secret: ?string,
     *     sources: array{repo: bool, dashboard: bool}
     * }
     */
    public static function for(Site $site, ?EdgeDeployment $deployment): array
    {
        $repo = self::extractRepo($deployment);
        $dash = self::extractDashboard($site);

        $url = ($dash['url'] ?? null) ?: ($repo['url'] ?? null);
        $failover = ($dash['failover_html'] ?? null) ?: ($repo['failover_html'] ?? null);

        $routes = array_values(array_unique(array_merge(
            $repo['routes'] ?? [],
            $dash['routes'] ?? [],
        )));

        return [
            'url' => $url,
            'routes' => $routes,
            'failover_html' => $failover,
            // Credentials come from the encrypted column, never from meta or
            // the repo — dply.yaml is committed, so it must not carry secrets.
            'auth_secret' => $site->edgeOriginSecret('auth_secret'),
            'access_client_id' => $site->edgeOriginSecret('access_client_id'),
            'access_client_secret' => $site->edgeOriginSecret('access_client_secret'),
            'sources' => [
                'repo' => $repo !== [],
                'dashboard' => $dash !== [],
            ],
        ];
    }

    /** @return array{url?: string, routes?: list<string>, failover_html?: string} */
    private static function extractRepo(?EdgeDeployment $deployment): array
    {
        $repoConfig = is_array($deployment?->repo_config) ? $deployment->repo_config : [];
        $origin = is_array($repoConfig['origin'] ?? null) ? $repoConfig['origin'] : [];

        return self::sanitize($origin);
    }

    /** @return array{url?: string, routes?: list<string>, failover_html?: string} */
    private static function extractDashboard(Site $site): array
    {
        $origin = is_array($site->edgeMeta()['origin'] ?? null) ? $site->edgeMeta()['origin'] : [];

        return self::sanitize($origin);
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array{url?: string, routes?: list<string>, failover_html?: string}
     */
    private static function sanitize(array $value): array
    {
        $out = [];
        $url = is_string($value['url'] ?? null) ? trim((string) $value['url']) : '';
        if ($url !== '' && filter_var($url, FILTER_VALIDATE_URL)) {
            $out['url'] = $url;
        }
        $routes = is_array($value['routes'] ?? null) ? $value['routes'] : [];
        $clean = [];
        foreach ($routes as $r) {
            if (is_string($r) && trim($r) !== '') {
                $clean[] = trim($r);
            }
        }
        if ($clean !== []) {
            $out['routes'] = $clean;
        }
        $failover = is_string($value['failover_html'] ?? null) ? (string) $value['failover_html'] : '';
        if ($failover !== '') {
            $out['failover_html'] = $failover;
        }

        return $out;
    }
}
