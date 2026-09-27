<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Models\EdgeKvUsage;
use App\Models\Organization;
use App\Modules\Billing\Support\UsagePrice;
use Carbon\CarbonInterface;

/**
 * Called by OrganizationBillingStateComputer and StarterUsageBudget.
 * Reads edge_kv_usage. Cost rates in dply.edge.usage_billing.kv_*, priced by
 * UsagePrice. No free storage: the plan's usage credit covers small stores.
 * A sleeping store is billed like any other: its data is still stored, and
 * the app can use it until the next deploy drops the binding. After that its
 * reads and writes are zero, so only storage remains.
 */
class EdgeKvCost
{
    /**
     * @return array{reads: int, writes: int, deletes: int, lists: int, storage_bytes: int, cents: int}
     */
    public function forOrganization(Organization $organization, CarbonInterface $from, CarbonInterface $to): array
    {
        $rows = EdgeKvUsage::query()
            ->where('organization_id', $organization->id)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->get(['namespace_id', 'reads', 'writes', 'deletes', 'lists', 'storage_bytes']);

        $byStore = [];
        $totals = ['reads' => 0, 'writes' => 0, 'deletes' => 0, 'lists' => 0, 'storage_bytes' => 0];
        foreach ($rows as $row) {
            $store = $byStore[$row->namespace_id] ?? $totals;
            $store['reads'] += (int) $row->reads;
            $store['writes'] += (int) $row->writes;
            $store['deletes'] += (int) $row->deletes;
            $store['lists'] += (int) $row->lists;
            $store['storage_bytes'] = max($store['storage_bytes'], (int) $row->storage_bytes);
            $byStore[$row->namespace_id] = $store;
        }

        foreach ($byStore as $store) {
            foreach (array_keys($totals) as $key) {
                $totals[$key] += $store[$key];
            }
        }

        // Storage is each store's peak, summed.
        return $totals + ['cents' => $this->cents($totals['reads'], $totals['writes'], $totals['deletes'], $totals['lists'], $totals['storage_bytes'])];
    }

    public function cents(int $reads, int $writes, int $deletes, int $lists, int $storageBytes): int
    {
        $millicents = $reads / 1_000_000 * UsagePrice::cost('kv_reads_millicents_per_million')
            + ($writes + $deletes + $lists) / 1_000_000 * UsagePrice::cost('kv_writes_millicents_per_million')
            + $storageBytes / 1024 ** 3 * UsagePrice::cost('kv_storage_millicents_per_gb_month');

        return UsagePrice::cents($millicents);
    }
}
