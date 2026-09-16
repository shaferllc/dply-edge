<?php

declare(strict_types=1);

namespace App\Modules\Edge\Services;

use App\Models\Site;
use App\Modules\Edge\Support\EdgeLoadBalancing;
use App\Modules\Providers\Cloudflare\EdgeCloudflareClient;
use RuntimeException;
use Throwable;

/**
 * Converges a site's Cloudflare Load Balancing resources with its
 * `load_balancing` meta: one monitor + one pool (account level) and one load
 * balancer at `lb-<site>.<worker zone>` on dply's platform zone. The Worker
 * reaches that hostname with a same-zone fetch, which skips Worker routes and
 * lands on the load balancer.
 *
 * Every created id is saved immediately, so a failure halfway leaves ids a
 * retry (or teardown) can reuse instead of orphaned Cloudflare resources.
 */
class EdgeLoadBalancerProvisioner
{
    public function __construct(private ?EdgeCloudflareClient $client = null) {}

    public function sync(Site $site): void
    {
        $cfg = EdgeLoadBalancing::config($site);
        if (! $cfg['enabled'] || $cfg['endpoints'] === []) {
            $this->teardown($site);

            return;
        }

        $cf = $cfg['cf'];

        try {
            $client = $this->client();
            $zoneName = strtolower(trim((string) config('edge.cloudflare.worker_zone_name')));
            $zoneId = $zoneName !== '' ? $client->activeZoneId($zoneName) : null;
            if ($zoneId === null) {
                throw new RuntimeException('Edge platform zone (DPLY_EDGE_CF_ZONE_NAME) is not an active Cloudflare zone.');
            }
            $this->store($site, ['status' => 'provisioning', 'error' => null]);

            $monitor = $client->upsertLbMonitor($cf['monitor_id'] ?? null, [
                'type' => 'https',
                'method' => 'GET',
                'path' => $cfg['health']['path'],
                'expected_codes' => $cfg['health']['expected_codes'],
                'interval' => $cfg['health']['interval'],
                'timeout' => 5,
                'retries' => 2,
                'follow_redirects' => true,
                'allow_insecure' => false,
                'description' => 'dply site '.$site->id,
            ]);
            $cf['monitor_id'] = (string) $monitor['id'];
            $this->store($site, ['cf' => $cf]);

            $pool = $client->upsertLbPool($cf['pool_id'] ?? null, [
                'name' => 'dply-site-'.$site->id,
                'description' => (string) $site->name,
                'enabled' => true,
                'minimum_origins' => 1,
                'monitor' => $cf['monitor_id'],
                'origin_steering' => ['policy' => $cfg['steering']],
                'origins' => array_map(static fn (array $e): array => array_filter([
                    'name' => $e['name'],
                    'address' => $e['address'],
                    'port' => $e['port'],
                    'weight' => $e['weight'],
                    'enabled' => $e['enabled'],
                    'header' => $e['host_header'] !== '' ? ['Host' => [$e['host_header']]] : null,
                ], static fn ($v) => $v !== null), $cfg['endpoints']),
            ]);
            $cf['pool_id'] = (string) $pool['id'];
            $this->store($site, ['cf' => $cf]);

            $hostname = $cf['hostname'] ?? 'lb-'.strtolower((string) $site->id).'.'.$zoneName;
            $lb = $client->upsertLoadBalancer($zoneId, $cf['lb_id'] ?? null, [
                'name' => $hostname,
                'description' => 'dply site '.$site->id,
                'default_pools' => [$cf['pool_id']],
                'fallback_pool' => $cf['pool_id'],
                'proxied' => true,
                'steering_policy' => 'off',
                'session_affinity' => 'none',
            ]);
            $cf['lb_id'] = (string) $lb['id'];
            $cf['hostname'] = $hostname;
            $cf['zone_id'] = $zoneId;
            $this->store($site, ['cf' => $cf, 'status' => 'active', 'error' => null]);
        } catch (Throwable $e) {
            $this->store($site, ['status' => 'error', 'error' => $e->getMessage()]);

            throw $e;
        }
    }

    /** Delete the load balancer, then pool, then monitor (each is referenced by the one before). */
    public function teardown(Site $site): void
    {
        $cf = EdgeLoadBalancing::config($site)['cf'];
        if ($cf === []) {
            $this->store($site, ['status' => 'disabled', 'error' => null]);

            return;
        }

        try {
            $client = $this->client();
            if (isset($cf['lb_id'], $cf['zone_id'])) {
                $client->deleteLoadBalancer($cf['zone_id'], $cf['lb_id']);
                unset($cf['lb_id'], $cf['zone_id'], $cf['hostname']);
            }
            if (isset($cf['pool_id'])) {
                $client->deleteLbPool($cf['pool_id']);
                unset($cf['pool_id']);
            }
            if (isset($cf['monitor_id'])) {
                $client->deleteLbMonitor($cf['monitor_id']);
                unset($cf['monitor_id']);
            }
            $this->store($site, ['cf' => $cf, 'status' => 'disabled', 'error' => null]);
        } catch (Throwable $e) {
            $this->store($site, ['cf' => $cf, 'status' => 'error', 'error' => $e->getMessage()]);

            throw $e;
        }
    }

    /**
     * Endpoint health keyed by address: healthy only when every reporting
     * Cloudflare PoP sees it healthy.
     *
     * @return array<string, array{healthy: bool, pops: int, failure_reason: ?string}>
     */
    public function health(Site $site): array
    {
        $poolId = EdgeLoadBalancing::config($site)['cf']['pool_id'] ?? null;
        if ($poolId === null) {
            return [];
        }

        $out = [];
        foreach ((array) ($this->client()->lbPoolHealth($poolId)['pop_health'] ?? []) as $pop) {
            foreach ((array) ($pop['origins'] ?? []) as $origins) {
                foreach ((array) $origins as $address => $state) {
                    $row = $out[$address] ?? ['healthy' => true, 'pops' => 0, 'failure_reason' => null];
                    $row['pops']++;
                    if (! ($state['healthy'] ?? false)) {
                        $row['healthy'] = false;
                        $row['failure_reason'] ??= isset($state['failure_reason']) ? (string) $state['failure_reason'] : null;
                    }
                    $out[(string) $address] = $row;
                }
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function store(Site $site, array $values): void
    {
        // Re-read first: a dashboard save while this runs must keep its
        // endpoints (it queues its own sync).
        $site->refresh();
        $current = is_array($site->edgeMeta()['load_balancing'] ?? null) ? $site->edgeMeta()['load_balancing'] : [];
        $site->mergeEdgeMeta(['load_balancing' => array_merge($current, $values)]);
        $site->save();
    }

    private function client(): EdgeCloudflareClient
    {
        return $this->client ??= EdgeCloudflareClient::fromConfig();
    }
}
