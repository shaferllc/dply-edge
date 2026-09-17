<?php

namespace App\Modules\Billing\Services;

/**
 * Snapshot of what an organization *should* be billed this cycle. The sync
 * layer reconciles a Stripe subscription against this shape.
 *
 * Billing model — plan tiers + usage (ruling r-zdescb7y05vp1bxx, monthly only):
 * - **Tier fee** — Free $0 / Pro / Team (planKey, planPriceCents).
 * - **Extra sites** — static/hybrid sites beyond the tier's included count.
 * - **SSR sites** — every Worker-native SSR site, never included.
 * - **Extra seats** — members beyond the tier's seats (Team only).
 * - **Load balancing** — per origin endpoint.
 * - **Container compute** — per second of vCPU / memory / disk after the
 *   tier's compute credit.
 * - **Usage** — delivery overage + build-minute overage + container compute,
 *   billed together as cents.
 *
 * Always pre-tax; expressed in cents and plain counts so it survives JSON
 * round-trips through queue payloads.
 */
class DesiredBillingState
{
    /**
     * @param  array<string, mixed>  $edgeUsageEstimate
     */
    private function __construct(
        public readonly string $planKey,
        public readonly string $planLabel,
        public readonly int $planPriceCents,
        public readonly int $edgeCount,
        /** Worker-native SSR sites included in edgeCount (billed at edge_ssr_cents). */
        public readonly int $edgeSsrCount,
        public readonly int $edgeSubtotalCents,
        public readonly int $edgeUsageSubtotalCents,
        public readonly array $edgeUsageEstimate,
        public readonly int $monthlyTotalCents,
        public readonly int $edgeLbEndpointCount = 0,
        public readonly int $edgeLbSubtotalCents = 0,
        /** Static/hybrid sites beyond the tier's included count (Stripe `edge` quantity). */
        public readonly int $extraSiteCount = 0,
        public readonly int $seatCount = 0,
        public readonly int $extraSeatCount = 0,
        public readonly int $extraSeatSubtotalCents = 0,
        public readonly int $buildMinutes = 0,
        public readonly int $buildMinuteOverageCents = 0,
        /** Container compute before the tier credit. */
        public readonly int $containerComputeGrossCents = 0,
        /** Container compute billed (after the tier credit). */
        public readonly int $containerComputeCents = 0,
    ) {}

    /**
     * Build a state from the plan record plus Edge usage. `includedSites`
     * null means every static/hybrid site is billable (the pre-tier shape).
     *
     * @param  array{key: string, label: string, price_cents: int}  $plan
     * @param  array<string, mixed>  $edgeUsageEstimate
     */
    public static function fromPlanAndUsage(
        array $plan,
        int $edgeCount = 0,
        int $edgeUnitCents = 0,
        int $edgeSsrCount = 0,
        int $edgeSsrUnitCents = 0,
        int $edgeUsageSubtotalCents = 0,
        array $edgeUsageEstimate = [],
        int $edgeLbEndpointCount = 0,
        int $edgeLbEndpointUnitCents = 0,
        ?int $includedSites = null,
        int $seatCount = 0,
        ?int $includedSeats = null,
        int $extraSeatUnitCents = 0,
        int $buildMinutes = 0,
        int $buildMinuteOverageCents = 0,
        int $containerComputeCents = 0,
        ?int $computeCreditCents = 0,
    ): self {
        $planPriceCents = max(0, (int) $plan['price_cents']);

        $edgeCount = max(0, $edgeCount);
        $edgeSsrCount = min($edgeCount, max(0, $edgeSsrCount));
        $edgeBaseCount = $edgeCount - $edgeSsrCount;
        $extraSites = $includedSites === null ? $edgeBaseCount : max(0, $edgeBaseCount - $includedSites);
        $edgeSubtotal = ($extraSites * max(0, $edgeUnitCents))
            + ($edgeSsrCount * max(0, $edgeSsrUnitCents));

        $edgeUsageSubtotalCents = max(0, $edgeUsageSubtotalCents);
        $edgeLbEndpointCount = max(0, $edgeLbEndpointCount);
        $edgeLbSubtotal = $edgeLbEndpointCount * max(0, $edgeLbEndpointUnitCents);

        $seatCount = max(0, $seatCount);
        $extraSeats = $includedSeats === null || $extraSeatUnitCents <= 0 ? 0 : max(0, $seatCount - $includedSeats);
        $extraSeatSubtotal = $extraSeats * $extraSeatUnitCents;
        $buildMinuteOverageCents = max(0, $buildMinuteOverageCents);
        $containerComputeGross = max(0, $containerComputeCents);
        // null credit = unlimited (Enterprise).
        $containerComputeBilled = $computeCreditCents === null ? 0 : max(0, $containerComputeGross - $computeCreditCents);

        return new self(
            planKey: $plan['key'],
            planLabel: $plan['label'],
            planPriceCents: $planPriceCents,
            edgeCount: $edgeCount,
            edgeSsrCount: $edgeSsrCount,
            edgeSubtotalCents: $edgeSubtotal,
            edgeUsageSubtotalCents: $edgeUsageSubtotalCents,
            edgeUsageEstimate: $edgeUsageEstimate,
            monthlyTotalCents: $planPriceCents + $edgeSubtotal + $edgeUsageSubtotalCents + $edgeLbSubtotal
                + $extraSeatSubtotal + $buildMinuteOverageCents + $containerComputeBilled,
            edgeLbEndpointCount: $edgeLbEndpointCount,
            edgeLbSubtotalCents: $edgeLbSubtotal,
            extraSiteCount: $extraSites,
            seatCount: $seatCount,
            extraSeatCount: $extraSeats,
            extraSeatSubtotalCents: $extraSeatSubtotal,
            buildMinutes: max(0, $buildMinutes),
            buildMinuteOverageCents: $buildMinuteOverageCents,
            containerComputeGrossCents: $containerComputeGross,
            containerComputeCents: $containerComputeBilled,
        );
    }

    /** Static / hybrid Edge sites. */
    public function edgeBaseCount(): int
    {
        return max(0, $this->edgeCount - $this->edgeSsrCount);
    }

    /** Stripe `edge_usage` quantity: delivery, build-minute and container compute, in cents. */
    public function usageLineCents(): int
    {
        return $this->edgeUsageSubtotalCents + $this->buildMinuteOverageCents + $this->containerComputeCents;
    }

    /**
     * Flat subtotal: tier fee, extra/SSR sites and extra seats (excludes usage
     * and add-ons).
     */
    public function managedSubtotalCents(): int
    {
        return $this->planPriceCents + $this->edgeSubtotalCents + $this->extraSeatSubtotalCents;
    }

    /**
     * True when the org owes nothing this cycle. Drives "no subscription /
     * never paused" lifecycle decisions.
     */
    public function isFree(): bool
    {
        return $this->monthlyTotalCents <= 0;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'plan_key' => $this->planKey,
            'plan_label' => $this->planLabel,
            'plan_price_cents' => $this->planPriceCents,
            'edge_count' => $this->edgeCount,
            'edge_ssr_count' => $this->edgeSsrCount,
            'extra_site_count' => $this->extraSiteCount,
            'edge_subtotal_cents' => $this->edgeSubtotalCents,
            'seat_count' => $this->seatCount,
            'extra_seat_count' => $this->extraSeatCount,
            'extra_seat_subtotal_cents' => $this->extraSeatSubtotalCents,
            'build_minutes' => $this->buildMinutes,
            'build_minute_overage_cents' => $this->buildMinuteOverageCents,
            'container_compute_gross_cents' => $this->containerComputeGrossCents,
            'container_compute_cents' => $this->containerComputeCents,
            'edge_usage_subtotal_cents' => $this->edgeUsageSubtotalCents,
            'edge_usage_estimate' => $this->edgeUsageEstimate,
            'edge_lb_endpoint_count' => $this->edgeLbEndpointCount,
            'edge_lb_subtotal_cents' => $this->edgeLbSubtotalCents,
            'monthly_total_cents' => $this->monthlyTotalCents,
        ];
    }
}
