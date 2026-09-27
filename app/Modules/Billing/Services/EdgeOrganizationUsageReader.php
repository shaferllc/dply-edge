<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Models\EdgeUsageSnapshot;
use App\Models\Organization;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Rolls up {@see EdgeUsageSnapshot} rows for an organization inside a billing
 * window (typically the current calendar month to date).
 */
class EdgeOrganizationUsageReader
{
    /**
     * Request-scoped totals keyed by org + period — billing state / analytics
     * can ask more than once per render.
     *
     * @var array<string, EdgeUsageTotals>
     */
    private static array $totalsMemo = [];

    public function totalsForOrganization(
        Organization $organization,
        CarbonInterface $periodStart,
        CarbonInterface $periodEnd,
    ): EdgeUsageTotals {
        $key = (string) $organization->id
            .'|'.$periodStart->toDateString()
            .'|'.$periodEnd->toDateString();

        if (isset(self::$totalsMemo[$key])) {
            return self::$totalsMemo[$key];
        }

        $row = EdgeUsageSnapshot::query()
            ->where('organization_id', $organization->id)
            ->where('period_start', '>=', $periodStart->toDateString())
            ->where('period_start', '<=', $periodEnd->toDateString())
            ->select([
                DB::raw('COALESCE(SUM(requests), 0) as requests'),
                DB::raw('COALESCE(SUM(bytes_egress), 0) as bytes_egress'),
                DB::raw('COALESCE(MAX(r2_storage_bytes), 0) as r2_storage_bytes'),
                DB::raw('COALESCE(SUM(r2_class_a_ops), 0) as r2_class_a_ops'),
                DB::raw('COALESCE(SUM(r2_class_b_ops), 0) as r2_class_b_ops'),
            ])
            ->first();

        if ($row === null) {
            return self::$totalsMemo[$key] = new EdgeUsageTotals;
        }

        return self::$totalsMemo[$key] = new EdgeUsageTotals(
            requests: (int) $row->requests,
            bytesEgress: (int) $row->bytes_egress,
            r2StorageBytes: (int) $row->r2_storage_bytes,
            r2ClassAOps: (int) $row->r2_class_a_ops,
            r2ClassBOps: (int) $row->r2_class_b_ops,
        );
    }

    public static function flushMemo(?string $organizationId = null): void
    {
        if ($organizationId === null) {
            self::$totalsMemo = [];

            return;
        }

        foreach (array_keys(self::$totalsMemo) as $key) {
            if (str_starts_with($key, $organizationId.'|')) {
                unset(self::$totalsMemo[$key]);
            }
        }
    }

    /**
     * "This period" for billing: the subscription's current Stripe period
     * (start day → today), so what the billing page shows is what the next
     * invoice charges. Calendar month when there is no subscription or its
     * period is not known yet (card-less trial, comped, no plan).
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function currentWindow(Organization $organization): array
    {
        $subscription = $organization->subscription('default');
        $start = $subscription?->valid() ? $subscription->getAttribute('current_period_start') : null;
        $end = $subscription?->getAttribute('current_period_end');
        // Unknown, or stale (a renewal webhook was missed): calendar month.
        if (! $start instanceof CarbonInterface || $start->isFuture() || ! $end instanceof CarbonInterface || $end->isPast()) {
            return $this->currentMonthWindow();
        }

        return [Carbon::instance($start)->startOfDay(), now()->endOfDay()];
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    public function currentMonthWindow(): array
    {
        $start = now()->startOfMonth();
        $end = now()->endOfDay();

        return [$start, $end];
    }
}
