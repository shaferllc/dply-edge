<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Models\EdgeRedisUsage;
use App\Models\Organization;
use App\Models\Site;
use App\Modules\Edge\Support\EdgeContainerConnections;
use App\Modules\Edge\Support\EdgeValkey;
use Carbon\CarbonInterface;

/**
 * Redis on the bill: dply Valkey awake time (T-021). Called by
 * OrganizationBillingStateComputer and StarterUsageBudget. Reads
 * edge_redis_usage.awake_seconds, written by EdgeValkeyUsageCollector.
 * A pasted Redis address is not billed here.
 */
class EdgeRedisCost
{
    /**
     * @return array{awake_seconds: int, cents: int}
     */
    public function forOrganization(Organization $organization, CarbonInterface $from, CarbonInterface $to): array
    {
        $seconds = EdgeRedisUsage::query()
            ->where('organization_id', $organization->id)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('site_id')
            ->selectRaw('site_id, SUM(awake_seconds) AS seconds')
            ->pluck('seconds', 'site_id')
            ->map(fn ($v): int => (int) $v)
            ->all();

        return ['awake_seconds' => array_sum($seconds), 'cents' => $this->valkeyCents($organization, $seconds)];
    }

    /**
     * dply Valkey (T-021): awake seconds times the class's price per second,
     * capped at the class's monthly price per app. The class is the one the
     * app has now. ponytail: a mid-month resize prices the whole month at the
     * new class; keep seconds per class if that matters.
     *
     * @param  array<string, int>  $secondsBySite
     */
    public function valkeyCents(Organization $organization, array $secondsBySite): int
    {
        $secondsBySite = array_filter($secondsBySite);
        if ($secondsBySite === []) {
            return 0;
        }
        $cents = 0;
        Site::query()->where('organization_id', $organization->id)->whereIn('id', array_keys($secondsBySite))->each(function (Site $site) use ($secondsBySite, &$cents): void {
            foreach (EdgeContainerConnections::for($site) as $connection) {
                if ($connection['kind'] !== 'redis' || ! EdgeValkey::isTarget($connection['target'])) {
                    continue;
                }
                $class = EdgeValkey::CLASSES[$connection['plan']] ?? EdgeValkey::CLASSES[EdgeValkey::DEFAULT_CLASS];
                $cents += min($class['cap_cents'], (int) ceil($secondsBySite[$site->id] * $class['per_second'] * 100));

                return;
            }
        });

        return $cents;
    }
}
