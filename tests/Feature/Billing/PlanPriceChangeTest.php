<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Billing\Services\DesiredBillingState;
use App\Modules\Billing\Services\StripeSubscriptionSyncer;
use App\Modules\Billing\Services\SubscriptionPlanResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;

uses(RefreshDatabase::class);

/*
 * After a plan-price change (dply:billing:provision-stripe archives the old
 * price), subscribers on the archived price must keep their plan: listed in
 * STRIPE_PRICE_*_LEGACY they are grandfathered; unlisted they are reported,
 * never paused and never repriced on a guess (docs/pricing-review.md §8.11).
 */
beforeEach(function () {
    config([
        'subscription.standard.stripe.tier_starter' => 'price_starter_new',
        'subscription.standard.stripe.tier_pro' => 'price_pro_new',
        'subscription.standard.stripe.tier_team' => 'price_team_new',
    ]);
    Exceptions::fake();
});

function orgOnPrice(string $price): Organization
{
    $org = Organization::factory()->create();
    Subscription::factory()->withPrice($price)->active()->create(['organization_id' => $org->id]);

    return $org->fresh();
}

test('a grandfathered starter price listed as legacy still reads as starter, and the syncer leaves it on it', function () {
    config(['subscription.standard.stripe.legacy_tiers.starter' => 'price_starter_2025, price_starter_old']);
    $org = orgOnPrice('price_starter_old');

    expect($org->subscribedTier())->toBe('starter')
        ->and($org->billingTier())->toBe('starter')
        ->and($org->onStandardSubscription())->toBeTrue();

    $desired = DesiredBillingState::fromPlanAndUsage(plan: ['key' => 'starter', 'label' => 'Starter', 'price_cents' => 500]);
    expect(app(StripeSubscriptionSyncer::class)->reconcile($org, $desired))->toBe([]);
    Exceptions::assertNothingReported();
});

test('an unlisted archived plan price is reported and reads as Pro, not paused as no plan', function () {
    $org = orgOnPrice('price_team_archived');

    expect($org->subscribedTier())->toBeNull()
        ->and($org->billingTier())->toBe('pro')
        ->and($org->hasPlan())->toBeTrue();
    Exceptions::assertReported(fn (RuntimeException $e) => str_contains($e->getMessage(), 'price_team_archived'));
});

test('the syncer never moves a subscription on an unrecognised price onto a guessed plan', function () {
    $org = orgOnPrice('price_team_archived');
    $desired = DesiredBillingState::fromPlanAndUsage(plan: ['key' => 'pro', 'label' => 'Pro', 'price_cents' => 2000]);

    // A move would swapAndInvoice through Stripe; returning [] means it did nothing.
    expect(app(StripeSubscriptionSyncer::class)->reconcile($org, $desired))->toBe([]);
    Exceptions::assertReported(fn (RuntimeException $e) => str_contains($e->getMessage(), 'Not syncing'));
});

test('a legacy plan price is never stripped as a retired price', function () {
    config([
        'subscription.standard.stripe.legacy_tiers.pro' => 'price_pro_old',
        'subscription.standard.stripe.retired' => ['price_pro_old', 'price_serverless'],
    ]);

    expect(SubscriptionPlanResolver::retiredPriceIds())->toBe(['price_serverless'])
        ->and(SubscriptionPlanResolver::tierOfPrice('price_pro_old'))->toBe('pro')
        // An archived price keeps the provisioner's metadata.
        ->and(SubscriptionPlanResolver::tierOfPrice('price_x', ['dply_role' => 'tier_team']))->toBe('team')
        ->and(SubscriptionPlanResolver::tierOfPrice('price_x', ['dply_role' => 'team_seat']))->toBeNull();
});
