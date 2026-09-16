<?php

declare(strict_types=1);

namespace App\Modules\Edge\Support;

use App\Models\EdgeDeployment;
use App\Models\Site;

/**
 * Resolves the effective image-optimization config by merging
 * dply.yaml `images.allowed_hosts` with the dashboard list. The
 * signing secret is dashboard-only — it must NEVER round-trip into
 * the repo file. Hosts are unioned + deduped.
 */
final class EdgeEffectiveImages
{
    /**
     * @return array{
     *     enabled: bool,
     *     allowed_hosts: list<string>,
     *     signing_secret: ?string,
     *     sources: array{repo: bool, dashboard: bool}
     * }
     */
    public static function for(Site $site, ?EdgeDeployment $deployment): array
    {
        $repoConfig = is_array($deployment?->repo_config) ? $deployment->repo_config : [];
        $repoImages = is_array($repoConfig['images'] ?? null) ? $repoConfig['images'] : [];
        $repoHosts = self::cleanHosts(is_array($repoImages['allowed_hosts'] ?? null) ? $repoImages['allowed_hosts'] : []);

        $dashImages = is_array($site->edgeMeta()['images'] ?? null) ? $site->edgeMeta()['images'] : [];
        $dashHosts = self::cleanHosts(is_array($dashImages['allowed_hosts'] ?? null) ? $dashImages['allowed_hosts'] : []);
        $secret = is_string($dashImages['signing_secret'] ?? null) ? trim((string) $dashImages['signing_secret']) : '';

        $hosts = array_values(array_unique(array_merge($repoHosts, $dashHosts)));
        sort($hosts);

        return [
            'enabled' => $secret !== '',
            'allowed_hosts' => $hosts,
            'signing_secret' => $secret !== '' ? $secret : null,
            'sources' => [
                'repo' => $repoHosts !== [],
                'dashboard' => $dashHosts !== [] || $secret !== '',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $hosts
     * @return list<string>
     */
    private static function cleanHosts(array $hosts): array
    {
        $out = [];
        foreach ($hosts as $h) {
            if (! is_string($h)) {
                continue;
            }
            $clean = strtolower(trim($h));
            if ($clean !== '' && preg_match('/^[a-z0-9.\-]+$/', $clean)) {
                $out[$clean] = true;
            }
        }

        return array_keys($out);
    }
}
