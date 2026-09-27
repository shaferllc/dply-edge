<?php

namespace Tests\Feature\Components\TrialPauseBannerTest;

use App\Models\Organization;
use App\Modules\Billing\Models\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Config;

uses(RefreshDatabase::class);

// No Free plan (ruling r-f17p5zgeh120cm5t): the banner shows where an org is
// in its trial, when it is paused, and a canceled subscription's grace period.

function renderTrialPauseBanner(Organization $org): string
{
    return Blade::render('<x-trial-pause-banner :organization="$organization" />', [
        'organization' => $org->fresh(),
    ]);
}

test('an org whose trial ended is told to choose a plan, not to start a trial', function () {
    $org = Organization::factory()->create(['trial_ends_at' => now()->subDays(60)]);

    expect(renderTrialPauseBanner($org))->toContain('Your trial has ended')->not->toContain('Start your');
});

test('a new org is asked to start its trial, one on trial sees when it ends, a paused one sees it is paused', function () {
    expect(renderTrialPauseBanner(Organization::factory()->create(['trial_ends_at' => null])))->toContain('Start your 5-day trial');
    expect(renderTrialPauseBanner(Organization::factory()->create(['trial_ends_at' => now()->addDays(3)])))->toContain('Trial until');
    expect(renderTrialPauseBanner(Organization::factory()->create(['trial_ends_at' => now()->subDays(2), 'billing_paused_at' => now()->subDay()])))->toContain('Your sites are paused');
});

test('a comped org shows no banner', function () {
    expect(trim(renderTrialPauseBanner(Organization::factory()->create(['comped_until' => now()->addYear()]))))->toBe('');
});

test('subscribed org shows no banner', function () {
    Config::set('subscription.standard.stripe.edge', 'price_sub_plan');
    $org = Organization::factory()->create(['trial_ends_at' => null]);
    Subscription::factory()
        ->withPrice('price_sub_plan')
        ->active()
        ->create(['organization_id' => $org->id]);

    $html = renderTrialPauseBanner($org);

    $this->assertStringNotContainsString('trial', strtolower($html));
    $this->assertStringNotContainsString('paused', strtolower($html));
    $this->assertStringNotContainsString('subscription ends', strtolower($html));
});

test('grace period shows resume banner with end date', function () {
    Config::set('subscription.standard.stripe.edge', 'price_sub_plan');
    $org = Organization::factory()->create(['trial_ends_at' => null]);
    Subscription::factory()
        ->withPrice('price_sub_plan')
        ->create([
            'organization_id' => $org->id,
            'stripe_status' => 'active',
            'ends_at' => now()->addDays(12), // canceled, still in grace
        ]);

    $html = renderTrialPauseBanner($org);

    $this->assertStringContainsString('Your subscription ends', $html);
    $this->assertStringContainsString('Resume subscription', $html);
    $this->assertStringContainsString('full access until then', $html);
});
