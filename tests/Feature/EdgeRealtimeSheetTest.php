<?php

declare(strict_types=1);

use App\Enums\SiteType;
use App\Livewire\Sites\Edge\Workspace\Environment;
use App\Livewire\Sites\Edge\Workspace\Resources;
use App\Models\EdgeRealtimeApp;
use App\Models\EdgeRealtimeUsage;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Modules\Edge\Services\Realtime\EdgeRealtimeApps;
use App\Modules\Edge\Support\EdgeContainerConnections;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'edge.cloudflare.account_id' => 'acct',
        'edge.cloudflare.api_token' => 'tok',
        'edge.realtime.host' => 'realtime-apps.test',
        'edge.realtime.kv_namespace_id' => 'rt-ns',
    ]);
});

/** @return array{0: Site, 1: User, 2: EdgeRealtimeApp} */
function rtSheetSite(string $runtime = 'container'): array
{
    $org = Organization::factory()->create(['comped_until' => now()->addYear()]);
    $site = Site::factory()->create([
        'organization_id' => $org->id,
        'server_id' => Server::factory()->create(['organization_id' => $org->id])->id,
        'edge_backend' => 'dply_edge',
        'type' => SiteType::Static,
        'status' => Site::STATUS_EDGE_ACTIVE,
        'meta' => ['edge' => ['runtime_mode' => $runtime, 'build' => ['framework' => 'laravel']]],
    ]);
    $user = User::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    $site->forceFill(['user_id' => $user->id])->save();
    $site->server->forceFill(['user_id' => $user->id, 'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE]])->save();

    Http::fake(['api.cloudflare.com/*' => Http::response(['success' => true, 'result' => null])]);
    $app = app(EdgeRealtimeApps::class)->provision($site, 'Chat');
    EdgeContainerConnections::attach($site, 'realtime', 'REALTIME', $app->id);

    return [$site->refresh(), $user, $app];
}

function rtSheetOpen(Site $site, User $user): Testable
{
    return Livewire::actingAs($user)->test(Resources::class, ['server' => $site->server, 'site' => $site])
        ->call('openResource', EdgeContainerConnections::resourceHost($site, 'realtime'));
}

function rtFakeRelay(array $stats = [], int $status = 200): void
{
    Http::fake([
        'api.cloudflare.com/*' => Http::response(['success' => true, 'result' => null]),
        'realtime-apps.test/*' => Http::response($stats, $status),
    ]);
}

test('the sheet opens with its tabs, and the secret is nowhere in the page', function () {
    [$site, $user, $app] = rtSheetSite();

    $page = rtSheetOpen($site, $user)
        ->assertDispatched('open-modal', 'resources-realtime')
        ->assertSeeInOrder(['Overview', 'Connect', 'Try it', 'Credentials', 'Settings'])
        ->assertSee($app->app_key)
        ->assertSee("'driver' => 'reverb'")
        ->assertSee('REVERB_APP_SECRET=••••')
        ->assertSee('Rotate secret')
        ->assertSee('Delete Realtime');

    expect($page->html())->not->toContain($app->app_secret);
});

test('stats load on open', function () {
    [$site, $user, $app] = rtSheetSite();
    rtFakeRelay(['connections' => 12, 'peak_connections' => 40, 'connection_seconds' => 5, 'messages_in' => 3, 'messages_out' => 1500, 'updated_at' => 1]);

    rtSheetOpen($site, $user)
        ->call('realtimeLoad')
        ->assertSet('realtimeError', null)
        ->assertSet('realtimeStats.connections', 12)
        ->assertSet('realtimeStats.peak_connections', 40)
        ->assertSee('Most at once');

    Http::assertSent(fn (Request $r): bool => $r->url() === 'https://realtime-apps.test/apps/'.$app->id.'/stats');
});

test('a relay that is down gives a friendly note', function () {
    [$site, $user] = rtSheetSite();
    rtFakeRelay([], 503);

    rtSheetOpen($site, $user)
        ->call('realtimeLoad')
        ->assertSet('realtimeStats', null)
        ->assertSee('The realtime relay isn’t reachable yet', false);
});

test('send test publishes dply.test, signed, to the browser channel', function () {
    [$site, $user, $app] = rtSheetSite();
    rtFakeRelay();

    rtSheetOpen($site, $user)
        ->call('realtimeSendTest', 'dply-test.abc12345', 'n0nce')
        ->assertReturned(['ok' => true, 'error' => null]);

    Http::assertSent(function (Request $r) use ($app): bool {
        if (! str_starts_with($r->url(), 'https://realtime-apps.test/apps/'.$app->id.'/events?')) {
            return false;
        }
        parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);
        $body = json_decode($r->body(), true);

        return $q === EdgeRealtimeApps::signedQuery($app->app_key, $app->app_secret, '/apps/'.$app->id.'/events', $r->body(), (string) $q['auth_timestamp'])
            && $body['name'] === 'dply.test'
            && $body['channels'] === ['dply-test.abc12345']
            && json_decode($body['data'], true)['nonce'] === 'n0nce';
    });
});

test('send test refuses a channel that is not a test channel', function () {
    [$site, $user] = rtSheetSite();
    rtFakeRelay();

    rtSheetOpen($site, $user)
        ->call('realtimeSendTest', 'private-orders', 'n0nce')
        ->assertReturned(fn ($r) => $r['ok'] === false);

    Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), '/events'));
});

test('rotating the secret rewrites KV and asks for a redeploy', function () {
    [$site, $user, $app] = rtSheetSite();
    $old = $app->app_secret;
    rtFakeRelay();

    $page = rtSheetOpen($site, $user)->call('realtimeRotateSecret')->assertHasNoErrors();

    $new = $app->fresh()->app_secret;
    expect($new)->not->toBe($old)
        ->and($site->fresh()->edgeMeta()['settings_saved_at'] ?? null)->not->toBeNull()
        ->and($page->html())->not->toContain($new);
    Http::assertSent(fn (Request $r): bool => $r->method() === 'PUT' && str_contains($r->url(), rawurlencode('id:'.$app->id)) && str_contains($r->body(), $new));
});

test('saving settings syncs KV with the new size, origins and client events', function () {
    [$site, $user, $app] = rtSheetSite();
    rtFakeRelay();

    rtSheetOpen($site, $user)
        ->call('realtimeLoad')
        ->set('realtimeEditMax', 1000)
        ->set('realtimeEditOrigins', "https://Example.com/\nhttps://app.example.com")
        ->set('realtimeEditClientEvents', true)
        ->call('realtimeSaveSettings')
        ->assertHasNoErrors();

    $app->refresh();
    expect($app->max_connections)->toBe(1000)
        ->and($app->allowed_origins)->toBe(['https://example.com', 'https://app.example.com'])
        ->and($app->client_events)->toBeTrue();
    Http::assertSent(fn (Request $r): bool => $r->method() === 'PUT'
        && str_contains($r->url(), rawurlencode('key:'.$app->app_key))
        && str_contains($r->body(), '"maxConnections":1000,"allowedOrigins":["https://example.com","https://app.example.com"],"clientEvents":true'));
});

test('settings refuse a bad origin or size', function () {
    [$site, $user, $app] = rtSheetSite();
    rtFakeRelay();

    rtSheetOpen($site, $user)
        ->set('realtimeEditMax', 1000)
        ->set('realtimeEditOrigins', 'example.com')
        ->call('realtimeSaveSettings')
        ->assertHasErrors('realtimeSettings')
        ->set('realtimeEditOrigins', '')
        ->set('realtimeEditMax', 7)
        ->call('realtimeSaveSettings')
        ->assertHasErrors('realtimeSettings');

    expect($app->fresh()->max_connections)->toBe(200);
});

test('the map card shows the relay host and size, with no Detach', function () {
    [$site, $user] = rtSheetSite();

    Livewire::actingAs($user)->test(Resources::class, ['server' => $site->server, 'site' => $site])
        ->assertSee('realtime-apps.test')
        ->assertSee('Up to 200 connections');
});

test('the environment page lists the realtime keys with the secret masked', function (string $runtime) {
    [$site, $user, $app] = rtSheetSite($runtime);

    $page = Livewire::actingAs($user)->test(Environment::class, ['server' => $site->server, 'site' => $site])
        ->assertSee('From resources')
        ->assertSee('REVERB_APP_KEY='.$app->app_key)
        ->assertSee('REVERB_APP_SECRET=••••')
        ->assertSee('PUSHER_APP_SECRET=••••');

    expect($page->html())->not->toContain($app->app_secret);
})->with(['container', 'ssr']);

test('the overview shows this month’s collected messages and cost', function () {
    [$site, $user, $app] = rtSheetSite();
    EdgeRealtimeUsage::query()->create([
        'organization_id' => $site->organization_id, 'site_id' => $site->id, 'realtime_app_id' => $app->id,
        'date' => now()->toDateString(), 'connection_seconds' => 60, 'messages' => 4200, 'peak_connections' => 3,
    ]);

    rtSheetOpen($site, $user)
        ->assertSee('4.2K')
        ->assertSee('This month so far')
        ->assertSee('paid from your plan’s included usage credit first');
});
