<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Modules\Billing\Services\SubscriptionPlanResolver;
use Carbon\CarbonInterface;
use Laravel\Cashier\Subscription;

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
        return (string) $this->tierAllowances()['label'];
    }

    /**
     * The tier whose price is on the subscription: `starter`, `pro`, `team`,
     * `enterprise`, or null (no subscription, or a pre-tier per-site one the
     * next billing sync moves onto a tier).
     */
    public function subscribedTier(): ?string
    {
        if ($this->onEnterpriseSubscription()) {
            return 'enterprise';
        }
        foreach (SubscriptionPlanResolver::PAID_TIERS as $tier) {
            if ($this->subscriptionMatchesAnyPrice([config('subscription.standard.stripe.tier_'.$tier)])) {
                return $tier;
            }
        }

        return null;
    }

    /**
     * Tier used for allowances and feature gates (ruling r-f17p5zgeh120cm5t):
     * a comped org is Team; a subscription's tier (a Stripe trial reads as
     * its tier: valid() is true while trialing); a pre-tier per-site
     * subscription reads as Pro until the sync moves it; an org on its
     * card-less trial (organizations.trial_ends_at) gets the trial tier.
     * Anything else is `none`: no plan, paused.
     */
    public function billingTier(): string
    {
        if ($this->isComped()) {
            return 'team';
        }

        return $this->subscribedTier()
            ?? ($this->onStandardSubscription() ? 'pro' : null)
            ?? ($this->onGenericTrial() ? (string) config('subscription.standard.trial.tier', 'pro') : 'none');
    }

    /** False once the trial is over and nothing is paid: the org is paused. */
    public function hasPlan(): bool
    {
        return $this->billingTier() !== 'none';
    }

    /** dply's own orgs and hand-picked ones: Team, no bill. */
    public function isComped(): bool
    {
        $until = $this->getAttribute('comped_until');

        return $until !== null && $until->isFuture();
    }

    /**
     * On a trial, card or not: capped by the trial spending limit, and
     * shown as a trial in billing. Comped and paid orgs are not.
     */
    public function onTrialPlan(): bool
    {
        if ($this->isComped()) {
            return false;
        }
        $subscription = $this->liveSubscription();
        if ($subscription !== null) {
            return $subscription->onTrial();
        }

        return $this->onGenericTrial();
    }

    /** When the trial ends (Stripe's or the card-less one), or null. */
    public function planTrialEndsAt(): ?CarbonInterface
    {
        $subscription = $this->subscription('default');
        if ($subscription !== null && $subscription->onTrial()) {
            return $subscription->trial_ends_at;
        }

        return $this->onGenericTrial() ? $this->trial_ends_at : null;
    }

    /**
     * Whether Checkout should start a trial: only an org that has never had
     * one or a subscription, whose owners have not had one on another org.
     */
    public function eligibleForTrial(): bool
    {
        if ($this->trial_ends_at !== null || $this->subscriptions()->exists()) {
            return false;
        }
        $owners = $this->users()->wherePivot('role', 'owner')->pluck('users.id');

        return ! static::query()
            ->whereKeyNot($this->getKey())
            ->whereHas('users', fn ($q) => $q->whereIn('users.id', $owners)->where('organization_user.role', 'owner'))
            ->where(fn ($q) => $q->whereNotNull('trial_ends_at')->orWhereHas('subscriptions'))
            ->exists();
    }

    /**
     * @return array<string, mixed> One `subscription.standard.tiers.*` record.
     */
    public function tierAllowances(): array
    {
        return (array) config('subscription.standard.tiers.'.$this->billingTier());
    }

    /**
     * True when this org has any active paid subscription — Standard or Enterprise.
     * Used as the "paying customer" gate by feature flags, API token creation, etc.
     */
    public function onAnyPaidPlan(): bool
    {
        return $this->isComped() || $this->onStandardSubscription() || $this->onEnterpriseSubscription();
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
            $stripe['edge_lb_endpoint'] ?? null,
            $stripe['tier_starter'] ?? null,
            $stripe['tier_pro'] ?? null,
            $stripe['tier_team'] ?? null,
            $stripe['team_seat'] ?? null,
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
     * The subscription while it counts: valid (active, trialing, canceled but
     * paid through), or past due while Stripe retries a paying customer's
     * card. The first charge after a trial failing is not a paying customer
     * yet, so that one does not count (ruling r-f17p5zgeh120cm5t).
     */
    public function liveSubscription(): ?Subscription
    {
        $subscription = $this->subscription('default');
        if ($subscription === null) {
            return null;
        }
        if ($subscription->valid()) {
            return $subscription;
        }
        $firstChargeAfterTrial = $subscription->trial_ends_at !== null && $subscription->trial_ends_at->gt(now()->subDays(35));

        return $subscription->pastDue() && ! $firstChargeAfterTrial ? $subscription : null;
    }

    /**
     * @param  list<?string>  $priceIds
     */
    private function subscriptionMatchesAnyPrice(array $priceIds): bool
    {
        $subscription = $this->liveSubscription();
        if ($subscription === null) {
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
     * The organization role that can only view (ruling r-jnv0r3qf1xk49kmc:
     * view-only members are free). Such members and their invitations never
     * take a seat: not in the bill, not against a plan's hard seat cap.
     */
    public const VIEW_ONLY_ROLE = 'viewer';

    /** Members that are seats: everyone but view-only members. */
    public function seatCount(): int
    {
        return $this->users()->wherePivot('role', '!=', self::VIEW_ONLY_ROLE)->count();
    }

    /** Seats plus unexpired invitations that would become seats: what the hard cap checks. */
    public function seatsWithPendingInvites(): int
    {
        return $this->seatCount()
            + $this->invitations()->where('expires_at', '>', now())->where('role', '!=', self::VIEW_ONLY_ROLE)->count();
    }

    /**
     * Tier seat cap: hard on tiers without a per-seat price (Starter, Pro), none
     * where extra seats are billed (Team) or unlimited (Enterprise). Beta
     * status changes nothing (ruling r-jnv0r3qf1xk49kmc: no beta perks).
     */
    public function seatCapFromSubscription(): ?int
    {
        $tier = $this->tierAllowances();
        if (($tier['extra_seat_cents'] ?? null) !== null || ($tier['seats'] ?? null) === null) {
            return null;
        }

        return (int) $tier['seats'];
    }

    /**
     * Maximum seats (members + pending invites, view-only ones excluded);
     * null means unlimited.
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
