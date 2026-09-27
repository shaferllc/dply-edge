<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Models\EdgeRealtimeUsage;
use App\Models\Organization;
use Carbon\CarbonInterface;

/**
 * Realtime on the bill: connection-minutes and messages past the org's
 * monthly plan allowance (subscription.standard.tiers.*.realtime_connection_minutes
 * / realtime_messages; null = unlimited). Called by OrganizationBillingStateComputer
 * and StarterUsageBudget. Reads edge_realtime_usage (EdgeRealtimeUsageCollector),
 * by organization so a deleted app's usage still bills. Rates in
 * dply.edge.usage_billing.realtime_*.
 */
class EdgeRealtimeCost
{
    /**
     * @return array{connection_seconds: int, messages: int, cents: int}
     */
    public function forOrganization(Organization $organization, CarbonInterface $from, CarbonInterface $to, ?string $tier = null): array
    {
        $row = EdgeRealtimeUsage::query()
            ->where('organization_id', $organization->id)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('COALESCE(SUM(connection_seconds), 0) AS seconds, COALESCE(SUM(messages), 0) AS messages')
            ->first();
        $seconds = (int) ($row->seconds ?? 0);
        $messages = (int) ($row->messages ?? 0);

        return ['connection_seconds' => $seconds, 'messages' => $messages, 'cents' => $this->cents($seconds, $messages, $this->allowance($organization, $tier))];
    }

    /**
     * The plan's monthly allowance; null on either side means unlimited.
     *
     * @return array{connection_minutes: ?int, messages: ?int}
     */
    public function allowance(Organization $organization, ?string $tier = null): array
    {
        $tiers = (array) config('subscription.standard.tiers');
        $plan = (array) ($tiers[$tier ?? $organization->billingTier()] ?? []);

        return [
            'connection_minutes' => array_key_exists('realtime_connection_minutes', $plan) ? $plan['realtime_connection_minutes'] : 0,
            'messages' => array_key_exists('realtime_messages', $plan) ? $plan['realtime_messages'] : 0,
        ];
    }

    /**
     * One month of usage, allowance taken off, rounded up to a whole cent.
     *
     * @param  array{connection_minutes: ?int, messages: ?int}  $allowance
     */
    public function cents(int $connectionSeconds, int $messages, array $allowance): int
    {
        $rate = static fn (string $key): float => (float) config('dply.edge.usage_billing.realtime_'.$key, 0);
        $minutes = $allowance['connection_minutes'] === null ? 0.0 : max(0.0, $connectionSeconds / 60 - $allowance['connection_minutes']);
        $billableMessages = $allowance['messages'] === null ? 0.0 : max(0.0, $messages - $allowance['messages']);
        $millicents = $minutes * $rate('connection_minute_millicents')
            + $billableMessages / 1_000_000 * $rate('message_millicents_per_million');

        return (int) ceil(round($millicents, 6) / 1000);
    }
}
