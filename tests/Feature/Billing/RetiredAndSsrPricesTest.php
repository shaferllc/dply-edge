<?php

use App\Models\Organization;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Billing\Services\DesiredBillingState;
use App\Modules\Billing\Services\StripeSubscriptionSyncer;
use App\Modules\Billing\Services\SubscriptionPlanResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
 * Retired product prices must come off customers' subscriptions on sync, and
 * SSR-only subscriptions must count as paying and as yearly when they are.
 */

beforeEach(function () {
    Config::set('subscription.standard.stripe.edge', 'price_edge');
    Config::set('subscription.standard.stripe.edge_yearly', 'price_edge_yearly');
    Config::set('subscription.standard.stripe.edge_ssr', 'price_ssr');
    Config::set('subscription.standard.stripe.edge_ssr_yearly', 'price_ssr_yearly');
    Config::set('subscription.standard.stripe.retired', ['price_old_pro', null, '', 'price_old_realtime']);
});

/** A multi-price subscription — Cashier reads its prices from the items. */
function subscriptionWithPrices(array $prices): Subscription
{
    $organization = Organization::factory()->create();
    $subscription = Subscription::factory()->active()->create([
        'organization_id' => $organization->id,
        'stripe_price' => null,
    ]);

    foreach ($prices as $price) {
        $subscription->items()->create([
            'stripe_id' => 'si_'.Str::random(12),
            'stripe_product' => 'prod_test',
            'stripe_price' => $price,
            'quantity' => 1,
        ]);
    }

    return $subscription->fresh();
}

test('an ssr-only subscription counts as paying', function () {
    $subscription = subscriptionWithPrices(['price_ssr']);

    expect(Organization::find($subscription->organization_id)->onAnyPaidPlan())->toBeTrue();
});

test('an ssr yearly price marks the subscription yearly', function () {
    expect(SubscriptionPlanResolver::isYearly(subscriptionWithPrices(['price_ssr_yearly'])))->toBeTrue()
        ->and(SubscriptionPlanResolver::isYearly(subscriptionWithPrices(['price_ssr'])))->toBeFalse();
});

test('retired price ids skip unset env vars', function () {
    expect(SubscriptionPlanResolver::retiredPriceIds())->toBe(['price_old_pro', 'price_old_realtime']);
});

test('the sync strips retired prices that sit beside an edge line', function () {
    $subscription = subscriptionWithPrices(['price_edge', 'price_old_pro', 'price_old_realtime']);

    expect(app(StripeSubscriptionSyncer::class)->retiredPricesToRemove($subscription))
        ->toBe(['price_old_pro', 'price_old_realtime']);
});

test('a subscription holding only retired prices is left for a person to cancel', function () {
    $subscription = subscriptionWithPrices(['price_old_pro']);

    expect(app(StripeSubscriptionSyncer::class)->retiredPricesToRemove($subscription))->toBe([]);
});

test('prices that are neither edge nor retired are left alone', function () {
    $subscription = subscriptionWithPrices(['price_edge', 'price_unrelated']);

    expect(app(StripeSubscriptionSyncer::class)->retiredPricesToRemove($subscription))->toBe([]);
});

/** Records swapAndInvoice instead of calling Stripe. */
function recordingSubscription(array $prices): Subscription
{
    $real = subscriptionWithPrices($prices);
    $fake = new class extends Subscription
    {
        public ?array $swapped = null;

        public function swapAndInvoice($prices, array $options = [])
        {
            $this->swapped = $prices;

            return $this;
        }
    };
    $fake->setRawAttributes($real->getAttributes(), true);
    $fake->exists = true;
    $fake->setRelation('items', $real->items);

    return $fake;
}

function orgWith(Subscription $subscription): Organization
{
    $org = new class extends Organization
    {
        public ?Subscription $fakeSubscription = null;

        public function subscription(string $type = 'default'): ?Laravel\Cashier\Subscription
        {
            return $this->fakeSubscription;
        }
    };
    $org->id = $subscription->organization_id;
    $org->fakeSubscription = $subscription;

    return $org;
}

test('the sync moves a pre-tier per-site subscription onto its tier in one swap', function () {
    Config::set('subscription.standard.stripe.tier_pro', 'price_tier_pro');
    $subscription = recordingSubscription(['price_edge_yearly']);
    $desired = DesiredBillingState::fromPlanAndUsage(
        plan: ['key' => 'pro', 'label' => 'Pro', 'price_cents' => 2000],
        edgeCount: 12, edgeUnitCents: 200, includedSites: 10,
    );

    $changes = app(StripeSubscriptionSyncer::class)->reconcile(orgWith($subscription), $desired);

    expect($subscription->swapped)->toBe([
        'price_tier_pro' => ['quantity' => 1],
        'price_edge' => ['quantity' => 2],
    ])->and($changes[0])->toMatchArray(['action' => 'move', 'to' => 'pro']);
});

test('the sync leaves subscriptions alone until tier prices are provisioned', function () {
    Config::set('subscription.standard.stripe.tier_pro', '');
    $subscription = recordingSubscription(['price_edge']);
    $desired = DesiredBillingState::fromPlanAndUsage(
        plan: ['key' => 'pro', 'label' => 'Pro', 'price_cents' => 2000], edgeCount: 3, edgeUnitCents: 200, includedSites: 10,
    );

    expect(app(StripeSubscriptionSyncer::class)->reconcile(orgWith($subscription), $desired))->toBe([])
        ->and($subscription->swapped)->toBeNull();
});
