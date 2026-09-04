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

test('pricing page renders the per-site platform fee for each delivery mode', function () {
    $response = $this->withoutMiddleware()->get(route('pricing'));

    $response->assertOk()
        ->assertSee('One product. Two numbers.')
        ->assertSee('Static / SSG')
        ->assertSee('Hybrid')
        ->assertSee('Worker SSR')
        ->assertSee('$2.00')
        ->assertSee('$7.00');
});

test('platform fees come from the billing config, not the markup', function () {
    Config::set('subscription.standard.edge_cents', 300);
    Config::set('subscription.standard.edge_ssr_cents', 900);

    $response = $this->withoutMiddleware()->get(route('pricing'));

    $response->assertOk()
        ->assertSee('$3.00')
        ->assertSee('$9.00');
});

test('included allowances are read from config so the page cannot drift from the invoice', function () {
    Config::set('dply.edge.usage_billing.included_requests_per_site', 2_000_000);
    Config::set('dply.edge.usage_billing.included_egress_gb_per_site', 250);
    Config::set('dply.edge.usage_billing.included_r2_class_a_ops_per_site', 50_000);

    $response = $this->withoutMiddleware()->get(route('pricing'));

    $response->assertOk()
        ->assertSee('Included with every site')
        ->assertSee('2M')
        ->assertSee('250 GB')
        ->assertSee('50k')
        // the overage table quotes the same allowance it charges past
        ->assertSee('per million, past 2M');
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
        ->assertDontSee('Serverless functions');
});
