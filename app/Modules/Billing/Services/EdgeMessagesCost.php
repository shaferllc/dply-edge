<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Models\EdgeMessageUsage;
use App\Models\Organization;
use App\Modules\Billing\Support\UsagePrice;
use Carbon\CarbonInterface;

/**
 * dply Messages on the bill: published messages per 100,000 at a fixed
 * customer price (dply.edge.usage_billing.messages_millicents_per_hundred_thousand).
 * Reads edge_message_usage (EdgeMessages::collectUsage). Counted under "data"
 * with SQL and queues (OrganizationBillingStateComputer, StarterUsageBudget).
 */
class EdgeMessagesCost
{
    /**
     * @return array{messages: int, cents: int}
     */
    public function forOrganization(Organization $organization, CarbonInterface $from, CarbonInterface $to): array
    {
        $messages = (int) EdgeMessageUsage::query()
            ->where('organization_id', $organization->id)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->sum('messages');

        return ['messages' => $messages, 'cents' => $this->cents($messages)];
    }

    public function cents(int $messages): int
    {
        return $messages > 0
            ? UsagePrice::cents($messages / 100_000 * UsagePrice::cost('messages_millicents_per_hundred_thousand'))
            : 0;
    }
}
