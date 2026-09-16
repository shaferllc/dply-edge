<?php

declare(strict_types=1);

namespace App\Modules\Edge\Support;

use App\Models\EdgeDeployment;
use App\Models\Site;

/**
 * Resolves the effective firewall config for an Edge site by merging
 * dply.yaml-declared rules with dashboard-managed ones. Same shape /
 * semantics as {@see EdgeEffectiveCrons}: repo entries are primary,
 * dashboard entries are additive.
 *
 * Merge rules:
 *   - Mode: dashboard wins if set to allow/block; falls back to repo,
 *           then 'off'.
 *   - Countries: union of both lists (dedup, uppercase).
 */
final class EdgeEffectiveFirewall
{
    /**
     * @return array{country_mode: string, countries: list<string>, sources: array{repo: bool, dashboard: bool}}
     */
    public static function for(Site $site, ?EdgeDeployment $deployment): array
    {
        $repo = self::extractRepo($deployment);
        $dashboard = self::extractDashboard($site);

        $repoMode = $repo['country_mode'] ?? null;
        $dashMode = $dashboard['country_mode'] ?? null;
        $mode = $dashMode && in_array($dashMode, ['allow', 'block'], true)
            ? $dashMode
            : ($repoMode && in_array($repoMode, ['allow', 'block'], true) ? $repoMode : 'off');

        $countries = array_values(array_unique(array_merge(
            $repo['countries'] ?? [],
            $dashboard['countries'] ?? [],
        )));
        sort($countries);

        return [
            'country_mode' => $mode,
            'countries' => $countries,
            'sources' => [
                'repo' => $repo !== [],
                'dashboard' => $dashboard !== [],
            ],
        ];
    }

    /** @return array{country_mode?: string, countries?: list<string>} */
    private static function extractRepo(?EdgeDeployment $deployment): array
    {
        $repoConfig = is_array($deployment?->repo_config) ? $deployment->repo_config : [];
        $firewall = is_array($repoConfig['firewall'] ?? null) ? $repoConfig['firewall'] : [];

        return self::sanitize($firewall);
    }

    /** @return array{country_mode?: string, countries?: list<string>} */
    private static function extractDashboard(Site $site): array
    {
        $firewall = is_array($site->edgeMeta()['firewall'] ?? null) ? $site->edgeMeta()['firewall'] : [];

        return self::sanitize($firewall);
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array{country_mode?: string, countries?: list<string>}
     */
    private static function sanitize(array $value): array
    {
        $out = [];
        $mode = is_string($value['country_mode'] ?? null) ? strtolower(trim($value['country_mode'])) : '';
        if (in_array($mode, ['allow', 'block'], true)) {
            $out['country_mode'] = $mode;
        }
        $codes = is_array($value['countries'] ?? null) ? $value['countries'] : [];
        $clean = [];
        foreach ($codes as $c) {
            if (! is_string($c)) {
                continue;
            }
            $upper = strtoupper(trim($c));
            if (preg_match('/^[A-Z]{2}$/', $upper) === 1) {
                $clean[$upper] = true;
            }
        }
        if ($clean !== []) {
            $out['countries'] = array_keys($clean);
        }

        return $out;
    }
}
