<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

/**
 * Surfaces dply's Edge per-site fees and usage rates in create flows, site
 * settings and the pricing page — read from the same config the biller uses.
 */
class ManagedProductCostEstimator
{
    public function edgeFee(): float
    {
        return ((int) config('subscription.standard.edge_cents', 0)) / 100;
    }

    /** Monthly platform fee (dollars) for Worker-native SSR Edge sites. */
    public function edgeSsrFee(): float
    {
        return ((int) config('subscription.standard.edge_ssr_cents', 0)) / 100;
    }

    /**
     * Platform fee for an Edge runtime mode (static/hybrid → edgeFee, ssr → edgeSsrFee).
     */
    public function edgeFeeForRuntimeMode(string $runtimeMode): float
    {
        return strtolower($runtimeMode) === 'ssr'
            ? $this->edgeSsrFee()
            : $this->edgeFee();
    }

    /**
     * Customer-facing Edge usage rates (monthly), with markup baked into
     * the displayed unit prices so create/billing copy matches the invoice.
     *
     * @return array{
     *     requests_per_million: float,
     *     egress_per_gb: float,
     *     storage_per_gb: float,
     *     markup_percent: int,
     *     included_requests_per_site: int,
     *     included_egress_gb_per_site: int,
     *     included_r2_storage_gb_per_site: int,
     * }
     */
    public function edgeUsageRates(): array
    {
        $markup = max(0, (int) config('dply.edge.usage_billing.markup_percent', 0));
        $multiplier = (100 + $markup) / 100;

        return [
            'requests_per_million' => round(((int) config('dply.edge.usage_billing.requests_cents_per_million', 0)) / 100 * $multiplier, 2),
            'egress_per_gb' => round(((int) config('dply.edge.usage_billing.egress_cents_per_gb', 0)) / 100 * $multiplier, 2),
            'storage_per_gb' => round(((int) config('dply.edge.usage_billing.r2_storage_cents_per_gb_month', 0)) / 100 * $multiplier, 2),
            'markup_percent' => $markup,
            'included_requests_per_site' => (int) config('dply.edge.usage_billing.included_requests_per_site', 0),
            'included_egress_gb_per_site' => (int) config('dply.edge.usage_billing.included_egress_gb_per_site', 0),
            'included_r2_storage_gb_per_site' => (int) config('dply.edge.usage_billing.included_r2_storage_gb_per_site', 0),
        ];
    }

    public function edgeUsageBillingEnabled(): bool
    {
        return (bool) config('dply.edge.usage_billing.enabled', false);
    }
}
