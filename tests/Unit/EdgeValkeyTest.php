<?php

use App\Models\Site;
use App\Modules\Edge\Support\EdgeContainerConnections;
use App\Modules\Edge\Support\EdgeValkey;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['edge.valkey.api_url' => 'http://gateway.test', 'edge.valkey.token' => 'tok', 'edge.valkey.domain' => 'cache.dply.test', 'edge.valkey.port' => 6380]);
});

function valkeySite(): Site
{
    $site = new Site;
    $site->forceFill(['id' => '01ABCDEFGHJKMNPQRSTVWXYZ12']);

    return $site;
}

test('provisioning a flex database asks the gateway for its size and sleep time', function () {
    Http::fake(['gateway.test/*' => Http::response(['id' => 'x'])]);

    $v = EdgeValkey::provision(valkeySite(), 'My Cache!', 'flex_1g', 900);

    expect($v['target'])->toBe('valkey:01abcdefghjkmnpqrstvwxyz12-my-cache');
    expect($v['url'])->toStartWith('rediss://default:')->toEndWith('@01abcdefghjkmnpqrstvwxyz12-my-cache.cache.dply.test:6380');
    Http::assertSent(fn ($request): bool => $request->method() === 'PUT'
        && $request->url() === 'http://gateway.test/tenants/01abcdefghjkmnpqrstvwxyz12-my-cache'
        && $request->hasHeader('Authorization', 'Bearer tok')
        && $request['memory_mb'] === 1024
        && $request['sleep_after'] === 900
        && $request['persistent'] === false
        && strlen($request['password']) === 40);
});

test('pro databases never sleep and are persistent', function () {
    Http::fake(['gateway.test/*' => Http::response([])]);

    EdgeValkey::provision(valkeySite(), 'cache', 'pro_5g', 300);

    Http::assertSent(fn ($request): bool => $request['sleep_after'] === 0 && $request['persistent'] === true && $request['memory_mb'] === 5120);
});

test('an unknown sleep time falls back to five minutes', function () {
    expect(EdgeValkey::sleepAfter('flex_250m', 42))->toBe(300)
        ->and(EdgeValkey::sleepAfter('flex_250m', 0))->toBe(0)
        ->and(EdgeValkey::sleepAfter('pro_12g', 900))->toBe(0);
});

test('a resize keeps the password from REDIS_URL', function () {
    Http::fake(['gateway.test/*' => Http::response([])]);

    EdgeValkey::update('valkey:abc-cache', 'rediss://default:s%2Fecret@abc-cache.cache.dply.test:6380', 'flex_2_5g', 3600);

    Http::assertSent(fn ($request): bool => $request->url() === 'http://gateway.test/tenants/abc-cache' && $request['password'] === 's/ecret' && $request['memory_mb'] === 2560 && $request['sleep_after'] === 3600);
});

test('deleting a Valkey connection deletes the tenant', function () {
    Http::fake(['gateway.test/*' => Http::response(null, 204)]);

    EdgeContainerConnections::destroy('redis', 'valkey:abc-cache');

    Http::assertSent(fn ($request): bool => $request->method() === 'DELETE' && $request->url() === 'http://gateway.test/tenants/abc-cache');
});

test('a Valkey class is kept as the connection plan', function () {
    $row = EdgeContainerConnections::normalize(['kind' => 'redis', 'name' => 'CACHE', 'host' => 'dply.app.cache.internal', 'target' => 'valkey:abc-cache', 'plan' => 'pro_25g']);

    expect($row['plan'])->toBe('pro_25g');
});
