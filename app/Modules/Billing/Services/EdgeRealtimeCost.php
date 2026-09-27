<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Models\EdgeRealtimeUsage;
use App\Models\Organization;
use App\Modules\Billing\Support\UsagePrice;
use Carbon\CarbonInterface;

/**
 * Realtime on the bill: connection-minutes and messages, every one billed
 * (the plan's usage credit replaced the old allowances, ruling
 * r-2zxevg4sj675qn1m). Called by OrganizationBillingStateComputer and
 * StarterUsageBudget. Reads edge_realtime_usage (EdgeRealtimeUsageCollector),
 * by organization so a deleted app's usage still bills. Cost rates in
 * dply.edge.usage_billing.realtime_*, priced by UsagePrice.
 */
class EdgeRealtimeCost
{
    /**
     * @return array{connection_seconds: int, messages: int, cents: int}
     */
    public function forOrganization(Organization $organization, CarbonInterface $from, CarbonInterface $to): array
    {
        $row = EdgeRealtimeUsage::query()
            ->where('organization_id', $organization->id)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('COALESCE(SUM(connection_seconds), 0) AS seconds, COALESCE(SUM(messages), 0) AS messages')
            ->first();
        $seconds = (int) ($row->seconds ?? 0);
        $messages = (int) ($row->messages ?? 0);

        return ['connection_seconds' => $seconds, 'messages' => $messages, 'cents' => $this->cents($seconds, $messages)];
    }

    /** Customer cents for the usage, rounded once. */
    public function cents(int $connectionSeconds, int $messages): int
    {
        return UsagePrice::cents(
            $connectionSeconds / 60 * UsagePrice::cost('realtime_connection_minute_millicents')
            + $messages / 1_000_000 * UsagePrice::cost('realtime_message_millicents_per_million'),
        );
    }
}
