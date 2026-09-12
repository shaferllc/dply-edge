<?php

use App\Models\Organization;
use App\Modules\Billing\Models\Subscription;
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
