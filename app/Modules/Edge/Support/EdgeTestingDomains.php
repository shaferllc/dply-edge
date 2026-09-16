<?php

declare(strict_types=1);

namespace App\Modules\Edge\Support;

use App\Support\TestingDomains;

/**
 * Edge delivery hostnames use the on-dply.* pool (e.g. on-dply.live), separate
 * from BYO/serverless testing domains and Cloud provider URLs.
 */
final class EdgeTestingDomains
{
    /**
     * @return list<string>
     */
    public static function all(): array
    {
        $configured = array_values(array_filter(array_map(
            static fn (string $value): string => strtolower(trim($value)),
            (array) config('edge.testing_domains', []),
        )));

        return $configured !== [] ? $configured : TestingDomains::edge();
    }

    private const PREFERRED_APEX = 'on-dply.live';

    public static function defaultApex(): string
    {
        foreach (self::all() as $domain) {
            if ($domain === self::PREFERRED_APEX) {
                return $domain;
            }
        }

        foreach (self::all() as $domain) {
            if ($domain === 'on-dply.cloud') {
                return $domain;
            }
        }

        foreach (self::all() as $domain) {
            if (self::isOnDplyDomain($domain)) {
                return $domain;
            }
        }

        return self::all()[0] ?? self::PREFERRED_APEX;
    }

    /**
     * Apex for Fake Edge / Valet so hostnames resolve to this machine.
     * Prefers non–on-dply entries (e.g. edge.test, dply.test), then the
     * APP_URL .test host — never a public Cloudflare on-dply apex when a
     * local option exists.
     */
    public static function localDevApex(): string
    {
        foreach (self::all() as $domain) {
            if (! self::isOnDplyDomain($domain)) {
                return $domain;
            }
        }

        $appHost = strtolower(trim((string) parse_url((string) config('app.url'), PHP_URL_HOST)));
        if ($appHost !== '' && (str_ends_with($appHost, '.test') || str_ends_with($appHost, '.localhost'))) {
            return $appHost;
        }

        return self::defaultApex();
    }

    public static function isOnDplyDomain(string $domain): bool
    {
        $domain = strtolower(trim($domain));

        return str_starts_with($domain, 'on-dply.') || str_starts_with($domain, 'ondply.');
    }

    public static function zoneForHost(string $host): ?string
    {
        $host = strtolower(trim($host));
        if ($host === '') {
            return null;
        }

        foreach (self::all() as $domain) {
            if ($domain !== '' && str_ends_with($host, '.'.$domain)) {
                return $domain;
            }
        }

        return null;
    }

    /**
     * Resolve the Cloudflare zone apex for analytics collection on a hostname.
     */
    public static function analyticsZoneForHost(string $host): ?string
    {
        $host = strtolower(trim($host));
        if ($host === '') {
            return null;
        }

        if ($zone = self::zoneForHost($host)) {
            return $zone;
        }

        foreach (self::workerRouteZones() as $zone) {
            if (str_ends_with($host, '.'.$zone) || $host === $zone) {
                return $zone;
            }
        }

        foreach (self::sharedTestingPool() as $domain) {
            if ($domain !== '' && str_ends_with($host, '.'.$domain)) {
                return $domain;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public static function workerRouteZones(): array
    {
        $zones = [];

        foreach ((array) config('edge.cloudflare.worker_routes', []) as $route) {
            if (! is_string($route)) {
                continue;
            }

            if (preg_match('/\*\.([a-z0-9.-]+)\/\*/', strtolower($route), $matches) === 1) {
                $zones[] = $matches[1];
            }
        }

        $configured = strtolower(trim((string) config('edge.cloudflare.worker_zone_name')));
        if ($configured !== '') {
            $zones[] = $configured;
        }

        return array_values(array_unique(array_filter($zones)));
    }

    /**
     * @return list<string>
     */
    public static function sharedTestingPool(): array
    {
        return array_values(array_filter(array_map(
            static fn (string $value): string => strtolower(trim($value)),
            (array) config('services.digitalocean.testing_domains', []),
        )));
    }

    /**
     * Resolve the default Edge testing-domain list when DPLY_EDGE_TESTING_DOMAINS
     * is unset — prefer on-dply.* entries from the shared testing-domain pool.
     *
     * @return list<string>
     */
    public static function defaultFromPool(): array
    {
        $pool = array_values(array_filter(array_map(
            static fn (string $value): string => strtolower(trim($value)),
            (array) config('services.digitalocean.testing_domains', []),
        )));

        if ($pool === []) {
            $pool = array_values(array_filter(array_map(
                static fn (string $value): string => strtolower(trim($value)),
                (array) config('services.dply.testing_domains.digitalocean', []),
            )));
        }

        $onDply = array_values(array_filter(
            $pool,
            static fn (string $domain): bool => self::isOnDplyDomain($domain),
        ));

        if ($onDply !== []) {
            if (in_array(self::PREFERRED_APEX, $onDply, true)) {
                return array_values(array_unique(array_merge(
                    [self::PREFERRED_APEX],
                    array_filter($onDply, static fn (string $d): bool => $d !== self::PREFERRED_APEX),
                )));
            }

            if (in_array('on-dply.cloud', $onDply, true)) {
                return array_values(array_unique(array_merge(
                    ['on-dply.cloud'],
                    array_filter($onDply, static fn (string $d): bool => $d !== 'on-dply.cloud'),
                )));
            }

            return $onDply;
        }

        return [self::PREFERRED_APEX];
    }
}
