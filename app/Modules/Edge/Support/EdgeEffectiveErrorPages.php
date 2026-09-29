<?php

declare(strict_types=1);

namespace App\Modules\Edge\Support;

use App\Models\EdgeDeployment;
use App\Models\Site;

/**
 * Resolves the effective custom 404 / 500 / maintenance config by
 * merging dply.yaml-declared values with dashboard-managed ones.
 *
 * Dashboard wins over repo when both supply the same field — error
 * pages are often edited in the moment (e.g., maintenance toggle
 * during an incident) and the dashboard is the operator's surface.
 * The repo declaration is the "baseline" / source-control'd version.
 *
 * Path-based dply.yaml entries (`html_404_path`, etc.) are not
 * resolved here — the build runner resolves them at build time and
 * inlines the HTML into the deployment's repo_config snapshot.
 */
final class EdgeEffectiveErrorPages
{
    /**
     * @return array{
     *     html_404: ?string,
     *     html_500: ?string,
     *     html_403: ?string,
     *     maintenance_enabled: bool,
     *     maintenance_html: ?string,
     *     sources: array{repo: bool, dashboard: bool}
     * }
     */
    public static function for(Site $site, ?EdgeDeployment $deployment): array
    {
        $repoConfig = is_array($deployment?->repo_config) ? $deployment->repo_config : [];
        $repoErrors = is_array($repoConfig['error_pages'] ?? null) ? $repoConfig['error_pages'] : [];
        $repoMaint = is_array($repoConfig['maintenance'] ?? null) ? $repoConfig['maintenance'] : [];

        $meta = $site->edgeMeta();
        $dashErrors = is_array($meta['error_pages'] ?? null) ? $meta['error_pages'] : [];
        $dashMaint = is_array($meta['maintenance'] ?? null) ? $meta['maintenance'] : [];

        $repo404 = is_string($repoErrors['html_404'] ?? null) ? trim((string) $repoErrors['html_404']) : '';
        $repo500 = is_string($repoErrors['html_500'] ?? null) ? trim((string) $repoErrors['html_500']) : '';
        $dash404 = is_string($dashErrors['html_404'] ?? null) ? trim((string) $dashErrors['html_404']) : '';
        $dash500 = is_string($dashErrors['html_500'] ?? null) ? trim((string) $dashErrors['html_500']) : '';
        // Geo-blocked 403 page is dashboard-only (no dply.yaml key).
        $dash403 = is_string($dashErrors['html_403'] ?? null) ? trim((string) $dashErrors['html_403']) : '';

        $repoMaintHtml = is_string($repoMaint['html'] ?? null) ? trim((string) $repoMaint['html']) : '';
        $dashMaintHtml = is_string($dashMaint['html'] ?? null) ? trim((string) $dashMaint['html']) : '';
        $repoMaintOn = (bool) ($repoMaint['enabled'] ?? false);
        $dashMaintOn = (bool) ($dashMaint['enabled'] ?? false);

        return [
            'html_404' => $dash404 !== '' ? $dash404 : ($repo404 !== '' ? $repo404 : null),
            'html_500' => $dash500 !== '' ? $dash500 : ($repo500 !== '' ? $repo500 : null),
            'html_403' => $dash403 !== '' ? $dash403 : null,
            'maintenance_enabled' => $dashMaintOn || $repoMaintOn,
            'maintenance_html' => $dashMaintHtml !== '' ? $dashMaintHtml : ($repoMaintHtml !== '' ? $repoMaintHtml : null),
            'sources' => [
                'repo' => $repoErrors !== [] || $repoMaint !== [],
                'dashboard' => $dashErrors !== [] || $dashMaint !== [],
            ],
        ];
    }
}
