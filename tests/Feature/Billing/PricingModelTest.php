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
use App\Modules\Billing\Support\UnitCosts;
use App\Modules\Billing\Support\UsagePrice;
use App\Modules\Edge\Actions\CreateEdgeSite;
use App\Modules\Edge\Services\EdgeAppDatabase;
use App\Modules\Edge\Support\EdgeContainerSettings;
use App\Modules\Edge\Support\EdgeDplyDatabase;
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
    $at20 = collect(UsagePrice::rates())->mapWithKeys(fn (array $r): array => [$r['group'].' '.$r['label'] => $r['millicents']])->all();
    $sizes20 = UsagePrice::sizes();
    $valkey20 = EdgeValkey::spec('flex_1g');
    $kv20 = app(EdgeKvCost::class)->cents(100_000_000, 0, 0, 0, 0);
    $delivery20 = app(EdgeUsageCostCalculator::class)->estimate(new EdgeUsageTotals(requests: 1_000_000_000))['subtotal_cents'];

    config(['dply.edge.usage_billing.margin_percent' => 50]);
    $at50 = collect(UsagePrice::rates())->mapWithKeys(fn (array $r): array => [$r['group'].' '.$r['label'] => $r['millicents']])->all();

    // Fixed customer prices (fixed_price_meters) do not move with the margin.
    $fixed = ['Delivery Bandwidth', 'Builds Build time', 'Databases Compute', 'Databases Storage', 'Realtime Connection-minutes', 'Realtime Messages'];
    foreach ($at20 as $label => $millicents) {
        expect($at50[$label])->toEqualWithDelta(in_array($label, $fixed, true) ? $millicents : $millicents / 1.2 * 1.5, 1e-9);
    }
    // Each rate is the configured cost plus the margin.
    expect(UsagePrice::rate('requests_millicents_per_million'))->toBe(30_000 * 1.5)
        // Valkey and database prices are fixed too (docs/pricing-review.md §9).
        ->and(EdgeValkey::spec('flex_1g')['cap_cents'])->toBe($valkey20['cap_cents'])
        ->and(UsagePrice::sizes()[0]['database']['second'])->toBe($sizes20[0]['database']['second'])
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

test('bandwidth is a fixed $0.06/GB at any margin, on every path that prices it', function (int $margin) {
    config(['dply.edge.usage_billing.margin_percent' => $margin]);
    $bandwidth = collect(UsagePrice::rates())->firstWhere('label', 'Bandwidth');

    expect($bandwidth['price'])->toBe('$0.06')
        ->and(UsagePrice::dollars(UsagePrice::rate('egress_millicents_per_gb')))->toBe('$0.06')
        // The invoice path: 100 GB of delivery egress is exactly $6.00.
        ->and(app(EdgeUsageCostCalculator::class)->estimate(new EdgeUsageTotals(bytesEgress: 100 * 1024 ** 3))['subtotal_cents'])->toBe(600)
        // Meters that are not fixed still move with the margin.
        ->and(UsagePrice::rate('requests_millicents_per_million'))->toEqualWithDelta(30_000 * (1 + $margin / 100), 1e-6);
})->with([20, 30, 50]);

test('every fixed-price meter and every Valkey class keeps its configured price at any margin', function (int $margin) {
    config(['dply.edge.usage_billing.margin_percent' => $margin]);

    foreach ((array) config('dply.edge.usage_billing.fixed_price_meters') as $key) {
        expect(UsagePrice::rate($key))->toEqualWithDelta((float) config('dply.edge.usage_billing.'.$key), 1e-9, $key);
    }
    foreach (EdgeValkey::CLASSES as $class => $spec) {
        expect(EdgeValkey::spec($class)['cap_cents'])->toBe((float) $spec['price_cap_cents'])
            ->and(EdgeValkey::spec($class)['per_second'])->toBe($spec['price_per_second']);
    }
    // The prices set 2026-09-27 (docs/pricing-review.md §9).
    expect(UsagePrice::dollars(UsagePrice::rate('build_millicents_per_minute')))->toBe('$0.005')
        ->and(UsagePrice::rate('database_compute_millicents_per_cu_second') * 3600 / 100_000)->toEqualWithDelta(0.12, 1e-9)
        ->and(UsagePrice::dollars(UsagePrice::rate('database_storage_millicents_per_gb_month')))->toBe('$0.20')
        ->and(UsagePrice::dollars(UsagePrice::rate('realtime_message_millicents_per_million')))->toBe('$0.62')
        ->and(UsagePrice::dollars(UsagePrice::rate('realtime_connection_minute_millicents') * 1_000_000))->toBe('$0.25')
        ->and(EdgeValkey::spec('flex_250m')['cap_cents'])->toBe(450.0);
})->with([20, 30, 50]);

test('no plan credit is larger than its fee', function () {
    foreach (['starter', 'pro', 'team'] as $tier) {
        $plan = config('subscription.standard.tiers.'.$tier);
        expect($plan['usage_credit_cents'])->toBeLessThanOrEqual($plan['price_cents'], $tier);
    }
    expect(config('subscription.standard.tiers.team.usage_credit_cents'))->toBe(4900);
});

test('every offered size and repriced meter is priced at least 30% over its estimated real cost', function () {
    foreach (UnitCosts::rows() as $row) {
        if (str_contains($row['note'], 'not offered')) {
            continue;
        }
        expect($row['ok'])->toBeTrue($row['meter'].' '.$row['unit']);
    }
    // The 1, 2 and 4 CU sizes (db-large / db-xl pools) are checked whether or
    // not dply.databases.large_sizes_enabled is on.
    $meters = array_column(UnitCosts::rows(), 'meter');
    foreach (['1', '2', '4'] as $cu) {
        expect($meters)->toContain("Database compute {$cu} CU");
    }
});

test('every large database size, alone on its node and running all month, bills at least 1.3x that node', function () {
    $do = config('dply.unit_costs.digitalocean');
    foreach (EdgeDplyDatabase::LARGE_SIZES as $size) {
        $node = $do['pools'][$do['database_pools'][$size]]['monthly'];
        $month = UsagePrice::databaseRate($size) * 3600 * 720 * (float) EdgeAppDatabase::POSTGRES_SIZES[$size]['cu'] / 100_000;
        expect($month)->toBeGreaterThanOrEqual($node * config('dply.unit_costs.min_markup'), "{$size} CU");
    }
    // 1 CU carries its own price; the ladder shows it.
    expect(UsagePrice::databaseRate('1') * 3600 / 100_000)->toEqualWithDelta(0.18, 1e-9)
        ->and(UsagePrice::databaseBilledCu('1'))->toEqualWithDelta(1.5, 1e-9)
        ->and(UsagePrice::databaseBilledCu('2'))->toEqualWithDelta(2.0, 1e-9);
});

test('large database sizes cannot sleep', function () {
    foreach (EdgeDplyDatabase::LARGE_SIZES as $size) {
        expect(EdgeDplyDatabase::alwaysOn($size))->toBeTrue($size);
    }
    expect(EdgeDplyDatabase::alwaysOn('0.25'))->toBeFalse()
        ->and(EdgeDplyDatabase::alwaysOn('0.5'))->toBeFalse();
});

test('dply:billing:unit-costs prints unit costs against prices and the fixed monthly total', function () {
    $total = array_sum(UnitCosts::fixedMonthly());

    $this->artisan('dply:billing:unit-costs')
        ->expectsOutputToContain('Unit costs vs price')
        ->expectsOutputToContain('Database compute')
        ->expectsOutputToContain('Valkey flex_250m')
        ->expectsOutputToContain('Build time')
        ->expectsOutputToContain('Realtime connection-minutes')
        ->expectsOutputToContain('Fixed monthly costs')
        ->expectsOutputToContain(UsagePrice::dollars($total * 100_000))
        ->assertSuccessful();
    expect($total)->toBeGreaterThan(0.0);
});
