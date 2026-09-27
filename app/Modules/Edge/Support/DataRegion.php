<?php

declare(strict_types=1);

namespace App\Modules\Edge\Support;

use App\Models\Site;
use App\Modules\Providers\Valkey\ValkeyRegions;

/**
 * Which dply region an app's data lives in, and so which Cloudflare region
 * the app should run in. See docs/DATA_REGIONS.md.
 */
final class DataRegion
{
    /** The region an app's data is in now: its database first, else its dply Valkey. Null when it keeps none with dply. */
    public static function of(Site $site): ?string
    {
        $database = $site->edgeMeta()['database'] ?? [];
        if (is_array($database) && ($database['provider'] ?? '') === 'dply' && in_array($database['engine'] ?? '', ['postgres', 'mysql', 'mongodb'], true)) {
            return ValkeyRegions::get((string) ($database['region'] ?? ''))['key'];
        }
        foreach (EdgeContainerConnections::for($site) as $connection) {
            if ($connection['kind'] === 'redis' && EdgeValkey::isTarget((string) $connection['target'])) {
                return ValkeyRegions::get(EdgeValkey::region((string) $connection['target']))['key'];
            }
        }

        return null;
    }

    /**
     * Where new data for this app goes: with its existing data; else the
     * region paired with the Cloudflare region the app chose; else Europe
     * for an EU-jurisdiction app; else the default.
     */
    public static function forSite(Site $site): string
    {
        if (($existing = self::of($site)) !== null) {
            return $existing;
        }
        $settings = EdgeContainerSettings::for($site);
        foreach ($settings['regions'] as $cloudflare) {
            if (($key = ValkeyRegions::forCloudflare($cloudflare)) !== null) {
                return $key;
            }
        }
        if ($settings['jurisdiction'] === 'eu') {
            return ValkeyRegions::forCloudflare('WEUR') ?? ValkeyRegions::forCloudflare('EEUR') ?? ValkeyRegions::default();
        }

        return ValkeyRegions::default();
    }

    /** The Cloudflare region an app with dply data should run in, or null. */
    public static function cloudflareFor(Site $site): ?string
    {
        $region = self::of($site);
        $cloudflare = $region !== null ? ValkeyRegions::get($region)['cloudflare'] : '';

        return $cloudflare !== '' ? $cloudflare : null;
    }
}
