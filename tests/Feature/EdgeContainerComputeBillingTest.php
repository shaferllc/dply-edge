<?php

declare(strict_types=1);

namespace Tests\Feature\EdgeContainerComputeBillingTest;

use App\Models\EdgeContainerUsage;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Modules\Billing\Services\DesiredBillingState;
use App\Modules\Billing\Services\EdgeContainerComputeCost;
use App\Modules\Billing\Support\UsagePrice;
use App\Modules\Edge\Services\Containers\EdgeContainerUsageCollector;
use App\Modules\Edge\Support\EdgeContainerSettings;
use App\Modules\Providers\Cloudflare\EdgeCloudflareClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(fn () => config(['dply.edge.usage_billing.margin_percent' => 0]));

test('compute is priced per second of vcpu, memory and disk; container egress is not billed twice', function () {
    $cost = app(EdgeContainerComputeCost::class);

    // 1 vCPU-hour (7.2¢) + 1 GiB-hour (0.9¢) + 4 GB-hours disk (0.1¢) = 8.2¢ → 8¢.
    // The 1 GiB the container sent is the visitors' responses, already billed
    // as delivery bandwidth (edgeResponseBytes on the site's hostname).
    expect($cost->cents(3600, 3600, 4 * 3600, 1024 ** 3))->toBe(8)
        ->and($cost->cents(3600, 3600, 4 * 3600, 0))->toBe(8)
        ->and(round($cost->perMinuteMillicents(0.25, 1, 4), 2))->toBe(46.68); // basic, all vCPU busy
});

test('every size gets the same margin: price scales linearly with the shape', function () {
    config(['dply.edge.usage_billing.margin_percent' => 20]);
    $cost = app(EdgeContainerComputeCost::class);

    expect($cost->perMinuteMillicents(4, 12, 20))->toEqualWithDelta((4 * 2 + 12 * 0.25 + 20 * 0.007) * 60 * 1.2, 1e-9)
        ->and($cost->perMinuteMillicents(0.25, 1, 4))->toEqualWithDelta((0.25 * 2 + 0.25 + 4 * 0.007) * 60 * 1.2, 1e-9);
});

test('compute is a usage line the plan credit covers, and enterprise never pays through sync', function () {
    $pro = DesiredBillingState::fromPlanAndUsage(plan: ['key' => 'pro', 'label' => 'Pro', 'price_cents' => 2000], usage: ['compute' => 2730], usageCreditCents: 2000);
    $enterprise = DesiredBillingState::fromPlanAndUsage(plan: ['key' => 'enterprise', 'label' => 'Enterprise', 'price_cents' => 0]);

    expect($pro->usageLines())->toBe(['compute' => 2730])
        ->and($pro->usageChargeCents())->toBe(730)
        ->and($pro->monthlyTotalCents)->toBe(2730)
        ->and($enterprise->monthlyTotalCents)->toBe(0);
});

test('the collector matches container applications to sites by script name', function () {
    $org = Organization::factory()->create();
    $server = Server::factory()->create(['organization_id' => $org->id]);
    $site = Site::factory()->create(['organization_id' => $org->id, 'server_id' => $server->id]);
    $appName = 'dply-ctr-'.strtolower((string) $site->id).'-app';

    Http::fake([
        'api.cloudflare.com/client/v4/graphql' => Http::response(['data' => ['viewer' => ['accounts' => [['containersUsageAdaptiveGroups' => [
            ['dimensions' => ['applicationId' => 'app-1'], 'sum' => ['cpuTimeSec' => 120, 'allocatedMemory' => 3600 * 1024 ** 3, 'allocatedDisk' => 0, 'txBytes' => 5]],
            ['dimensions' => ['applicationId' => 'someone-else'], 'sum' => ['cpuTimeSec' => 999, 'allocatedMemory' => 0, 'allocatedDisk' => 0, 'txBytes' => 0]],
        ]]]]]]),
        'api.cloudflare.com/client/v4/accounts/acct/containers/applications' => Http::response(['success' => true, 'result' => [
            ['id' => 'app-1', 'name' => $appName],
            ['id' => 'someone-else', 'name' => 'unrelated-app'],
        ]]),
    ]);

    $result = (new EdgeContainerUsageCollector(new EdgeCloudflareClient('acct', 'token')))->collectForDate(now()->startOfDay());
    $row = EdgeContainerUsage::query()->where('site_id', $site->id)->first();

    expect($result)->toBe(['sites' => 1, 'applications' => 2])
        ->and($row->cpu_seconds)->toBe(120.0)
        ->and($row->memory_gib_seconds)->toBe(3600.0)
        ->and($row->tx_bytes)->toBe(5)
        ->and(app(EdgeContainerComputeCost::class)->forOrganization($org, now()->startOfMonth(), now())['cents'])->toBe(1); // 0.24¢ cpu + 0.9¢ mem = 1.14¢, rounded once
});

test('the monthly cap never sells an always-on 100%-CPU instance below cost, at any margin', function () {
    foreach ([20, 30, 40] as $margin) {
        config(['dply.edge.usage_billing.margin_percent' => $margin, 'dply.edge.usage_billing.container_monthly_cap_hours' => 540]);
        // 540 h is under break-even (720 / 1.3 = 553.8 h at 30%): the floor keeps it 5% over cost.
        expect(UsagePrice::containerCapHours())->toEqualWithDelta(max(540, 720 * 1.05 / (1 + $margin / 100)), 1e-9);

        foreach (EdgeContainerSettings::INSTANCE_TYPES as [$vcpu, $memory, $disk]) {
            $cost = app(EdgeContainerComputeCost::class)->costMillicents($vcpu * 2_592_000, $memory * 2_592_000, $disk * 2_592_000);
            expect(UsagePrice::containerCapMillicents($vcpu, $memory, $disk))->toBeGreaterThan($cost * 1.04);
        }
    }

    config(['dply.edge.usage_billing.margin_percent' => 30, 'dply.edge.usage_billing.container_monthly_cap_hours' => 600]);
    $basic = UsagePrice::containerMonthly(0.25, 1, 4);
    // 600 h × $0.0000101/s = $21.85 cap; 25% CPU always on = $13.58.
    expect(round($basic['cap'] / 100_000, 2))->toBe(21.85)
        ->and(round($basic['typical'] / 100_000, 2))->toBe(13.58)
        ->and(UsagePrice::sizes()[0]['app'])->toMatchArray(['typical' => '$13.58', 'cap' => '$21.85']);
});

test('each app is billed at most its cap per instance-month, never below cost', function () {
    config(['dply.edge.usage_billing.margin_percent' => 30, 'dply.edge.usage_billing.container_monthly_cap_hours' => 600]);
    $org = Organization::factory()->create();
    $server = Server::factory()->create(['organization_id' => $org->id]);
    $busy = Site::factory()->create(['organization_id' => $org->id, 'server_id' => $server->id, 'meta' => ['edge' => ['container' => ['instance_type' => 'basic']]]]);
    $pair = Site::factory()->create(['organization_id' => $org->id, 'server_id' => $server->id, 'meta' => ['edge' => ['container' => ['instance_type' => 'basic']]]]);
    $month = 2_592_000;
    // One basic instance always on at 100% CPU; two more, the same, on another app.
    EdgeContainerUsage::query()->create(['organization_id' => $org->id, 'site_id' => $busy->id, 'date' => now()->toDateString(), 'cpu_seconds' => 0.25 * $month, 'memory_gib_seconds' => $month, 'disk_gb_seconds' => 4 * $month, 'tx_bytes' => 0]);
    EdgeContainerUsage::query()->create(['organization_id' => $org->id, 'site_id' => $pair->id, 'date' => now()->toDateString(), 'cpu_seconds' => 0.5 * $month, 'memory_gib_seconds' => 2 * $month, 'disk_gb_seconds' => 8 * $month, 'tx_bytes' => 0]);

    $cost = app(EdgeContainerComputeCost::class);
    $cap = UsagePrice::containerCapMillicents(0.25, 1, 4);

    expect($cost->siteMillicents($busy, 0.25 * $month, $month, 4 * $month))->toEqualWithDelta($cap, 1e-6)
        ->and($cost->siteMillicents($busy, 0.25 * $month * 0.25, $month, 4 * $month))->toBeLessThan($cap) // typical CPU: under the cap, metered
        ->and($cost->forOrganization($org, now()->startOfMonth(), now()->endOfMonth())['cents'])->toBe((int) round(3 * $cap / 1000))
        ->and($cost->siteMillicents(null, 0.25 * $month, $month, 4 * $month))->toBeGreaterThan($cap); // deleted app: no cap

    // A cap config below cost is floored: billed at least cost.
    config(['dply.edge.usage_billing.margin_percent' => 0]);
    expect($cost->siteMillicents($busy, 0.25 * $month, $month, 4 * $month))->toEqualWithDelta($cost->costMillicents(0.25 * $month, $month, 4 * $month), 1e-6);
});

test('after a downsize the cap undercounts, and the bill stops at cost', function () {
    config(['dply.edge.usage_billing.margin_percent' => 30, 'dply.edge.usage_billing.container_monthly_cap_hours' => 600]);
    $site = new Site(['meta' => ['edge' => ['container' => ['instance_type' => 'basic']]]]);
    $month = 2_592_000;
    // A month on 1 vCPU / 3 GiB at 100% CPU, billed after the owner stored basic.
    $cost = app(EdgeContainerComputeCost::class);
    $atCost = $cost->costMillicents($month, 3 * $month, 6 * $month);

    expect($cost->capMillicents($site, 3 * $month))->toBeLessThan($atCost)
        ->and($cost->siteMillicents($site, $month, 3 * $month, 6 * $month))->toEqualWithDelta($atCost, 1e-6);
});

test('a site with several container applications is billed for all of them', function () {
    $org = Organization::factory()->create();
    $server = Server::factory()->create(['organization_id' => $org->id]);
    $site = Site::factory()->create(['organization_id' => $org->id, 'server_id' => $server->id]);
    $prefix = 'dply-ctr-'.strtolower((string) $site->id);

    Http::fake([
        'api.cloudflare.com/client/v4/graphql' => Http::response(['data' => ['viewer' => ['accounts' => [['containersUsageAdaptiveGroups' => [
            ['dimensions' => ['applicationId' => 'web'], 'sum' => ['cpuTimeSec' => 100, 'allocatedMemory' => 3600 * 1024 ** 3, 'allocatedDisk' => 0, 'txBytes' => 1]],
            ['dimensions' => ['applicationId' => 'worker'], 'sum' => ['cpuTimeSec' => 50, 'allocatedMemory' => 1800 * 1024 ** 3, 'allocatedDisk' => 0, 'txBytes' => 2]],
        ]]]]]]),
        'api.cloudflare.com/client/v4/accounts/acct/containers/applications' => Http::response(['success' => true, 'result' => [
            ['id' => 'web', 'name' => $prefix.'-app'],
            ['id' => 'worker', 'name' => $prefix.'-worker-default'],
        ]]),
    ]);

    $result = (new EdgeContainerUsageCollector(new EdgeCloudflareClient('acct', 'token')))->collectForDate(now()->startOfDay());
    $row = EdgeContainerUsage::query()->where('site_id', $site->id)->sole();

    expect($result)->toBe(['sites' => 1, 'applications' => 2])
        ->and($row->cpu_seconds)->toBe(150.0)
        ->and($row->memory_gib_seconds)->toBe(5400.0)
        ->and($row->tx_bytes)->toBe(3);
});

test('outbound bills tx minus counted replies per app-day at cost + margin, never below zero, never unmeasured', function () {
    config(['dply.edge.usage_billing.margin_percent' => 30]);
    $site = Site::factory()->create();
    $gb = 1024 ** 3;
    $row = fn (string $date, int $tx, ?int $reply) => EdgeContainerUsage::query()->create(['organization_id' => $site->organization_id, 'site_id' => $site->id, 'date' => $date, 'cpu_seconds' => 0, 'memory_gib_seconds' => 0, 'disk_gb_seconds' => 0, 'tx_bytes' => $tx, 'reply_bytes' => $reply]);
    $row(now()->subDays(3)->toDateString(), 12 * $gb, 2 * $gb);  // 10 GB out
    $row(now()->subDays(2)->toDateString(), 1 * $gb, 5 * $gb);   // replies > tx: 0, not -4
    $row(now()->subDays(1)->toDateString(), 50 * $gb, null);     // Worker predates the counter: 0

    $result = app(EdgeContainerComputeCost::class)->forOrganization($site->organization, now()->subDays(5), now());

    // 10 GB × $0.025 × 1.3 = 32.5¢.
    expect($result['outbound_bytes'])->toBe(10 * $gb)
        ->and($result['cents'])->toBe(33);
});

test('the collector stores counted reply bytes, null for a site whose Worker never counted, and leaves them on an AE failure', function () {
    config(['edge.cloudflare.analytics_dataset' => 'dply_edge']);
    $org = Organization::factory()->create();
    $counted = Site::factory()->create(['organization_id' => $org->id]);
    $idle = Site::factory()->create(['organization_id' => $org->id]);
    $old = Site::factory()->create(['organization_id' => $org->id]);
    $sites = ['a' => $counted, 'b' => $idle, 'c' => $old];
    $aeFails = false;

    Http::fake(function (\Illuminate\Http\Client\Request $request) use ($sites, &$aeFails) {
        if (str_contains($request->url(), '/analytics_engine/sql')) {
            if ($aeFails) {
                return Http::response('unknown dataset', 400);
            }
            $body = $request->body();

            return Http::response(['data' => str_contains($body, 'SUM(')
                ? [['site' => strtolower((string) $sites['a']->id), 'bytes' => 700]]
                : [['site' => strtolower((string) $sites['a']->id)], ['site' => strtolower((string) $sites['b']->id)]], 'meta' => [['name' => 'site']]]);
        }
        if (str_contains($request->url(), '/containers/applications')) {
            return Http::response(['success' => true, 'result' => array_map(fn (string $k, Site $s): array => ['id' => $k, 'name' => 'dply-ctr-'.strtolower((string) $s->id)], array_keys($sites), $sites)]);
        }

        return Http::response(['data' => ['viewer' => ['accounts' => [['containersUsageAdaptiveGroups' => array_map(
            fn (string $k): array => ['dimensions' => ['applicationId' => $k], 'sum' => ['cpuTimeSec' => 1, 'allocatedMemory' => 0, 'allocatedDisk' => 0, 'txBytes' => 1000]],
            array_keys($sites),
        )]]]]]);
    });
    $collect = fn () => (new EdgeContainerUsageCollector(new EdgeCloudflareClient('acct', 'token')))->collectForDate(now()->startOfDay());
    $reply = fn (Site $s) => EdgeContainerUsage::query()->where('site_id', $s->id)->value('reply_bytes');

    $collect();
    expect($reply($counted))->toBe(700)
        ->and($reply($idle))->toBe(0)        // counts replies, none today: all tx is outbound
        ->and($reply($old))->toBeNull();     // no point in 30 days: Worker predates the counter

    $aeFails = true;
    $collect();
    expect($reply($counted))->toBe(700)
        ->and($reply($old))->toBeNull();
});
