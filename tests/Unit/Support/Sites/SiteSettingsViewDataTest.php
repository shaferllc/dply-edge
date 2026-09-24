<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Sites;

use App\Models\EdgeUsageSnapshot;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Support\Sites\SiteSettingsViewData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('edge deploys section skips usage analytics queries', function () {
    [$server, $site] = makeEdgeSiteForViewData();

    EdgeUsageSnapshot::query()->create([
        'organization_id' => $site->organization_id,
        'site_id' => $site->id,
        'period_start' => now()->subDay()->toDateString(),
        'period_end' => now()->subDay()->toDateString(),
        'requests' => 500,
        'bytes_egress' => 1024,
        'r2_storage_bytes' => 0,
        'r2_class_a_ops' => 0,
        'r2_class_b_ops' => 0,
        'source' => 'manual',
    ]);

    DB::enableQueryLog();

    $payload = SiteSettingsViewData::for(
        $server,
        $site,
        'deploys',
        null,
        [],
        null,
    );

    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($payload['edgeSiteBilling'])->toBeNull()
        ->and($payload['edgeSiteTraffic'])->toBeNull()
        ->and($payload['edgeSiteAccess'])->toBeNull()
        ->and($payload['isEdgeWorkspace'])->toBeTrue()
        ->and($payload['functionsHost'] ?? null)->toBeNull()
        ->and(collect($queries)->contains(fn (array $query): bool => str_contains($query['query'], 'edge_usage_snapshots')))->toBeFalse();
});

test('edge deploys section skips delivery worker context', function () {
    [$server, $site] = makeEdgeSiteForViewData();

    $payload = SiteSettingsViewData::for(
        $server,
        $site,
        'routing',
        null,
        [],
        null,
    );

    expect($payload['edgeWorkerScriptName'])->toBe('')
        ->and($payload['edgeWorkerZoneName'])->toBe('')
        ->and($payload['edgeWorkerRoutes'])->toBe([])
        ->and($payload['edgeDeliveryBanner'])->toBeNull()
        ->and($payload['edgeAttachedDomains'])->toBeArray();
});

test('edge overview section defers billing and traffic snapshots', function () {
    [$server, $site] = makeEdgeSiteForViewData();

    EdgeUsageSnapshot::query()->create([
        'organization_id' => $site->organization_id,
        'site_id' => $site->id,
        'period_start' => now()->subDay()->toDateString(),
        'period_end' => now()->subDay()->toDateString(),
        'requests' => 500,
        'bytes_egress' => 1024,
        'r2_storage_bytes' => 0,
        'r2_class_a_ops' => 0,
        'r2_class_b_ops' => 0,
        'source' => 'manual',
    ]);

    $payload = SiteSettingsViewData::for(
        $server,
        $site,
        'general',
        null,
        [],
        null,
    );

    expect($payload['edgeSiteBilling'])->toBeNull()
        ->and($payload['edgeSiteTraffic'])->toBeNull()
        ->and($payload['edgeSiteAccess'])->toBeNull();
});

test('edge overview observability helper loads billing and traffic snapshots', function () {
    [$server, $site] = makeEdgeSiteForViewData();

    EdgeUsageSnapshot::query()->create([
        'organization_id' => $site->organization_id,
        'site_id' => $site->id,
        'period_start' => now()->subDay()->toDateString(),
        'period_end' => now()->subDay()->toDateString(),
        'requests' => 500,
        'bytes_egress' => 1024,
        'r2_storage_bytes' => 0,
        'r2_class_a_ops' => 0,
        'r2_class_b_ops' => 0,
        'source' => 'manual',
    ]);

    $payload = SiteSettingsViewData::edgeOverviewObservability($site);

    expect($payload['edgeSiteBilling'])->not->toBeNull()
        ->and($payload['edgeSiteTraffic'])->not->toBeNull();
});

test('edge traffic shell defers usage analytics to the nested child', function () {
    [$server, $site] = makeEdgeSiteForViewData();

    EdgeUsageSnapshot::query()->create([
        'organization_id' => $site->organization_id,
        'site_id' => $site->id,
        'period_start' => now()->subDay()->toDateString(),
        'period_end' => now()->subDay()->toDateString(),
        'requests' => 500,
        'bytes_egress' => 1024,
        'r2_storage_bytes' => 0,
        'r2_class_a_ops' => 0,
        'r2_class_b_ops' => 0,
        'source' => 'manual',
    ]);

    DB::enableQueryLog();

    $shell = SiteSettingsViewData::for($server, $site, 'traffic', null, [], null);

    $shellQueries = collect(DB::getQueryLog())
        ->filter(fn (array $query): bool => str_contains($query['query'], 'edge_usage_snapshots'))
        ->count();

    expect($shell['edgeSiteTraffic'])->toBeNull()
        ->and($shell['edgeSiteAccess'])->toBeNull()
        ->and($shellQueries)->toBe(0);

    $child = SiteSettingsViewData::edgeSectionAnalytics($site, 'traffic');
    $again = SiteSettingsViewData::edgeSectionAnalytics($site, 'traffic');

    $usageQueries = collect(DB::getQueryLog())
        ->filter(fn (array $query): bool => str_contains($query['query'], 'edge_usage_snapshots'))
        ->count();

    DB::disableQueryLog();

    expect($child['edgeSiteTraffic'])->not->toBeNull()
        ->and($again['edgeSiteTraffic'])->toBe($child['edgeSiteTraffic'])
        ->and($usageQueries)->toBe(2); // MTD aggregate + daily rows, once each
});

/**
 * @return array{0: Server, 1: Site}
 */
function makeEdgeSiteForViewData(): array
{
    $organization = Organization::factory()->create();
    $server = Server::factory()->for($organization)->create();
    $site = Site::factory()->for($organization)->for($server)->create([
        'status' => Site::STATUS_EDGE_ACTIVE,
        'edge_backend' => 'dply_edge',
        'meta' => [
            'runtime_profile' => 'edge_web',
            'edge' => [
                'source' => ['repo' => 'acme/web', 'branch' => 'main'],
                'build' => ['command' => 'npm run build', 'output_dir' => 'dist'],
            ],
        ],
    ]);

    return [$server, $site];
}
