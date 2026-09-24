<?php

declare(strict_types=1);

use App\Models\Site;
use App\Modules\Edge\Support\EdgeAnalyticsEngineTraffic;
use App\Modules\Providers\Cloudflare\EdgeCloudflareClient;
use Illuminate\Support\Carbon;

test('same day traffic reads totals status paths and recent rows from one dataset', function () {
    Carbon::setTestNow('2026-09-24 15:00:00');
    config(['edge.cloudflare.analytics_dataset' => 'dply_edge_requests']);

    $site = new Site;
    $site->id = '01ARZ3NDEKTSV4RRFFQ69G5FAV';

    $client = Mockery::mock(EdgeCloudflareClient::class);
    $client->shouldReceive('canQueryAnalyticsEngine')->andReturn(true);
    $client->shouldReceive('queryAnalyticsEngineSql')
        ->times(3)
        ->andReturnUsing(function (string $sql) {
            expect($sql)->toContain('dply_edge_requests')
                ->and($sql)->toContain('01ARZ3NDEKTSV4RRFFQ69G5FAV');

            if (str_contains($sql, 'status_2xx')) {
                return [[
                    'requests' => 10,
                    'bytes_egress' => 2048,
                    'status_2xx' => 7,
                    'status_3xx' => 1,
                    'status_4xx' => 2,
                    'status_5xx' => 0,
                ]];
            }

            if (str_contains($sql, 'GROUP BY path')) {
                return [
                    ['path' => '/login', 'requests' => 6],
                    ['path' => '', 'requests' => 1],
                    ['path' => '/health', 'requests' => 3],
                ];
            }

            return [[
                'timestamp' => '2026-09-24 14:59:00',
                'hostname' => 'bookstack.on-dply.live',
                'method' => 'GET',
                'path' => '/login',
                'status' => 200,
                'duration_ms' => 40,
                'bytes_egress' => 512,
                'cache_status' => 'miss',
            ]];
        });

    $overview = (new EdgeAnalyticsEngineTraffic($client))->overview($site);

    expect($overview['available'])->toBeTrue()
        ->and($overview['requests'])->toBe(10)
        ->and($overview['bytes_egress'])->toBe(2048)
        ->and($overview['status'])->toBe(['2xx' => 7, '3xx' => 1, '4xx' => 2, '5xx' => 0])
        ->and($overview['paths'])->toBe([
            ['path' => '/login', 'requests' => 6],
            ['path' => '/health', 'requests' => 3],
        ])
        ->and($overview['recent'][0]['path'])->toBe('/login')
        ->and($overview['recent'][0]['status'])->toBe(200)
        ->and($overview['recent'][0]['method'])->toBe('GET');
});
