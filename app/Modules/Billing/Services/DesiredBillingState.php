<?php

namespace App\Modules\Billing\Services;

/**
 * Snapshot of what an organization *should* be billed this cycle. The sync
 * layer reconciles a Stripe subscription against this shape.
 *
 * Billing model — three plans + usage credit + one margin (ruling
 * r-2zxevg4sj675qn1m, docs/adr/pricing-model-2026-09.md):
 * - **Plan fee** — Starter / Pro / Team (planKey, planPriceCents).
 * - **Extra seats** — members beyond the plan's seats (Team only).
 * - **Usage** — every meter at cost + margin (UsagePrice), one line per
 *   category, billed in arrears for each closed Stripe period
 *   (UsageInvoicer).
 * - **Included usage credit** — the plan's usage_credit_cents, taken off the
 *   usage (never below $0).
 * No per-site fees: sites are unlimited, subject to fair use.
 *
 * Always pre-tax; expressed in cents and plain counts so it survives JSON
 * round-trips through queue payloads.
 */
class DesiredBillingState
{
    /** Invoice/billing-page order of the usage categories. */
    public const USAGE_KEYS = ['delivery', 'builds', 'compute', 'databases', 'valkey', 'data', 'realtime', 'platform'];

    /**
     * @param  array<string, int>  $usage  category => customer cents, zero lines dropped
     * @param  array<string, mixed>  $edgeUsageEstimate
     */
    private function __construct(
        public readonly string $planKey,
        public readonly string $planLabel,
        public readonly int $planPriceCents,
        /** Live, non-preview sites (fair use; not billed). */
        public readonly int $edgeCount,
        public readonly int $seatCount,
        public readonly int $extraSeatCount,
        public readonly int $extraSeatSubtotalCents,
        public readonly array $usage,
        /** The plan's included usage credit for the period. */
        public readonly int $usageCreditCents,
        public readonly array $edgeUsageEstimate,
        public readonly int $buildSeconds,
        public readonly int $monthlyTotalCents,
    ) {}

    /**
     * @param  array{key: string, label: string, price_cents: int}  $plan
     * @param  array<string, int>  $usage  category => customer cents
     * @param  array<string, mixed>  $edgeUsageEstimate
     */
    public static function fromPlanAndUsage(
        array $plan,
        int $edgeCount = 0,
        int $seatCount = 0,
        ?int $includedSeats = null,
        int $extraSeatUnitCents = 0,
        array $usage = [],
        int $usageCreditCents = 0,
        array $edgeUsageEstimate = [],
        int $buildSeconds = 0,
    ): self {
        $planPriceCents = max(0, (int) $plan['price_cents']);
        $seatCount = max(0, $seatCount);
        $extraSeats = $includedSeats === null || $extraSeatUnitCents <= 0 ? 0 : max(0, $seatCount - $includedSeats);
        $extraSeatSubtotal = $extraSeats * $extraSeatUnitCents;

        $lines = [];
        foreach (self::USAGE_KEYS as $key) {
            $cents = max(0, (int) ($usage[$key] ?? 0));
            if ($cents > 0) {
                $lines[$key] = $cents;
            }
        }
        $usageCreditCents = max(0, $usageCreditCents);
        $usageCharge = max(0, array_sum($lines) - $usageCreditCents);

        return new self(
            planKey: $plan['key'],
            planLabel: $plan['label'],
            planPriceCents: $planPriceCents,
            edgeCount: max(0, $edgeCount),
            seatCount: $seatCount,
            extraSeatCount: $extraSeats,
            extraSeatSubtotalCents: $extraSeatSubtotal,
            usage: $lines,
            usageCreditCents: $usageCreditCents,
            edgeUsageEstimate: $edgeUsageEstimate,
            buildSeconds: max(0, $buildSeconds),
            monthlyTotalCents: $planPriceCents + $extraSeatSubtotal + $usageCharge,
        );
    }

    /**
     * Usage at customer price, category => cents, zero lines dropped —
     * before the included credit.
     *
     * @return array<string, int>
     */
    public function usageLines(): array
    {
        return $this->usage;
    }

    /** All usage at customer price, before the credit. */
    public function usageLineCents(): int
    {
        return array_sum($this->usage);
    }

    /** The part of the plan's credit this usage uses: min(credit, usage). */
    public function creditAppliedCents(): int
    {
        return min($this->usageCreditCents, $this->usageLineCents());
    }

    /** Usage owed after the credit (never below zero). */
    public function usageChargeCents(): int
    {
        return $this->usageLineCents() - $this->creditAppliedCents();
    }

    /** Invoice wording for a {@see usageLines()} key. */
    public static function usageLineLabel(string $key): string
    {
        return match ($key) {
            'delivery' => 'Delivery (requests, bandwidth, site storage)',
            'builds' => 'Build time',
            'compute' => 'Apps and workers (compute)',
            'databases' => 'Databases',
            'valkey' => 'Valkey',
            'data' => 'SQL, queues and key-value',
            'realtime' => 'Realtime',
            'platform' => 'Workers CPU, Durable Objects, object storage and images',
            'credit' => 'Included usage credit',
            default => 'Usage',
        };
    }

    /** Flat subtotal: plan fee and extra seats (excludes usage). */
    public function managedSubtotalCents(): int
    {
        return $this->planPriceCents + $this->extraSeatSubtotalCents;
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
            'seat_count' => $this->seatCount,
            'extra_seat_count' => $this->extraSeatCount,
            'extra_seat_subtotal_cents' => $this->extraSeatSubtotalCents,
            'usage' => $this->usage,
            'usage_cents' => $this->usageLineCents(),
            'usage_credit_cents' => $this->usageCreditCents,
            'credit_applied_cents' => $this->creditAppliedCents(),
            'usage_charge_cents' => $this->usageChargeCents(),
            'build_seconds' => $this->buildSeconds,
            'edge_usage_estimate' => $this->edgeUsageEstimate,
            'monthly_total_cents' => $this->monthlyTotalCents,
        ];
    }
}
