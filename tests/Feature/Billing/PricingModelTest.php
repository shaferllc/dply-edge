<?php

declare(strict_types=1);

use App\Models\EdgeUsageSnapshot;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Billing\Services\BillingAnalytics;
use App\Modules\Billing\Services\DesiredBillingState;
use App\Modules\Billing\Services\EdgeKvCost;
use App\Modules\Billing\Services\EdgeUsageCostCalculator;
use App\Modules\Billing\Services\EdgeUsageTotals;
use App\Modules\Billing\Services\StandardSubscriptionCreator;
use App\Modules\Billing\Services\StripeBillingProvisioner;
use App\Modules\Billing\Support\UsagePrice;
use App\Modules\Edge\Actions\CreateEdgeSite;
use App\Modules\Edge\Support\EdgeContainerSettings;
use App\Modules\Edge\Support\EdgeValkey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Stripe\Price;
use Stripe\Product;
use Stripe\StripeClient;

uses(RefreshDatabase::class);

/*
 * The pricing model (docs/adr/pricing-model-2026-09.md): three plans, an
 * included usage credit, and one margin applied in one place.
 */

test('the margin is the one knob: every usage price moves with it, and only through UsagePrice', function () {
    config(['dply.edge.usage_billing.margin_percent' => 20]);
    $at20 = collect(UsagePrice::rates())->pluck('millicents', 'label')->all();
    $sizes20 = UsagePrice::sizes();
    $valkey20 = EdgeValkey::spec('flex_1g');
    $kv20 = app(EdgeKvCost::class)->cents(100_000_000, 0, 0, 0, 0);
    $delivery20 = app(EdgeUsageCostCalculator::class)->estimate(new EdgeUsageTotals(requests: 1_000_000_000))['subtotal_cents'];

    config(['dply.edge.usage_billing.margin_percent' => 50]);
    $at50 = collect(UsagePrice::rates())->pluck('millicents', 'label')->all();

    foreach ($at20 as $label => $millicents) {
        expect($at50[$label])->toEqualWithDelta($millicents / 1.2 * 1.5, 1e-9);
    }
    // Each rate is the configured cost plus the margin.
    expect(UsagePrice::rate('requests_millicents_per_million'))->toBe(30_000 * 1.5)
        ->and(EdgeValkey::spec('flex_1g')['cap_cents'])->toEqualWithDelta($valkey20['cap_cents'] / 1.2 * 1.5, 1e-6)
        ->and(UsagePrice::sizes()[0]['database']['second'])->not->toBe($sizes20[0]['database']['second'])
        // 100M KV reads cost $50 → $60 at 20%, $75 at 50%.
        ->and($kv20)->toBe(6000)
        ->and(app(EdgeKvCost::class)->cents(100_000_000, 0, 0, 0, 0))->toBe(7500)
        // 1B requests cost $300 → $360 at 20%, $450 at 50%.
        ->and($delivery20)->toBe(36_000)
        ->and(app(EdgeUsageCostCalculator::class)->estimate(new EdgeUsageTotals(requests: 1_000_000_000))['subtotal_cents'])->toBe(45_000);
});

test('the margin is never printed on the pricing page', function () {
    config(['dply.edge.usage_billing.margin_percent' => 37]);

    $this->get(route('pricing'))->assertOk()
        ->assertSee('Unlimited sites')
        ->assertSee('Starter')->assertSee('Pro')->assertSee('Team')->assertSee('Enterprise')
        ->assertSee(UsagePrice::dollars(UsagePrice::rate('requests_millicents_per_million')))
        ->assertDontSee('37%')
        ->assertDontSee('markup')
        ->assertDontSee('$2/mo');
});

test('usage comes off the included credit, never below zero', function () {
    $plan = ['key' => 'pro', 'label' => 'Pro', 'price_cents' => 2000];

    $under = DesiredBillingState::fromPlanAndUsage(plan: $plan, usage: ['compute' => 700, 'delivery' => 300], usageCreditCents: 2000);
    expect($under->usageLineCents())->toBe(1000)
        ->and($under->creditAppliedCents())->toBe(1000)
        ->and($under->usageChargeCents())->toBe(0)
        ->and($under->monthlyTotalCents)->toBe(2000);

    $over = DesiredBillingState::fromPlanAndUsage(plan: $plan, usage: ['compute' => 2500, 'valkey' => 600], usageCreditCents: 2000);
    expect($over->creditAppliedCents())->toBe(2000)
        ->and($over->usageChargeCents())->toBe(1100)
        ->and($over->monthlyTotalCents)->toBe(3100)
        ->and(array_keys($over->usageLines()))->toBe(['compute', 'valkey']);

    $lines = app(BillingAnalytics::class)->lineItems($over);
    expect(array_column($lines, 'label'))->toBe(['Pro plan', 'Apps and workers (compute)', 'Valkey', 'Included usage credit'])
        ->and(end($lines)['line_cents'])->toBe(-2000);
});

test('fair use: creating an app past the plan cap asks to contact us, and previews do not count', function () {
    config(['subscription.standard.tiers.pro.fair_use_apps' => 2]);
    $org = Organization::factory()->create(); // on the Pro trial
    $server = Server::factory()->create(['organization_id' => $org->id]);
    Site::factory()->create(['organization_id' => $org->id, 'server_id' => $server->id]);
    Site::factory()->create(['organization_id' => $org->id, 'server_id' => $server->id, 'meta' => ['edge' => ['preview_parent_site_id' => 'parent']]]);

    CreateEdgeSite::assertWithinFairUse($org); // one app, one preview: under the cap

    Site::factory()->create(['organization_id' => $org->id, 'server_id' => $server->id]);
    expect(fn () => (new CreateEdgeSite)->handle(User::factory()->create(), $org, ['name' => 'three', 'repo' => 'acme/three']))
        ->toThrow(RuntimeException::class, 'fair-use limit for your plan — contact us');
});

test('starter is a plan: its own stripe price, one seat, $5 of usage included', function () {
    config(['subscription.standard.stripe.tier_starter' => 'price_starter']);
    $tier = config('subscription.standard.tiers.starter');

    expect($tier['price_cents'])->toBe(500)
        ->and($tier['seats'])->toBe(1)
        ->and($tier['extra_seat_cents'])->toBeNull()
        ->and($tier['usage_credit_cents'])->toBe(500)
        ->and($tier['fair_use_apps'])->toBe(25);

    $state = DesiredBillingState::fromPlanAndUsage(plan: ['key' => 'starter', 'label' => 'Starter', 'price_cents' => 500], seatCount: 3, includedSeats: 1);
    expect(app(StandardSubscriptionCreator::class)->buildPriceList($state))->toBe([['price' => 'price_starter', 'quantity' => 1]]);
});

test('the provisioner creates the starter price and no per-site prices', function () {
    $created = [];
    $stripe = Mockery::mock(StripeClient::class);
    $stripe->products = Mockery::mock();
    $stripe->prices = Mockery::mock();
    $stripe->products->shouldReceive('search')->andReturn((object) ['data' => []]);
    $stripe->prices->shouldReceive('search')->andReturn((object) ['data' => []]);
    $stripe->products->shouldReceive('create')->andReturnUsing(function (array $p) use (&$created) {
        $created[] = $p['metadata']['dply_role'];

        return Product::constructFrom(['id' => 'prod_'.$p['metadata']['dply_role']] + $p);
    });
    $stripe->prices->shouldReceive('create')->andReturnUsing(function (array $p) use (&$created) {
        $created[] = $p['metadata']['dply_role'].':'.$p['unit_amount'];

        return Price::constructFrom(['id' => 'price_'.$p['metadata']['dply_role']] + $p);
    });

    $result = (new StripeBillingProvisioner($stripe))->provision();

    expect($created)->toContain('tier_starter:500', 'tier_pro:2000', 'tier_team:4900', 'team_seat:500')
        ->and(preg_grep('/standard_edge/', $created))->toBe([])
        ->and(StripeBillingProvisioner::formatEnv($result))->toContain('STRIPE_PRICE_STARTER=price_tier_starter');
});

test('daily delivery storage sums each site’s peak instead of taking the largest site', function () {
    config(['dply.edge.usage_billing.enabled' => true]);
    $org = Organization::factory()->create();
    $server = Server::factory()->create(['organization_id' => $org->id]);
    foreach ([3, 5] as $gb) {
        $site = Site::factory()->create(['organization_id' => $org->id, 'server_id' => $server->id]);
        EdgeUsageSnapshot::query()->create([
            'organization_id' => $org->id, 'site_id' => $site->id,
            'period_start' => now()->toDateString(), 'period_end' => now()->toDateString(),
            'requests' => 0, 'bytes_egress' => 0, 'r2_storage_bytes' => $gb * 1024 ** 3, 'r2_class_a_ops' => 0, 'r2_class_b_ops' => 0,
        ]);
    }

    $today = collect(app(BillingAnalytics::class)->forOrganization($org)['edge_usage_daily'])->last();

    expect($today['r2_storage_bytes'])->toBe(8 * 1024 ** 3);
});

test('starter runs one app instance, scaling windows included', function () {
    config(['subscription.standard.stripe.tier_starter' => 'price_starter']);
    $org = Organization::factory()->create();
    Subscription::factory()->withPrice('price_starter')->active()->create(['organization_id' => $org->id]);
    $site = Site::factory()->create(['organization_id' => $org->id, 'server_id' => Server::factory()->create(['organization_id' => $org->id])->id]);
    $site->mergeEdgeMeta(['container' => ['max_instances' => 5, 'min_instances' => 2, 'schedules' => [['days' => 'daily', 'start' => '09:00', 'end' => '17:00', 'timezone' => 'UTC', 'min' => 3, 'max' => 8]]]]);
    $site->save();

    $settings = EdgeContainerSettings::for($site->fresh());

    expect($org->fresh()->billingTier())->toBe('starter')
        ->and($settings['max_instances'])->toBe(1)
        ->and($settings['min_instances'])->toBe(1)
        ->and(EdgeContainerSettings::peakInstances($settings))->toBe(1);
});

test('the default margin is 30% and bandwidth stays a flat $0.06/GB at it', function () {
    expect(UsagePrice::marginPercent())->toBe(30.0)
        // 1M requests cost $0.30 → $0.39 at 30%.
        ->and(UsagePrice::dollars(UsagePrice::rate('requests_millicents_per_million')))->toBe('$0.39')
        ->and(UsagePrice::dollars(UsagePrice::rate('egress_millicents_per_gb')))->toBe('$0.06')
        ->and(UsagePrice::cents(UsagePrice::cost('egress_millicents_per_gb') * 100))->toBe(600);
});
