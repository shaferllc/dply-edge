<?php

declare(strict_types=1);

namespace App\Modules\Billing\Support;

use App\Modules\Edge\Services\EdgeAppDatabase;
use App\Modules\Edge\Support\EdgeContainerSettings;
use App\Modules\Edge\Support\EdgeSizeLadder;
use App\Modules\Edge\Support\EdgeValkey;

/**
 * The one place a provider cost becomes a customer price (ruling
 * r-2zxevg4sj675qn1m, docs/adr/pricing-model-2026-09.md):
 *
 *   price = cost × (1 + dply.edge.usage_billing.margin_percent / 100)
 *
 * Every cost class, invoice line, pricing page, billing page and docs table
 * goes through here, so changing the margin reprices all of them. Rates in
 * config are costs in millicents (1/1000 ¢). The margin itself is never shown.
 */
final class UsagePrice
{
    /** The margin, in percent. */
    public static function marginPercent(): float
    {
        return max(0.0, (float) config('dply.edge.usage_billing.margin_percent', 30));
    }

    /** Customer price for a cost, in the same unit. */
    public static function customer(float $cost): float
    {
        return $cost * (100 + self::marginPercent()) / 100;
    }

    /** Customer cents for a cost in millicents, rounded once to the nearest cent. */
    public static function cents(float $costMillicents): int
    {
        return (int) round(round(self::customer($costMillicents), 6) / 1000);
    }

    /** A configured cost rate (dply.edge.usage_billing.<key>), millicents. */
    public static function cost(string $key): float
    {
        return max(0.0, (float) config('dply.edge.usage_billing.'.$key, 0));
    }

    /** The customer rate for a configured key, millicents. */
    public static function rate(string $key): float
    {
        return self::customer(self::cost($key));
    }

    /**
     * Dollars for a customer amount in millicents, with enough decimals to
     * show a per-second rate: $5.40, $0.36, $0.018, $0.000024.
     */
    public static function dollars(float $millicents): string
    {
        $dollars = $millicents / 100_000;
        if ($dollars == 0.0) {
            return '$0';
        }
        // Three significant digits below $1, cents above.
        $decimals = $dollars >= 1 ? 2 : max(2, min(10, (int) ceil(-log10($dollars)) + 2));
        $formatted = number_format($dollars, $decimals, '.', ',');
        if ($decimals > 2) {
            // Drop trailing zeros past the cents: $0.90, $0.018.
            $formatted = preg_replace('/(\.\d\d\d*?)0+$/', '$1', $formatted) ?? $formatted;
        }

        return '$'.$formatted;
    }

    /**
     * Every per-unit rate, customer-priced: the pricing page, the billing
     * page and the docs (dply:billing:price-table) render this list.
     *
     * @return list<array{group: string, label: string, unit: string, millicents: float, price: string}>
     */
    public static function rates(): array
    {
        $rows = [
            // group, label, unit, config key, units per config unit
            ['Delivery', 'Requests', 'per million', 'requests_millicents_per_million', 1],
            ['Delivery', 'Bandwidth', 'per GB', 'egress_millicents_per_gb', 1],
            ['Delivery', 'Site storage', 'per GB-month', 'r2_storage_millicents_per_gb_month', 1],
            ['Delivery', 'Site storage writes', 'per million', 'r2_class_a_millicents_per_million', 1],
            ['Delivery', 'Site storage reads', 'per million', 'r2_class_b_millicents_per_million', 1],
            ['Builds', 'Build time', 'per minute, billed per second', 'build_millicents_per_minute', 1],
            ['Apps and workers', 'vCPU', 'per vCPU-second', 'container_vcpu_millicents_per_second', 1],
            ['Apps and workers', 'Memory', 'per GiB-second', 'container_memory_millicents_per_gib_second', 1],
            ['Apps and workers', 'Disk', 'per GB-second', 'container_disk_millicents_per_gb_second', 1],
            ['Apps and workers', 'Container bandwidth', 'per GB', 'container_egress_millicents_per_gb', 1],
            ['Databases', 'Compute', 'per compute-unit-second (1 vCPU, 4 GB)', 'database_compute_millicents_per_cu_second', 1],
            ['Databases', 'Storage', 'per GB-month', 'database_storage_millicents_per_gb_month', 1],
            ['SQL (D1)', 'Rows read', 'per million', 'd1_rows_read_millicents_per_million', 1],
            ['SQL (D1)', 'Rows written', 'per million', 'd1_rows_written_millicents_per_million', 1],
            ['SQL (D1)', 'Storage', 'per GB-month', 'd1_storage_millicents_per_gb_month', 1],
            ['Queues', 'Operations', 'per million', 'queue_operations_millicents_per_million', 1],
            ['Key-value', 'Reads', 'per million', 'kv_reads_millicents_per_million', 1],
            ['Key-value', 'Writes, deletes and lists', 'per million', 'kv_writes_millicents_per_million', 1],
            ['Key-value', 'Storage', 'per GB-month', 'kv_storage_millicents_per_gb_month', 1],
            ['Realtime', 'Connection-minutes', 'per million', 'realtime_connection_minute_millicents', 1_000_000],
            ['Realtime', 'Messages', 'per million', 'realtime_message_millicents_per_million', 1],
            ['Workers', 'CPU time', 'per million CPU-ms', 'workers_cpu_millicents_per_million_ms', 1],
            ['Durable Objects', 'Requests', 'per million', 'do_requests_millicents_per_million', 1],
            ['Durable Objects', 'Duration', 'per million GB-seconds', 'do_duration_millicents_per_million_gb_s', 1],
            ['Durable Objects', 'Rows read', 'per million', 'do_rows_read_millicents_per_million', 1],
            ['Durable Objects', 'Rows written', 'per million', 'do_rows_written_millicents_per_million', 1],
            ['Durable Objects', 'Storage', 'per GB-month', 'do_storage_millicents_per_gb_month', 1],
            ['Object storage', 'Storage', 'per GB-month', 'r2_bucket_storage_millicents_per_gb_month', 1],
            ['Object storage', 'Writes (Class A)', 'per million', 'r2_bucket_class_a_millicents_per_million', 1],
            ['Object storage', 'Reads (Class B)', 'per million', 'r2_bucket_class_b_millicents_per_million', 1],
            ['Images', 'Transformations', 'per 1,000', 'images_transformations_millicents_per_million', 0.001],
        ];

        return array_map(static function (array $row): array {
            $millicents = self::rate($row[3]) * $row[4];

            return ['group' => $row[0], 'label' => $row[1], 'unit' => $row[2], 'millicents' => $millicents, 'price' => self::dollars($millicents)];
        }, $rows);
    }

    /**
     * The size ladder with each product's per-second price while awake:
     * container apps (vCPU + memory + disk), dply databases (compute units)
     * and Valkey. Null where a product has no size on that rung.
     *
     * @return list<array{key: string, label: string, app: ?array{memory: string, second: string, hour: string}, database: array{memory: string, second: string, hour: string}, valkey: ?array{memory: string, second: string, hour: string, cap: string, sleeps: bool}}>
     */
    public static function sizes(): array
    {
        $row = static fn (string $memory, float $perSecond): array => [
            'memory' => $memory,
            'second' => self::dollars($perSecond),
            'hour' => self::dollars($perSecond * 3600),
        ];
        $ladder = [];
        foreach (EdgeSizeLadder::RUNGS as $key => $label) {
            $key = (string) $key; // '1', '2', '4' become int array keys
            $type = array_search($key, EdgeSizeLadder::CONTAINER_TYPES, true);
            $valkey = array_search($key, EdgeSizeLadder::VALKEY_CLASSES, true);
            $database = EdgeAppDatabase::POSTGRES_SIZES[$key]; // database sizes are the rungs
            $ladder[] = [
                'key' => $key,
                'label' => $label,
                'app' => is_string($type) ? $row(self::memoryLabel(EdgeContainerSettings::INSTANCE_TYPES[$type][1]), self::containerPerSecond(...EdgeContainerSettings::INSTANCE_TYPES[$type])) : null,
                'database' => $row($database['memory'], self::rate('database_compute_millicents_per_cu_second') * $database['cu']),
                'valkey' => is_string($valkey) ? $row(self::memoryLabel(EdgeValkey::CLASSES[$valkey]['memory_mb'] / 1024), self::valkeyPerSecond($valkey)) + [
                    'cap' => '$'.number_format(self::valkeyCapCents($valkey) / 100, 2),
                    'sleeps' => EdgeValkey::CLASSES[$valkey]['sleeps'],
                ] : null,
            ];
        }

        return $ladder;
    }

    /** Customer millicents for one container instance awake for a second, all vCPU busy. */
    public static function containerPerSecond(float $vcpu, float $memoryGib, float $diskGb): float
    {
        return $vcpu * self::rate('container_vcpu_millicents_per_second')
            + $memoryGib * self::rate('container_memory_millicents_per_gib_second')
            + $diskGb * self::rate('container_disk_millicents_per_gb_second');
    }

    /** Customer millicents for a Valkey class awake for a second. */
    public static function valkeyPerSecond(string $class): float
    {
        $spec = EdgeValkey::CLASSES[$class] ?? EdgeValkey::CLASSES[EdgeValkey::DEFAULT_CLASS];

        return self::customer($spec['cost_per_second'] * 100_000);
    }

    /** Customer cents a Valkey class costs at most in a month. */
    public static function valkeyCapCents(string $class): float
    {
        $spec = EdgeValkey::CLASSES[$class] ?? EdgeValkey::CLASSES[EdgeValkey::DEFAULT_CLASS];

        return self::customer((float) $spec['cap_cost_cents']);
    }

    private static function memoryLabel(float $gib): string
    {
        return $gib < 1 ? round($gib * 1024).' MB' : rtrim(rtrim(number_format($gib, 1), '0'), '.').' GB';
    }
}
