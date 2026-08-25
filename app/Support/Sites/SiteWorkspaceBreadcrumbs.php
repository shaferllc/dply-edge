<?php

declare(strict_types=1);

namespace App\Support\Sites;

use App\Models\Server;
use App\Models\Site;
use App\Support\SiteSettingsHeader;

/**
 * Breadcrumb items for BYO / Edge / Serverless site workspace sub-pages.
 */
final class SiteWorkspaceBreadcrumbs
{
    /**
     * @return list<array{label: string, href?: string|null, icon?: string|null}>
     */
    public static function items(
        Server $server,
        Site $site,
        string $currentLabel,
        ?string $currentIcon = null,
    ): array {
        return self::edgeItems($server, $site, $currentLabel, $currentIcon);
    }

    /**
     * @return list<array{label: string, href?: string|null, icon?: string|null}>
     */
    private static function edgeItems(
        Server $server,
        Site $site,
        string $currentLabel,
        ?string $currentIcon,
    ): array {
        $items = [
            ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
            ['label' => __('Edge'), 'href' => route('edge.index'), 'icon' => 'globe-alt'],
            [
                'label' => $site->name,
                'href' => route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'general']),
                'icon' => 'globe-alt',
                'avatar' => $site->name ?: (string) $site->id,
                'avatar_image' => $site->logoUrl(),
            ],
            [
                'label' => $currentLabel,
                'icon' => $currentIcon ?? 'map-pin',
            ],
        ];

        return $items;
    }

    public static function iconKeyFromSection(string $section, Site $site, Server $server): string
    {
        $header = SiteSettingsHeader::for($site, $server, $section);
        $icon = $header['icon'];

        if ($icon === '') {
            return 'map-pin';
        }

        if (str_starts_with($icon, 'heroicon-o-')) {
            return substr($icon, strlen('heroicon-o-'));
        }

        return $icon;
    }
}
