<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\EdgePerformanceHourly;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Modules\Edge\Services\EdgeAnalyticsEngineRollup;
use App\Modules\Edge\Services\EdgePerformanceHourlyRollup;
use App\Modules\Providers\Cloudflare\EdgeCloudflareClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;

uses(RefreshDatabase::class);

test('analytics engine rollup writes hourly rows by site', function () {
    config(['edge.cloudflare.analytics_dataset' => 'dply_edge_requests']);

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

    $mockClient = Mockery::mock(EdgeCloudflareClient::class);
    $mockClient->shouldReceive('canQueryAnalyticsEngine')->andReturn(true);
    $mockClient->shouldReceive('queryAnalyticsEngineSql')
        ->once()
        ->andReturn([
            [
                'site_id' => $site->id,
                'hour_start' => now()->startOfHour()->toIso8601String(),
                'requests' => 12,
                'bytes_egress' => 4096,
                'duration_ms_total' => 600,
                'status_2xx' => 12,
                'status_4xx' => 0,
                'status_5xx' => 0,
                'cache_hits' => 8,
            ],
        ]);

    app()->instance(EdgeAnalyticsEngineRollup::class, new EdgeAnalyticsEngineRollup(
        $mockClient,
        app(EdgePerformanceHourlyRollup::class),
    ));

    $this->artisan('dply:edge:rollup-analytics-engine', ['--hours' => 2])->assertOk();

    $row = EdgePerformanceHourly::query()
        ->where('site_id', $site->id)
        ->where('source', 'analytics_engine')
        ->first();

    expect($row)->not->toBeNull();
    expect($row->requests)->toBe(12);
    expect($row->cache_hits)->toBe(8);
});
