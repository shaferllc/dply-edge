<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Models\OrganizationBillingSnapshot;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

final class BillingForecastCalculator
{
    /**
     * @return array<string, int|null|string>
     */
    public function calculate(
        DesiredBillingState $state,
        ?string $subscriptionInterval = null,
        ?OrganizationBillingSnapshot $snapshotThirtyDaysAgo = null,
        ?CarbonInterface $asOf = null,
    ): array {
        $asOfDate = $asOf ?? now();
        $monthlyTotalCents = $state->monthlyTotalCents;
        // Every usage kind (delivery, builds, compute, data), not just delivery.
        $edgeUsageCents = $state->usageLineCents();
        $fixedCents = max(0, $monthlyTotalCents - $edgeUsageCents);

        // Usage so far this billing period (the Stripe period, else the
        // calendar month), run out to the period's end.
        $periodStart = isset($state->edgeUsageEstimate['period_start'])
            ? Carbon::parse((string) $state->edgeUsageEstimate['period_start'])
            : Carbon::instance($asOfDate)->startOfMonth();
        $periodDays = max(1, (int) $periodStart->diffInDays($periodStart->copy()->addMonthNoOverflow()));
        $daysElapsed = (int) min($periodDays, max(1, (int) $periodStart->copy()->startOfDay()->diffInDays(Carbon::instance($asOfDate)->startOfDay()) + 1));
        $projectedEdgeUsageCents = (int) round(($edgeUsageCents / $daysElapsed) * $periodDays);
        $projectedMonthEndCents = $fixedCents + $projectedEdgeUsageCents;

        $normalizedMrrCents = $this->normalizedMrr($monthlyTotalCents, $subscriptionInterval);
        $arrCents = $normalizedMrrCents * 12;

        $baselineCents = $snapshotThirtyDaysAgo?->monthly_total_cents;
        $deltaVsThirtyDaysCents = is_int($baselineCents)
            ? $monthlyTotalCents - $baselineCents
            : null;

        return [
            'subscription_interval' => $subscriptionInterval,
            'mrr_cents' => $normalizedMrrCents,
            'arr_cents' => $arrCents,
            'fixed_cents' => $fixedCents,
            'edge_usage_mtd_cents' => $edgeUsageCents,
            'projected_edge_usage_cents' => $projectedEdgeUsageCents,
            'projected_month_end_cents' => $projectedMonthEndCents,
            'period_start' => $periodStart->toDateString(),
            'period_end' => $periodStart->copy()->addMonthNoOverflow()->toDateString(),
            'thirty_day_baseline_cents' => $baselineCents,
            'delta_vs_thirty_days_cents' => $deltaVsThirtyDaysCents,
        ];
    }

    private function normalizedMrr(int $monthlyTotalCents, ?string $subscriptionInterval): int
    {
        if ($subscriptionInterval !== 'year') {
            return $monthlyTotalCents;
        }

        $annualDiscountPct = (int) config('subscription.standard.annual_discount_pct', 20);
        $annualCents = (int) round($monthlyTotalCents * 12 * (100 - $annualDiscountPct) / 100);

        return (int) round($annualCents / 12);
    }
}
