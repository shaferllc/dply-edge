<?php

declare(strict_types=1);

namespace Tests\Feature\Console\CollectEdgeUsageCommandTest;

use App\Models\EdgeDeployment;
use App\Models\EdgeUsageSnapshot;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Modules\Billing\Services\EdgeUsageTotals;
use App\Modules\Edge\Services\EdgeUsageCollector;
use App\Modules\Providers\Cloudflare\EdgeCloudflareClient;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Mockery;

uses(RefreshDatabase::class);

test('collect usage writes placeholder snapshots for active edge sites', function () {
    Config::set('edge.cloudflare.account_id', '');
    Config::set('edge.cloudflare.api_token', '');

    $org = Organization::factory()->create();
    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'status' => Server::STATUS_READY,
        'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE],
    ]);
    $site = Site::factory()->create([
        'organization_id' => $org->id,
        'server_id' => $server->id,
        'status' => Site::STATUS_EDGE_ACTIVE,
        'edge_backend' => 'dply_edge',
    ]);

    $date = now()->subDay()->toDateString();

    $this->artisan('dply:edge:collect-usage', ['--date' => $date])
        ->expectsOutputToContain('source=placeholder')
        ->assertOk();

    expect(EdgeUsageSnapshot::query()->where('site_id', $site->id)->count())->toBe(1);

    $snapshot = EdgeUsageSnapshot::query()->where('site_id', $site->id)->first();
    expect($snapshot->source)->toBe(EdgeUsageSnapshot::SOURCE_PLACEHOLDER);
    expect($snapshot->requests)->toBe(0);
});

test('dry run does not persist snapshots', function () {
    $org = Organization::factory()->create();
    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'status' => Server::STATUS_READY,
        'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE],
    ]);
    Site::factory()->create([
        'organization_id' => $org->id,
        'server_id' => $server->id,
        'status' => Site::STATUS_EDGE_ACTIVE,
        'edge_backend' => 'dply_edge',
    ]);

    $this->artisan('dply:edge:collect-usage', ['--dry-run' => true])
        ->expectsOutputToContain('[dry-run]')
        ->assertOk();

    expect(EdgeUsageSnapshot::query()->count())->toBe(0);
});

test('collect usage resolves edge hostname from meta without site domain rows', function () {
    Config::set('edge.cloudflare.account_id', 'acct');
    Config::set('edge.cloudflare.api_token', 'token');
    Config::set('edge.cloudflare.worker_zone_name', 'on-dply.site');

    $org = Organization::factory()->create();
    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'status' => Server::STATUS_READY,
        'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE],
    ]);
    $site = Site::factory()->create([
        'organization_id' => $org->id,
        'server_id' => $server->id,
        'status' => Site::STATUS_EDGE_ACTIVE,
        'edge_backend' => 'dply_edge',
        'meta' => [
            'edge' => [
                'routing' => [
                    'hostname' => 'my-app-abc123.on-dply.site',
                ],
            ],
        ],
    ]);

    $mockClient = Mockery::mock(EdgeCloudflareClient::class);
    $mockClient->shouldReceive('canCollectAnalytics')->andReturn(true);
    $mockClient->shouldReceive('fetchHttpUsageByHostnames')
        ->once()
        ->with(['my-app-abc123.on-dply.site'], Mockery::any(), Mockery::any(), 'on-dply.site')
        ->andReturn(collect([
            'my-app-abc123.on-dply.site' => new EdgeUsageTotals(requests: 42, bytesEgress: 8192),
        ]));

    app()->instance(EdgeUsageCollector::class, new EdgeUsageCollector($mockClient));

    $date = now()->subDay()->toDateString();

    $this->artisan('dply:edge:collect-usage', ['--date' => $date])
        ->expectsOutputToContain('source=cloudflare_graphql')
        ->assertOk();

    $snapshot = EdgeUsageSnapshot::query()->where('site_id', $site->id)->first();
    expect($snapshot)->not->toBeNull();
    expect($snapshot->source)->toBe(EdgeUsageSnapshot::SOURCE_CLOUDFLARE_GRAPHQL);
    expect($snapshot->requests)->toBe(42);
    expect($snapshot->bytes_egress)->toBe(8192);
    expect($snapshot->meta['hostname'])->toBe('my-app-abc123.on-dply.site');
    expect($snapshot->meta['hostnames'])->toBe(['my-app-abc123.on-dply.site']);
});

test('collect usage aggregates custom domain traffic into site snapshot', function () {
    Config::set('edge.cloudflare.account_id', 'acct');
    Config::set('edge.cloudflare.api_token', 'token');
    Config::set('edge.cloudflare.worker_zone_name', 'on-dply.site');

    $org = Organization::factory()->create();
    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'status' => Server::STATUS_READY,
        'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE],
    ]);
    $site = Site::factory()->create([
        'organization_id' => $org->id,
        'server_id' => $server->id,
        'status' => Site::STATUS_EDGE_ACTIVE,
        'edge_backend' => 'dply_edge',
        'meta' => [
            'edge' => [
                'routing' => [
                    'hostname' => 'my-app-abc123.on-dply.site',
                    'custom_domains' => [
                        ['hostname' => 'shop.on-dply.site', 'mode' => 'primary', 'dns_status' => 'ready'],
                    ],
                ],
            ],
        ],
    ]);

    $date = Carbon::parse('2026-05-22');

    $mockClient = Mockery::mock(EdgeCloudflareClient::class);
    $mockClient->shouldReceive('canCollectAnalytics')->andReturn(true);
    $mockClient->shouldReceive('fetchHttpUsageByHostnames')
        ->once()
        ->with(['my-app-abc123.on-dply.site', 'shop.on-dply.site'], Mockery::any(), Mockery::any(), 'on-dply.site')
        ->andReturn(collect([
            'my-app-abc123.on-dply.site' => new EdgeUsageTotals(requests: 10, bytesEgress: 1000),
            'shop.on-dply.site' => new EdgeUsageTotals(requests: 5, bytesEgress: 500),
        ]));

    app()->instance(EdgeUsageCollector::class, new EdgeUsageCollector($mockClient));

    $this->artisan('dply:edge:collect-usage', ['--date' => $date->toDateString()])->assertOk();

    $snapshot = EdgeUsageSnapshot::query()->where('site_id', $site->id)->first();
    expect($snapshot->requests)->toBe(15);
    expect($snapshot->bytes_egress)->toBe(1500);
    expect($snapshot->meta['hostnames'])->toBe(['my-app-abc123.on-dply.site', 'shop.on-dply.site']);
});

test('collect usage merges r2 metrics into snapshots', function () {
    Config::set('edge.cloudflare.account_id', 'acct');
    Config::set('edge.cloudflare.api_token', 'token');
    Config::set('edge.cloudflare.worker_zone_name', 'on-dply.site');
    Config::set('edge.r2.bucket', 'dply-edge-artifacts');

    $org = Organization::factory()->create();
    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'status' => Server::STATUS_READY,
        'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE],
    ]);
    $site = Site::factory()->create([
        'organization_id' => $org->id,
        'server_id' => $server->id,
        'status' => Site::STATUS_EDGE_ACTIVE,
        'edge_backend' => 'dply_edge',
        'meta' => ['edge' => ['routing' => ['hostname' => 'demo.on-dply.site']]],
    ]);

    EdgeDeployment::query()->create([
        'site_id' => $site->id,
        'organization_id' => $org->id,
        'status' => EdgeDeployment::STATUS_LIVE,
        'storage_prefix' => 'edge/'.$site->id.'/deploy-1/',
        'meta' => ['artifact_bytes' => 2048],
    ]);

    $mockClient = Mockery::mock(EdgeCloudflareClient::class);
    $mockClient->shouldReceive('canCollectAnalytics')->andReturn(true);
    $mockClient->shouldReceive('fetchHttpUsageByHostnames')
        ->once()
        ->andReturn(collect([
            'demo.on-dply.site' => new EdgeUsageTotals(requests: 100, bytesEgress: 5000),
        ]));
    $mockClient->shouldReceive('fetchR2BucketUsage')
        ->once()
        ->andReturn(new EdgeUsageTotals(r2StorageBytes: 4096, r2ClassAOps: 10, r2ClassBOps: 40));

    app()->instance(EdgeUsageCollector::class, new EdgeUsageCollector($mockClient));

    $this->artisan('dply:edge:collect-usage', ['--date' => now()->subDay()->toDateString()])->assertOk();

    $snapshot = EdgeUsageSnapshot::query()->where('site_id', $site->id)->first();
    expect($snapshot->r2_storage_bytes)->toBe(2048);
    expect($snapshot->r2_class_a_ops)->toBe(10);
    expect($snapshot->r2_class_b_ops)->toBe(40);
    expect($snapshot->meta['r2_collected'])->toBeTrue();
});
