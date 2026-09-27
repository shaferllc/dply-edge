<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Support\UsagePrice;

/**
 * Customer-facing delivery rates for site settings — read through the same
 * helper the biller uses (UsagePrice), so the copy matches the invoice. The
 * margin is never exposed.
 */
class ManagedProductCostEstimator
{
    /**
     * @return array{requests_per_million: float, egress_per_gb: float, storage_per_gb: float, requests_per_million_label: string, egress_per_gb_label: string, storage_per_gb_label: string}
     */
    public function edgeUsageRates(): array
    {
        $rates = [
            'requests_per_million' => UsagePrice::rate('requests_millicents_per_million'),
            'egress_per_gb' => UsagePrice::rate('egress_millicents_per_gb'),
            'storage_per_gb' => UsagePrice::rate('r2_storage_millicents_per_gb_month'),
        ];
        $out = [];
        foreach ($rates as $key => $millicents) {
            $out[$key] = $millicents / 100_000;
            $out[$key.'_label'] = UsagePrice::dollars($millicents);
        }

        return $out;
    }

    public function edgeUsageBillingEnabled(): bool
    {
        return (bool) config('dply.edge.usage_billing.enabled', false);
    }
}
