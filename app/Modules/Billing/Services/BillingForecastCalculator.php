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
        // Usage so far at customer price (before the credit), and what the
        // plan's included credit takes off it.
        $usageCents = $state->usageLineCents();
        $fixedCents = $state->managedSubtotalCents();

        // Usage so far this billing period (the Stripe period, else the
        // calendar month), run out to the period's end.
        $periodStart = isset($state->edgeUsageEstimate['period_start'])
            ? Carbon::parse((string) $state->edgeUsageEstimate['period_start'])
            : Carbon::instance($asOfDate)->startOfMonth();
        $periodDays = max(1, (int) $periodStart->diffInDays($periodStart->copy()->addMonthNoOverflow()));
        $daysElapsed = (int) min($periodDays, max(1, (int) $periodStart->copy()->startOfDay()->diffInDays(Carbon::instance($asOfDate)->startOfDay()) + 1));
        $projectedUsageCents = (int) round(($usageCents / $daysElapsed) * $periodDays);
        $projectedCreditCents = min($state->usageCreditCents, $projectedUsageCents);
        $projectedMonthEndCents = $fixedCents + $projectedUsageCents - $projectedCreditCents;

        $baselineCents = $snapshotThirtyDaysAgo?->monthly_total_cents;
        $deltaVsThirtyDaysCents = is_int($baselineCents)
            ? $monthlyTotalCents - $baselineCents
            : null;

        return [
            'subscription_interval' => $subscriptionInterval,
            'mrr_cents' => $monthlyTotalCents,
            'arr_cents' => $monthlyTotalCents * 12,
            'fixed_cents' => $fixedCents,
            // "Usage this period $X · included credit $Y · estimated charge $Z"
            'usage_cents' => $usageCents,
            'credit_cents' => $state->creditAppliedCents(),
            'usage_credit_cents' => $state->usageCreditCents,
            'estimated_charge_cents' => $monthlyTotalCents,
            'edge_usage_mtd_cents' => $usageCents,
            'projected_edge_usage_cents' => $projectedUsageCents,
            'projected_credit_cents' => $projectedCreditCents,
            'projected_month_end_cents' => $projectedMonthEndCents,
            'period_start' => $periodStart->toDateString(),
            'period_end' => $periodStart->copy()->addMonthNoOverflow()->toDateString(),
            'thirty_day_baseline_cents' => $baselineCents,
            'delta_vs_thirty_days_cents' => $deltaVsThirtyDaysCents,
        ];
    }
}
