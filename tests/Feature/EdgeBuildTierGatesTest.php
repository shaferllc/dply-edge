<?php

declare(strict_types=1);

namespace Tests\Feature\EdgeBuildTierGatesTest;

use App\Enums\SiteType;
use App\Models\EdgeDeployment;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Modules\Edge\Jobs\BuildEdgeSiteJob;
use App\Modules\Edge\Services\EdgeBuildRunner;
use App\Modules\Edge\Support\EdgeBuildMinutes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

/** Build minutes and concurrency are tier allowances (ruling r-zdescb7y05vp1bxx). */
function edgeSite(): array
{
    $org = Organization::factory()->create();
    $server = Server::factory()->create(['organization_id' => $org->id, 'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE]]);
    $site = Site::factory()->create([
        'organization_id' => $org->id, 'server_id' => $server->id, 'type' => SiteType::Static,
        'edge_backend' => 'dply_edge', 'status' => Site::STATUS_EDGE_ACTIVE,
    ]);
    $deployment = EdgeDeployment::query()->create([
        'site_id' => $site->id, 'organization_id' => $org->id, 'status' => EdgeDeployment::STATUS_BUILDING,
    ]);

    return [$org, $site, $deployment];
}

function neverRuns(): EdgeBuildRunner
{
    return \Mockery::mock(EdgeBuildRunner::class)->shouldNotReceive('build')->getMock();
}

test('build minutes round each build up and bill overage in millicents', function () {
    [$org, , $deployment] = edgeSite();
    $deployment->update(['build_seconds' => 61]);
    EdgeDeployment::query()->create(['site_id' => $deployment->site_id, 'organization_id' => $org->id, 'build_seconds' => 120]);

    $pro = config('subscription.standard.tiers.pro');

    expect(EdgeBuildMinutes::usedThisMonth($org))->toBe(4)
        ->and(EdgeBuildMinutes::overageCents(1_001, $pro))->toBe(1)   // 0.6¢ rounds up
        ->and(EdgeBuildMinutes::overageCents(1_500, $pro))->toBe(300)
        ->and(EdgeBuildMinutes::exhausted(300, config('subscription.standard.tiers.free')))->toBeTrue()
        ->and(EdgeBuildMinutes::exhausted(5_000, $pro))->toBeFalse();
});

test('a free org out of build minutes fails the deploy but keeps the live site', function () {
    [$org, $site, $deployment] = edgeSite();
    EdgeDeployment::query()->create(['site_id' => $site->id, 'organization_id' => $org->id, 'build_seconds' => 300 * 60]);

    (new BuildEdgeSiteJob($deployment->id))->handle(neverRuns());

    expect($deployment->fresh()->status)->toBe(EdgeDeployment::STATUS_FAILED)
        ->and($deployment->fresh()->failure_reason)->toContain('build minutes are used up')
        ->and($site->fresh()->status)->toBe(Site::STATUS_EDGE_ACTIVE);
});

test('a build waits when the org has no free build slot', function () {
    [$org, , $deployment] = edgeSite();
    Cache::lock('edge-build-slot:'.$org->id.':0', 600)->get(); // Free: 1 concurrent build, taken

    $job = (new BuildEdgeSiteJob($deployment->id))->withFakeQueueInteractions();

    $job->handle(neverRuns());

    $job->assertReleased(20);

    expect($deployment->fresh()->status)->toBe(EdgeDeployment::STATUS_BUILDING);
});
