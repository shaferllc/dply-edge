<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Models\Organization;
use App\Modules\Edge\Support\EdgeBuildMinutes;
use Carbon\CarbonInterface;

/**
 * Trial spending cap (subscription.standard.trial.spending_limit_cents).
 * Paid plans bill overage on the invoice; a trial has not been charged yet,
 * every usage cost we can price (delivery past the allowance, build minutes,
 * container compute, Valkey, KV, databases, D1/Queues, Realtime) counts,
 * from the day the trial started. Past the cap new builds stop
 * (BuildEdgeSiteJob) and the hourly dply:billing:enforce pauses the org
 * (OrganizationBillingEnforcer) until the trial converts.
 */
final class StarterUsageBudget
{
    public function __construct(
        private EdgeContainerComputeCost $compute,
        private EdgeOrganizationUsageReader $usage,
        private EdgeUsageCostCalculator $calculator,
        private EdgeRedisCost $redis,
        private EdgeKvCost $kv,
        private EdgeAppDatabaseCost $databases,
        private EdgeRealtimeCost $realtime,
        private EdgeDataUsageCost $data,
        private EdgePlatformUsageCost $platform,
    ) {}

    /**
     * @return array{used_cents: int, limit_cents: int|null, exhausted: bool}
     */
    public function status(Organization $organization): array
    {
        $tier = $organization->tierAllowances();
        // A trial (card or not) is capped: a trialing subscription counts as
        // paid, but nothing has been charged yet.
        $trial = $organization->onTrialPlan();
        $limit = $trial ? config('subscription.standard.trial.spending_limit_cents') : ($tier['spending_limit_cents'] ?? null);
        if ($limit === null || (! $trial && $organization->onAnyPaidPlan())) {
            return ['used_cents' => 0, 'limit_cents' => null, 'exhausted' => false];
        }

        $used = $this->usedCents($organization, $tier);

        return [
            'used_cents' => $used,
            'limit_cents' => (int) $limit,
            'exhausted' => $used >= (int) $limit,
        ];
    }

    /**
     * @param  array{used_cents: int, limit_cents: int|null, exhausted: bool}  $status
     */
    public function alertKind(array $status): ?string
    {
        if ($status['limit_cents'] === null) {
            return null;
        }
        if ($status['exhausted']) {
            return 'over';
        }
        if ($status['limit_cents'] > 0 && ($status['used_cents'] / $status['limit_cents']) >= 0.8) {
            return 'warn';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $tier
     */
    private function usedCents(Organization $organization, array $tier): int
    {
        [$start, $end] = $this->window($organization);
        $compute = $this->compute->forOrganization($organization, $start, $end)['cents'];
        $redis = $this->redis->forOrganization($organization, $start, $end)['cents'];
        $kv = $this->kv->forOrganization($organization, $start, $end)['cents'];
        $databases = $this->databases->forOrganization($organization, $start, $end)['cents'];
        $realtime = $this->realtime->forOrganization($organization, $start, $end)['cents'];
        $data = $this->data->forOrganization($organization, $start, $end)['cents'];
        $platform = $this->platform->forOrganization($organization, $start, $end)['cents'];
        $totals = $this->usage->totalsForOrganization($organization, $start, $end);
        $delivery = $this->calculator->estimate($totals, 1, $tier)['subtotal_cents'];
        $millicents = (int) ($tier['build_minute_credit_millicents'] ?? 1000);
        $builds = (int) ceil(EdgeBuildMinutes::usedThisMonth($organization, $start) * $millicents / 1000);

        return $compute + $delivery + $builds + $redis + $kv + $databases + $realtime + $data + $platform;
    }

    /**
     * A trial counts from the day it started, not the calendar month, so the
     * cap does not reset on the 1st mid-trial.
     *
     * @return array{0: CarbonInterface, 1: CarbonInterface}
     */
    private function window(Organization $organization): array
    {
        $ends = $organization->planTrialEndsAt();
        if ($ends === null) {
            return $this->usage->currentMonthWindow();
        }

        return [$ends->copy()->subDays((int) config('subscription.standard.trial.days', 5))->startOfDay(), now()->endOfDay()];
    }
}
