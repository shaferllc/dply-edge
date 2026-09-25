<?php

declare(strict_types=1);

namespace Tests\Feature\EdgeValkeyBillingTest;

use App\Models\EdgeRedisUsage;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Modules\Billing\Services\EdgeRedisCost;
use App\Modules\Edge\Services\EdgeValkeyUsageCollector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['edge.valkey.api_url' => 'http://gateway.test', 'edge.valkey.token' => 'tok']);
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

    // One hour of Flex 250 MB: 3600 x $0.00000248 = 0.89 cents, rounded up.
    expect($cost->valkeyCents($this->org, [$this->site->id => 3600]))->toBe(1)
        // A full month would be $6.43; the cap is $6.
        ->and($cost->valkeyCents($this->org, [$this->site->id => 30 * 86400]))->toBe(600);
});

test('valkey time reaches the organization redis total', function () {
    EdgeRedisUsage::query()->create(['organization_id' => $this->org->id, 'site_id' => $this->site->id, 'date' => now()->toDateString(), 'awake_seconds' => 30 * 86400]);

    $total = app(EdgeRedisCost::class)->forOrganization($this->org, now()->startOfMonth(), now()->endOfMonth());

    expect($total['cents'])->toBe(600);
});
