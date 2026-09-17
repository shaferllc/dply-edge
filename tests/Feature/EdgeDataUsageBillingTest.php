<?php

declare(strict_types=1);

namespace Tests\Feature\EdgeDataUsageBillingTest;

use App\Models\EdgeDatabase;
use App\Models\EdgeDataUsage;
use App\Models\EdgeQueue;
use App\Models\Organization;
use App\Modules\Billing\Services\DesiredBillingState;
use App\Modules\Billing\Services\EdgeDataUsageCost;
use App\Modules\Edge\Services\EdgeDataUsageCollector;
use App\Modules\Providers\Cloudflare\EdgeCloudflareClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(fn () => config(['dply.edge.usage_billing.markup_percent' => 0]));

test('d1 and queues usage is priced at list rates', function () {
    // 1B rows read (100¢) + 1M rows written (100¢) + 1 GiB storage (75¢) + 1M queue ops (40¢)
    expect(app(EdgeDataUsageCost::class)->cents(1_000_000_000, 1_000_000, 1024 ** 3, 1_000_000))->toBe(315);
});

test('usage is attributed to the org that owns each database and queue', function () {
    $org = Organization::factory()->create();
    EdgeDatabase::query()->create(['organization_id' => $org->id, 'name' => 'app', 'cloudflare_id' => 'db-1']);
    EdgeQueue::query()->create(['organization_id' => $org->id, 'name' => 'jobs', 'cloudflare_id' => 'q-1', 'cloudflare_name' => 'dply-x-jobs']);

    Http::fake(['api.cloudflare.com/client/v4/graphql' => Http::response(['data' => ['viewer' => ['accounts' => [[
        'd1AnalyticsAdaptiveGroups' => [
            ['dimensions' => ['databaseId' => 'db-1'], 'sum' => ['rowsRead' => 500, 'rowsWritten' => 20]],
            ['dimensions' => ['databaseId' => 'not-ours'], 'sum' => ['rowsRead' => 9, 'rowsWritten' => 9]],
        ],
        'd1StorageAdaptiveGroups' => [['dimensions' => ['databaseId' => 'db-1'], 'max' => ['databaseSizeBytes' => 4096]]],
        'queueMessageOperationsAdaptiveGroups' => [['dimensions' => ['queueId' => 'q-1'], 'sum' => ['billableOperations' => 3]]],
    ]]]]])]);

    $result = (new EdgeDataUsageCollector(new EdgeCloudflareClient('acct', 'tok')))->collectForDate(now()->startOfDay());

    expect($result['organizations'])->toBe(1)
        ->and(EdgeDataUsage::query()->where('organization_id', $org->id)->first()->only(['d1_rows_read', 'd1_rows_written', 'd1_storage_bytes', 'queue_operations']))
        ->toBe(['d1_rows_read' => 500, 'd1_rows_written' => 20, 'd1_storage_bytes' => 4096, 'queue_operations' => 3]);
});

test('data usage lands on the usage line and the monthly total', function () {
    $state = DesiredBillingState::fromPlanAndUsage(plan: ['key' => 'pro', 'label' => 'Pro', 'price_cents' => 2000], dataUsageCents: 315);

    expect($state->usageLineCents())->toBe(315)->and($state->monthlyTotalCents)->toBe(2315);
});
