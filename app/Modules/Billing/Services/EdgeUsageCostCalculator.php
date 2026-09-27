<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Support\UsagePrice;

/**
 * Prices measured Edge delivery (requests, bandwidth, site storage and its
 * operations) at cost from dply.edge.usage_billing, through UsagePrice (one
 * margin). No per-site or per-plan allowances: the plan's included usage
 * credit comes off the whole bill instead (ruling r-2zxevg4sj675qn1m).
 */
class EdgeUsageCostCalculator
{
    public function isEnabled(): bool
    {
        return (bool) config('dply.edge.usage_billing.enabled', false);
    }

    /**
     * @return array{subtotal_cents: int, billable_requests: int, billable_bytes_egress: int, billable_r2_storage_bytes: int, billable_r2_class_a_ops: int, billable_r2_class_b_ops: int}
     */
    public function estimate(EdgeUsageTotals $usage): array
    {
        $enabled = $this->isEnabled();

        return [
            'subtotal_cents' => $enabled ? UsagePrice::cents($this->costMillicents($usage)) : 0,
            'billable_requests' => $enabled ? $usage->requests : 0,
            'billable_bytes_egress' => $enabled ? $usage->bytesEgress : 0,
            'billable_r2_storage_bytes' => $enabled ? $usage->r2StorageBytes : 0,
            'billable_r2_class_a_ops' => $enabled ? $usage->r2ClassAOps : 0,
            'billable_r2_class_b_ops' => $enabled ? $usage->r2ClassBOps : 0,
        ];
    }

    /** Storage is billed as the period's peak for a full month. */
    public function costMillicents(EdgeUsageTotals $usage): float
    {
        return $usage->requests / 1_000_000 * UsagePrice::cost('requests_millicents_per_million')
            + $usage->bytesEgress / 1024 ** 3 * UsagePrice::cost('egress_millicents_per_gb')
            + $usage->r2StorageBytes / 1024 ** 3 * UsagePrice::cost('r2_storage_millicents_per_gb_month')
            + $usage->r2ClassAOps / 1_000_000 * UsagePrice::cost('r2_class_a_millicents_per_million')
            + $usage->r2ClassBOps / 1_000_000 * UsagePrice::cost('r2_class_b_millicents_per_million');
    }
}
