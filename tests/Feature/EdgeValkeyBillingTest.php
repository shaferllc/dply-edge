<?php

declare(strict_types=1);

namespace Tests\Feature\EdgeValkeyBillingTest;

use App\Models\EdgeRedisUsage;
use App\Models\EdgeSiteEnvVar;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Modules\Billing\Services\EdgeRedisCost;
use App\Modules\Edge\Services\EdgeValkeyUsageCollector;
use App\Modules\Edge\Support\EdgeContainerConnections;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Valkey's costs are backed out of its prices at 20% (EdgeValkey::CLASSES).
    config(['edge.valkey.api_url' => 'http://gateway.test', 'edge.valkey.token' => 'tok', 'dply.edge.usage_billing.margin_percent' => 20]);
    $user = User::factory()->create();
    $this->org = Organization::factory()->create();
    $server = Server::factory()->create(['organization_id' => $this->org->id, 'user_id' => $user->id]);
    $this->site = Site::factory()->create(['organization_id' => $this->org->id, 'server_id' => $server->id, 'user_id' => $user->id, 'edge_backend' => 'dply_edge']);
    $this->site->mergeEdgeMeta(['connections' => [[
        'kind' => 'redis', 'name' => 'CACHE', 'host' => 'dply.app.cache.internal', 'target' => 'valkey:app-cache', 'plan' => 'flex_250m',
    ]]]);
    $this->site->save();
});

/** The gateway's running total on each successive call. */
function gatewayReports(int ...$totals): void
{
    $sequence = Http::sequence();
    foreach ($totals as $seconds) {
        $sequence->push(['awake_seconds' => ['app-cache' => $seconds, 'someone-else' => 999]]);
    }
    Http::fake(['gateway.test/usage' => $sequence]);
}

function awakeToday(Site $site): int
{
    return (int) EdgeRedisUsage::query()->where('site_id', $site->id)->where('date', now()->utc()->toDateString())->value('awake_seconds');
}

test('each run adds only what changed since the last run', function () {
    gatewayReports(100, 250, 250);
    app(EdgeValkeyUsageCollector::class)->collect();
    expect(awakeToday($this->site))->toBe(100);

    app(EdgeValkeyUsageCollector::class)->collect();
    expect(awakeToday($this->site))->toBe(250);

    app(EdgeValkeyUsageCollector::class)->collect();
    expect(awakeToday($this->site))->toBe(250);
});

test('a recreated tenant starts counting from zero again', function () {
    gatewayReports(500, 40);
    app(EdgeValkeyUsageCollector::class)->collect();
    app(EdgeValkeyUsageCollector::class)->collect();

    expect(awakeToday($this->site))->toBe(540);
});

test('a dry run writes nothing', function () {
    gatewayReports(100);
    $result = app(EdgeValkeyUsageCollector::class)->collect(dryRun: true);

    expect($result)->toBe(['sites' => 1, 'seconds' => 100])
        ->and(awakeToday($this->site))->toBe(0);
});

test('awake seconds are priced per second and capped at the monthly price', function () {
    $cost = app(EdgeRedisCost::class);

    // One hour of Flex 250 MB: 3600 x $4.50 / 672 h = 0.67 cents, to the nearest cent.
    // Valkey prices are fixed: the margin (20% here) is not added.
    expect($cost->valkeyCents($this->org, [$this->site->id => 3600]))->toBe(1)
        // Minutes of use are not rounded up to a cent: 691 s = 0.13 cents.
        ->and($cost->valkeyCents($this->org, [$this->site->id => 691]))->toBe(0)
        // A full month would be $4.82; the cap is $4.50.
        ->and($cost->valkeyCents($this->org, [$this->site->id => 30 * 86400]))->toBe(450);
});

test('valkey time reaches the organization redis total', function () {
    EdgeRedisUsage::query()->create(['organization_id' => $this->org->id, 'site_id' => $this->site->id, 'date' => now()->toDateString(), 'awake_seconds' => 30 * 86400]);

    $total = app(EdgeRedisCost::class)->forOrganization($this->org, now()->startOfMonth(), now()->endOfMonth());

    expect($total['cents'])->toBe(450);
});

test('REST commands are collected as a delta and priced per 100,000, uncapped', function () {
    $sequence = Http::sequence();
    foreach ([[10, 250_000], [20, 1_250_000]] as [$seconds, $rest]) {
        $sequence->push(['awake_seconds' => ['app-cache' => $seconds], 'rest_commands' => ['app-cache' => $rest, 'someone-else' => 5]]);
    }
    Http::fake(['gateway.test/usage' => $sequence]);

    app(EdgeValkeyUsageCollector::class)->collect();
    app(EdgeValkeyUsageCollector::class)->collect();
    $row = EdgeRedisUsage::query()->where('site_id', $this->site->id)->first();
    expect($row->rest_commands)->toBe(1_250_000)->and($row->awake_seconds)->toBe(20);

    // $0.10 per 100K is a fixed customer price: 1.25M commands = $1.25, margin not added, no cap.
    expect(app(EdgeRedisCost::class)->restCents(1_250_000))->toBe(125)
        ->and(app(EdgeRedisCost::class)->forOrganization($this->org, now()->startOfMonth(), now()->endOfMonth()))
        ->toMatchArray(['rest_commands' => 1_250_000, 'cents' => 125]);
});

test('an app with dply Valkey gets the Upstash REST env, masked in the preview and secret on Workers', function () {
    config(['edge.valkey.domain' => 'cache.dply.io']);
    (new EdgeSiteEnvVar(['site_id' => $this->site->id, 'key' => 'REDIS_URL', 'value' => 'rediss://default:p%40ss@app-cache.cache.dply.io:6380', 'scope' => EdgeSiteEnvVar::SCOPE_PRODUCTION]))->save();
    $site = $this->site->fresh();

    expect(EdgeContainerConnections::valkeyRestEnv($site))->toBe([
        'REDIS_REST_URL' => 'https://app-cache.cache.dply.io:8443',
        'REDIS_REST_TOKEN' => 'p@ss',
    ])
        ->and(EdgeContainerConnections::redisDriverEnv($site))->toHaveKey('REDIS_REST_URL');

    $preview = collect(EdgeContainerConnections::redisInjectionPreview($site))->keyBy('key');
    expect($preview['REDIS_REST_TOKEN']['value'])->toBe('••••');

    $bindings = collect(EdgeContainerConnections::resourceWorkerBindings($site))->keyBy('name');
    expect($bindings['REDIS_REST_TOKEN']['type'])->toBe('secret_text')
        ->and($bindings['REDIS_REST_URL']['type'])->toBe('plain_text');
    // The app's own saved value wins: the binding is left out.
    expect(collect(EdgeContainerConnections::resourceWorkerBindings($site, ['REDIS_REST_URL']))->pluck('name')->all())->not->toContain('REDIS_REST_URL');

    // Asleep, or a pasted Redis: no REST env.
    $site->mergeEdgeMeta(['connections' => [['kind' => 'redis', 'name' => 'CACHE', 'host' => 'dply.app.cache.internal', 'target' => 'valkey:app-cache', 'plan' => 'flex_250m', 'asleep' => true]]]);
    $site->save();
    expect(EdgeContainerConnections::valkeyRestEnv($site->fresh()))->toBe([]);
});
