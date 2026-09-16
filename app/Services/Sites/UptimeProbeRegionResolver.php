<?php

declare(strict_types=1);

namespace App\Services\Sites;

use App\Models\Site;

/**
 * Picks the uptime probe region that best matches where a site is hosted,
 * so an auto-created monitor's region label reflects the host instead of
 * defaulting to EU. Maps a provider region slug — DigitalOcean (nyc1, sfo3,
 * ams3, …) or Hetzner (fsn1, nbg1, ash, …) — onto one of the configured
 * `site_uptime.probe_regions` keys.
 */
final class UptimeProbeRegionResolver
{
    /** Provider region slug prefix → preferred probe-region key. */
    private const MAP = [
        // DigitalOcean
        'nyc' => 'us-east',
        'tor' => 'us-east',
        'sfo' => 'us-west',
        'ams' => 'eu-amsterdam',
        'lon' => 'eu-amsterdam',
        'fra' => 'eu-frankfurt',
        'sgp' => 'ap-sydney',
        'blr' => 'ap-sydney',
        'syd' => 'ap-sydney',
        // Hetzner — Falkenstein/Nuremberg/Helsinki are the eu-central zone,
        // nearest the worker-1 box in Falkenstein.
        'fsn' => 'eu-falkenstein',
        'nbg' => 'eu-falkenstein',
        'hel' => 'eu-falkenstein',
        'ash' => 'us-east',
        'hil' => 'us-west',
        'sin' => 'ap-sydney',
    ];

    public function forSite(Site $site): string
    {
        $site->loadMissing('server');

        return $this->resolve($site->server?->region);
    }

    /**
     * Map a host region slug to a configured probe-region key, falling back
     * to the first configured region when it's unknown or unmappable.
     */
    public function resolve(?string $serverRegion): string
    {
        $regions = array_keys((array) config('site_uptime.probe_regions', []));
        $fallback = $regions[0] ?? 'us-east';

        $region = strtolower(trim((string) $serverRegion));
        if ($region === '') {
            return $fallback;
        }

        foreach (self::MAP as $prefix => $probe) {
            if (str_starts_with($region, $prefix)) {
                return in_array($probe, $regions, true) ? $probe : $fallback;
            }
        }

        return $fallback;
    }
}
