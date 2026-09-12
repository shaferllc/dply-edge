<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Modules\Billing\Services\SubscriptionPlanResolver;
use Laravel\Cashier\Billable;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesOrganizationSubscription
{
    /**
     * The plan record the org's quota ceilings are read from. dply-edge has no
     * paid plan tiers, so this is always the Free allowance — paying orgs are
     * uncapped upstream ({@see ManagesOrganizationQuotas::quotaLimit()}).
     *
     * @return array{key: string, label: string, price_cents: int, max_sites: ?int, max_edge_apps: ?int, max_functions: ?int}
     */
    public function currentSubscriptionPlan(): array
    {
        return app(SubscriptionPlanResolver::class)->resolveByKey('free');
    }

    public function planTierLabel(): string
    {
        if ($this->onEnterpriseSubscription()) {
            return 'Enterprise';
        }
        if ($this->onStandardSubscription()) {
            return 'Standard';
        }

        return 'Free';
    }

    /**
     * True when this org has any active paid subscription — Standard or Enterprise.
     * Used as the "paying customer" gate by feature flags, API token creation, etc.
     */
    public function onAnyPaidPlan(): bool
    {
        return $this->onStandardSubscription() || $this->onEnterpriseSubscription();
    }

    /**
     * True when the org has an active dply Standard subscription — i.e. it
     * carries an Edge site price (static/hybrid or SSR, monthly or yearly) or
     * the Edge usage price. A free org with no live Edge sites has no Stripe
     * subscription at all and returns false here.
     */
    public function onStandardSubscription(): bool
    {
        return $this->subscriptionMatchesAnyPrice($this->standardStripePriceIds());
    }

    /**
     * The Edge Stripe price IDs that mark a Standard subscription, across both
     * billing intervals.
     *
     * @return list<?string>
     */
    private function standardStripePriceIds(): array
    {
        $stripe = (array) config('subscription.standard.stripe', []);

        $ids = [
            $stripe['edge'] ?? null,
            $stripe['edge_yearly'] ?? null,
            // An SSR-only subscription is paying too — without these it was
            // never synced and stayed capped at the free allowance.
            $stripe['edge_ssr'] ?? null,
            $stripe['edge_ssr_yearly'] ?? null,
            $stripe['edge_usage'] ?? null,
        ];

        return array_map(
            static fn ($id): ?string => is_string($id) ? $id : null,
            $ids,
        );
    }

    /**
     * True when the org has a sales-led Enterprise subscription.
     */
    public function onEnterpriseSubscription(): bool
    {
        return $this->subscriptionMatchesAnyPrice([
            config('subscription.enterprise.stripe_price_id'),
        ]);
    }

    /**
     * @param  list<?string>  $priceIds
     */
    private function subscriptionMatchesAnyPrice(array $priceIds): bool
    {
        $subscription = $this->subscription('default');
        if (! $subscription || ! $subscription->valid()) {
            return false;
        }

        foreach ($priceIds as $priceId) {
            if (is_string($priceId) && $priceId !== '' && $subscription->hasPrice($priceId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Seat cap from Stripe is not part of the Standard pricing story — every
     * paid plan gets unlimited team members. Kept as a stub returning null so
     * {@see effectiveMemberSeatCap} can fall through to the env-level cap.
     */
    public function seatCapFromSubscription(): ?int
    {
        return null;
    }

    /**
     * Maximum members + pending invites; null means unlimited.
     */
    public function effectiveMemberSeatCap(): ?int
    {
        $env = config('dply.max_organization_members');
        $stripeCap = $this->seatCapFromSubscription();
        if ($stripeCap !== null && $env !== null) {
            return min($env, $stripeCap);
        }

        return $stripeCap ?? $env;
    }
}
