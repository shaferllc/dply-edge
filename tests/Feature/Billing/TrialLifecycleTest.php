<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\User;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Billing\Services\OrganizationBillingEnforcer;
use App\Modules\Billing\Services\StarterUsageBudget;
use App\Notifications\OrganizationBillingNotice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

// No Free plan: a 5-day trial of the chosen plan, then pause, then deletion
// after 30 days with warnings. Rulings r-f17p5zgeh120cm5t, r-jnv0r3qf1xk49kmc.

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
        ->and(app(StarterUsageBudget::class)->status($org)['limit_cents'])->toBe(200);
});

test('a Starter or Team checkout trials that plan, capped, and the emails name it', function (string $tier, string $label, string $price) {
    Notification::fake();
    config(['subscription.standard.stripe.tier_'.$tier => 'price_tier_'.$tier]);
    $org = trialOrg();
    Subscription::factory()->withPrice('price_tier_'.$tier)->create(['organization_id' => $org->id, 'stripe_status' => 'trialing', 'trial_ends_at' => now()->addDays(5)]);
    $org = $org->fresh();

    expect($org->billingTier())->toBe($tier)
        ->and($org->onTrialPlan())->toBeTrue()
        ->and(app(StarterUsageBudget::class)->status($org)['limit_cents'])->toBe(200);

    $mail = (new OrganizationBillingNotice($org, 'trial_started', now()->addDays(5)))->toMail($org->users()->first());
    expect(implode(' ', $mail->introLines))->toContain('trial of '.$label)->toContain($label.' ('.$price.')')->not->toContain('Pro');
})->with([
    'starter' => ['starter', 'Starter', '$5/mo'],
    'team' => ['team', 'Team', '$49/mo'],
]);

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

test('switched off, deletion only reports what it would delete', function () {
    Notification::fake();
    config(['subscription.standard.trial.purge_enabled' => false]);
    $org = trialOrg(['trial_ends_at' => now()->subDays(40), 'billing_paused_at' => now()->subDays(31)]);

    $this->artisan('dply:billing:enforce --dry-run')->expectsOutputToContain('would purge (purge is off)')->assertSuccessful();
    $this->artisan('dply:billing:enforce')->assertSuccessful();
    expect($org->fresh()->billing_notices)->toBeNull();
    Notification::assertNothingSent();
});

test('on by default, deletion comes 30 days after the pause, warned 7 days and 1 day before', function () {
    Notification::fake();
    expect(config('subscription.standard.trial.purge_enabled'))->toBeTrue()
        ->and(config('subscription.standard.trial.keep_data_days'))->toBe(30);
    $org = trialOrg(['trial_ends_at' => now()->subDays(25), 'billing_paused_at' => now()->subDays(20)]);

    $this->artisan('dply:billing:enforce')->assertSuccessful();
    Notification::assertNothingSent();

    $this->travel(3)->days(); // 7 days left
    $this->artisan('dply:billing:enforce')->assertSuccessful();
    Notification::assertSentTimes(OrganizationBillingNotice::class, 1);
    expect($org->fresh()->billing_notices)->toHaveKey('deleting_soon')->not->toHaveKey('deleting');

    $this->travel(6)->days(); // 1 day left
    $this->artisan('dply:billing:enforce')->doesntExpectOutputToContain(': purge')->assertSuccessful();
    Notification::assertSentTimes(OrganizationBillingNotice::class, 2);

    $this->travel(25)->hours();
    $this->artisan('dply:billing:enforce')->expectsOutputToContain(': purge')->assertSuccessful();
    $this->artisan('dply:billing:enforce')->doesntExpectOutputToContain(': purge')->assertSuccessful();
    expect($org->fresh()->billing_notices)->toHaveKey('purged');
});

test('an org already past its keep period is warned first, not deleted in the same run', function () {
    Notification::fake();
    $org = trialOrg(['trial_ends_at' => now()->subDays(60), 'billing_paused_at' => now()->subDays(45)]);

    $this->artisan('dply:billing:enforce')->doesntExpectOutputToContain(': purge')->assertSuccessful();
    Notification::assertSentTimes(OrganizationBillingNotice::class, 1);
    expect(OrganizationBillingEnforcer::deleteAt($org->fresh())->isSameDay(now()->addDays(7)))->toBeTrue();

    $this->travel(145)->hours();
    $this->artisan('dply:billing:enforce')->doesntExpectOutputToContain(': purge')->assertSuccessful();
    Notification::assertSentTimes(OrganizationBillingNotice::class, 2); // deleting, a day out

    $this->travel(25)->hours();
    $this->artisan('dply:billing:enforce')->expectsOutputToContain(': purge')->assertSuccessful();
});

test('resuming clears the deletion warnings, so a later pause warns again', function () {
    Notification::fake();
    $org = trialOrg(['trial_ends_at' => now()->subDays(60), 'billing_paused_at' => now()->subDays(45)]);
    $this->artisan('dply:billing:enforce')->assertSuccessful();
    expect($org->fresh()->billing_notices)->toHaveKey('deleting_soon');

    $org->forceFill(['comped_until' => now()->addDay()])->save();
    $this->artisan('dply:billing:enforce')->assertSuccessful();
    expect($org->fresh()->billing_notices ?? [])->not->toHaveKey('deleting_soon')->not->toHaveKey('deleting');
});

test('comp command comps and un-comps an org', function () {
    $org = trialOrg(['trial_ends_at' => now()->addDay()]);

    $this->artisan('dply:billing:comp', ['organization' => $org->slug])->assertSuccessful();
    expect($org->fresh()->isComped())->toBeTrue();

    $this->artisan('dply:billing:comp', ['organization' => $org->id, '--off' => true])->assertSuccessful();
    expect($org->fresh()->isComped())->toBeFalse();
});

test('a paying customer keeps running while Stripe retries the card; a failed first charge after a trial does not', function () {
    config(['subscription.standard.stripe.tier_pro' => 'price_tier_pro']);
    $payer = trialOrg(['trial_ends_at' => null]);
    Subscription::factory()->withPrice('price_tier_pro')->create(['organization_id' => $payer->id, 'stripe_status' => 'past_due', 'trial_ends_at' => now()->subMonths(3)]);
    expect($payer->fresh()->billingTier())->toBe('pro');

    $converting = trialOrg(['trial_ends_at' => null]);
    Subscription::factory()->withPrice('price_tier_pro')->create(['organization_id' => $converting->id, 'stripe_status' => 'past_due', 'trial_ends_at' => now()->subDay()]);
    expect($converting->fresh()->billingTier())->toBe('none');

    $gone = trialOrg(['trial_ends_at' => null]);
    Subscription::factory()->withPrice('price_tier_pro')->create(['organization_id' => $gone->id, 'stripe_status' => 'unpaid']);
    expect($gone->fresh()->billingTier())->toBe('none');
});
