<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Models\EdgeRedisUsage;
use App\Models\Organization;
use Carbon\CarbonInterface;

/**
 * Called by OrganizationBillingStateComputer and StarterUsageBudget.
 * Reads edge_redis_usage. Rates in dply.edge.usage_billing.redis_*.
 * User request: "ok so how can we implement upstash and bill for it".
 */
class EdgeRedisCost
{
    /**
     * @return array{commands: int, storage_bytes: int, bandwidth_bytes: int, cents: int}
     */
    public function forOrganization(Organization $organization, CarbonInterface $from, CarbonInterface $to): array
    {
        $rows = EdgeRedisUsage::query()
            ->where('organization_id', $organization->id)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->get(['site_id', 'commands', 'storage_bytes', 'bandwidth_bytes']);

        $bySite = [];
        foreach ($rows as $row) {
            $site = $bySite[$row->site_id] ?? ['commands' => 0, 'storage_bytes' => 0, 'bandwidth_bytes' => 0];
            $site['commands'] += (int) $row->commands;
            $site['storage_bytes'] = max($site['storage_bytes'], (int) $row->storage_bytes);
            $site['bandwidth_bytes'] += (int) $row->bandwidth_bytes;
            $bySite[$row->site_id] = $site;
        }

        $totals = ['commands' => 0, 'storage_bytes' => 0, 'bandwidth_bytes' => 0];
        $cents = 0;
        foreach ($bySite as $site) {
            $totals['commands'] += $site['commands'];
            $totals['storage_bytes'] += $site['storage_bytes'];
            $totals['bandwidth_bytes'] += $site['bandwidth_bytes'];
            $cents += $this->cents($site['commands'], $site['storage_bytes'], $site['bandwidth_bytes']);
        }

        return $totals + ['cents' => $cents];
    }

    public function cents(int $commands, int $storageBytes, int $bandwidthBytes): int
    {
        $rate = static fn (string $key): float => (float) config('dply.edge.usage_billing.'.$key, 0);
        $storage = max(0, $storageBytes - 1024 ** 3);
        $bandwidth = max(0, $bandwidthBytes - 200 * 1024 ** 3);
        $millicents = $commands / 100_000 * $rate('redis_commands_millicents_per_100k')
            + $storage / 1024 ** 3 * $rate('redis_storage_millicents_per_gb_month')
            + $bandwidth / 1024 ** 3 * $rate('redis_bandwidth_millicents_per_gb');

        return (int) ceil($millicents * (100 + max(0, (int) config('dply.edge.usage_billing.markup_percent', 0))) / 100 / 1000);
    }
}
