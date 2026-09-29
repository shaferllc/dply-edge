<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Models\EdgePlatformUsage;
use App\Models\Organization;
use App\Modules\Billing\Support\UsagePrice;
use Carbon\CarbonInterface;

/**
 * Workers CPU, Workers Logs, Durable Objects, customer R2 buckets and Images on the bill. Called by
 * OrganizationBillingStateComputer. Reads edge_platform_usage
 * (EdgePlatformUsageCollector) by organization, so a deleted app's usage
 * still bills. Cost rates in dply.edge.usage_billing.{workers_cpu,workers_logs,do,r2_bucket,images}_*,
 * priced by UsagePrice.
 * Counts are summed over the period; storage is each resource's peak day,
 * charged as a whole GB-month (like EdgeKvCost).
 */
class EdgePlatformUsageCost
{
    private const COUNTS = ['cpu_ms', 'do_requests', 'do_gb_seconds', 'do_rows_read', 'do_rows_written', 'r2_class_a_ops', 'r2_class_b_ops', 'images_transformations', 'log_events'];

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
        $totals['log_events_billable'] = 0;
        foreach ($rows as $row) {
            foreach (array_merge(self::COUNTS, self::STORAGE) as $column) {
                $totals[$column] += $column === 'do_gb_seconds' ? (float) $row->{$column} : (int) $row->{$column};
            }
            // Rows are per app (resource): each gets its own included events.
            $totals['log_events_billable'] += self::billableLogEvents((int) $row->log_events);
        }

        return $totals + ['cents' => $this->cents($totals)];
    }

    /** One app's log events past its monthly allowance (dply.edge.usage_billing.workers_logs_included_per_app_month). */
    public static function billableLogEvents(int $events): int
    {
        return max(0, $events - (int) config('dply.edge.usage_billing.workers_logs_included_per_app_month', 5_000_000));
    }

    /**
     * @param  array<string, int|float>  $usage  one app's usage, or an organization's with log_events_billable
     */
    public function cents(array $usage): int
    {
        $rate = static fn (string $key): float => UsagePrice::cost($key);
        $perMillion = static fn (string $column, string $key): float => ($usage[$column] ?? 0) / 1_000_000 * $rate($key);
        $millicents = $perMillion('cpu_ms', 'workers_cpu_millicents_per_million_ms')
            + $perMillion('do_requests', 'do_requests_millicents_per_million')
            + $perMillion('do_gb_seconds', 'do_duration_millicents_per_million_gb_s')
            + $perMillion('do_rows_read', 'do_rows_read_millicents_per_million')
            + $perMillion('do_rows_written', 'do_rows_written_millicents_per_million')
            + $perMillion('r2_class_a_ops', 'r2_bucket_class_a_millicents_per_million')
            + $perMillion('r2_class_b_ops', 'r2_bucket_class_b_millicents_per_million')
            + $perMillion('images_transformations', 'images_transformations_millicents_per_million')
            + ($usage['log_events_billable'] ?? self::billableLogEvents((int) ($usage['log_events'] ?? 0))) / 1_000_000 * $rate('workers_logs_millicents_per_million')
            + ($usage['do_storage_bytes'] ?? 0) / 1024 ** 3 * $rate('do_storage_millicents_per_gb_month')
            + ($usage['r2_storage_bytes'] ?? 0) / 1024 ** 3 * $rate('r2_bucket_storage_millicents_per_gb_month');

        return UsagePrice::cents($millicents);
    }
}
