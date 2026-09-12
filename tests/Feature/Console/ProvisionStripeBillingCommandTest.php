<?php

namespace Tests\Feature\Console\ProvisionStripeBillingCommandTest;

use App\Modules\Billing\Services\StripeBillingProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;

uses(RefreshDatabase::class);

test('dry run lists only edge and enterprise objects without calling stripe', function () {
    Config::set('cashier.secret', 'sk_test_dummy');
    Config::set('subscription.standard.annual_discount_pct', 20);

    // Each expectsOutputToContain is matched against a single write call, so
    // assert at most one substring per emitted line.
    $this->artisan('dply:billing:provision-stripe', ['--dry-run' => true])
        ->expectsOutputToContain('Dry-run')
        ->expectsOutputToContain('dply Edge site (static / hybrid)')
        ->expectsOutputToContain('Per site $2.00/mo   $19.20/yr')
        ->expectsOutputToContain('dply Edge SSR site')
        ->expectsOutputToContain('Per site $7.00/mo   $67.20/yr')
        ->expectsOutputToContain('dply Edge delivery usage')
        ->expectsOutputToContain('dply Enterprise')
        ->doesntExpectOutputToContain('Plans (metered by BYO server count)')
        ->doesntExpectOutputToContain('dply serverless function')
        ->doesntExpectOutputToContain('dply Cloud app')
        ->assertOk();
});

test('fails loudly when stripe secret is missing', function () {
    Config::set('cashier.secret', '');

    $this->artisan('dply:billing:provision-stripe')
        ->expectsOutputToContain('STRIPE_SECRET')
        ->assertFailed();
});

test('format env emits the edge price lines and skips product roles', function () {
    $env = StripeBillingProvisioner::formatEnv([
        StripeBillingProvisioner::ROLE_EDGE_PRODUCT => 'prod_edge',
        StripeBillingProvisioner::ROLE_EDGE_MONTHLY => 'price_edge',
        StripeBillingProvisioner::ROLE_EDGE_YEARLY => 'price_edge_y',
        StripeBillingProvisioner::ROLE_EDGE_SSR_PRODUCT => 'prod_edge_ssr',
        StripeBillingProvisioner::ROLE_EDGE_SSR_MONTHLY => 'price_edge_ssr',
        StripeBillingProvisioner::ROLE_EDGE_SSR_YEARLY => 'price_edge_ssr_y',
        StripeBillingProvisioner::ROLE_EDGE_USAGE_PRODUCT => 'prod_edge_usage',
        StripeBillingProvisioner::ROLE_EDGE_USAGE_MONTHLY => 'price_edge_usage',
        StripeBillingProvisioner::ROLE_ENTERPRISE_PRODUCT => 'prod_ent',
    ]);

    expect($env)->toBe(implode("\n", [
        'STRIPE_PRICE_STANDARD_EDGE=price_edge',
        'STRIPE_PRICE_STANDARD_EDGE_YEARLY=price_edge_y',
        'STRIPE_PRICE_STANDARD_EDGE_SSR=price_edge_ssr',
        'STRIPE_PRICE_STANDARD_EDGE_SSR_YEARLY=price_edge_ssr_y',
        'STRIPE_PRICE_STANDARD_EDGE_USAGE=price_edge_usage',
    ]));
});
