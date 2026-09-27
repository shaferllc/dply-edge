<?php

declare(strict_types=1);

namespace App\Modules\Billing\Support;

use App\Modules\Edge\Support\EdgeValkey;

/**
 * What dply's own infrastructure really costs per unit, from the estimates in
 * `dply.unit_costs`, next to the price we charge (UsagePrice). Nothing bills
 * from here: it is the check behind the fixed prices of the meters that run
 * on dply's hosts (databases, Valkey, builds) or have no provider unit price
 * (realtime). Printed by `dply:billing:unit-costs`; docs/pricing-review.md §9.
 *
 * Money is dollars. Markup is price ÷ cost − 1, the same sense as
 * margin_percent (0.30 = the 30% margin).
 */
final class UnitCosts
{
    private const MONTH_HOURS = 720;

    /**
     * @return list<array{meter: string, unit: string, cost: float, price: float, markup: float, ok: bool, note: string}>
     */
    public static function rows(): array
    {
        $c = (array) config('dply.unit_costs');
        $do = $c['digitalocean'];
        $rt = $c['realtime'];
        $b = $c['build'];

        $rows = [];
        $add = static function (string $meter, string $unit, float $cost, float $price, string $note = '') use (&$rows, $c): void {
            $markup = $cost > 0 ? $price / $cost - 1 : INF;
            $rows[] = ['meter' => $meter, 'unit' => $unit, 'cost' => $cost, 'price' => $price, 'markup' => $markup, 'ok' => $markup + 1 >= (float) $c['min_markup'] - 1e-9, 'note' => $note];
        };

        // Databases: an awake 1 CU reserves 4 GiB (memory is the binding
        // resource on the db pool's 4 GB nodes), plus a volume per GB.
        $add('Database compute', 'per CU-hour', self::gibMonth('db') * 4 / self::MONTH_HOURS,
            UsagePrice::rate('database_compute_millicents_per_cu_second') * 3600 / 100_000,
            'db pool '.$do['pools']['db']['size'].', '.round($do['packing']['db'] * 100).'% packed');
        $add('Database storage', 'per GB-month', $do['volume_per_gb_month'] + $do['backup_per_gb_month'],
            UsagePrice::rate('database_storage_millicents_per_gb_month') / 100_000, 'DO volume + backup copy');

        // Valkey: flex shares the cache pool; pro gets a whole node of its
        // pool the moment the first tenant needs one (min_nodes = 0).
        $add('Valkey (flex)', 'per GiB-hour', self::gibMonth('cache') / self::MONTH_HOURS,
            EdgeValkey::CLASSES['flex_1g']['price_per_second'] * 3600,
            'price: the 1 GB class per hour awake');
        foreach (EdgeValkey::CLASSES as $key => $class) {
            $gib = $class['memory_mb'] / 1024;
            if ($class['sleeps']) {
                $cost = $gib * self::gibMonth('cache');
                $note = 'share of the cache pool';
            } else {
                $pool = $class['memory_mb'] > 16_384 ? 'pro-64' : 'pro-16';
                $cost = $do['pools'][$pool]['monthly'] + 2 * $gib * $do['volume_per_gb_month'];
                $note = 'the whole '.$do['pools'][$pool]['size'].' node it brings up, + AOF volume';
            }
            if (in_array($key, EdgeValkey::NOT_OFFERED, true)) {
                $note .= ' (not offered)';
            }
            $add('Valkey '.$key, 'per month always on', $cost, $class['price_cap_cents'] / 100, $note);
        }

        $slotMinutes = $b['hosts'] * $b['concurrent_builds'] * self::MONTH_HOURS * 60 * $b['utilisation'];
        $add('Build time', 'per minute', $b['hosts'] * $b['monthly'] / max(1, $slotMinutes),
            UsagePrice::rate('build_millicents_per_minute') / 100_000,
            round($b['utilisation'] * 100).'% of '.($b['hosts'] * $b['concurrent_builds']).' build slots busy');

        // Realtime on Cloudflare list prices (the usage_billing costs).
        $request = (UsagePrice::cost('requests_millicents_per_million') + UsagePrice::cost('do_requests_millicents_per_million')) / 100_000;
        $publish = $request + $rt['publish_wall_ms'] / 1000 * $rt['do_memory_gb'] * UsagePrice::cost('do_duration_millicents_per_million_gb_s') / 100_000;
        $messagePrice = UsagePrice::rate('realtime_message_millicents_per_million') / 100_000;
        $add('Realtime messages', 'per million, nobody listening', $publish, $messagePrice, 'each publish: Worker + DO request + '.$rt['publish_wall_ms'].' ms');
        $add('Realtime messages', 'per million, '.$rt['deliveries_per_publish'].' delivery per publish', $publish / (1 + $rt['deliveries_per_publish']), $messagePrice, 'deliveries are free');
        $add('Realtime connection-minutes', 'per million', $request / max(0.1, $rt['avg_connection_minutes']),
            UsagePrice::rate('realtime_connection_minute_millicents') * 1_000_000 / 100_000,
            'upgrade over a '.$rt['avg_connection_minutes'].'-minute average connection');

        return $rows;
    }

    /**
     * dply's fixed monthly bills: minimum node pools, build and control-plane
     * hosts, Cloudflare base fees, services.
     *
     * @return array<string, float>
     */
    public static function fixedMonthly(): array
    {
        $c = (array) config('dply.unit_costs');
        $do = $c['digitalocean'];
        $lines = [];
        foreach ($do['pools'] as $name => $pool) {
            if ($pool['nodes'] > 0) {
                $lines["DOKS pool {$name} ({$pool['nodes']} × {$pool['size']})"] = (float) ($pool['nodes'] * $pool['monthly']);
            }
        }
        $lines['DOKS HA control plane'] = (float) $do['ha_control_plane'];
        $lines['DOKS load balancer (gateway)'] = (float) $do['load_balancer'];
        $lines['Container registry'] = (float) $do['registry'];
        $lines["Build host ({$c['build']['hosts']} × {$c['build']['size']})"] = (float) ($c['build']['hosts'] * $c['build']['monthly']);
        foreach ($c['control_plane'] as $name => $usd) {
            $lines["Control plane: {$name}"] = (float) $usd;
        }
        $lines['Cloudflare Workers Paid'] = (float) $c['cloudflare']['workers_paid'];
        $lines['Cloudflare Workers for Platforms'] = (float) $c['cloudflare']['workers_for_platforms'];
        foreach ($c['services'] as $name => $usd) {
            if ($usd > 0) {
                $lines["Service: {$name}"] = (float) $usd;
            }
        }

        return $lines;
    }

    /** Dollars per allocatable GiB-month of a pool, at its packing. */
    private static function gibMonth(string $pool): float
    {
        $do = (array) config('dply.unit_costs.digitalocean');

        return $do['pools'][$pool]['monthly'] / $do['pools'][$pool]['allocatable_gib'] / max(0.01, $do['packing'][$pool]);
    }
}
