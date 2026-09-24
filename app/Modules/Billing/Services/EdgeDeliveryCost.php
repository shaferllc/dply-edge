<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Models\EdgeDeliveryUsage;
use App\Models\Organization;
use Carbon\CarbonInterface;

/**
 * Called by OrganizationBillingStateComputer and StarterUsageBudget.
 * Reads edge_delivery_usage. Rates in dply.edge.usage_billing.delivery_*.
 * User request: "ok then lets build that out and we need to charge for it".
 */
class EdgeDeliveryCost
{
    /**
     * @return array{messages: int, bandwidth_bytes: int, cents: int}
     */
    public function forOrganization(Organization $organization, CarbonInterface $from, CarbonInterface $to): array
    {
        $row = EdgeDeliveryUsage::query()
            ->where('organization_id', $organization->id)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('COALESCE(SUM(messages),0) messages, COALESCE(SUM(bandwidth_bytes),0) bandwidth')
            ->first();
        $messages = (int) ($row->messages ?? 0);
        $bandwidth = (int) ($row->bandwidth ?? 0);

        return [
            'messages' => $messages,
            'bandwidth_bytes' => $bandwidth,
            'cents' => $this->cents($messages, $bandwidth),
        ];
    }

    public function cents(int $messages, int $bandwidthBytes): int
    {
        $rate = static fn (string $key): float => (float) config('dply.edge.usage_billing.'.$key, 0);
        $included = max(0, (int) config('dply.edge.usage_billing.delivery_included_bandwidth_bytes', 1024 ** 3));
        $bandwidth = max(0, $bandwidthBytes - $included);
        $millicents = $messages / 100_000 * $rate('delivery_messages_millicents_per_100k')
            + $bandwidth / 1024 ** 3 * $rate('delivery_bandwidth_millicents_per_gb');

        return (int) ceil($millicents / 1000);
    }
}
