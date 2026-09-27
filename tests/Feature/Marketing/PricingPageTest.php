<?php

namespace Tests\Feature\Marketing\PricingPageTest;

use App\Modules\Billing\Support\UsagePrice;
use Illuminate\Support\Facades\Config;

test('pricing page lists starter, pro and team with unlimited sites, the trial, and enterprise', function () {
    $response = $this->withoutMiddleware()->get(route('pricing'));

    $response->assertOk()
        ->assertSee('Unlimited sites. Pay for what runs.')
        ->assertSee('5-day')
        ->assertDontSee('No card needed')
        ->assertSee('Starter')->assertSee('$5')
        ->assertSee('Pro')->assertSee('$20')
        ->assertSee('Team')->assertSee('$49')
        ->assertSee('Enterprise')->assertSee('Contact us')
        ->assertSee('Unlimited sites')
        ->assertSee('What each plan includes');
});

test('plan prices and included usage come from the billing config', function () {
    Config::set('subscription.standard.tiers.pro.price_cents', 2500);
    Config::set('subscription.standard.tiers.team.usage_credit_cents', 7_300);

    $this->withoutMiddleware()->get(route('pricing'))->assertOk()
        ->assertSee('$25')
        ->assertSee('$73 of usage included');
});

test('usage rates are the configured cost plus the margin, with the margin never shown', function () {
    Config::set('dply.edge.usage_billing.margin_percent', 50);
    Config::set('dply.edge.usage_billing.requests_millicents_per_million', 40_000);
    Config::set('dply.edge.usage_billing.egress_millicents_per_gb', 2_000);

    $this->withoutMiddleware()->get(route('pricing'))->assertOk()
        ->assertSee('Usage rates')
        ->assertSee('$0.60')   // requests: $0.40 cost + 50%
        ->assertSee('$0.03')   // bandwidth: $0.02 cost + 50%
        ->assertDontSee('50%')
        ->assertDontSee('margin');
});

test('pricing page says previews have no fee but their usage counts', function () {
    $this->withoutMiddleware()->get(route('pricing'))->assertOk()
        ->assertDontSee('Preview deployments are free.')
        ->assertSee('Previews have no fee', false)
        ->assertSee('Do preview deployments cost anything?');
});

test('pricing page carries the estimator and the faq', function () {
    $this->withoutMiddleware()->get(route('pricing'))->assertOk()
        ->assertSee('Estimate a month')
        ->assertSee('Frequently asked')
        ->assertSee('What exactly am I paying for?');
});

test('pricing page sells one product — no per-site fees, server plans or other product lines', function () {
    $this->withoutMiddleware()->get(route('pricing'))->assertOk()
        ->assertDontSee('priced by server count')
        ->assertDontSee('dply Cloud')
        ->assertDontSee('Serverless functions')
        ->assertDontSee('/site/mo')
        ->assertDontSee('Worker SSR sites')
        ->assertDontSee('then $2 each');
});

test('pricing page shows the one size ladder with per-second prices from the helper', function () {
    $this->withoutMiddleware()->get(route('pricing'))->assertOk()
        ->assertSee('Sizes, by the second')
        ->assertSee('0.25 vCPU')->assertSee('4 vCPU')
        ->assertSee(UsagePrice::sizes()[0]['app']['second'])
        ->assertSee('App hours')
        ->assertSee('Laravel app');
});
