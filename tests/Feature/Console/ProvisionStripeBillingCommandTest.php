<?php

namespace Tests\Feature\Console\ProvisionStripeBillingCommandTest;

use App\Modules\Billing\Services\StripeBillingProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;

uses(RefreshDatabase::class);

test('dry run lists the plans, the team seat and enterprise without calling stripe', function () {
    Config::set('cashier.secret', 'sk_test_dummy');

    // Each expectsOutputToContain is matched against a single write call, so
    // assert at most one substring per emitted line.
    $this->artisan('dply:billing:provision-stripe', ['--dry-run' => true])
        ->expectsOutputToContain('Dry-run')
        ->expectsOutputToContain('dply Starter — $5.00/mo')
        ->expectsOutputToContain('dply Pro — $20.00/mo')
        ->expectsOutputToContain('dply Team — $49.00/mo')
        ->expectsOutputToContain('dply Team seat — $5.00/mo')
        ->expectsOutputToContain('dply Enterprise')
        ->doesntExpectOutputToContain('dply Edge site')
        ->doesntExpectOutputToContain('dply Edge SSR site')
        ->assertOk();
});

test('fails loudly when stripe secret is missing', function () {
    Config::set('cashier.secret', '');

    $this->artisan('dply:billing:provision-stripe')
        ->expectsOutputToContain('STRIPE_SECRET')
        ->assertFailed();
});

test('format env emits the plan price lines and skips product roles', function () {
    $env = StripeBillingProvisioner::formatEnv([
        StripeBillingProvisioner::ROLE_TIER_STARTER_PRODUCT => 'prod_starter',
        StripeBillingProvisioner::ROLE_TIER_STARTER_MONTHLY => 'price_starter',
        StripeBillingProvisioner::ROLE_TIER_PRO_MONTHLY => 'price_pro',
        StripeBillingProvisioner::ROLE_TIER_TEAM_MONTHLY => 'price_team',
        StripeBillingProvisioner::ROLE_TEAM_SEAT_MONTHLY => 'price_seat',
        StripeBillingProvisioner::ROLE_ENTERPRISE_PRODUCT => 'prod_ent',
    ]);

    expect($env)->toBe(implode("\n", [
        'STRIPE_PRICE_STARTER=price_starter',
        'STRIPE_PRICE_TIER_PRO=price_pro',
        'STRIPE_PRICE_TIER_TEAM=price_team',
        'STRIPE_PRICE_TEAM_SEAT=price_seat',
    ]));
});
