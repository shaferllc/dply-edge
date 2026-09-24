<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Models\Organization;
use App\Modules\Edge\Support\EdgeBuildMinutes;

/**
 * Free starter spending cap. Paid plans bill overage on the invoice. An org
 * with no card cannot, so compute, delivery past the allowance, and build
 * minutes pause new builds once spending_limit_cents is reached.
 *
 * Called from BuildEdgeSiteJob::handle before a build starts.
 */
final class StarterUsageBudget
{
    public function __construct(
        private EdgeContainerComputeCost $compute,
        private EdgeOrganizationUsageReader $usage,
        private EdgeUsageCostCalculator $calculator,
        private EdgeRedisCost $redis,
    ) {}

    /**
     * @return array{used_cents: int, limit_cents: int|null, exhausted: bool}
     */
    public function status(Organization $organization): array
    {
        $tier = $organization->tierAllowances();
        $limit = $tier['spending_limit_cents'] ?? null;
        if ($limit === null || $organization->onAnyPaidPlan()) {
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
        [$start, $end] = $this->usage->currentMonthWindow();
        $compute = $this->compute->forOrganization($organization, $start, $end)['cents'];
        $redis = $this->redis->forOrganization($organization, $start, $end)['cents'];
        $totals = $this->usage->totalsForOrganization($organization, $start, $end);
        $delivery = $this->calculator->estimate($totals, 1, $tier)['subtotal_cents'];
        $millicents = (int) ($tier['build_minute_credit_millicents'] ?? 1000);
        $builds = (int) ceil(EdgeBuildMinutes::usedThisMonth($organization) * $millicents / 1000);

        return $compute + $delivery + $builds + $redis;
    }
}
