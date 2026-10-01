<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Edge\EdgeKvInstantTest;

use App\Models\EdgeDeployment;
use App\Models\EdgeRealtimeApp;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Modules\Billing\Services\StarterTrafficGate;
use App\Modules\Edge\Jobs\EdgeKvWriteJob;
use App\Modules\Edge\Services\EdgeHostMapPublisher;
use App\Modules\Edge\Services\EdgeKvInstant;
use App\Modules\Edge\Services\Realtime\EdgeRealtimeApps;
use App\Modules\Edge\Support\EdgeDeliveryContext;
use App\Modules\Edge\Support\EdgeWranglerConfigGenerator;
use App\Modules\Providers\Cloudflare\EdgeCloudflareClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'edge.fake.enabled' => false,
        'edge.cloudflare.account_id' => 'acct',
        'edge.cloudflare.api_token' => 'tok',
        'edge.cloudflare.kv_namespace_id' => 'hostmap',
        'edge.r2.bucket' => 'bucket',
        'subscription.standard.trial.spending_limit_cents' => 0,
    ]);
    Http::fake(['api.cloudflare.com/*' => Http::response(['success' => true, 'result' => ['id' => 'new-ns']])]);
});

function containerSite(): Site
{
    $org = Organization::factory()->create();

    return Site::factory()->for($org)->for(Server::factory()->for($org)->create())->create([
        'meta' => ['edge' => ['runtime_mode' => 'container']],
    ]);
}

/** @return list<string> "METHOD namespace/key" of the KV writes sent */
function kvWrites(): array
{
    return collect(Http::recorded())->map(fn ($pair) => $pair[0])
        ->filter(fn (Request $r) => str_contains($r->url(), '/values/'))
        ->map(fn (Request $r) => $r->method().' '.urldecode(preg_replace('#^.*/namespaces/#', '', $r->url())))
        ->values()->all();
}

test('a namespace is created in instant mode only when asked', function () {
    $client = new EdgeCloudflareClient('acct', 'tok');
    $client->createKvNamespace('classic');
    $client->createKvNamespace('fast', 'instant');

    Http::assertSent(fn (Request $r) => $r['title'] === 'classic' && ! array_key_exists('mode', $r->data()));
    Http::assertSent(fn (Request $r) => $r['title'] === 'fast' && $r['mode'] === 'instant');
});

test('pause flags are written only when they change; the daily run rewrites them all', function () {
    $site = containerSite();
    $gate = app(StarterTrafficGate::class);

    $gate->syncAll();
    expect(kvWrites())->toBe(['PUT hostmap/values/container-pause:'.$site->id])
        ->and($site->fresh()->edgeMeta()['traffic_gate'])->toBeTrue();

    $gate->syncAll();
    expect(kvWrites())->toHaveCount(1);

    $gate->syncAll(force: true);
    expect(kvWrites())->toHaveCount(2);
});

test('with a gates namespace the pause flag also goes there, queued in instant mode and read back from the site', function () {
    config(['edge.cloudflare.gates_kv_namespace_id' => 'gates', 'edge.kv_instant.enabled' => true]);
    Queue::fake();
    $site = containerSite();

    app(StarterTrafficGate::class)->syncAll();

    expect(kvWrites())->toBe(['PUT hostmap/values/container-pause:'.$site->id]); // dual write
    Queue::assertPushed(EdgeKvWriteJob::class, fn (EdgeKvWriteJob $job) => $job->namespace === 'gates'
        && $job->key === 'container-pause:'.$site->id && $job->uniqueId() === 'gates:container-pause:'.$site->id);
    expect(app(EdgeKvInstant::class)->value('container-pause:'.$site->id, EdgeKvInstant::GATE, [(string) $site->id]))->toBe('1');

    // Without dual write the host map is left alone.
    config(['edge.kv_instant.dual_write' => false]);
    $site->mergeEdgeMeta(['traffic_gate' => null]);
    $site->save();
    app(StarterTrafficGate::class)->syncAll();
    expect(kvWrites())->toHaveCount(1);
});

test('on classic KV (instant off) the gates namespace is written straight away', function () {
    config(['edge.cloudflare.gates_kv_namespace_id' => 'gates']);
    Queue::fake();
    $site = containerSite();

    app(StarterTrafficGate::class)->syncAll();

    Queue::assertNothingPushed();
    expect(kvWrites())->toBe(['PUT hostmap/values/container-pause:'.$site->id, 'PUT gates/values/container-pause:'.$site->id]);
});

test('routes pointers: an immutable payload, the pointer queued, the old payload collected later; aliases get none', function () {
    config(['edge.cloudflare.routes_kv_namespace_id' => 'routes', 'edge.kv_instant.enabled' => true]);
    Queue::fake();
    $site = containerSite();
    $deployment = EdgeDeployment::query()->create(['site_id' => $site->id, 'organization_id' => $site->organization_id, 'status' => EdgeDeployment::STATUS_LIVE, 'storage_prefix' => 'edge/test/prefix']);
    $publisher = app(EdgeHostMapPublisher::class);

    $publisher->publishHostname($site, $deployment, 'App.example.com', EdgeDeliveryContext::platform());
    $hash = Cache::get(EdgeHostMapPublisher::ROUTE_CACHE.'app.example.com');
    expect(kvWrites())->toBe(['PUT hostmap/values/app.example.com', 'PUT hostmap/values/payload:app.example.com:'.$hash]);
    Queue::assertPushed(EdgeKvWriteJob::class, fn (EdgeKvWriteJob $j) => $j->namespace === 'routes' && $j->key === 'app.example.com');
    expect(app(EdgeKvInstant::class)->value('app.example.com', EdgeKvInstant::ROUTE))->toBe($hash);

    // A changed payload: a new one, and the old one collected unless the pointer comes back to it.
    $site->mergeEdgeMeta(['deploy_footer' => ['enabled' => true]]);
    $site->save();
    $publisher->publishHostname($site->fresh(), $deployment, 'app.example.com', EdgeDeliveryContext::platform());
    Queue::assertPushed(EdgeKvWriteJob::class, fn (EdgeKvWriteJob $j) => $j->kind === EdgeKvInstant::PAYLOAD_GC && $j->key === 'payload:app.example.com:'.$hash);
    expect(app(EdgeKvInstant::class)->value('payload:app.example.com:'.$hash, EdgeKvInstant::PAYLOAD_GC, ['app.example.com', $hash]))->toBeNull();
    $current = Cache::get(EdgeHostMapPublisher::ROUTE_CACHE.'app.example.com');
    expect(app(EdgeKvInstant::class)->value('x', EdgeKvInstant::PAYLOAD_GC, ['app.example.com', $current]))->toBeFalse();

    // A preview alias: the full entry only.
    $before = count(kvWrites());
    $publisher->publishHostname($site, $deployment, 'alias.example.com', EdgeDeliveryContext::platform(), false, false);
    expect(array_slice(kvWrites(), $before))->toBe(['PUT hostmap/values/alias.example.com']);
});

test('routes are not used on classic KV', function () {
    config(['edge.cloudflare.routes_kv_namespace_id' => 'routes']);
    Queue::fake();
    $site = containerSite();
    $deployment = EdgeDeployment::query()->create(['site_id' => $site->id, 'organization_id' => $site->organization_id, 'status' => EdgeDeployment::STATUS_LIVE, 'storage_prefix' => 'edge/test/prefix']);

    app(EdgeHostMapPublisher::class)->publishHostname($site, $deployment, 'app.example.com', EdgeDeliveryContext::platform());

    expect(kvWrites())->toBe(['PUT hostmap/values/app.example.com']);
    Queue::assertNothingPushed();
});

test('realtime records are queued in instant mode, and a key: entry for an old key is deleted', function () {
    config(['edge.realtime.kv_instant' => true, 'edge.realtime.kv_namespace_id' => 'rt']);
    Queue::fake();
    $site = containerSite();
    $app = EdgeRealtimeApp::query()->create([
        'organization_id' => $site->organization_id, 'site_id' => $site->id, 'name' => 'x', 'hostname' => 'x-rt',
        'app_key' => 'rtk_old', 'app_secret' => 'rts_s', 'status' => EdgeRealtimeApp::STATUS_ACTIVE, 'max_connections' => 100,
    ]);

    app(EdgeRealtimeApps::class)->sync($app);

    Queue::assertPushed(EdgeKvWriteJob::class, 2);
    $kv = app(EdgeKvInstant::class);
    expect(json_decode($kv->value('id:'.$app->id, EdgeKvInstant::REALTIME, [$app->id]), true)['key'])->toBe('rtk_old');
    $app->update(['app_key' => 'rtk_new']);
    expect($kv->value('key:rtk_old', EdgeKvInstant::REALTIME, [$app->id]))->toBeNull()
        ->and($kv->value('key:rtk_new', EdgeKvInstant::REALTIME, [$app->id]))->not->toBeNull();
});

test('the platform Worker binds GATES and ROUTES only when configured (ROUTES only in instant mode)', function () {
    $toml = fn () => file_get_contents((new EdgeWranglerConfigGenerator)->write(EdgeDeliveryContext::platform()));

    expect($toml())->not->toContain('GATES')->not->toContain('ROUTES');
    config(['edge.cloudflare.gates_kv_namespace_id' => 'gates', 'edge.cloudflare.routes_kv_namespace_id' => 'routes']);
    expect($toml())->toContain("binding = \"GATES\"\nid = \"gates\"")->not->toContain('ROUTES');
    config(['edge.kv_instant.enabled' => true]);
    expect($toml())->toContain("binding = \"ROUTES\"\nid = \"routes\"");
});
