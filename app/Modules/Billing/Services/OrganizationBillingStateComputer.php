<?php

namespace App\Modules\Billing\Services;

use App\Models\Organization;
use App\Models\Site;
use App\Modules\Edge\Support\EdgeLoadBalancing;

/**
 * Builds a {@see DesiredBillingState} for an organization by scanning its
 * currently *billable* units. dply-edge bills exactly one kind — **Edge
 * sites**: edge_active sites with `edge_backend = dply_edge`, excluding branch
 * previews, plus their metered usage (requests / egress / R2 storage).
 *
 * Age filter: units younger than min_billable_age_days are excluded.
 */
class OrganizationBillingStateComputer
{
    public function __construct(
        private EdgeOrganizationUsageReader $usageReader,
        private EdgeUsageCostCalculator $usageCostCalculator,
        private SubscriptionPlanResolver $planResolver,
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

    private function computeFresh(Organization $organization): DesiredBillingState
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

        // No plan tiers: the Free record ($0) carries the plan triple, and every
        // charge rides on the per-site + metered lines below.
        return DesiredBillingState::fromPlanAndUsage(
            plan: $this->planResolver->resolveByKey('free'),
            edgeCount: $edgeCount,
            edgeUnitCents: (int) config('subscription.standard.edge_cents', 200),
            edgeSsrCount: $edgeSsrCount,
            edgeSsrUnitCents: (int) config('subscription.standard.edge_ssr_cents', 700),
            edgeUsageSubtotalCents: (int) $edgeUsageEstimate['subtotal_cents'],
            edgeUsageEstimate: $edgeUsageEstimate,
            edgeLbEndpointCount: $edgeLbEndpointCount,
            edgeLbEndpointUnitCents: (int) config('subscription.standard.edge_lb_endpoint_cents', 800),
        );
    }
}
