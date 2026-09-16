<?php

declare(strict_types=1);

namespace App\Modules\Edge\Support;

use App\Models\Site;

/**
 * Site load balancing config (`edgeMeta()['load_balancing']`): a Cloudflare
 * Load Balancer on the platform zone in front of the customer's origin
 * endpoints. The Worker proxies hybrid origin routes to its hostname.
 *
 *   enabled, steering, health{path, expected_codes, interval},
 *   endpoints[{name, address, port, weight, enabled, host_header}],
 *   cf{monitor_id, pool_id, lb_id, hostname}, status, error
 */
final class EdgeLoadBalancing
{
    public const MAX_ENDPOINTS = 20;

    /** Cloudflare pool origin_steering policies. */
    public const STEERING = ['random', 'hash', 'least_outstanding_requests', 'least_connections'];

    /**
     * @return array{enabled: bool, steering: string, health: array{path: string, expected_codes: string, interval: int}, endpoints: list<array{name: string, address: string, port: ?int, weight: float, enabled: bool, host_header: string}>, cf: array<string, string>, status: string, error: ?string}
     */
    public static function config(Site $site): array
    {
        $raw = is_array($site->edgeMeta()['load_balancing'] ?? null) ? $site->edgeMeta()['load_balancing'] : [];
        $health = is_array($raw['health'] ?? null) ? $raw['health'] : [];
        $steering = (string) ($raw['steering'] ?? 'random');

        $endpoints = [];
        foreach (is_array($raw['endpoints'] ?? null) ? $raw['endpoints'] : [] as $e) {
            if (! is_array($e) || trim((string) ($e['address'] ?? '')) === '') {
                continue;
            }
            $endpoints[] = [
                'name' => trim((string) ($e['name'] ?? '')) ?: 'origin-'.(count($endpoints) + 1),
                'address' => strtolower(trim((string) $e['address'])),
                'port' => isset($e['port']) && $e['port'] !== '' ? (int) $e['port'] : null,
                'weight' => max(0.0, min(1.0, (float) ($e['weight'] ?? 1))),
                'enabled' => (bool) ($e['enabled'] ?? true),
                'host_header' => strtolower(trim((string) ($e['host_header'] ?? ''))),
            ];
        }

        return [
            'enabled' => (bool) ($raw['enabled'] ?? false),
            'steering' => in_array($steering, self::STEERING, true) ? $steering : 'random',
            'health' => [
                'path' => (string) ($health['path'] ?? '/') ?: '/',
                'expected_codes' => (string) ($health['expected_codes'] ?? '2xx') ?: '2xx',
                'interval' => max(60, (int) ($health['interval'] ?? 60)),
            ],
            'endpoints' => array_slice($endpoints, 0, self::MAX_ENDPOINTS),
            'cf' => array_map('strval', array_filter(is_array($raw['cf'] ?? null) ? $raw['cf'] : [], 'is_scalar')),
            'status' => (string) ($raw['status'] ?? 'disabled'),
            'error' => isset($raw['error']) ? (string) $raw['error'] : null,
        ];
    }

    /**
     * Endpoints billed at edge_lb_endpoint_cents: every configured endpoint,
     * but only while the load balancer is live — the same condition that
     * routes traffic through it, so a failed provision never bills.
     */
    public static function billableEndpointCount(Site $site): int
    {
        return self::originUrl($site) !== null ? count(self::config($site)['endpoints']) : 0;
    }

    /** `https://<lb hostname>` once Cloudflare has the load balancer, else null. */
    public static function originUrl(Site $site): ?string
    {
        $cfg = self::config($site);

        return $cfg['enabled'] && $cfg['status'] === 'active' && ($cfg['cf']['hostname'] ?? '') !== ''
            ? 'https://'.$cfg['cf']['hostname']
            : null;
    }
}
