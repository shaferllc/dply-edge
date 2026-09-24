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
     * @return array{label: string, href: string, icon: string}
     */
    public static function projectsItem(): array
    {
        return ['label' => __('Projects'), 'href' => route('dashboard'), 'icon' => 'globe-alt'];
    }

    /**
     * Crumb for the project. Shows the uploaded logo when one is set, and the
     * same initials mark as the workspace sidebar when it is not.
     *
     * @return array{label: string, href: string|null, icon: string, avatar: string, avatar_image: string|null}
     */
    public static function projectItem(Site $site, ?string $href = null): array
    {
        $name = (string) $site->name;

        return [
            'label' => $name,
            'href' => $href,
            'icon' => 'globe-alt',
            'avatar' => $name !== '' ? $name : (string) $site->id,
            'avatar_image' => $site->logoUrl(),
        ];
    }

    /**
     * @return list<array{label: string, href?: string|null, icon?: string|null, avatar?: string, avatar_image?: string|null}>
     */
    private static function edgeItems(
        Server $server,
        Site $site,
        string $currentLabel,
        ?string $currentIcon,
    ): array {
        return [
            ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
            self::projectsItem(),
            self::projectItem($site, route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'general'])),
            [
                'label' => $currentLabel,
                'icon' => $currentIcon ?? 'map-pin',
            ],
        ];
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
