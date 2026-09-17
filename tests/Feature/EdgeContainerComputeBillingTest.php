<?php

declare(strict_types=1);

namespace Tests\Feature\EdgeContainerComputeBillingTest;

use App\Models\EdgeContainerUsage;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Modules\Billing\Services\DesiredBillingState;
use App\Modules\Billing\Services\EdgeContainerComputeCost;
use App\Modules\Edge\Services\Containers\EdgeContainerUsageCollector;
use App\Modules\Providers\Cloudflare\EdgeCloudflareClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(fn () => config(['dply.edge.usage_billing.markup_percent' => 0]));

test('compute is priced per second of vcpu, memory, disk and egress', function () {
    $cost = app(EdgeContainerComputeCost::class);

    // 1 vCPU-hour (7.2¢) + 1 GiB-hour (0.9¢) + 4 GB-hours disk (0.1¢) + 1 GiB egress (2.5¢) = 10.7¢ → 11¢
    expect($cost->cents(3600, 3600, 4 * 3600, 1024 ** 3))->toBe(11)
        ->and(round($cost->perMinuteMillicents(0.25, 1, 4), 2))->toBe(46.67); // basic, all vCPU busy
});

test('the tier credit covers compute first, and enterprise never pays through sync', function () {
    $pro = DesiredBillingState::fromPlanAndUsage(plan: ['key' => 'pro', 'label' => 'Pro', 'price_cents' => 2000], containerComputeCents: 730, computeCreditCents: 500);
    $enterprise = DesiredBillingState::fromPlanAndUsage(plan: ['key' => 'enterprise', 'label' => 'Enterprise', 'price_cents' => 0], containerComputeCents: 99_999, computeCreditCents: null);

    expect($pro->containerComputeCents)->toBe(230)
        ->and($pro->usageLineCents())->toBe(230)
        ->and($pro->monthlyTotalCents)->toBe(2230)
        ->and($enterprise->containerComputeCents)->toBe(0);
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
        ->and(app(EdgeContainerComputeCost::class)->forOrganization($org, now()->startOfMonth(), now())['cents'])->toBe(2); // 0.24¢ cpu + 0.9¢ mem
});
