<?php

declare(strict_types=1);

namespace Tests\Feature\EdgeRealtimeBillingTest;

use App\Enums\SiteType;
use App\Livewire\Sites\Edge\Workspace\Resources;
use App\Models\EdgeRealtimeApp;
use App\Models\EdgeRealtimeUsage;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Modules\Billing\Services\EdgeRealtimeCost;
use App\Modules\Billing\Services\OrganizationBillingStateComputer;
use App\Modules\Billing\Services\OrganizationDataPurger;
use App\Modules\Edge\Jobs\TeardownEdgeSiteJob;
use App\Modules\Edge\Services\Realtime\EdgeRealtimeApps;
use App\Modules\Edge\Services\Realtime\EdgeRealtimeUsageCollector;
use App\Modules\Edge\Support\EdgeContainerConnections;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
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

/** @return array{0: Organization, 1: Site, 2: User} */
function site(bool $comped = true): array
{
    $org = Organization::factory()->create($comped ? ['comped_until' => now()->addYear()] : []);
    $user = User::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    $server = Server::factory()->create(['organization_id' => $org->id, 'user_id' => $user->id, 'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE]]);
    $site = Site::factory()->create([
        'organization_id' => $org->id, 'server_id' => $server->id, 'user_id' => $user->id,
        'edge_backend' => 'dply_edge', 'type' => SiteType::Static, 'status' => Site::STATUS_EDGE_ACTIVE,
        'meta' => ['edge' => ['runtime_mode' => 'container', 'build' => ['framework' => 'laravel']]],
    ]);

    return [$org, $site, $user];
}

/**
 * An attached app, written straight to the table so no HTTP fake is used up
 * (Laravel matches the first registered fake, so each test fakes once).
 */
function realtimeApp(Site $site): EdgeRealtimeApp
{
    $app = EdgeRealtimeApp::query()->create([
        'organization_id' => $site->organization_id, 'site_id' => $site->id, 'name' => 'Chat',
        'app_key' => 'rtk_'.Str::random(24), 'app_secret' => 'rts_'.Str::random(40),
        'status' => EdgeRealtimeApp::STATUS_ACTIVE, 'max_connections' => 200,
    ]);
    EdgeContainerConnections::attach($site, 'realtime', 'REALTIME', $app->id);
    $site->refresh();

    return $app;
}

/** The relay's stats on each successive read. */
function relayReports(array ...$readings): void
{
    $sequence = Http::sequence();
    foreach ($readings as $r) {
        $sequence->push($r + ['connections' => 0, 'peak_connections' => 0, 'connection_seconds' => 0, 'messages_in' => 0, 'messages_out' => 0, 'updated_at' => 1]);
    }
    Http::fake([
        'realtime-apps.test/apps/*/stats/reset' => Http::response(['ok' => true]),
        'realtime-apps.test/apps/*/stats' => $sequence,
    ]);
}

function today(EdgeRealtimeApp $app): ?EdgeRealtimeUsage
{
    return EdgeRealtimeUsage::query()->where('realtime_app_id', $app->id)->where('date', now()->utc()->toDateString())->first();
}

test('the collector adds deltas, keeps the day peak, and resets the relay peak', function () {
    [, $site] = site();
    $app = realtimeApp($site);
    relayReports(
        ['connection_seconds' => 600, 'messages_in' => 10, 'messages_out' => 90, 'peak_connections' => 7],
        ['connection_seconds' => 900, 'messages_in' => 15, 'messages_out' => 135, 'peak_connections' => 3],
    );

    app(EdgeRealtimeUsageCollector::class)->collect();
    app(EdgeRealtimeUsageCollector::class)->collect();

    $row = today($app);
    expect($row->connection_seconds)->toBe(900)
        ->and($row->messages)->toBe(15) // publishes only: the 135 deliveries are free
        ->and($row->peak_connections)->toBe(7)
        ->and($row->site_id)->toBe($site->id)
        ->and($app->fresh()->meta)->toMatchArray(['last_connection_seconds' => 900, 'last_messages_in' => 15]);
    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/apps/'.$app->id.'/stats/reset') && $r->header('X-Dply-Secret') === [$app->app_secret]);
});

test('a counter below the last reading counts as all new', function () {
    [, $site] = site();
    $app = realtimeApp($site);
    $app->forceFill(['meta' => ['last_connection_seconds' => 5_000, 'last_messages_in' => 800]])->save();
    relayReports(['connection_seconds' => 120, 'messages_in' => 1, 'messages_out' => 9]);

    app(EdgeRealtimeUsageCollector::class)->collect();

    expect(today($app)->connection_seconds)->toBe(120)->and(today($app)->messages)->toBe(1);
});

test('an app last read when deliveries still billed starts its publish baseline now', function () {
    [, $site] = site();
    $app = realtimeApp($site);
    $app->forceFill(['meta' => ['last_connection_seconds' => 0, 'last_messages' => 800]])->save();
    relayReports(
        ['connection_seconds' => 60, 'messages_in' => 100, 'messages_out' => 900],
        ['connection_seconds' => 60, 'messages_in' => 104, 'messages_out' => 950],
    );

    app(EdgeRealtimeUsageCollector::class)->collect();
    app(EdgeRealtimeUsageCollector::class)->collect();

    expect(today($app)->messages)->toBe(4)
        ->and($app->fresh()->meta)->not->toHaveKey('last_messages');
});

test('a held lock skips the app', function () {
    [, $site] = site();
    $app = realtimeApp($site);
    relayReports(['connection_seconds' => 60]);

    $lock = Cache::lock('edge-realtime-usage:'.$app->id, 60);
    $lock->get();
    expect(app(EdgeRealtimeUsageCollector::class)->collect()['apps'])->toBe(0);
    $lock->release();
    expect(today($app))->toBeNull();
    Http::assertNothingSent();
});

test('an unreachable relay skips the app without throwing', function () {
    [, $site] = site();
    $app = realtimeApp($site);
    Http::fake(['realtime-apps.test/*' => Http::response('down', 502)]);

    expect(app(EdgeRealtimeUsageCollector::class)->collect()['apps'])->toBe(0)
        ->and(today($app))->toBeNull()
        ->and($app->fresh()->meta)->toBeNull();
});

test('the command is registered', function () {
    relayReports();
    $this->artisan('dply:edge:collect-realtime-usage', ['--dry-run' => true])->assertSuccessful();
});

test('every connection-minute and message bills at its fixed price: no allowance, no margin on top', function () {
    config(['dply.edge.usage_billing.margin_percent' => 20]);
    $cost = app(EdgeRealtimeCost::class);

    // 1M connection-minutes: $0.25 whatever the margin (fixed_price_meters).
    expect($cost->cents(1_000_000 * 60, 0))->toBe(25)
        // 1M messages: $0.62.
        ->and($cost->cents(0, 1_000_000))->toBe(62)
        ->and($cost->cents(0, 0))->toBe(0);
});

test('forOrganization sums the month, including a deleted app', function () {
    config(['dply.edge.usage_billing.margin_percent' => 20]);
    [$org] = site();
    EdgeRealtimeUsage::query()->create(['organization_id' => $org->id, 'realtime_app_id' => '01GONEGONEGONEGONEGONEGONE', 'date' => now()->toDateString(), 'connection_seconds' => 1_000_000 * 60, 'messages' => 0]);
    EdgeRealtimeUsage::query()->create(['organization_id' => $org->id, 'realtime_app_id' => '01OLDOLDOLDOLDOLDOLDOLDOLD', 'date' => now()->subMonths(2)->toDateString(), 'connection_seconds' => 9_000_000 * 60, 'messages' => 0]);

    expect(app(EdgeRealtimeCost::class)->forOrganization($org, now()->startOfMonth(), now()->endOfMonth()))
        ->toBe(['connection_seconds' => 1_000_000 * 60, 'messages' => 0, 'cents' => 25]);
});

test('the billing computer includes realtime in usage', function () {
    config(['dply.edge.usage_billing.margin_percent' => 20]);
    [$org] = site(false);
    EdgeRealtimeUsage::query()->create(['organization_id' => $org->id, 'realtime_app_id' => '01APPAPPAPPAPPAPPAPPAPPAPP', 'date' => now()->toDateString(), 'connection_seconds' => 0, 'messages' => 1_000_000]);

    expect(app(OrganizationBillingStateComputer::class)->computeForTier($org, 'pro')->usageLines()['realtime'] ?? 0)->toBe(62);
});

test('the card shows this app\'s cost this month', function () {
    config(['dply.edge.usage_billing.margin_percent' => 20]);
    [, $site, $user] = site();
    $app = realtimeApp($site);
    EdgeRealtimeUsage::query()->create(['organization_id' => $site->organization_id, 'site_id' => $site->id, 'realtime_app_id' => $app->id, 'date' => now()->toDateString(), 'connection_seconds' => 0, 'messages' => 1_000_000]);

    $component = Livewire::actingAs($user)->test(Resources::class, ['server' => $site->server, 'site' => $site])->instance();

    expect($component->realtimeCostCents(['target' => $app->id]))->toBe(62)
        ->and($component->realtimeCostCents(['target' => '']))->toBeNull();
});

test('the plan caps the size: provision rejects it, sync clamps the relay record', function () {
    [$org, $site] = site(); // comped = Team, 5,000
    Http::fake(['api.cloudflare.com/*' => Http::response(['success' => true, 'result' => null])]);
    config(['subscription.standard.tiers.team.realtime_max_connections' => 500]);

    expect(EdgeRealtimeApps::maxConnectionsFor($org))->toBe(500)
        ->and(fn () => app(EdgeRealtimeApps::class)->provision($site, 'x', ['max_connections' => 1000]))->toThrow(\RuntimeException::class, 'up to 500 connections');
    expect(EdgeRealtimeApp::query()->count())->toBe(0);

    $app = app(EdgeRealtimeApps::class)->provision($site, 'x', ['max_connections' => 500]);
    config(['subscription.standard.tiers.team.realtime_max_connections' => 100]); // a downgrade
    app(EdgeRealtimeApps::class)->sync($app);

    Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && str_contains($r->url(), 'id%3A'.$app->id) && str_contains($r->body(), '"maxConnections":100'));
    expect($app->fresh()->max_connections)->toBe(500);

    config(['subscription.standard.tiers.team.realtime_max_connections' => null]);
    expect(EdgeRealtimeApps::maxConnectionsFor($org))->toBe(PHP_INT_MAX);
});

test('the builder refuses a size over the plan with a clear error', function () {
    [, $site, $user] = site();
    config(['subscription.standard.tiers.team.realtime_max_connections' => 500]);
    Http::fake();

    Livewire::actingAs($user)->test(Resources::class, ['server' => $site->server, 'site' => $site])
        ->call('openConnectionBuilder')
        ->call('chooseConnectionKind', 'realtime')
        ->call('$set', 'realtimeMaxConnections', 1000)
        ->call('saveConnection')
        ->assertHasErrors(['connection'])
        ->assertSee('up to 500 connections');

    expect(EdgeRealtimeApp::query()->count())->toBe(0);
});

test('sleep disables the app, disconnects, and keeps the env; wake re-enables', function () {
    [, $site, $user] = site();
    $app = realtimeApp($site);
    $env = EdgeContainerConnections::realtimeDriverEnv($site);
    $host = EdgeContainerConnections::resourceHost($site, 'realtime');
    Http::fake([
        'api.cloudflare.com/*' => Http::response(['success' => true, 'result' => null]),
        'realtime-apps.test/*' => Http::response(['ok' => true, 'closed' => 3]),
    ]);

    $component = Livewire::actingAs($user)->test(Resources::class, ['server' => $site->server, 'site' => $site])
        ->call('sleepConnection', $host, true);

    expect($app->fresh()->status)->toBe(EdgeRealtimeApp::STATUS_DISABLED)
        ->and(collect(EdgeContainerConnections::for($site->fresh()))->firstWhere('host', $host)['asleep'])->toBeTrue()
        ->and(EdgeContainerConnections::realtimeDriverEnv($site->fresh()))->toBe($env);
    Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && str_contains($r->url(), 'id%3A'.$app->id) && str_contains($r->body(), '"enabled":false'));
    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->url() === 'https://realtime-apps.test/apps/'.$app->id.'/disconnect' && $r->header('X-Dply-Key') === [$app->app_key]);

    $component->call('sleepConnection', $host, false);

    expect($app->fresh()->status)->toBe(EdgeRealtimeApp::STATUS_ACTIVE)
        ->and(collect(EdgeContainerConnections::for($site->fresh()))->firstWhere('host', $host)['asleep'])->toBeFalse();
    Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && str_contains($r->body(), '"enabled":true'));
    expect(Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/disconnect')))->toHaveCount(1);
});

test('sleep that cannot reach KV leaves the app awake', function () {
    [, $site, $user] = site();
    $app = realtimeApp($site);
    $host = EdgeContainerConnections::resourceHost($site, 'realtime');
    Http::fake(['api.cloudflare.com/*' => Http::response(['success' => false, 'errors' => [['message' => 'boom']]], 500)]);

    Livewire::actingAs($user)->test(Resources::class, ['server' => $site->server, 'site' => $site])
        ->call('sleepConnection', $host, true);

    expect($app->fresh()->status)->toBe(EdgeRealtimeApp::STATUS_ACTIVE)
        ->and(collect(EdgeContainerConnections::for($site->fresh()))->firstWhere('host', $host)['asleep'])->toBeFalse();
});

test('site teardown disconnects and deletes the realtime KV keys', function () {
    [, $site] = site();
    $app = realtimeApp($site);
    Http::fake(['*' => Http::response(['success' => true, 'result' => null, 'ok' => true])]);

    app()->call([new TeardownEdgeSiteJob((string) $site->id), 'handle']);

    expect(EdgeRealtimeApp::query()->count())->toBe(0);
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/apps/'.$app->id.'/disconnect'));
    Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), 'id%3A'.$app->id));
    Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), 'key%3A'.$app->app_key));
});

test('org purge deletes attached and orphaned realtime apps from KV', function () {
    [$org, $site] = site();
    $attached = realtimeApp($site);
    $orphan = EdgeRealtimeApp::query()->create([
        'organization_id' => $org->id, 'name' => 'old', 'app_key' => 'rtk_orphanorphanorphanorph', 'app_secret' => 'rts_x',
        'status' => 'active', 'max_connections' => 100,
    ]);
    Http::fake(['*' => Http::response(['success' => true, 'result' => null, 'ok' => true])]);

    expect(app(OrganizationDataPurger::class)->plan($org))->toContain('realtime '.$orphan->id);
    expect(app(OrganizationDataPurger::class)->purge($org))->toBe([]);

    expect(EdgeRealtimeApp::query()->count())->toBe(0);
    foreach ([$attached, $orphan] as $app) {
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), 'key%3A'.$app->app_key));
    }
});

test('deleting an app bills the stretch since the last run first', function () {
    [, $site] = site();
    $app = realtimeApp($site);
    Http::fake([
        'realtime-apps.test/apps/*/stats' => Http::response(['connection_seconds' => 300, 'messages_in' => 2, 'messages_out' => 8, 'peak_connections' => 0]),
        '*' => Http::response(['success' => true, 'result' => null, 'ok' => true]),
    ]);

    app(EdgeRealtimeApps::class)->destroy($app);

    $row = EdgeRealtimeUsage::query()->where('realtime_app_id', $app->id)->sole();
    expect($row->connection_seconds)->toBe(300)->and($row->messages)->toBe(2)
        ->and(EdgeRealtimeApp::query()->count())->toBe(0);
});
