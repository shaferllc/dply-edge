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

/** Build minutes and concurrency are tier allowances; an org on its trial runs as Pro (ruling r-f17p5zgeh120cm5t). */
function edgeSite(array $org = []): array
{
    $org = Organization::factory()->create($org + ['trial_ends_at' => now()->addDays(3)]);
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

test('build time bills per second, with no allowance and no rounding up per build', function () {
    config(['dply.edge.usage_billing.build_millicents_per_minute' => 500]);
    [$org, , $deployment] = edgeSite();
    $deployment->update(['build_seconds' => 61]);
    EdgeDeployment::query()->create(['site_id' => $deployment->site_id, 'organization_id' => $org->id, 'build_seconds' => 120]);

    $seconds = EdgeBuildMinutes::secondsBetween($org, now()->startOfMonth(), now());

    expect($seconds)->toBe(181)
        ->and(EdgeBuildMinutes::costMillicents($seconds))->toEqualWithDelta(181 / 60 * 500, 1e-9);
});

test('a trial at its usage credit fails the deploy but keeps the live site', function () {
    config(['subscription.standard.trial.spending_limit_cents' => 0]);
    [, $site, $deployment] = edgeSite([]);

    (new BuildEdgeSiteJob($deployment->id))->handle(neverRuns());

    expect($deployment->fresh()->status)->toBe(EdgeDeployment::STATUS_FAILED)
        ->and($deployment->fresh()->failure_reason)->toContain('usage cap is used up')
        ->and($site->fresh()->status)->toBe(Site::STATUS_EDGE_ACTIVE);
});

test('a build waits when the org has no free build slot', function () {
    [$org, , $deployment] = edgeSite([]);
    Cache::lock('edge-build-slot:'.$org->id.':0', 600)->get(); // Pro: 2 concurrent builds, both taken
    Cache::lock('edge-build-slot:'.$org->id.':1', 600)->get();

    $job = (new BuildEdgeSiteJob($deployment->id))->withFakeQueueInteractions();

    $job->handle(neverRuns());

    $job->assertReleased(20);

    expect($deployment->fresh()->status)->toBe(EdgeDeployment::STATUS_BUILDING);
});

test('an org without a plan cannot deploy', function () {
    [, $site, $deployment] = edgeSite(['trial_ends_at' => now()->subDay()]);

    (new BuildEdgeSiteJob($deployment->id))->handle(neverRuns());

    expect($deployment->fresh()->status)->toBe(EdgeDeployment::STATUS_FAILED)
        ->and($deployment->fresh()->failure_reason)->toContain('no plan')
        ->and($site->fresh()->status)->toBe(Site::STATUS_EDGE_ACTIVE);
});
