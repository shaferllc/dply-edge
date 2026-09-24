<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Billing;

use App\Models\EdgeUsageSnapshot;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Billing\Services\EdgeSiteBillingAnalytics;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('a site inside the plan has no site fee', function () {
    config(['dply.edge.usage_billing.enabled' => true]);

    $org = Organization::factory()->create();
    $server = Server::factory()->for($org)->create(['status' => Server::STATUS_READY]);
    $site = Site::factory()->for($org)->for($server)->create([
        'status' => Site::STATUS_EDGE_ACTIVE,
        'edge_backend' => 'dply_edge',
        'created_at' => now()->subDays(5),
    ]);

    EdgeUsageSnapshot::query()->create([
        'organization_id' => $org->id,
        'site_id' => $site->id,
        'period_start' => now()->toDateString(),
        'period_end' => now()->toDateString(),
        'requests' => 50_000,
        'bytes_egress' => 512 * 1024 * 1024,
        'r2_storage_bytes' => 0,
        'r2_class_a_ops' => 0,
        'r2_class_b_ops' => 0,
        'source' => 'manual',
    ]);

    $row = app(EdgeSiteBillingAnalytics::class)->forSite($site->fresh());

    expect($row)->not->toBeNull()
        ->and($row['platform_cents'])->toBe(0)
        ->and($row['platform_kind'])->toBe('included')
        ->and($row['requests'])->toBe(50_000)
        ->and($row['daily'])->not->toBeEmpty();
});

test('sites for organization lists all billable edge sites', function () {
    $org = Organization::factory()->create();
    $server = Server::factory()->for($org)->create();
    Site::factory()->for($org)->for($server)->create([
        'name' => 'Marketing',
        'status' => Site::STATUS_EDGE_ACTIVE,
        'edge_backend' => 'dply_edge',
        'created_at' => now()->subDays(3),
    ]);

    $sites = app(EdgeSiteBillingAnalytics::class)->sitesForOrganization($org);

    expect($sites)->toHaveCount(1)
        ->and($sites[0]['site_name'])->toBe('Marketing')
        ->and($sites[0]['platform_cents'])->toBe(0)
        ->and($sites[0]['platform_kind'])->toBe('included');
});

test('sites past the plan count and every ssr site carry a fee on pro', function () {
    config([
        'subscription.standard.stripe.tier_pro' => 'price_test_tier_pro',
        'subscription.standard.tiers.pro.sites' => 1,
    ]);

    $org = Organization::factory()->create();
    Subscription::factory()->withPrice('price_test_tier_pro')->active()->create(['organization_id' => $org->id]);
    $server = Server::factory()->for($org)->create();

    $included = Site::factory()->for($org)->for($server)->create([
        'name' => 'Included',
        'status' => Site::STATUS_EDGE_ACTIVE,
        'edge_backend' => 'dply_edge',
        'created_at' => now()->subDays(10),
    ]);
    $extra = Site::factory()->for($org)->for($server)->create([
        'name' => 'Extra',
        'status' => Site::STATUS_EDGE_ACTIVE,
        'edge_backend' => 'dply_edge',
        'created_at' => now()->subDays(4),
    ]);
    $ssr = Site::factory()->for($org)->for($server)->create([
        'name' => 'Ssr',
        'status' => Site::STATUS_EDGE_ACTIVE,
        'edge_backend' => 'dply_edge',
        'meta' => ['edge' => ['runtime_mode' => 'ssr']],
        'created_at' => now()->subDays(3),
    ]);

    $rows = collect(app(EdgeSiteBillingAnalytics::class)->sitesForOrganization($org))->keyBy('site_name');

    expect($rows['Included']['platform_cents'])->toBe(0)
        ->and($rows['Included']['platform_kind'])->toBe('included')
        ->and($rows['Extra']['platform_cents'])->toBe(200)
        ->and($rows['Extra']['platform_kind'])->toBe('extra')
        ->and($rows['Ssr']['platform_cents'])->toBe(700)
        ->and($rows['Ssr']['platform_kind'])->toBe('ssr')
        ->and(app(EdgeSiteBillingAnalytics::class)->forSite($included->fresh())['platform_cents'])->toBe(0)
        ->and(app(EdgeSiteBillingAnalytics::class)->forSite($extra->fresh())['platform_cents'])->toBe(200)
        ->and(app(EdgeSiteBillingAnalytics::class)->forSite($ssr->fresh())['platform_cents'])->toBe(700);
});
