<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Models\EdgePlatformUsage;
use App\Models\Organization;
use Carbon\CarbonInterface;

/**
 * Workers CPU, Durable Objects, customer R2 buckets and Images on the bill. Called by
 * OrganizationBillingStateComputer. Reads edge_platform_usage
 * (EdgePlatformUsageCollector) by organization, so a deleted app's usage
 * still bills. Rates in dply.edge.usage_billing.{workers_cpu,do,r2_bucket,images}_*.
 * Counts are summed over the period; storage is each resource's peak day,
 * charged as a whole GB-month (like EdgeKvCost).
 */
class EdgePlatformUsageCost
{
    private const COUNTS = ['cpu_ms', 'do_requests', 'do_gb_seconds', 'do_rows_read', 'do_rows_written', 'r2_class_a_ops', 'r2_class_b_ops', 'images_transformations'];

    private const STORAGE = ['do_storage_bytes', 'r2_storage_bytes'];

    /**
     * @return array<string, int|float>&array{cents: int}
     */
    public function forOrganization(Organization $organization, CarbonInterface $from, CarbonInterface $to): array
    {
        $select = array_map(static fn (string $c): string => "COALESCE(SUM({$c}), 0) AS {$c}", self::COUNTS);
        $select = array_merge($select, array_map(static fn (string $c): string => "COALESCE(MAX({$c}), 0) AS {$c}", self::STORAGE));
        $rows = EdgePlatformUsage::query()
            ->where('organization_id', $organization->id)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('resource')
            ->selectRaw(implode(', ', $select))
            ->toBase()
            ->get();

        $totals = array_fill_keys([...self::COUNTS, ...self::STORAGE], 0);
        foreach ($rows as $row) {
            foreach ($totals as $column => $value) {
                $totals[$column] = $value + ($column === 'do_gb_seconds' ? (float) $row->{$column} : (int) $row->{$column});
            }
        }

        return $totals + ['cents' => $this->cents($totals)];
    }

    /**
     * @param  array<string, int|float>  $usage
     */
    public function cents(array $usage): int
    {
        $rate = static fn (string $key): float => (float) config('dply.edge.usage_billing.'.$key, 0);
        $perMillion = static fn (string $column, string $key): float => ($usage[$column] ?? 0) / 1_000_000 * $rate($key);
        $millicents = $perMillion('cpu_ms', 'workers_cpu_millicents_per_million_ms')
            + $perMillion('do_requests', 'do_requests_millicents_per_million')
            + $perMillion('do_gb_seconds', 'do_duration_millicents_per_million_gb_s')
            + $perMillion('do_rows_read', 'do_rows_read_millicents_per_million')
            + $perMillion('do_rows_written', 'do_rows_written_millicents_per_million')
            + $perMillion('r2_class_a_ops', 'r2_bucket_class_a_millicents_per_million')
            + $perMillion('r2_class_b_ops', 'r2_bucket_class_b_millicents_per_million')
            + $perMillion('images_transformations', 'images_transformations_millicents_per_million')
            + ($usage['do_storage_bytes'] ?? 0) / 1024 ** 3 * $rate('do_storage_millicents_per_gb_month')
            + ($usage['r2_storage_bytes'] ?? 0) / 1024 ** 3 * $rate('r2_bucket_storage_millicents_per_gb_month');

        return (int) ceil($millicents / 1000);
    }
}
