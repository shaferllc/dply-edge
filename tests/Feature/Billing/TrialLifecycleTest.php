<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\User;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Billing\Services\StarterUsageBudget;
use App\Notifications\OrganizationBillingNotice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

// No Free plan: a 5-day Pro trial, then pause, then (when switched on)
// deletion after 7 days. Ruling r-f17p5zgeh120cm5t.

function trialOrg(array $attributes = []): Organization
{
    $org = Organization::factory()->create($attributes);
    $org->users()->attach(User::factory()->create()->id, ['role' => 'owner']);

    return $org->fresh();
}

test('an org runs as Pro on its trial, Team when comped, and has no plan after', function () {
    $new = trialOrg(['trial_ends_at' => null]);
    expect($new->billingTier())->toBe('none')->and($new->hasPlan())->toBeFalse()->and($new->eligibleForTrial())->toBeTrue();

    $trial = trialOrg(['trial_ends_at' => now()->addDays(3)]);
    expect($trial->billingTier())->toBe('pro')->and($trial->onTrialPlan())->toBeTrue()->and($trial->eligibleForTrial())->toBeFalse();

    $over = trialOrg(['trial_ends_at' => now()->subDay()]);
    expect($over->billingTier())->toBe('none')->and($over->eligibleForTrial())->toBeFalse();

    $comped = trialOrg(['comped_until' => now()->addYear()]);
    expect($comped->billingTier())->toBe('team')->and($comped->onAnyPaidPlan())->toBeTrue()->and($comped->onTrialPlan())->toBeFalse();
});

test('a card trial reads as its tier and is still capped', function () {
    config(['subscription.standard.stripe.tier_pro' => 'price_tier_pro']);
    $org = trialOrg();
    Subscription::factory()->withPrice('price_tier_pro')->create(['organization_id' => $org->id, 'stripe_status' => 'trialing', 'trial_ends_at' => now()->addDays(5)]);
    $org = $org->fresh();

    expect($org->billingTier())->toBe('pro')
        ->and($org->onTrialPlan())->toBeTrue()
        ->and(app(StarterUsageBudget::class)->status($org)['limit_cents'])->toBe(500);
});

test('an owner gets one trial: a second org of theirs does not', function () {
    $owner = User::factory()->create();
    $first = Organization::factory()->create(['trial_ends_at' => now()->addDays(2)]);
    $first->users()->attach($owner->id, ['role' => 'owner']);
    $second = Organization::factory()->create(['trial_ends_at' => null]);
    $second->users()->attach($owner->id, ['role' => 'owner']);

    expect($second->fresh()->eligibleForTrial())->toBeFalse();
});

test('the enforcer emails once, pauses an unpaid org, and resumes it when it has a plan', function () {
    Notification::fake();
    $org = trialOrg(['trial_ends_at' => now()->addHours(12)]);

    $this->artisan('dply:billing:enforce')->assertSuccessful();
    $this->artisan('dply:billing:enforce')->assertSuccessful();
    Notification::assertSentTimes(OrganizationBillingNotice::class, 2); // started + ending, once each
    expect(array_keys($org->fresh()->billing_notices))->toContain('trial_started', 'trial_ending');

    $this->travel(1)->days();
    $this->artisan('dply:billing:enforce')->assertSuccessful();
    $org = $org->fresh();
    expect($org->billing_paused_at)->not->toBeNull();
    Notification::assertSentTimes(OrganizationBillingNotice::class, 3);

    $org->forceFill(['comped_until' => now()->addMonth()])->save();
    $this->artisan('dply:billing:enforce')->assertSuccessful();
    expect($org->fresh()->billing_paused_at)->toBeNull();
});

test('an org that never started its trial is left alone', function () {
    Notification::fake();
    $org = trialOrg(['trial_ends_at' => null]);

    $this->artisan('dply:billing:enforce')->assertSuccessful();

    expect($org->fresh()->billing_paused_at)->toBeNull();
    Notification::assertNothingSent();
});

test('deletion is off by default; switched on it warns, then purges once after the keep period', function () {
    Notification::fake();
    $org = trialOrg(['trial_ends_at' => now()->subDays(20), 'billing_paused_at' => now()->subDays(8)]);

    $this->artisan('dply:billing:enforce --dry-run')->expectsOutputToContain('would purge (purge is off)')->assertSuccessful();
    $this->artisan('dply:billing:enforce')->assertSuccessful();
    expect($org->fresh()->billing_notices)->toBeNull();
    Notification::assertNothingSent();

    config(['subscription.standard.trial.purge_enabled' => true]);
    $this->artisan('dply:billing:enforce')->expectsOutputToContain(': purge')->assertSuccessful();
    $this->artisan('dply:billing:enforce')->doesntExpectOutputToContain(': purge')->assertSuccessful();

    Notification::assertSentTimes(OrganizationBillingNotice::class, 1); // deleting
    expect($org->fresh()->billing_notices)->toHaveKey('purged');
});

test('comp command comps and un-comps an org', function () {
    $org = trialOrg(['trial_ends_at' => now()->addDay()]);

    $this->artisan('dply:billing:comp', ['organization' => $org->slug])->assertSuccessful();
    expect($org->fresh()->isComped())->toBeTrue();

    $this->artisan('dply:billing:comp', ['organization' => $org->id, '--off' => true])->assertSuccessful();
    expect($org->fresh()->isComped())->toBeFalse();
});
