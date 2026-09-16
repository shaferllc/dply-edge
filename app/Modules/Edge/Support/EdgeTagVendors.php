<?php

declare(strict_types=1);

namespace App\Modules\Edge\Support;

/**
 * Tag vendors the Edge Worker knows how to load (loader + init snippet).
 * The Worker writes `id` into an inline <script>, so every id is matched
 * against its vendor pattern here — on dashboard Save, repo config, and
 * host-map publish — and rejected rather than escaped.
 */
final class EdgeTagVendors
{
    public const PURPOSES = ['necessary', 'analytics', 'marketing'];

    /**
     * @return array<string, array{name: string, label: string, pattern: string, placeholder: string, purpose: string, hint: string}>
     */
    public static function all(): array
    {
        return [
            'ga4' => [
                'name' => 'Google Analytics',
                'label' => 'GA4',
                'pattern' => '/^G-[A-Z0-9]{4,16}$/',
                'placeholder' => 'G-XXXXXXXXXX',
                'purpose' => 'analytics',
                'hint' => __('Measurement ID from GA4 → Admin → Data streams.'),
            ],
            'gtm' => [
                'name' => 'Google Tag Manager',
                'label' => 'GTM',
                'pattern' => '/^GTM-[A-Z0-9]{4,12}$/',
                'placeholder' => 'GTM-XXXXXXX',
                'purpose' => 'analytics',
                'hint' => __('Container ID from Tag Manager.'),
            ],
            'meta' => [
                'name' => 'Meta Pixel',
                'label' => 'Meta',
                'pattern' => '/^\d{5,20}$/',
                'placeholder' => '123456789012345',
                'purpose' => 'marketing',
                'hint' => __('Pixel ID from Events Manager.'),
            ],
            'clarity' => [
                'name' => 'Microsoft Clarity',
                'label' => 'Clarity',
                'pattern' => '/^[a-z0-9]{6,16}$/i',
                'placeholder' => 'abcd12345',
                'purpose' => 'analytics',
                'hint' => __('Project ID from Clarity → Settings.'),
            ],
            'hotjar' => [
                'name' => 'Hotjar',
                'label' => 'Hotjar',
                'pattern' => '/^\d{4,10}$/',
                'placeholder' => '1234567',
                'purpose' => 'analytics',
                'hint' => __('Site ID from Hotjar → Sites & Organizations.'),
            ],
            'plausible' => [
                'name' => 'Plausible',
                'label' => 'Plausible',
                'pattern' => '/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/i',
                'placeholder' => 'example.com',
                'purpose' => 'analytics',
                'hint' => __('The domain as registered in Plausible.'),
            ],
        ];
    }

    public static function validId(string $vendor, string $id): bool
    {
        $def = self::all()[$vendor] ?? null;

        return $def !== null && strlen($id) <= 253 && preg_match($def['pattern'], $id) === 1;
    }

    /**
     * Normalize one tool row from dashboard, repo or meta. Returns null when
     * the row can't be published (bad vendor id, non-https custom src).
     * Rows without `vendor` are pre-catalog custom script URLs.
     *
     * @param  array<string, mixed>  $tool
     * @return array{name: string, vendor: string, id: string, src: string, async: bool, purpose: string, path: string}|null
     */
    public static function normalize(array $tool): ?array
    {
        $vendor = (string) ($tool['vendor'] ?? 'custom');
        $id = trim((string) ($tool['id'] ?? ''));
        if (in_array($vendor, ['ga4', 'gtm'], true)) {
            $id = strtoupper($id);
        }
        $src = trim((string) ($tool['src'] ?? ''));

        if ($vendor === 'custom') {
            if ($src === '' || ! str_starts_with($src, 'https://') || strlen($src) > 500) {
                return null;
            }
            $id = '';
        } elseif (self::validId($vendor, $id)) {
            $src = '';
        } else {
            return null;
        }

        $purpose = (string) ($tool['purpose'] ?? (self::all()[$vendor]['purpose'] ?? 'analytics'));
        $path = trim((string) ($tool['path'] ?? '')) ?: '/*';

        return [
            'name' => trim((string) ($tool['name'] ?? '')) ?: (self::all()[$vendor]['name'] ?? 'tag'),
            'vendor' => $vendor,
            'id' => $id,
            'src' => $src,
            'async' => (bool) ($tool['async'] ?? true),
            'purpose' => in_array($purpose, self::PURPOSES, true) ? $purpose : 'analytics',
            'path' => str_starts_with($path, '/') || $path === '*' ? substr($path, 0, 200) : '/*',
        ];
    }
}
