<?php

namespace App\Modules\Billing\Services;

/**
 * Snapshot of what an organization *should* be billed this cycle, derived
 * purely from its live Edge sites. The sync layer reconciles a Stripe
 * subscription against this shape.
 *
 * Billing model (dply-edge sells one product):
 * - **Edge sites** — a flat fee per live production site (static/hybrid at
 *   edge_cents, Worker-native SSR at edge_ssr_cents).
 * - **Edge delivery usage** — metered pass-through on top.
 *
 * The plan triple survives only as the Free allowance record ($0): there are
 * no paid plan tiers.
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
    ) {}

    /**
     * Build a state from the plan record plus Edge usage.
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
    ): self {
        $planPriceCents = max(0, (int) $plan['price_cents']);

        $edgeCount = max(0, $edgeCount);
        $edgeSsrCount = min($edgeCount, max(0, $edgeSsrCount));
        $edgeBaseCount = $edgeCount - $edgeSsrCount;
        $edgeSubtotal = ($edgeBaseCount * max(0, $edgeUnitCents))
            + ($edgeSsrCount * max(0, $edgeSsrUnitCents));

        $edgeUsageSubtotalCents = max(0, $edgeUsageSubtotalCents);

        return new self(
            planKey: $plan['key'],
            planLabel: $plan['label'],
            planPriceCents: $planPriceCents,
            edgeCount: $edgeCount,
            edgeSsrCount: $edgeSsrCount,
            edgeSubtotalCents: $edgeSubtotal,
            edgeUsageSubtotalCents: $edgeUsageSubtotalCents,
            edgeUsageEstimate: $edgeUsageEstimate,
            monthlyTotalCents: $planPriceCents + $edgeSubtotal + $edgeUsageSubtotalCents,
        );
    }

    /** Static / hybrid Edge sites (Stripe `edge` line quantity). */
    public function edgeBaseCount(): int
    {
        return max(0, $this->edgeCount - $this->edgeSsrCount);
    }

    /**
     * Flat per-site subtotal (excludes Edge delivery usage).
     */
    public function managedSubtotalCents(): int
    {
        return $this->edgeSubtotalCents;
    }

    /**
     * True when the org owes nothing this cycle — no live Edge sites and no
     * Edge usage. Drives "no subscription / never paused" lifecycle decisions.
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
            'edge_subtotal_cents' => $this->edgeSubtotalCents,
            'edge_usage_subtotal_cents' => $this->edgeUsageSubtotalCents,
            'edge_usage_estimate' => $this->edgeUsageEstimate,
            'monthly_total_cents' => $this->monthlyTotalCents,
        ];
    }
}
