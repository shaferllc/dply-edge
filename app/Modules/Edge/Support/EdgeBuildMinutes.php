<?php

declare(strict_types=1);

namespace App\Modules\Edge\Support;

use App\Models\EdgeDeployment;
use App\Models\Organization;
use Carbon\CarbonInterface;

/**
 * Build time an org used, for usage billing: every second of every build,
 * at dply.edge.usage_billing.build_millicents_per_minute (cost), priced by
 * UsagePrice. No per-plan allowance; the plan's usage credit covers it.
 */
final class EdgeBuildMinutes
{
    /** Build seconds on the days $from..$to (inclusive) — a billing period. */
    public static function secondsBetween(Organization|string $organization, CarbonInterface $from, CarbonInterface $to): int
    {
        $organizationId = $organization instanceof Organization ? (string) $organization->id : $organization;

        return (int) EdgeDeployment::query()
            ->where('organization_id', $organizationId)
            ->where('created_at', '>=', $from->copy()->startOfDay())
            ->where('created_at', '<=', $to->copy()->endOfDay())
            ->whereNotNull('build_seconds')
            ->sum('build_seconds');
    }

    /** Cost of build seconds, in millicents. */
    public static function costMillicents(int $seconds): float
    {
        return $seconds / 60 * max(0.0, (float) config('dply.edge.usage_billing.build_millicents_per_minute', 0));
    }
}
