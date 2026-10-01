<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Models\EdgeRedisUsage;
use App\Models\Organization;
use App\Models\Site;
use App\Modules\Billing\Support\UsagePrice;
use App\Modules\Edge\Support\EdgeContainerConnections;
use App\Modules\Edge\Support\EdgeValkey;
use Carbon\CarbonInterface;

/**
 * Redis on the bill: dply Valkey awake time (T-021), plus commands served
 * over its REST API per 100K. Called by
 * OrganizationBillingStateComputer and StarterUsageBudget. Reads
 * edge_redis_usage.awake_seconds, written by EdgeValkeyUsageCollector.
 * A pasted Redis address is not billed here.
 */
class EdgeRedisCost
{
    /**
     * @return array{awake_seconds: int, rest_commands: int, cents: int}
     */
    public function forOrganization(Organization $organization, CarbonInterface $from, CarbonInterface $to): array
    {
        $rows = EdgeRedisUsage::query()
            ->where('organization_id', $organization->id)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('site_id')
            ->selectRaw('site_id, SUM(awake_seconds) AS seconds, SUM(rest_commands) AS rest')
            ->get();
        $seconds = $rows->pluck('seconds', 'site_id')->map(fn ($v): int => (int) $v)->all();
        $rest = (int) $rows->sum('rest');

        return [
            'awake_seconds' => array_sum($seconds),
            'rest_commands' => $rest,
            'cents' => $this->valkeyCents($organization, $seconds) + $this->restCents($rest),
        ];
    }

    /** REST commands (valkey-gateway rest.go), per 100,000 at the customer price. Not capped by the class. */
    public function restCents(int $commands): int
    {
        return $commands > 0
            ? UsagePrice::cents($commands / 100_000 * UsagePrice::cost('valkey_rest_millicents_per_hundred_thousand'))
            : 0;
    }

    /**
     * dply Valkey (T-021): awake seconds times the class's customer price per
     * second (EdgeValkey::spec, cost + margin), capped at the class's monthly
     * price per app. The class is the one the
     * app has now. ponytail: a mid-month resize prices the whole month at the
     * new class; keep seconds per class if that matters.
     *
     * Exact per app, then rounded once to the nearest cent for the invoice
     * (Stripe needs whole cents). Rounding each app up would bill minutes of
     * use as a full cent.
     *
     * @param  array<string, int>  $secondsBySite
     */
    public function valkeyCents(Organization $organization, array $secondsBySite): int
    {
        $secondsBySite = array_filter($secondsBySite);
        if ($secondsBySite === []) {
            return 0;
        }
        $cents = 0.0;
        Site::query()->where('organization_id', $organization->id)->whereIn('id', array_keys($secondsBySite))->each(function (Site $site) use ($secondsBySite, &$cents): void {
            foreach (EdgeContainerConnections::for($site) as $connection) {
                if ($connection['kind'] !== 'redis' || ! EdgeValkey::isTarget($connection['target'])) {
                    continue;
                }
                $class = EdgeValkey::spec((string) $connection['plan']);
                $cents += min($class['cap_cents'], $secondsBySite[$site->id] * $class['per_second'] * 100);

                return;
            }
        });

        return (int) round($cents);
    }
}
