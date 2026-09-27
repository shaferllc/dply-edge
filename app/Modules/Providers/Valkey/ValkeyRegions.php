<?php

declare(strict_types=1);

namespace App\Modules\Providers\Valkey;

/**
 * Where dply keeps data: one gateway cluster per region (a DigitalOcean
 * region paired with the Cloudflare region its apps run in). The first is
 * built from the original single-region settings (DPLY_VALKEY_*); more come
 * from DPLY_VALKEY_REGIONS (JSON list). See docs/DATA_REGIONS.md.
 *
 * A record without a region is in the default one.
 */
final class ValkeyRegions
{
    /**
     * @return array<string, array{key: string, label: string, cloudflare: string, api_url: string, token: string, domain: string, db_domain: string, port: int}>
     */
    public static function all(): array
    {
        $out = [];
        foreach (array_values((array) config('edge.valkey.regions', [])) as $i => $region) {
            if (! is_array($region) || ($region['key'] ?? '') === '') {
                continue;
            }
            if ($i === 0) {
                // The default region is the original single-region setup: its
                // edge.valkey.* keys stay the source (and what tests override).
                $region = array_merge($region, array_filter([
                    'api_url' => config('edge.valkey.api_url'),
                    'token' => config('edge.valkey.token'),
                    'domain' => config('edge.valkey.domain'),
                    'db_domain' => config('edge.valkey.db_domain'),
                    'port' => config('edge.valkey.port'),
                ], static fn ($v): bool => $v !== null && $v !== ''));
                // Where its apps run (empty turns near-data placement off).
                if (config()->has('edge.valkey.data_region')) {
                    $region['cloudflare'] = (string) config('edge.valkey.data_region');
                }
            }
            $key = strtolower((string) $region['key']);
            $out[$key] = [
                'key' => $key,
                'label' => (string) ($region['label'] ?? $key),
                'cloudflare' => strtoupper((string) ($region['cloudflare'] ?? '')),
                'api_url' => rtrim((string) ($region['api_url'] ?? ''), '/'),
                'token' => (string) ($region['token'] ?? ''),
                'domain' => (string) ($region['domain'] ?? 'cache.dply.local'),
                'db_domain' => (string) ($region['db_domain'] ?? 'db.dply.local'),
                'port' => (int) ($region['port'] ?? 6380),
            ];
        }

        return $out;
    }

    public static function default(): string
    {
        return (string) (array_key_first(self::all()) ?? 'default');
    }

    /** A region's settings; an unknown or empty key means the default region. */
    public static function get(?string $key = null): array
    {
        $all = self::all();

        return $all[strtolower((string) $key)] ?? $all[self::default()] ?? [
            'key' => 'default', 'label' => 'default', 'cloudflare' => '', 'api_url' => '', 'token' => '',
            'domain' => 'cache.dply.local', 'db_domain' => 'db.dply.local', 'port' => 6380,
        ];
    }

    /** The region for data used by apps in this Cloudflare region, or null. */
    public static function forCloudflare(string $cloudflare): ?string
    {
        foreach (self::all() as $key => $region) {
            if ($region['cloudflare'] === strtoupper($cloudflare)) {
                return $key;
            }
        }

        return null;
    }

    public static function configured(?string $key = null): bool
    {
        $region = self::get($key);

        return $region['api_url'] !== '' && $region['token'] !== '';
    }
}
