<?php

namespace Tests\Feature\Marketing\PricingPageTest;

use Illuminate\Support\Facades\Config;
use Laravel\Pennant\Feature;
use Tests\Concerns\WithFeatures;

uses(WithFeatures::class);

beforeEach(function () {
    Feature::define('global.billing_enabled', fn () => true);
    Feature::flushCache();
});

test('pricing page lists the free, pro and team plans from config', function () {
    $response = $this->withoutMiddleware()->get(route('pricing'));

    $response->assertOk()
        ->assertSee('Pick a plan. Pay for what you outgrow.')
        ->assertSee('Free')
        ->assertSee('Pro')
        ->assertSee('Team')
        ->assertSee('$20')
        ->assertSee('$49')
        ->assertSee('What each plan includes');
});

test('plan prices and allowances come from the billing config, not the markup', function () {
    Config::set('subscription.standard.tiers.pro.price_cents', 2500);
    Config::set('subscription.standard.tiers.team.build_minutes', 4_000);

    $response = $this->withoutMiddleware()->get(route('pricing'));

    $response->assertOk()
        ->assertSee('$25')
        ->assertSee('4,000');
});

test('overage rates carry the configured markup', function () {
    Config::set('dply.edge.usage_billing.markup_percent', 0);
    Config::set('dply.edge.usage_billing.requests_cents_per_million', 50);
    Config::set('dply.edge.usage_billing.egress_cents_per_gb', 5);

    $response = $this->withoutMiddleware()->get(route('pricing'));

    $response->assertOk()
        ->assertSee('$0.50')   // requests, no markup
        ->assertSee('$0.05');  // egress, no markup
});

test('pricing page tells you previews are free', function () {
    $response = $this->withoutMiddleware()->get(route('pricing'));

    $response->assertOk()
        ->assertSee('Preview deployments are free.')
        ->assertSee('Do preview deployments cost anything?');
});

test('pricing page carries the estimator and the faq', function () {
    $response = $this->withoutMiddleware()->get(route('pricing'));

    $response->assertOk()
        ->assertSee('Estimate a month')
        ->assertSee('Frequently asked')
        ->assertSee('What exactly am I paying for?');
});

test('pricing page sells one product — no server plans or other product lines', function () {
    $response = $this->withoutMiddleware()->get(route('pricing'));

    $response->assertOk()
        ->assertDontSee('priced by server count')
        ->assertDontSee('Up to 3 servers')
        ->assertDontSee('dply Cloud')
        ->assertDontSee('Serverless functions')
        ->assertDontSee('/site/mo');
});
