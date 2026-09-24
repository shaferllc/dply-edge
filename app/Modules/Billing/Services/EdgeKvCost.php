<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Models\EdgeKvUsage;
use App\Models\Organization;
use App\Models\Site;
use App\Modules\Edge\Support\EdgeContainerConnections;
use Carbon\CarbonInterface;

/**
 * Called by OrganizationBillingStateComputer and StarterUsageBudget.
 * Reads edge_kv_usage. Rates in dply.edge.usage_billing.kv_*.
 * User request: "continue buuiikding out key value".
 */
class EdgeKvCost
{
    /**
     * @return array{reads: int, writes: int, deletes: int, lists: int, storage_bytes: int, cents: int}
     */
    public function forOrganization(Organization $organization, CarbonInterface $from, CarbonInterface $to): array
    {
        $asleep = $this->asleepNamespaces($organization);
        $rows = EdgeKvUsage::query()
            ->where('organization_id', $organization->id)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->get(['namespace_id', 'reads', 'writes', 'deletes', 'lists', 'storage_bytes']);

        $byStore = [];
        $totals = ['reads' => 0, 'writes' => 0, 'deletes' => 0, 'lists' => 0, 'storage_bytes' => 0];
        foreach ($rows as $row) {
            if (isset($asleep[$row->namespace_id])) {
                continue;
            }
            $store = $byStore[$row->namespace_id] ?? $totals;
            $store['reads'] += (int) $row->reads;
            $store['writes'] += (int) $row->writes;
            $store['deletes'] += (int) $row->deletes;
            $store['lists'] += (int) $row->lists;
            $store['storage_bytes'] = max($store['storage_bytes'], (int) $row->storage_bytes);
            $byStore[$row->namespace_id] = $store;
        }

        $cents = 0;
        foreach ($byStore as $store) {
            foreach (array_keys($totals) as $key) {
                $totals[$key] += $store[$key];
            }
            $cents += $this->cents($store['reads'], $store['writes'], $store['deletes'], $store['lists'], $store['storage_bytes']);
        }

        return $totals + ['cents' => $cents];
    }

    public function cents(int $reads, int $writes, int $deletes, int $lists, int $storageBytes): int
    {
        $rate = static fn (string $key): float => (float) config('dply.edge.usage_billing.'.$key, 0);
        $storage = max(0, $storageBytes - 1024 ** 3);
        $millicents = $reads / 1_000_000 * $rate('kv_reads_millicents_per_million')
            + ($writes + $deletes + $lists) / 1_000_000 * $rate('kv_writes_millicents_per_million')
            + $storage / 1024 ** 3 * $rate('kv_storage_millicents_per_gb_month');

        return (int) ceil($millicents / 1000);
    }

    /**
     * @return array<string, true>
     */
    private function asleepNamespaces(Organization $organization): array
    {
        $ids = [];
        Site::query()->where('organization_id', $organization->id)->each(function (Site $site) use (&$ids): void {
            foreach (EdgeContainerConnections::for($site) as $connection) {
                if ($connection['kind'] === 'key_value' && $connection['asleep'] && $connection['target'] !== '') {
                    $ids[$connection['target']] = true;
                }
            }
        });

        return $ids;
    }
}
