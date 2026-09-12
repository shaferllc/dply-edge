<?php

namespace Tests\Feature\Components\TrialPauseBannerTest;

use App\Models\Organization;
use App\Modules\Billing\Models\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Config;

uses(RefreshDatabase::class);

// The trial and its pause ladder were dropped (2026-09-11); the banner now only
// reminds a canceled subscription that it is in its paid-through grace period.

function renderTrialPauseBanner(Organization $org): string
{
    return Blade::render('<x-trial-pause-banner :organization="$organization" />', [
        'organization' => $org->fresh(),
    ]);
}

test('an org with no subscription shows no banner', function () {
    $org = Organization::factory()->create(['trial_ends_at' => now()->subDays(60)]);

    expect(trim(renderTrialPauseBanner($org)))->toBe('');
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
