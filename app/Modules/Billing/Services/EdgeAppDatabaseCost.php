<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Models\EdgePostgresUsage;
use App\Models\Organization;
use Carbon\CarbonInterface;

/**
 * dply databases (Postgres, MySQL, MongoDB): compute-unit hours while awake
 * plus the disk's GB-months whether awake or asleep, from edge_postgres_usage
 * (written by EdgeValkeyUsageCollector). Each database is priced exactly and
 * the organization's total is rounded once to the nearest cent.
 *
 * Called from OrganizationBillingStateComputer, StarterUsageBudget and the
 * Resources tab (rates).
 */
class EdgeAppDatabaseCost
{
    public function hourly(float $cu): string
    {
        // Dollars: rates are in millicents (100,000 per dollar).
        return number_format($cu * $this->rate('postgres_compute_millicents_per_cu_hour') / 100_000, 3);
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
     * Customer-facing rates after markup. Hour is the smallest size (0.25 CU).
     *
     * @return array{hour: string, gigabyte: string}
     */
    public function presentation(): array
    {
        return [
            'hour' => $this->hourly(0.25),
            'gigabyte' => number_format($this->rate('postgres_storage_millicents_per_gb_month') / 100_000, 2),
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

    /** Exact (fractional) cents for one database's month so far. */
    public function databaseCents(int $computeUnitSeconds, int $storageByteHours, int $hoursInMonth): float
    {
        $millicents = $computeUnitSeconds / 3600 * $this->rate('postgres_compute_millicents_per_cu_hour')
            + $storageByteHours / (1024 ** 3) / $hoursInMonth * $this->rate('postgres_storage_millicents_per_gb_month');

        return $millicents / 1000;
    }

    /** A configured rate in millicents, after markup. */
    private function rate(string $key): float
    {
        return (float) config('dply.edge.usage_billing.'.$key, 0) * (100 + max(0, (int) config('dply.edge.usage_billing.markup_percent', 0))) / 100;
    }
}
