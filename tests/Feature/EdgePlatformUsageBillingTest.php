<?php

declare(strict_types=1);

namespace Tests\Feature\EdgePlatformUsageBillingTest;

use App\Models\EdgePlatformUsage;
use App\Models\Organization;
use App\Models\Site;
use App\Modules\Billing\Services\EdgePlatformUsageCost;
use App\Modules\Billing\Services\OrganizationBillingStateComputer;
use App\Modules\Billing\Services\StarterUsageBudget;
use App\Modules\Edge\Services\EdgePlatformUsageCollector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Cost only, so the numbers below read as Cloudflare list price.
    config(['edge.cloudflare.account_id' => 'acct', 'edge.cloudflare.api_token' => 'tok', 'dply.edge.usage_billing.margin_percent' => 0]);
});

/**
 * Fake the analytics endpoint by dataset: each query gets the groups for the
 * datasets it asks for. A dataset set to 'error' fails the query naming it.
 *
 * @param  array<string, mixed>  $datasets
 */
function fakeAnalytics(array $datasets): void
{
    Http::fake(function (Request $request) use ($datasets) {
        // Vectorize is REST, not GraphQL: 'vectorize' => [index name => [vectorCount, dimensions]].
        if (str_contains($request->url(), '/vectorize/v2/indexes')) {
            $indexes = (array) ($datasets['vectorize'] ?? []);
            if (preg_match('#/indexes/([^/]+)/info$#', $request->url(), $m) === 1) {
                [$count, $dims] = $indexes[urldecode($m[1])];

                return Http::response(['success' => true, 'result' => ['vectorCount' => $count, 'dimensions' => $dims]]);
            }
            if (preg_match('#/indexes/([^/]+)$#', $request->url(), $m) === 1) {
                return Http::response(['success' => true, 'result' => ['name' => urldecode($m[1])]]);
            }

            return Http::response(['success' => true, 'result' => array_map(static fn (string $name): array => ['name' => $name], array_keys($indexes))]);
        }
        $query = (string) $request['query'];
        $account = [];
        foreach ($datasets as $name => $groups) {
            if (! str_contains($query, $name.'(')) {
                continue;
            }
            if ($groups === 'error') {
                return Http::response(['data' => null, 'errors' => [['message' => "unknown field on {$name}"]]]);
            }
            $account[$name] = $groups;
        }

        return Http::response(['data' => ['viewer' => ['accounts' => [$account]]]]);
    });
}

/** @return array<string, mixed> */
function usage(Site $ssr, Organization $org): array
{
    $tail = strtolower(substr((string) $ssr->id, -6));

    return [
        'workersInvocationsAdaptive' => [
            ['dimensions' => ['scriptName' => 'dply-ssr-'.$tail.'-abcdefgh'], 'sum' => ['cpuTimeUs' => 5_000_000_000]],
            ['dimensions' => ['scriptName' => 'dply-ssr-'.$tail.'-12345678'], 'sum' => ['cpuTimeUs' => 1_000_000_000]],
            ['dimensions' => ['scriptName' => 'dply-edge-router'], 'sum' => ['cpuTimeUs' => 9_000_000_000]],
            ['dimensions' => ['scriptName' => 'dply-state-'.strtolower((string) $ssr->id)], 'sum' => ['cpuTimeUs' => 9_000_000_000]],
        ],
        'durableObjectsInvocationsAdaptiveGroups' => [
            ['dimensions' => ['namespaceId' => 'ns1', 'scriptName' => 'dply-state-'.strtolower((string) $ssr->id)], 'sum' => ['requests' => 2_000_000]],
        ],
        'durableObjectsPeriodicGroups' => [
            ['dimensions' => ['namespaceId' => 'ns1'], 'sum' => ['duration' => 1_000_000.0, 'rowsRead' => 10, 'rowsWritten' => 3_000_000]],
            ['dimensions' => ['namespaceId' => 'ns-unknown'], 'sum' => ['duration' => 1.0, 'rowsRead' => 1, 'rowsWritten' => 1]],
        ],
        'durableObjectsStorageGroups' => [
            ['dimensions' => ['namespaceIds' => ['ns1']], 'max' => ['storedBytes' => 10 * 1024 ** 3]],
            ['dimensions' => ['namespaceIds' => ['ns1', 'ns2']], 'max' => ['storedBytes' => 99 * 1024 ** 3]],
        ],
        'imagesTransformationsAdaptiveGroups' => [
            ['dimensions' => ['scriptName' => 'dply-ctr-'.strtolower((string) $ssr->id)], 'sum' => ['billableEventCount' => 3_000]],
        ],
        'r2StorageAdaptiveGroups' => [
            ['dimensions' => ['bucketName' => 'dply-'.strtolower((string) $org->id).'-uploads'], 'max' => ['payloadSize' => 100 * 1024 ** 3, 'metadataSize' => 0]],
            ['dimensions' => ['bucketName' => 'dply-edge-'.strtolower((string) $org->id)], 'max' => ['payloadSize' => 500 * 1024 ** 3, 'metadataSize' => 0]],
        ],
        'r2OperationsAdaptiveGroups' => [
            ['dimensions' => ['bucketName' => 'dply-'.strtolower((string) $org->id).'-uploads', 'actionType' => 'PutObject'], 'sum' => ['requests' => 1_000_000]],
            ['dimensions' => ['bucketName' => 'dply-'.strtolower((string) $org->id).'-uploads', 'actionType' => 'GetObject'], 'sum' => ['requests' => 10_000_000]],
        ],
    ];
}

test('usage lands on the org and site that own the script or bucket', function () {
    $site = Site::factory()->create();
    $org = $site->organization;
    $other = Site::factory()->create();
    fakeAnalytics(usage($site, $org));

    $result = app(EdgePlatformUsageCollector::class)->collectForDate(now());

    expect($result)->toBe(['resources' => 5, 'failed' => []]);
    expect(EdgePlatformUsage::query()->where('resource', 'dply-ctr-'.strtolower((string) $site->id))->value('images_transformations'))->toBe(3_000);
    $cpu = EdgePlatformUsage::query()->where('resource', 'like', 'dply-ssr-%')->get();
    expect($cpu)->toHaveCount(2)
        ->and($cpu->sum('cpu_ms'))->toBe(6_000_000)
        ->and($cpu->pluck('site_id')->unique()->all())->toBe([$site->id]);

    $state = EdgePlatformUsage::query()->where('resource', 'dply-state-'.strtolower((string) $site->id))->sole();
    expect($state->organization_id)->toBe($org->id)
        ->and($state->cpu_ms)->toBe(0)
        ->and($state->do_requests)->toBe(2_000_000)
        ->and($state->do_gb_seconds)->toBe(1_000_000.0)
        ->and($state->do_rows_written)->toBe(3_000_000)
        ->and($state->do_storage_bytes)->toBe(10 * 1024 ** 3);

    $bucket = EdgePlatformUsage::query()->where('resource', 'like', '%-uploads')->sole();
    expect($bucket->organization_id)->toBe($org->id)
        ->and($bucket->site_id)->toBeNull()
        ->and($bucket->r2_storage_bytes)->toBe(100 * 1024 ** 3)
        ->and($bucket->r2_class_a_ops)->toBe(1_000_000)
        ->and($bucket->r2_class_b_ops)->toBe(10_000_000);

    // The artifact bucket and platform router are ours; nothing on the other org.
    expect(EdgePlatformUsage::query()->where('organization_id', $other->organization_id)->exists())->toBeFalse();
});

test('re-running a day overwrites instead of adding', function () {
    $site = Site::factory()->create();
    fakeAnalytics(usage($site, $site->organization));

    app(EdgePlatformUsageCollector::class)->collectForDate(now());
    app(EdgePlatformUsageCollector::class)->collectForDate(now());

    expect(EdgePlatformUsage::query()->count())->toBe(5)
        ->and((int) EdgePlatformUsage::query()->sum('cpu_ms'))->toBe(6_000_000);
});

test('a failed dataset leaves its columns alone and is reported', function () {
    $site = Site::factory()->create();
    $org = $site->organization;
    $bucket = 'dply-'.strtolower((string) $org->id).'-uploads';
    EdgePlatformUsage::query()->create(['organization_id' => $org->id, 'resource' => $bucket, 'date' => now()->utc()->toDateString(), 'r2_storage_bytes' => 42, 'r2_class_a_ops' => 7]);
    fakeAnalytics(['r2StorageAdaptiveGroups' => 'error'] + usage($site, $org));

    $result = app(EdgePlatformUsageCollector::class)->collectForDate(now());

    expect($result['failed'])->toBe(['r2'])
        ->and(EdgePlatformUsage::query()->where('resource', $bucket)->sole()->only(['r2_storage_bytes', 'r2_class_a_ops']))
        ->toBe(['r2_storage_bytes' => 42, 'r2_class_a_ops' => 7])
        ->and(EdgePlatformUsage::query()->where('resource', 'like', 'dply-ssr-%')->count())->toBe(2);
    $this->artisan('dply:edge:collect-platform-usage', ['--today' => true])->assertFailed();
});

test('cost is Cloudflare list price with no allowance', function () {
    // 6M CPU-ms $0.12 · 2M DO req $0.30 · 1M GB-s $12.50 · 3M rows written $3.00
    // · 10 GB DO storage $2.00 · 100 GB bucket $1.50 · 1M class A $4.50 · 10M class B $3.60
    // · 3,000 image transformations $1.50
    expect(app(EdgePlatformUsageCost::class)->cents([
        'cpu_ms' => 6_000_000, 'do_requests' => 2_000_000, 'do_gb_seconds' => 1_000_000.0, 'do_rows_read' => 0,
        'do_rows_written' => 3_000_000, 'do_storage_bytes' => 10 * 1024 ** 3, 'r2_storage_bytes' => 100 * 1024 ** 3,
        'r2_class_a_ops' => 1_000_000, 'r2_class_b_ops' => 10_000_000, 'images_transformations' => 3_000,
    ]))->toBe(12 + 30 + 1250 + 300 + 200 + 150 + 450 + 360 + 150);
});

test('storage bills each resource at its peak day, counts sum over the month', function () {
    $site = Site::factory()->create();
    $org = $site->organization;
    foreach ([[now()->startOfMonth(), 2], [now()->startOfMonth()->addDay(), 1]] as [$date, $gb]) {
        EdgePlatformUsage::query()->create(['organization_id' => $org->id, 'resource' => 'dply-state-x', 'date' => $date->toDateString(), 'do_storage_bytes' => $gb * 1024 ** 3, 'do_requests' => 1_000_000]);
    }
    EdgePlatformUsage::query()->create(['organization_id' => $org->id, 'resource' => 'dply-state-x', 'date' => now()->subMonths(2)->toDateString(), 'do_requests' => 9_000_000]);

    $cost = app(EdgePlatformUsageCost::class)->forOrganization($org, now()->startOfMonth(), now()->endOfMonth());

    expect($cost['do_storage_bytes'])->toBe(2 * 1024 ** 3)
        ->and($cost['do_requests'])->toBe(2_000_000)
        ->and($cost['cents'])->toBe(30 + 40);
});

test('the billing computer includes platform usage in usage', function () {
    $site = Site::factory()->create();
    $org = $site->organization;
    $before = app(OrganizationBillingStateComputer::class)->computeForTier($org, 'pro')->usageLines()['platform'] ?? 0;
    EdgePlatformUsage::query()->create(['organization_id' => $org->id, 'resource' => 'dply-ctr-x', 'date' => now()->toDateString(), 'cpu_ms' => 100_000_000]);

    // 100M CPU-ms at $0.02 per million.
    expect(app(OrganizationBillingStateComputer::class)->computeForTier($org, 'pro')->usageLines()['platform'] ?? 0)->toBe($before + 200);
});

test('the trial spending cap counts platform usage', function () {
    $site = Site::factory()->create();
    $org = $site->organization;
    $org->forceFill(['trial_ends_at' => now()->addDays(3)])->save();
    $budget = app(StarterUsageBudget::class);
    $before = $budget->status($org->fresh())['used_cents'];
    EdgePlatformUsage::query()->create(['organization_id' => $org->id, 'resource' => 'dply-ctr-x', 'date' => now()->toDateString(), 'cpu_ms' => 100_000_000]);

    expect($budget->status($org->fresh())['used_cents'])->toBe($before + 200);
});
