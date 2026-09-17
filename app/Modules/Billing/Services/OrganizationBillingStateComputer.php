<?php

namespace App\Modules\Billing\Services;

use App\Models\Organization;
use App\Models\Site;
use App\Modules\Edge\Support\EdgeBuildMinutes;
use App\Modules\Edge\Support\EdgeLoadBalancing;

/**
 * Builds a {@see DesiredBillingState} for an organization: its tier (from the
 * subscription, or the cheapest fit for a pre-tier per-site one), live Edge
 * sites (edge_active, `edge_backend = dply_edge`, not previews), seats, build
 * minutes, load balancer endpoints and metered delivery usage.
 *
 * Age filter: units younger than min_billable_age_days are excluded.
 */
class OrganizationBillingStateComputer
{
    public function __construct(
        private EdgeOrganizationUsageReader $usageReader,
        private EdgeUsageCostCalculator $usageCostCalculator,
    ) {}

    /**
     * Full {@see DesiredBillingState} per org for the request. Livewire billing
     * blades and analytics all call {@see compute()} — without this each access re-runs
     * site scans + usage SUMs (Debugbar duplicate-query noise).
     *
     * @var array<string, DesiredBillingState>
     */
    private static array $desiredStateMemo = [];

    /**
     * Drop the request-scoped desired-state memo. Call from TestCase tearDown
     * and after fleet mutations that must be visible to a later compute() in
     * the same process.
     */
    public static function flushMemo(?string $organizationId = null): void
    {
        if ($organizationId === null) {
            self::$desiredStateMemo = [];

            return;
        }

        unset(self::$desiredStateMemo[$organizationId]);
    }

    public function compute(Organization $organization): DesiredBillingState
    {
        $key = (string) $organization->id;
        if (isset(self::$desiredStateMemo[$key])) {
            return self::$desiredStateMemo[$key];
        }

        return self::$desiredStateMemo[$key] = $this->computeFresh($organization);
    }

    /**
     * Does this org's fleet bill to nothing this cycle? Same answer as
     * {@see compute()}->isFree().
     */
    public function isFree(Organization $organization): bool
    {
        return $this->compute($organization)->isFree();
    }

    /**
     * What the org would owe on a given tier — the checkout / plan-change
     * preview and price list. Not memoized.
     */
    public function computeForTier(Organization $organization, string $tierKey): DesiredBillingState
    {
        return $this->computeFresh($organization, $tierKey);
    }

    private function computeFresh(Organization $organization, ?string $forceTier = null): DesiredBillingState
    {
        $minAgeDays = max(0, (int) config('subscription.standard.min_billable_age_days', 1));
        $ageCutoff = now()->subDays($minAgeDays);

        $edgeCount = 0;
        $edgeSsrCount = 0;
        $edgeLbEndpointCount = 0;

        $organization->sites()
            ->where('created_at', '<=', $ageCutoff)
            ->get()
            ->each(function (Site $site) use (&$edgeCount, &$edgeSsrCount, &$edgeLbEndpointCount): void {
                if (
                    $site->status !== Site::STATUS_EDGE_ACTIVE
                    || $site->edge_backend !== 'dply_edge'
                    || $site->isEdgePreview()
                ) {
                    return;
                }

                $edgeCount++;
                $runtimeMode = strtolower((string) ($site->edgeMeta()['runtime_mode'] ?? 'static'));
                if ($runtimeMode === 'ssr') {
                    $edgeSsrCount++;
                }
                $edgeLbEndpointCount += EdgeLoadBalancing::billableEndpointCount($site);
            });

        [$usagePeriodStart, $usagePeriodEnd] = $this->usageReader->currentMonthWindow();
        $usageTotals = $this->usageReader->totalsForOrganization($organization, $usagePeriodStart, $usagePeriodEnd);
        $edgeUsageEstimate = $this->usageCostCalculator->estimate($usageTotals, $edgeCount);
        $edgeUsageEstimate = array_merge($edgeUsageEstimate, [
            'period_start' => $usagePeriodStart->toDateString(),
            'period_end' => $usagePeriodEnd->toDateString(),
            'requests' => $usageTotals->requests,
            'bytes_egress' => $usageTotals->bytesEgress,
            'r2_storage_bytes' => $usageTotals->r2StorageBytes,
        ]);
        $buildMinutes = EdgeBuildMinutes::usedThisMonth($organization);

        return DesiredBillingState::fromPlanAndUsage(
            plan: ['key' => $tierKey, 'label' => (string) $tier['label'], 'price_cents' => (int) $tier['price_cents']],
            edgeCount: $edgeCount,
            edgeUnitCents: $billable ? (int) config('subscription.standard.edge_cents', 200) : 0,
            edgeSsrCount: $edgeSsrCount,
            edgeSsrUnitCents: $billable ? (int) config('subscription.standard.edge_ssr_cents', 700) : 0,
            edgeUsageSubtotalCents: $billable ? (int) $edgeUsageEstimate['subtotal_cents'] : 0,
            edgeUsageEstimate: $edgeUsageEstimate,
            edgeLbEndpointCount: $edgeLbEndpointCount,
            edgeLbEndpointUnitCents: $billable ? (int) config('subscription.standard.edge_lb_endpoint_cents', 800) : 0,
            includedSites: $tier['sites'] ?? PHP_INT_MAX,
            seatCount: $seatCount,
            includedSeats: $tier['seats'] ?? null,
            extraSeatUnitCents: $billable ? (int) ($tier['extra_seat_cents'] ?? 0) : 0,
            buildMinutes: $buildMinutes,
            buildMinuteOverageCents: $billable ? EdgeBuildMinutes::overageCents($buildMinutes, $tier) : 0,
        );
    }

    /**
     * The cheapest Pro/Team tier for a fleet: tier fee + extra sites + extra
     * seats, skipping tiers whose hard seat cap the org is over. Used to move
     * a pre-tier per-site subscription onto a tier.
     */
    public static function cheapestPaidTier(int $baseSites, int $seats): string
    {
        $best = null;
        $bestCents = PHP_INT_MAX;
        foreach (['pro', 'team'] as $key) {
            $tier = (array) config('subscription.standard.tiers.'.$key);
            if ($tier['extra_seat_cents'] === null && $seats > (int) $tier['seats']) {
                continue;
            }
            $cents = (int) $tier['price_cents']
                + max(0, $baseSites - (int) $tier['sites']) * (int) config('subscription.standard.edge_cents', 200)
                + ($tier['extra_seat_cents'] === null ? 0 : max(0, $seats - (int) $tier['seats']) * (int) $tier['extra_seat_cents']);
            if ($cents < $bestCents) {
                [$best, $bestCents] = [$key, $cents];
            }
        }

        return $best ?? 'team';
    }
}
