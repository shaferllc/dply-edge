<?php

declare(strict_types=1);

use App\Enums\SiteType;
use App\Livewire\Sites\Edge\Workspace\Resources;
use App\Models\EdgeRealtimeApp;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Modules\Edge\Services\Realtime\EdgeRealtimeApps;
use App\Modules\Edge\Support\EdgeContainerConnections;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'edge.cloudflare.account_id' => 'acct',
        'edge.cloudflare.api_token' => 'tok',
        'edge.realtime.host' => 'realtime-apps.test',
        'edge.realtime.kv_namespace_id' => 'rt-ns',
        'edge.realtime.app_host_suffix' => 'realtime.dply.io',
        'edge.realtime.per_app_hosts' => false,
        'edge.testing_domains' => ['on-dply.site'],
    ]);
    Http::fake(['api.cloudflare.com/*' => Http::response(['success' => true, 'result' => null])]);
});

/** @return array{0: Site, 1: User} */
function rtHostsSite(array $edge = ['live_url' => 'https://shop-a1b2c3.on-dply.site'], string $name = 'Shop'): array
{
    $org = Organization::factory()->create(['comped_until' => now()->addYear()]);
    $site = Site::factory()->create([
        'name' => $name,
        'organization_id' => $org->id,
        'server_id' => Server::factory()->create(['organization_id' => $org->id])->id,
        'edge_backend' => 'dply_edge',
        'type' => SiteType::Static,
        'status' => Site::STATUS_EDGE_ACTIVE,
        'meta' => ['edge' => ['runtime_mode' => 'container', 'build' => ['framework' => 'laravel']] + $edge],
    ]);
    $user = User::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    $site->forceFill(['user_id' => $user->id])->save();
    $site->server->forceFill(['user_id' => $user->id, 'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE]])->save();

    return [$site->refresh(), $user];
}

function rtHostsProvision(Site $site): EdgeRealtimeApp
{
    $app = app(EdgeRealtimeApps::class)->provision($site, 'Chat');
    EdgeContainerConnections::attach($site, 'realtime', 'REALTIME', $app->id);
    $site->refresh();

    return $app;
}

test('the label is the site label on its dply host', function () {
    [$site] = rtHostsSite();

    expect(EdgeRealtimeApps::labelFor($site))->toBe('shop-a1b2c3')
        ->and(rtHostsProvision($site)->hostname)->toBe('shop-a1b2c3.realtime.dply.io');
});

test('a site off the dply zones falls back to a name slug with a random suffix', function () {
    [$site] = rtHostsSite(['routing' => ['hostname' => 'www.example.com']], 'My Shop!');

    expect(EdgeRealtimeApps::labelFor($site))->toMatch('/^my-shop-[a-z0-9]{6}$/')
        ->and(EdgeRealtimeApps::labelFor(null, 'Chat'))->toMatch('/^chat-[a-z0-9]{6}$/')
        ->and(EdgeRealtimeApps::labelFor(null, '!!!'))->toMatch('/^app-[a-z0-9]{6}$/');

    [$long] = rtHostsSite(['routing' => ['hostname' => 'www.example.com']], str_repeat('very long name ', 10));
    $label = EdgeRealtimeApps::labelFor($long);
    expect(strlen($label))->toBeLessThanOrEqual(63)
        ->and(EdgeRealtimeApps::isDnsLabel($label))->toBeTrue();
});

test('DNS label validation', function (string $label, bool $valid) {
    expect(EdgeRealtimeApps::isDnsLabel($label))->toBe($valid);
})->with([
    ['shop', true], ['a', true], ['a-1', true], [str_repeat('a', 63), true],
    ['', false], ['-a', false], ['a-', false], ['A', false], ['a.b', false], ['a_b', false], [str_repeat('a', 64), false],
]);

test('a taken hostname gets -2, -3, within 63 characters', function () {
    [$site] = rtHostsSite();

    expect(rtHostsProvision($site)->hostname)->toBe('shop-a1b2c3.realtime.dply.io')
        ->and(app(EdgeRealtimeApps::class)->provision($site, 'Two')->hostname)->toBe('shop-a1b2c3-2.realtime.dply.io')
        ->and(app(EdgeRealtimeApps::class)->provision($site, 'Three')->hostname)->toBe('shop-a1b2c3-3.realtime.dply.io');

    $long = str_repeat('a', 63);
    EdgeRealtimeApp::query()->first()->forceFill(['hostname' => $long.'.realtime.dply.io'])->save();
    expect(EdgeRealtimeApps::uniqueHostname($long))->toBe(str_repeat('a', 61).'-2.realtime.dply.io');
});

test('the migration backfills existing rows', function () {
    [$site] = rtHostsSite();
    [$other] = rtHostsSite(['routing' => ['hostname' => 'www.example.com']], 'Other');
    $a = rtHostsProvision($site);
    $b = app(EdgeRealtimeApps::class)->provision($other, 'Chat');
    $c = app(EdgeRealtimeApps::class)->provision($site, 'Second');

    Schema::table('edge_realtime_apps', fn ($table) => $table->dropColumn('hostname'));
    (require database_path('migrations/2026_09_26_210000_add_hostname_to_edge_realtime_apps.php'))->up();

    expect($a->refresh()->hostname)->toBe('shop-a1b2c3.realtime.dply.io')
        ->and($b->refresh()->hostname)->toMatch('/^other-[a-z0-9]{6}\.realtime\.dply\.io$/')
        ->and($c->refresh()->hostname)->toBe('shop-a1b2c3-2.realtime.dply.io');
});

test('the KV record carries the hostname', function () {
    [$site] = rtHostsSite();
    $app = rtHostsProvision($site);

    expect(json_decode(EdgeRealtimeApps::record($app), true)['hostname'])->toBe('shop-a1b2c3.realtime.dply.io');
    Http::assertSent(fn (Request $r): bool => $r->method() === 'PUT'
        && str_ends_with($r->url(), rawurlencode('key:'.$app->app_key))
        && str_contains($r->body(), '"hostname":"shop-a1b2c3.realtime.dply.io"'));
});

test('env uses the app host only with per_app_hosts on', function () {
    [$site] = rtHostsSite();
    rtHostsProvision($site);
    $hosts = fn (): array => array_intersect_key(
        EdgeContainerConnections::realtimeDriverEnv($site),
        array_flip(['REVERB_HOST', 'PUSHER_HOST', 'VITE_REVERB_HOST', 'VITE_PUSHER_HOST']),
    );

    expect(array_unique(array_values($hosts())))->toBe(['realtime-apps.test']);

    config(['edge.realtime.per_app_hosts' => true]);
    expect(array_unique(array_values($hosts())))->toBe(['shop-a1b2c3.realtime.dply.io'])
        ->and(EdgeContainerConnections::realtimeBuildEnv($site)['VITE_REVERB_HOST'])->toBe('shop-a1b2c3.realtime.dply.io')
        ->and(collect(EdgeContainerConnections::realtimeWorkerBindings($site))->firstWhere('name', 'PUSHER_HOST')['text'])->toBe('shop-a1b2c3.realtime.dply.io');
});

test('an app without a hostname stays on the shared host with the flag on', function () {
    [$site] = rtHostsSite();
    $app = rtHostsProvision($site);
    $app->forceFill(['hostname' => null])->save();
    config(['edge.realtime.per_app_hosts' => true]);

    expect(EdgeContainerConnections::realtimeDriverEnv($site)['REVERB_HOST'])->toBe('realtime-apps.test');
});

test('control-plane calls keep the shared host with the flag on', function () {
    [$site] = rtHostsSite();
    $app = rtHostsProvision($site);
    config(['edge.realtime.per_app_hosts' => true]);
    Http::fake(['realtime-apps.test/*' => Http::response(['connections' => 1])]);

    app(EdgeRealtimeApps::class)->stats($app);

    Http::assertSent(fn (Request $r): bool => $r->url() === 'https://realtime-apps.test/apps/'.$app->id.'/stats');
});

test('the sheet shows the app host with the flag on, the shared host off', function () {
    [$site, $user] = rtHostsSite();
    rtHostsProvision($site);
    Http::fake(['*' => Http::response(['success' => true, 'result' => null])]);
    $open = fn () => Livewire::actingAs($user)->test(Resources::class, ['server' => $site->server, 'site' => $site])
        ->call('openResource', EdgeContainerConnections::resourceHost($site, 'realtime'));

    $open()->assertSee('wss://realtime-apps.test')->assertDontSee('shop-a1b2c3.realtime.dply.io');

    config(['edge.realtime.per_app_hosts' => true]);
    $open()->assertSee('wss://shop-a1b2c3.realtime.dply.io')
        ->assertSee('REVERB_HOST=shop-a1b2c3.realtime.dply.io')
        ->assertSee("host: 'shop-a1b2c3.realtime.dply.io'", false);
});

test('with custom domains on, provisioning attaches the app hostname to the relay Worker', function () {
    config(['edge.realtime.custom_domains' => true, 'edge.realtime.zone_id' => 'zone-dply-io', 'edge.realtime.worker' => 'dply-realtime-apps']);
    [$site] = rtHostsSite();

    $app = app(EdgeRealtimeApps::class)->provision($site, 'Chat');

    Http::assertSent(fn (Request $r): bool => $r->method() === 'PUT'
        && str_ends_with($r->url(), '/accounts/acct/workers/domains')
        && $r['hostname'] === 'shop-a1b2c3.realtime.dply.io'
        && $r['service'] === 'dply-realtime-apps'
        && $r['zone_id'] === 'zone-dply-io');
    expect($app->exists)->toBeTrue();
});

test('a failed domain attach undoes the app: no row, no KV record', function () {
    config(['edge.realtime.custom_domains' => true, 'edge.realtime.zone_id' => 'zone-dply-io']);
    Http::swap(new Factory); // drop beforeEach's catch-all fake
    Http::fake([
        'api.cloudflare.com/client/v4/accounts/acct/workers/domains' => Http::response(['success' => false, 'errors' => [['code' => 100116, 'message' => 'hostname in use']]], 409),
        'api.cloudflare.com/*' => Http::response(['success' => true, 'result' => null]),
    ]);
    [$site] = rtHostsSite();

    expect(fn () => app(EdgeRealtimeApps::class)->provision($site, 'Chat'))->toThrow(Exception::class);
    expect(EdgeRealtimeApp::query()->count())->toBe(0);
    Http::assertSent(fn (Request $r): bool => $r->method() === 'DELETE' && str_contains($r->url(), '/storage/kv/namespaces/rt-ns/values/id%3A'));
});

test('without a zone id, custom domains refuse to provision rather than half-create', function () {
    config(['edge.realtime.custom_domains' => true, 'edge.realtime.zone_id' => null]);
    [$site] = rtHostsSite();

    expect(fn () => app(EdgeRealtimeApps::class)->provision($site, 'Chat'))->toThrow(RuntimeException::class, 'EDGE_REALTIME_ZONE_ID');
    expect(EdgeRealtimeApp::query()->count())->toBe(0);
});

test('destroy detaches the custom domain', function () {
    config(['edge.realtime.custom_domains' => true, 'edge.realtime.zone_id' => 'zone-dply-io']);
    Http::swap(new Factory); // drop beforeEach's catch-all fake
    Http::fake([
        'api.cloudflare.com/client/v4/accounts/acct/workers/domains?*' => Http::response(['success' => true, 'result' => [['id' => 'dom-1', 'hostname' => 'shop-a1b2c3.realtime.dply.io']]]),
        '*' => Http::response(['success' => true, 'result' => null]),
    ]);
    [$site] = rtHostsSite();
    $app = app(EdgeRealtimeApps::class)->provision($site, 'Chat');

    app(EdgeRealtimeApps::class)->destroy($app);

    Http::assertSent(fn (Request $r): bool => $r->method() === 'DELETE' && str_ends_with($r->url(), '/workers/domains/dom-1'));
    expect(EdgeRealtimeApp::query()->count())->toBe(0);
});
