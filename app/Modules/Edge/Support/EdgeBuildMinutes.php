<?php

declare(strict_types=1);

namespace App\Modules\Edge\Support;

use App\Models\EdgeDeployment;
use App\Models\Organization;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Build minutes an org used this calendar month — the tier allowance
 * (subscription.standard.tiers.*.build_minutes). Each build rounds up to
 * whole minutes, like the vendors customers compare us with.
 */
final class EdgeBuildMinutes
{
    /** $since overrides the start of the month (a trial counts from its first day). */
    public static function usedThisMonth(Organization|string $organization, ?CarbonInterface $since = null): int
    {
        $organizationId = $organization instanceof Organization ? (string) $organization->id : $organization;

        return (int) EdgeDeployment::query()
            ->where('organization_id', $organizationId)
            ->where('created_at', '>=', $since ?? now()->startOfMonth())
            ->whereNotNull('build_seconds')
            ->sum(DB::raw('CEIL(build_seconds / 60.0)'));
    }

    /** Build minutes on the days $from..$to (inclusive) — a billing period. */
    public static function usedBetween(Organization|string $organization, CarbonInterface $from, CarbonInterface $to): int
    {
        $organizationId = $organization instanceof Organization ? (string) $organization->id : $organization;

        return (int) EdgeDeployment::query()
            ->where('organization_id', $organizationId)
            ->where('created_at', '>=', $from->copy()->startOfDay())
            ->where('created_at', '<=', $to->copy()->endOfDay())
            ->whereNotNull('build_seconds')
            ->sum(DB::raw('CEIL(build_seconds / 60.0)'));
    }

    /**
     * Minutes over the allowance billed in cents (rounded up), or 0 when the
     * tier has no overage price (builds stop instead) or no cap.
     *
     * @param  array<string, mixed>  $tier
     */
    public static function overageCents(int $minutes, array $tier): int
    {
        $included = $tier['build_minutes'] ?? null;
        $millicents = $tier['build_minute_overage_millicents'] ?? null;
        if ($included === null || $millicents === null) {
            return 0;
        }

        return (int) ceil(max(0, $minutes - (int) $included) * (int) $millicents / 1000);
    }

    /**
     * True when the tier stops builds at its allowance and the org is there.
     *
     * @param  array<string, mixed>  $tier
     */
    public static function exhausted(int $minutes, array $tier): bool
    {
        return ($tier['build_minutes'] ?? null) !== null
            && ($tier['build_minute_overage_millicents'] ?? null) === null
            && $minutes >= (int) $tier['build_minutes'];
    }
}
