<?php

declare(strict_types=1);

namespace Tests\Feature\MeasureWakeTimeCommandTest;

use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Modules\Edge\Console\MeasureWakeTimeCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

uses(RefreshDatabase::class);

/** A deployed container app; $container overrides meta.edge.container. */
function wakeApp(array $container = []): Site
{
    $org = Organization::factory()->create();

    return Site::factory()->create([
        'organization_id' => $org->id,
        'server_id' => Server::factory()->create(['organization_id' => $org->id])->id,
        'edge_backend' => 'dply_edge',
        'meta' => ['edge' => [
            'runtime_mode' => 'container',
            'live_url' => 'https://shop.on-dply.live',
            'container' => ['instance_type' => 'basic', 'sleep_after' => '5m', ...$container],
        ]],
    ]);
}

function instances(string ...$statuses): array
{
    return array_map(fn (int $i, string $status): array => ['name' => 'instance-'.$i, 'status' => $status], array_keys($statuses), $statuses);
}

test('waits until the app is asleep, then times one cold and the warm requests', function () {
    Sleep::fake();
    $site = wakeApp();
    Http::fake([
        'shop.on-dply.live/_dply/instances' => Http::sequence()
            ->push(instances('healthy'))
            ->push(instances('healthy'))
            ->push(instances('stopped')),
        'shop.on-dply.live/health' => Http::response('ok'),
    ]);

    $this->artisan('dply:edge:wake-time', ['site' => $site->id, '--path' => '/health', '--warm' => 3])
        ->expectsOutputToContain('Waiting for it to fall asleep (up to 8 min)')
        ->expectsOutputToContain('Cold (first request after sleep):')
        ->expectsOutputToContain('Warm, 3 requests: median')
        ->expectsOutputToContain('Wake overhead: ~')
        ->assertSuccessful();

    Sleep::assertSleptTimes(2);
    $app = Http::recorded(fn (Request $r): bool => str_ends_with($r->url(), '/health'));
    expect($app)->toHaveCount(4) // 1 cold + 3 warm
        ->and(Http::recorded(fn (Request $r): bool => str_ends_with($r->url(), '/_dply/instances'))->first()[0]->hasHeader('x-dply-queue-token'))->toBeTrue();
});

test('never requests the app while it is still awake, and gives up after the wait', function () {
    Sleep::fake();
    $site = wakeApp();
    Http::fake([
        'shop.on-dply.live/_dply/instances' => Http::response(instances('stopped', 'running')),
        '*' => Http::response('ok'),
    ]);

    $this->artisan('dply:edge:wake-time', ['site' => $site->id, '--wait' => 60, '--poll' => 30])
        ->expectsOutputToContain('did not fall asleep within 1 minutes')
        ->assertFailed();

    expect(Http::recorded(fn (Request $r): bool => ! str_contains($r->url(), '/_dply/')))->toBeEmpty();
});

test('an app that keeps instances warm is refused without waiting', function () {
    Sleep::fake();
    Http::fake();
    $site = wakeApp(['min_instances' => 1, 'max_instances' => 2]);
    // A trial or unpaid org always scales to zero (EdgeTrialLimits): a paid plan keeps min instances.
    $site->organization->forceFill(['comped_until' => now()->addMonth()])->save();

    $this->artisan('dply:edge:wake-time', ['site' => $site->id])
        ->expectsOutputToContain('never sleeps')
        ->assertFailed();

    Http::assertNothingSent();
});

test('a first request that fails is reported, not quoted', function () {
    Sleep::fake();
    $site = wakeApp();
    Http::fake([
        'shop.on-dply.live/_dply/instances' => Http::response(instances('stopped')),
        '*' => Http::response('boom', 502),
    ]);

    $this->artisan('dply:edge:wake-time', ['site' => $site->id])
        ->expectsOutputToContain('answered 502')
        ->assertFailed();
});

test('a site that is not a container app is refused', function () {
    $site = wakeApp();
    $site->update(['meta' => ['edge' => ['runtime_mode' => 'static', 'live_url' => 'https://shop.on-dply.live']]]);

    $this->artisan('dply:edge:wake-time', ['site' => $site->id])
        ->expectsOutputToContain('No deployed container app')
        ->assertFailed();
});

test('sleep durations and medians', function () {
    expect(MeasureWakeTimeCommand::seconds('5m'))->toBe(300)
        ->and(MeasureWakeTimeCommand::seconds('1h'))->toBe(3600)
        ->and(MeasureWakeTimeCommand::seconds('nonsense'))->toBe(300)
        ->and(MeasureWakeTimeCommand::median([30.0, 10.0, 20.0]))->toBe(20.0)
        ->and(MeasureWakeTimeCommand::median([40.0, 10.0, 20.0, 30.0]))->toBe(25.0);
});

test('--recent reports what visitors waited and where boot time went', function () {
    config(['edge.cloudflare.account_id' => 'acct', 'edge.cloudflare.api_token' => 'tok', 'edge.cloudflare.analytics_dataset' => 'dply_rum']);
    $site = wakeApp();
    Http::fake([
        'api.cloudflare.com/client/v4/accounts/acct/analytics_engine/sql' => Http::response(['meta' => [['name' => 'ready'], ['name' => 'probe'], ['name' => 'request'], ['name' => 'timestamp']], 'data' => [
            ['ready' => 3000, 'probe' => 40, 'request' => 800],
            ['ready' => 5000, 'probe' => 60, 'request' => 1000],
            ['ready' => -1, 'probe' => -1, 'request' => 7000], // the port never answered; the request still counts
        ]]),
        'api.cloudflare.com/client/v4/accounts/acct/containers/applications' => Http::response(['success' => true, 'result' => []]),
        'api.cloudflare.com/client/v4/accounts/acct/workers/observability/telemetry/query' => Http::response(['success' => true, 'result' => ['events' => ['events' => [
            ['timestamp' => 1_757_000_000_000, '$metadata' => ['message' => 'dply-boot: start=1.20 sqlite=1.30 migrate=1.30 caches=2.80']],
            ['timestamp' => 1_757_000_001_000, '$metadata' => ['message' => 'dply-boot: start=1.00 sqlite=1.50 migrate=1.50 caches=2.50']],
        ]]]]),
        '*' => Http::response('no', 500),
    ]);

    $this->artisan('dply:edge:wake-time', ['site' => $site->id, '--recent' => true])
        ->expectsOutputToContain('Cold starts in the last 7 days: 3')
        ->expectsOutputToContain('A visitor waited: median 6,000 ms, p95 7,000 ms')
        ->expectsOutputToContain('until the app answered its port (start + boot + probe): median 4,000 ms')
        ->expectsOutputToContain('then their first request: median 1,000 ms')
        ->expectsOutputToContain('Boot, median of 2 in the last day: SQLite restore 300 ms, migrations 0 ms, Laravel caches 1,250 ms')
        ->assertSuccessful();

    $sql = Http::recorded(fn (Request $r): bool => str_ends_with($r->url(), '/analytics_engine/sql'))->first()[0]->body();
    // Cloudflare rejects ORDER BY timestamp unless timestamp is selected.
    expect($sql)->toContain('request, timestamp FROM dply_container_wake')->toContain("blob1 = '{$site->id}'");
});

test('--recent with nothing recorded yet says so', function () {
    config(['edge.cloudflare.account_id' => 'acct', 'edge.cloudflare.api_token' => 'tok', 'edge.cloudflare.analytics_dataset' => 'dply_rum']);
    $site = wakeApp();
    Http::fake(['*' => Http::response('unknown dataset', 400)]);

    $this->artisan('dply:edge:wake-time', ['site' => $site->id, '--recent' => true])
        ->expectsOutputToContain('No cold starts recorded in the last 7 days')
        ->assertSuccessful();
});
