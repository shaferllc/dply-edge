<?php

namespace App\Modules\Billing\Services;

use App\Models\Organization;
use App\Models\Site;
use App\Modules\Billing\Support\UsagePrice;
use App\Modules\Edge\Support\EdgeBuildMinutes;
use Carbon\CarbonInterface;

/**
 * Builds a {@see DesiredBillingState} for an organization: its plan (from the
 * subscription, or the cheapest fit for a pre-tier per-site one), seats,
 * every usage category at customer price (UsagePrice) and the plan's
 * included usage credit. Live sites are counted for fair use, not billed.
 */
class OrganizationBillingStateComputer
{
    public function __construct(
        private EdgeOrganizationUsageReader $usageReader,
        private EdgeUsageCostCalculator $usageCostCalculator,
        private EdgeContainerComputeCost $computeCost,
        private EdgeDataUsageCost $dataUsageCost,
        private EdgeRedisCost $redisCost,
        private EdgeKvCost $kvCost,
        private EdgeAppDatabaseCost $databaseCost,
        private EdgeRealtimeCost $realtimeCost,
        private EdgePlatformUsageCost $platformUsageCost,
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

    /**
     * Usage for a closed billing period (days $from..$to inclusive) on a
     * given tier — what UsageInvoicer charges. Not memoized.
     */
    public function computeForPeriod(Organization $organization, CarbonInterface $from, CarbonInterface $to, string $tierKey): DesiredBillingState
    {
        return $this->computeFresh($organization, $tierKey, [$from, $to]);
    }

    /** @param  array{0: CarbonInterface, 1: CarbonInterface}|null  $window  null = the current period */
    private function computeFresh(Organization $organization, ?string $forceTier = null, ?array $window = null): DesiredBillingState
    {
        $edgeCount = $organization->sites()
            ->where('status', Site::STATUS_EDGE_ACTIVE)
            ->where('edge_backend', 'dply_edge')
            ->get()
            ->reject(fn (Site $site): bool => $site->isEdgePreview())
            ->count();

        $seatCount = $organization->users()->count();
        $tierKey = $forceTier
            ?? $organization->subscribedTier()
            ?? ($organization->onStandardSubscription() ? self::cheapestPaidTier($seatCount) : ($organization->hasPlan() ? $organization->billingTier() : 'none'));
        $tier = (array) config('subscription.standard.tiers.'.$tierKey);
        // No plan, a card-less trial and comped orgs have nothing to bill;
        // Enterprise is invoiced by hand. None owe anything through this path.
        $billable = in_array($tierKey, SubscriptionPlanResolver::PAID_TIERS, true);

        [$usagePeriodStart, $usagePeriodEnd] = $window ?? $this->usageReader->currentWindow($organization);
        $usageTotals = $this->usageReader->totalsForOrganization($organization, $usagePeriodStart, $usagePeriodEnd);
        $edgeUsageEstimate = array_merge($this->usageCostCalculator->estimate($usageTotals), [
            'period_start' => $usagePeriodStart->toDateString(),
            'period_end' => $usagePeriodEnd->toDateString(),
            'requests' => $usageTotals->requests,
            'bytes_egress' => $usageTotals->bytesEgress,
            'r2_storage_bytes' => $usageTotals->r2StorageBytes,
        ]);
        $buildSeconds = EdgeBuildMinutes::secondsBetween($organization, $usagePeriodStart, $usagePeriodEnd);
        $data = $this->dataUsageCost->forOrganization($organization, $usagePeriodStart, $usagePeriodEnd);
        $kv = $this->kvCost->forOrganization($organization, $usagePeriodStart, $usagePeriodEnd);

        $usage = $billable ? [
            'delivery' => (int) $edgeUsageEstimate['subtotal_cents'],
            'builds' => UsagePrice::cents(EdgeBuildMinutes::costMillicents($buildSeconds)),
            'compute' => $this->computeCost->forOrganization($organization, $usagePeriodStart, $usagePeriodEnd)['cents'],
            'databases' => $this->databaseCost->forOrganization($organization, $usagePeriodStart, $usagePeriodEnd)['cents'],
            'valkey' => $this->redisCost->forOrganization($organization, $usagePeriodStart, $usagePeriodEnd)['cents'],
            'data' => $data['cents'] + $kv['cents'],
            'realtime' => $this->realtimeCost->forOrganization($organization, $usagePeriodStart, $usagePeriodEnd)['cents'],
            'platform' => $this->platformUsageCost->forOrganization($organization, $usagePeriodStart, $usagePeriodEnd)['cents'],
        ] : [];

        return DesiredBillingState::fromPlanAndUsage(
            plan: ['key' => $tierKey, 'label' => (string) $tier['label'], 'price_cents' => (int) $tier['price_cents']],
            edgeCount: $edgeCount,
            seatCount: $seatCount,
            includedSeats: $tier['seats'] ?? null,
            extraSeatUnitCents: $billable ? (int) ($tier['extra_seat_cents'] ?? 0) : 0,
            usage: $usage,
            usageCreditCents: $billable ? (int) ($tier['usage_credit_cents'] ?? 0) : 0,
            edgeUsageEstimate: $edgeUsageEstimate,
            buildSeconds: $buildSeconds,
        );
    }

    /**
     * The cheaper of Pro and Team whose seats fit the org (plan fee + extra
     * seats; a hard seat cap rules a plan out). Used to move a pre-tier
     * per-site subscription onto a plan (owner, 2026-09-16: auto-move to
     * Pro/Team). Starter is left out on purpose: its limits (1 build at a
     * time, 3 domains) could break a site that was already running.
     */
    public static function cheapestPaidTier(int $seats): string
    {
        $best = null;
        $bestCents = PHP_INT_MAX;
        foreach (['pro', 'team'] as $key) {
            $tier = (array) config('subscription.standard.tiers.'.$key);
            if ($tier['extra_seat_cents'] === null && $seats > (int) $tier['seats']) {
                continue;
            }
            $cents = (int) $tier['price_cents']
                + ($tier['extra_seat_cents'] === null ? 0 : max(0, $seats - (int) $tier['seats']) * (int) $tier['extra_seat_cents']);
            if ($cents < $bestCents) {
                [$best, $bestCents] = [$key, $cents];
            }
        }

        return $best ?? 'team';
    }
}
