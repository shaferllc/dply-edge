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

test('no site carries a fee on any plan, SSR included', function () {
    config(['subscription.standard.stripe.tier_pro' => 'price_test_tier_pro']);

    $org = Organization::factory()->create();
    Subscription::factory()->withPrice('price_test_tier_pro')->active()->create(['organization_id' => $org->id]);
    $server = Server::factory()->for($org)->create();
    foreach (['Static' => [], 'Ssr' => ['edge' => ['runtime_mode' => 'ssr']]] as $name => $meta) {
        Site::factory()->for($org)->for($server)->create(['name' => $name, 'status' => Site::STATUS_EDGE_ACTIVE, 'edge_backend' => 'dply_edge', 'meta' => $meta]);
    }

    $rows = collect(app(EdgeSiteBillingAnalytics::class)->sitesForOrganization($org))->keyBy('site_name');

    expect($rows)->toHaveCount(2)
        ->and($rows['Static']['platform_cents'])->toBe(0)
        ->and($rows['Ssr']['platform_cents'])->toBe(0)
        ->and($rows['Ssr']['platform_kind'])->toBe('included');
});

test('a container app bills its compute on the site, not just delivery', function () {
    $org = Organization::factory()->create();
    $server = Server::factory()->for($org)->create();
    $site = Site::factory()->for($org)->for($server)->create([
        'status' => Site::STATUS_EDGE_ACTIVE,
        'edge_backend' => 'dply_edge',
        'meta' => ['edge' => ['runtime_mode' => 'container']],
    ]);
    \App\Models\EdgeContainerUsage::query()->create([
        'organization_id' => $org->id,
        'site_id' => $site->id,
        'date' => now()->toDateString(),
        'cpu_seconds' => 86_400,
        'memory_gib_seconds' => 86_400,
        'disk_gb_seconds' => 86_400,
        'tx_bytes' => 0,
    ]);

    $row = app(EdgeSiteBillingAnalytics::class)->forSite($site->fresh());
    $compute = collect($row['lines'])->firstWhere('key', 'compute');

    expect($compute)->not->toBeNull()
        ->and($compute['cents'])->toBeGreaterThan(0)
        ->and($row['usage_cents'])->toBe($row['delivery_cents'] + $compute['cents'])
        ->and($row['total_cents'])->toBe($row['usage_cents'])
        ->and($row['daily_compute'])->toHaveCount(1)
        ->and($row['daily_compute'][0]['cpu_hours'])->toBe(24.0)
        ->and($row['daily_compute'][0]['cents'])->toBeGreaterThan(0);

    $html = view('livewire.billing.partials.edge-site-daily-compute', ['billing' => $row])->render();
    expect($html)->toContain('Daily compute')->toContain('24.0 vCPU-h');
});

test('a site mid-deploy or after a failed deploy still shows its usage; a preview does not', function () {
    config(['dply.edge.usage_billing.enabled' => true]);
    $org = Organization::factory()->create();
    $server = Server::factory()->for($org)->create(['status' => Server::STATUS_READY]);

    foreach ([Site::STATUS_EDGE_PROVISIONING, Site::STATUS_EDGE_FAILED] as $status) {
        $site = Site::factory()->for($org)->for($server)->create(['status' => $status, 'edge_backend' => 'dply_edge']);
        expect(app(EdgeSiteBillingAnalytics::class)->forSite($site->fresh()))->not->toBeNull($status);
    }
});
