<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Models\Organization;
use App\Notifications\UsageThresholdNotice;
use Illuminate\Support\Facades\Notification;

/**
 * Emails a paying org's owners when this period's usage passes 50%, 80% and
 * 100% of its usage soft limit (organizations.usage_alert_cents, default twice
 * the plan price). Each threshold is sent once per billing period; nothing is
 * stopped — it is a heads-up, the usage still bills.
 */
final class UsageAlerts
{
    public const THRESHOLDS = [100, 80, 50];

    /** The org's soft limit in cents: its own, else twice the plan price. */
    public static function limitCents(Organization $organization, DesiredBillingState $state): int
    {
        return (int) ($organization->usage_alert_cents ?? 2 * $state->planPriceCents);
    }

    /** @return int|null the threshold emailed, if any */
    public function check(Organization $organization, DesiredBillingState $state): ?int
    {
        if (! in_array($state->planKey, ['pro', 'team'], true) || $organization->isComped() || $organization->onTrialPlan()) {
            return null;
        }
        $limit = self::limitCents($organization, $state);
        $used = $state->usageLineCents();
        if ($limit <= 0 || $used <= 0) {
            return null;
        }

        $period = (string) ($state->edgeUsageEstimate['period_start'] ?? now()->startOfMonth()->toDateString());
        $sent = (array) $organization->usage_alerts;
        $already = ($sent['period'] ?? null) === $period ? (int) ($sent['pct'] ?? 0) : 0;
        $reached = collect(self::THRESHOLDS)->first(fn (int $pct): bool => $used * 100 >= $limit * $pct);
        if ($reached === null || $reached <= $already) {
            return null;
        }

        $owners = $organization->users()->wherePivot('role', 'owner')->get();
        if ($owners->isNotEmpty()) {
            Notification::send($owners, new UsageThresholdNotice($organization, $reached, $used, $limit));
        }
        $organization->forceFill(['usage_alerts' => ['period' => $period, 'pct' => $reached]])->save();

        return $reached;
    }
}
