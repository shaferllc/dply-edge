<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Models\EdgeRedisUsage;
use App\Models\Organization;
use App\Models\Site;
use App\Modules\Edge\Services\EdgeRedisUsageCollector;
use App\Modules\Edge\Support\EdgeContainerConnections;
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
        $flat = $this->flatPlans($organization);
        foreach ($bySite as $siteId => $site) {
            $totals['commands'] += $site['commands'];
            $totals['storage_bytes'] += $site['storage_bytes'];
            $totals['bandwidth_bytes'] += $site['bandwidth_bytes'];
            if (! isset($flat['skip'][$siteId])) {
                $cents += $this->cents($site['commands'], $site['storage_bytes'], $site['bandwidth_bytes']);
            }
        }
        $cents += $flat['cents'];
        $extraDatabases = max(0, $this->databaseCount($organization) - max(0, (int) config('dply.edge.usage_billing.redis_included_databases', 10)));
        $cents += $extraDatabases * max(0, (int) config('dply.edge.usage_billing.redis_database_cents', 100));

        return $totals + ['cents' => $cents];
    }

    public function cents(int $commands, int $storageBytes, int $bandwidthBytes): int
    {
        $rate = static fn (string $key): float => (float) config('dply.edge.usage_billing.'.$key, 0);
        $storage = max(0, $storageBytes - 1024 ** 3);
        $bandwidth = max(0, $bandwidthBytes - 200 * 1024 ** 3);
        $commandsMillicents = $commands / 100_000 * $rate('redis_commands_millicents_per_100k');
        $commandsMillicents *= (100 + max(0, (int) config('dply.edge.usage_billing.markup_percent', 0))) / 100;
        $millicents = $commandsMillicents
            + $storage / 1024 ** 3 * $rate('redis_storage_millicents_per_gb_month')
            + $bandwidth / 1024 ** 3 * $rate('redis_bandwidth_millicents_per_gb');

        return (int) ceil($millicents / 1000);
    }

    public function planCents(string $plan, int $readRegions = 0): int
    {
        $base = $this->fixedPlans()[$plan]['cents'] ?? 0;

        return $base + max(0, $readRegions) * intdiv($base, 2);
    }

    /**
     * @return array<string, array{label: string, cents: int, data: string, bandwidth: string}>
     */
    public function fixedPlans(): array
    {
        $cents = (array) config('dply.edge.usage_billing.redis_fixed_cents', []);
        $plans = [
            'fixed_250mb' => ['label' => '250 MB', 'data' => '250 MB', 'bandwidth' => '50 GB'],
            'fixed_1gb' => ['label' => '1 GB', 'data' => '1 GB', 'bandwidth' => '100 GB'],
            'fixed_5gb' => ['label' => '5 GB', 'data' => '5 GB', 'bandwidth' => '500 GB'],
            'fixed_10gb' => ['label' => '10 GB', 'data' => '10 GB', 'bandwidth' => '1 TB'],
            'fixed_50gb' => ['label' => '50 GB', 'data' => '50 GB', 'bandwidth' => '5 TB'],
            'fixed_100gb' => ['label' => '100 GB', 'data' => '100 GB', 'bandwidth' => '10 TB'],
            'fixed_500gb' => ['label' => '500 GB', 'data' => '500 GB', 'bandwidth' => '20 TB'],
        ];
        foreach ($plans as $id => $plan) {
            $plans[$id]['cents'] = max(0, (int) ($cents[$id] ?? 0));
        }

        return $plans;
    }

    /**
     * @return array{skip: array<string, true>, cents: int}
     */
    private function flatPlans(Organization $organization): array
    {
        $skip = [];
        $cents = 0;
        Site::query()
            ->where('organization_id', $organization->id)
            ->where('meta->edge->runtime_mode', 'container')
            ->orderBy('id')
            ->each(function (Site $site) use (&$skip, &$cents): void {
                foreach (EdgeContainerConnections::for($site) as $connection) {
                    if ($connection['kind'] !== 'redis' || ! EdgeRedisUsageCollector::isProvisionedId((string) ($connection['target'] ?? ''))) {
                        continue;
                    }
                    $plan = $connection['plan'];
                    if ($plan === 'free' || isset($this->fixedPlans()[$plan])) {
                        $skip[(string) $site->id] = true;
                    }
                    if (isset($this->fixedPlans()[$plan])) {
                        $cents += $this->planCents($plan, $connection['read_regions']);
                    }
                }
            });

        return ['skip' => $skip, 'cents' => $cents];
    }

    private function databaseCount(Organization $organization): int
    {
        $count = 0;
        Site::query()
            ->where('organization_id', $organization->id)
            ->where('meta->edge->runtime_mode', 'container')
            ->orderBy('id')
            ->each(function (Site $site) use (&$count): void {
                foreach (EdgeContainerConnections::for($site) as $connection) {
                    if ($connection['kind'] === 'redis' && EdgeRedisUsageCollector::isProvisionedId((string) ($connection['target'] ?? ''))) {
                        $count++;
                    }
                }
            });

        return $count;
    }
}
