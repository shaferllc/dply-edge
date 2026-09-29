<?php

declare(strict_types=1);

namespace App\Modules\Billing\Support;

use App\Modules\Edge\Services\EdgeAppDatabase;
use App\Modules\Edge\Support\EdgeContainerSettings;
use App\Modules\Edge\Support\EdgeDplyDatabase;
use App\Modules\Edge\Support\EdgeSizeLadder;
use App\Modules\Edge\Support\EdgeValkey;

/**
 * The one place a provider cost becomes a customer price (ruling
 * r-2zxevg4sj675qn1m, docs/adr/pricing-model-2026-09.md):
 *
 *   price = cost × (1 + dply.edge.usage_billing.margin_percent / 100)
 *
 * except for the fixed-price meters (`fixed_price_meters`), whose config
 * value is the customer price and never moves with the margin.
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

    /**
     * A configured cost rate (dply.edge.usage_billing.<key>), millicents.
     *
     * A key in `fixed_price_meters` is configured as the customer price
     * instead (bandwidth: $0.06/GB whatever the margin, ruling
     * r-jnv0r3qf1xk49kmc). Its "cost" is backed out of that price at the
     * current margin, so every path that adds the margin back (cents(),
     * rate(), the cost classes) lands on exactly the configured price.
     */
    public static function cost(string $key): float
    {
        $value = max(0.0, (float) config('dply.edge.usage_billing.'.$key, 0));

        return in_array($key, (array) config('dply.edge.usage_billing.fixed_price_meters', []), true)
            ? $value * 100 / (100 + self::marginPercent())
            : $value;
    }

    /** The customer rate for a configured key, millicents. */
    public static function rate(string $key): float
    {
        return self::customer(self::cost($key));
    }

    /**
     * A dply database size's customer price per CU-second, millicents: its
     * own (database_compute_price_by_size, a fixed price) or the base one.
     */
    public static function databaseRate(string $size): float
    {
        $own = ((array) config('dply.edge.usage_billing.database_compute_price_by_size', []))[$size] ?? null;

        return $own === null ? self::rate('database_compute_millicents_per_cu_second') : max(0.0, (float) $own);
    }

    /**
     * Compute units a size bills as: its CU scaled by its own price over the
     * base one. The usage collector records awake seconds × this, so the one
     * base rate prices every size (1 CU at $0.18/CU-h bills as 1.5 CU).
     */
    public static function databaseBilledCu(string $size): float
    {
        $cu = (float) (EdgeAppDatabase::POSTGRES_SIZES[$size]['cu'] ?? 0.25);
        $base = self::rate('database_compute_millicents_per_cu_second');

        return $base > 0 ? $cu * self::databaseRate($size) / $base : $cu;
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
            ['Apps and workers', 'Outbound traffic', 'per GB (calls out, not replies)', 'container_outbound_millicents_per_gb', 1],
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
            ['Workers', 'Log events', 'per million', 'workers_logs_millicents_per_million', 1],
            ['Durable Objects', 'Requests', 'per million', 'do_requests_millicents_per_million', 1],
            ['Durable Objects', 'Duration', 'per million GB-seconds', 'do_duration_millicents_per_million_gb_s', 1],
            ['Durable Objects', 'Rows read', 'per million', 'do_rows_read_millicents_per_million', 1],
            ['Durable Objects', 'Rows written', 'per million', 'do_rows_written_millicents_per_million', 1],
            ['Durable Objects', 'Storage', 'per GB-month', 'do_storage_millicents_per_gb_month', 1],
            ['Object storage', 'Storage', 'per GB-month', 'r2_bucket_storage_millicents_per_gb_month', 1],
            ['Object storage', 'Writes (Class A)', 'per million', 'r2_bucket_class_a_millicents_per_million', 1],
            ['Object storage', 'Reads (Class B)', 'per million', 'r2_bucket_class_b_millicents_per_million', 1],
            ['Images', 'Transformations', 'per 1,000', 'images_transformations_millicents_per_million', 0.001],
            ['AI', 'Neurons', 'per 1,000', 'ai_neurons_millicents_per_thousand', 1],
            ['Browser rendering', 'Browser time', 'per browser-hour', 'browser_millicents_per_hour', 1],
            ['Vector search', 'Queried dimensions', 'per million', 'vector_queried_millicents_per_million_dims', 1],
            ['Vector search', 'Stored dimensions', 'per 100 million, per month', 'vector_stored_millicents_per_hundred_million_dims', 1],
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
     * @return list<array{key: string, label: string, app: ?array{memory: string, second: string, hour: string, typical: string, cap: string}, database: ?array{memory: string, second: string, hour: string}, valkey: ?array{memory: string, second: string, hour: string, cap: string, sleeps: bool}}>
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
                'app' => is_string($type) ? $row(self::memoryLabel(EdgeContainerSettings::INSTANCE_TYPES[$type][1]), self::containerPerSecond(...EdgeContainerSettings::INSTANCE_TYPES[$type])) + array_map(
                    static fn (float $millicents): string => '$'.number_format($millicents / 100_000, 2),
                    self::containerMonthly(...EdgeContainerSettings::INSTANCE_TYPES[$type]),
                ) : null,
                // Only sizes the database nodes can schedule are sold (EdgeDplyDatabase::offeredSizes()).
                'database' => in_array($key, EdgeDplyDatabase::offeredSizes(), true)
                    ? $row($database['memory'], self::rate('database_compute_millicents_per_cu_second') * self::databaseBilledCu($key))
                    : null,
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

    /** vCPU share a typical web app keeps busy. Cloudflare bills vCPU on active use. */
    public const TYPICAL_CPU = 0.25;

    /** A month, as EdgeAppDatabaseCost::monthly() counts it. */
    public const MONTH_HOURS = 720;

    /**
     * Hours of a size's 100%-CPU price that one instance is billed at most
     * in a month. Break-even is MONTH_HOURS / (1 + margin): below it, an
     * always-on instance at 100% CPU would bill under cost. So whatever the
     * config says, the cap stays at least 5% over that cost.
     */
    public static function containerCapHours(): float
    {
        $floor = self::MONTH_HOURS * 1.05 / (1 + self::marginPercent() / 100);

        return min((float) self::MONTH_HOURS, max($floor, (float) config('dply.edge.usage_billing.container_monthly_cap_hours', 600)));
    }

    /** Customer millicents one instance of a size is billed at most in a month. */
    public static function containerCapMillicents(float $vcpu, float $memoryGib, float $diskGb): float
    {
        return self::containerCapHours() * 3600 * self::containerPerSecond($vcpu, $memoryGib, $diskGb);
    }

    /**
     * One instance always on for a month, in millicents: `typical` at
     * TYPICAL_CPU busy, `cap` the most it can be billed.
     *
     * @return array{typical: float, cap: float}
     */
    public static function containerMonthly(float $vcpu, float $memoryGib, float $diskGb): array
    {
        $cap = self::containerCapMillicents($vcpu, $memoryGib, $diskGb);

        return [
            'typical' => min($cap, self::MONTH_HOURS * 3600 * self::containerPerSecond($vcpu * self::TYPICAL_CPU, $memoryGib, $diskGb)),
            'cap' => $cap,
        ];
    }

    /**
     * Customer millicents for a Valkey class awake for a second. Valkey is
     * fixed-price like the meters in `fixed_price_meters`: EdgeValkey::CLASSES
     * holds customer prices, so the margin is not added.
     */
    public static function valkeyPerSecond(string $class): float
    {
        $spec = EdgeValkey::CLASSES[$class] ?? EdgeValkey::CLASSES[EdgeValkey::DEFAULT_CLASS];

        return $spec['price_per_second'] * 100_000;
    }

    /** Customer cents a Valkey class costs at most in a month. */
    public static function valkeyCapCents(string $class): float
    {
        $spec = EdgeValkey::CLASSES[$class] ?? EdgeValkey::CLASSES[EdgeValkey::DEFAULT_CLASS];

        return (float) $spec['price_cap_cents'];
    }

    private static function memoryLabel(float $gib): string
    {
        return $gib < 1 ? round($gib * 1024).' MB' : rtrim(rtrim(number_format($gib, 1), '0'), '.').' GB';
    }
}
