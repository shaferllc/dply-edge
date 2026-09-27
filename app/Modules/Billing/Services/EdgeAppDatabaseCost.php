<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Models\EdgePostgresUsage;
use App\Models\Organization;
use App\Modules\Billing\Support\UsagePrice;
use Carbon\CarbonInterface;

/**
 * dply databases (Postgres, MySQL, MongoDB): compute-unit seconds while awake
 * plus the disk's GB-months (prorated by the second held) whether awake or
 * asleep, from edge_postgres_usage (written by EdgeValkeyUsageCollector).
 * Cost rates in dply.edge.usage_billing.database_*, priced by UsagePrice.
 * The organization's total is rounded once to the nearest cent.
 *
 * Called from OrganizationBillingStateComputer, StarterUsageBudget and the
 * Resources tab (rates).
 */
class EdgeAppDatabaseCost
{
    /** Customer dollars per second awake for a size. */
    public function perSecond(float $cu): string
    {
        return UsagePrice::dollars(UsagePrice::rate('database_compute_millicents_per_cu_second') * $cu);
    }

    public function hourly(float $cu): string
    {
        // Dollars: rates are in millicents (100,000 per dollar).
        return number_format($cu * UsagePrice::rate('database_compute_millicents_per_cu_second') * 3600 / 100_000, 3);
    }

    public function daily(float $cu): string
    {
        return number_format((float) $this->hourly($cu) * 24, 2);
    }

    public function monthly(float $cu): string
    {
        return number_format((float) $this->hourly($cu) * 720, 2);
    }

    /**
     * Customer-facing rates. Hour is the smallest size (0.25 CU).
     *
     * @return array{hour: string, gigabyte: string}
     */
    public function presentation(): array
    {
        return [
            'hour' => $this->hourly(0.25),
            'gigabyte' => number_format(UsagePrice::rate('database_storage_millicents_per_gb_month') / 100_000, 2),
        ];
    }

    /**
     * @return array{databases: int, cents: int}
     */
    public function forOrganization(Organization $organization, ?CarbonInterface $from = null, ?CarbonInterface $to = null): array
    {
        $from ??= now()->startOfMonth();
        $to ??= now()->endOfMonth();
        $rows = EdgePostgresUsage::query()
            ->where('organization_id', $organization->id)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('project_id, SUM(compute_unit_seconds) AS compute, SUM(storage_byte_hours) AS storage')
            ->groupBy('project_id')
            ->get();

        $hours = max(1, $from->daysInMonth * 24);
        $cents = 0.0;
        foreach ($rows as $row) {
            $cents += $this->databaseCents((int) $row->compute, (int) $row->storage, $hours);
        }

        return ['databases' => $rows->count(), 'cents' => (int) round($cents)];
    }

    /** Exact (fractional) customer cents for one database's month so far. */
    public function databaseCents(int $computeUnitSeconds, int $storageByteHours, int $hoursInMonth): float
    {
        $millicents = $computeUnitSeconds * UsagePrice::cost('database_compute_millicents_per_cu_second')
            + $storageByteHours / (1024 ** 3) / $hoursInMonth * UsagePrice::cost('database_storage_millicents_per_gb_month');

        return UsagePrice::customer($millicents) / 1000;
    }
}
