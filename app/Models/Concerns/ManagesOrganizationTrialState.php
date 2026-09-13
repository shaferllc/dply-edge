<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Subscription-lifecycle helpers for the app-wide billing banner.
 *
 * The 14-day trial and its soft/hard pause ladder were dropped on 2026-09-11.
 * Nothing enforced the pause (canDeploy() and friends had no callers), the
 * banner told free orgs their deploys were paused and "agents" disconnected,
 * and Edge's free allowance — one site, no card — replaced the trial.
 */
trait ManagesOrganizationTrialState
{
    /**
     * True when the subscription is canceled but still inside the period the
     * customer already paid for — full access continues, billing stops at
     * {@see subscriptionEndsAt}.
     */
    public function onSubscriptionGracePeriod(): bool
    {
        $subscription = $this->subscription('default');

        return $subscription !== null && $subscription->onGracePeriod();
    }

    /**
     * The date a canceled subscription's access ends. Null when not canceled.
     */
    public function subscriptionEndsAt(): ?CarbonInterface
    {
        $subscription = $this->subscription('default');
        if ($subscription === null) {
            return null;
        }

        $endsAt = data_get($subscription->getAttributes(), 'ends_at');
        if ($endsAt === null) {
            return null;
        }

        return $endsAt instanceof CarbonInterface ? $endsAt : CarbonImmutable::parse($endsAt);
    }
}
