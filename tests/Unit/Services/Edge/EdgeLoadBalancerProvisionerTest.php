<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Edge\EdgeLoadBalancerProvisionerTest;

use App\Models\Site;
use App\Modules\Edge\Services\EdgeLoadBalancerProvisioner;
use App\Modules\Edge\Support\EdgeLoadBalancing;
use App\Modules\Providers\Cloudflare\EdgeCloudflareClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function lbSite(array $lb): Site
{
    // Unsaved site whose save()/refresh() are no-ops, so meta stays in memory.
    $site = new class(['edge_backend' => 'dply_edge', 'meta' => ['edge' => ['runtime_mode' => 'hybrid', 'load_balancing' => $lb]]]) extends Site
    {
        public function save(array $options = []): bool
        {
            return true;
        }

        public function refresh(): static
        {
            return $this;
        }
    };
    $site->id = '01abcsite';

    return $site;
}

beforeEach(function () {
    config(['edge.cloudflare.worker_zone_name' => 'on-dply.live']);
});

test('sync creates monitor, pool and load balancer and records their ids', function () {
    Http::fake([
        'api.cloudflare.com/client/v4/zones?*' => Http::response(['success' => true, 'result' => [['id' => 'zone1']]]),
        'api.cloudflare.com/client/v4/accounts/acct/load_balancers/monitors' => Http::response(['success' => true, 'result' => ['id' => 'mon1']]),
        'api.cloudflare.com/client/v4/accounts/acct/load_balancers/pools' => Http::response(['success' => true, 'result' => ['id' => 'pool1']]),
        'api.cloudflare.com/client/v4/zones/zone1/load_balancers' => Http::response(['success' => true, 'result' => ['id' => 'lb1']]),
    ]);
    $site = lbSite([
        'enabled' => true,
        'endpoints' => [
            ['name' => 'a', 'address' => '203.0.113.10', 'weight' => 1],
            ['name' => 'b', 'address' => 'app2.example.com', 'port' => 8443, 'weight' => 0.5, 'host_header' => 'app.example.com'],
        ],
    ]);

    (new EdgeLoadBalancerProvisioner(new EdgeCloudflareClient('acct', 'token')))->sync($site);

    $cfg = EdgeLoadBalancing::config($site);
    expect($cfg['status'])->toBe('active')
        ->and($cfg['cf'])->toMatchArray(['monitor_id' => 'mon1', 'pool_id' => 'pool1', 'lb_id' => 'lb1', 'hostname' => 'lb-01abcsite.on-dply.live'])
        ->and(EdgeLoadBalancing::originUrl($site))->toBe('https://lb-01abcsite.on-dply.live')
        ->and(EdgeLoadBalancing::billableEndpointCount($site))->toBe(2);

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/pools')
        && $r['monitor'] === 'mon1'
        && $r['origins'][1] === ['name' => 'b', 'address' => 'app2.example.com', 'port' => 8443, 'weight' => 0.5, 'enabled' => true, 'header' => ['Host' => ['app.example.com']]]);
});

test('existing ids are updated in place, and a 404 falls back to create', function () {
    Http::fake([
        'api.cloudflare.com/client/v4/zones?*' => Http::response(['success' => true, 'result' => [['id' => 'zone1']]]),
        'api.cloudflare.com/client/v4/accounts/acct/load_balancers/monitors/mon1' => Http::response(['success' => true, 'result' => ['id' => 'mon1']]),
        'api.cloudflare.com/client/v4/accounts/acct/load_balancers/pools/gone' => Http::response(['success' => false, 'errors' => [['message' => 'not found']]], 404),
        'api.cloudflare.com/client/v4/accounts/acct/load_balancers/pools' => Http::response(['success' => true, 'result' => ['id' => 'pool2']]),
        'api.cloudflare.com/client/v4/zones/zone1/load_balancers/lb1' => Http::response(['success' => true, 'result' => ['id' => 'lb1']]),
    ]);
    $site = lbSite([
        'enabled' => true,
        'endpoints' => [['name' => 'a', 'address' => '203.0.113.10']],
        'cf' => ['monitor_id' => 'mon1', 'pool_id' => 'gone', 'lb_id' => 'lb1', 'hostname' => 'lb-01abcsite.on-dply.live'],
    ]);

    (new EdgeLoadBalancerProvisioner(new EdgeCloudflareClient('acct', 'token')))->sync($site);

    expect(EdgeLoadBalancing::config($site)['cf']['pool_id'])->toBe('pool2');
    Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && str_ends_with($r->url(), '/load_balancers/lb1') && $r['default_pools'] === ['pool2']);
});

test('disabling tears down load balancer, pool and monitor and stops billing', function () {
    Http::fake(['api.cloudflare.com/*' => Http::response(['success' => true, 'result' => ['id' => 'x']])]);
    $site = lbSite([
        'enabled' => false,
        'endpoints' => [['name' => 'a', 'address' => '203.0.113.10']],
        'status' => 'active',
        'cf' => ['monitor_id' => 'mon1', 'pool_id' => 'pool1', 'lb_id' => 'lb1', 'zone_id' => 'zone1', 'hostname' => 'lb-x.on-dply.live'],
    ]);

    (new EdgeLoadBalancerProvisioner(new EdgeCloudflareClient('acct', 'token')))->sync($site);

    $deletes = collect(Http::recorded())->map(fn ($pair) => $pair[0])->filter(fn (Request $r) => $r->method() === 'DELETE')->map(fn (Request $r) => $r->url())->values()->all();
    expect($deletes)->toBe([
        'https://api.cloudflare.com/client/v4/zones/zone1/load_balancers/lb1',
        'https://api.cloudflare.com/client/v4/accounts/acct/load_balancers/pools/pool1',
        'https://api.cloudflare.com/client/v4/accounts/acct/load_balancers/monitors/mon1',
    ])
        ->and(EdgeLoadBalancing::config($site)['cf'])->toBe([])
        ->and(EdgeLoadBalancing::originUrl($site))->toBeNull()
        ->and(EdgeLoadBalancing::billableEndpointCount($site))->toBe(0);
});

test('a failed provision does not bill and does not reroute traffic', function () {
    Http::fake([
        'api.cloudflare.com/client/v4/zones?*' => Http::response(['success' => true, 'result' => [['id' => 'zone1']]]),
        'api.cloudflare.com/client/v4/accounts/acct/load_balancers/monitors' => Http::response(['success' => false, 'errors' => [['message' => 'Load Balancing is not enabled']]], 403),
    ]);
    $site = lbSite(['enabled' => true, 'endpoints' => [['name' => 'a', 'address' => '203.0.113.10']]]);

    expect(fn () => (new EdgeLoadBalancerProvisioner(new EdgeCloudflareClient('acct', 'token')))->sync($site))
        ->toThrow(\RuntimeException::class, 'Load Balancing is not enabled');

    expect(EdgeLoadBalancing::config($site)['status'])->toBe('error')
        ->and(EdgeLoadBalancing::billableEndpointCount($site))->toBe(0)
        ->and(EdgeLoadBalancing::originUrl($site))->toBeNull();
});
