<?php

declare(strict_types=1);

namespace Tests\Feature\DeployEdgeCommitTest;

use App\Enums\SiteType;
use App\Models\EdgeDeployment;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Modules\Edge\Actions\DeployEdgeCommit;
use App\Modules\Edge\Jobs\BuildEdgeSiteJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['edge.fake.enabled' => true]);
});

test('deploy-from-commit reuses stored artifacts when commit matches existing deployment', function () {
    [$site, $live, $old] = scaffoldEdgeSiteWithCommit();
    $beforeCount = EdgeDeployment::query()->where('site_id', $site->id)->count();

    $result = (new DeployEdgeCommit)->handle($site, 'abcdef1');

    $afterCount = EdgeDeployment::query()->where('site_id', $site->id)->count();
    expect($afterCount)->toBe($beforeCount);
    expect($result->id)->toBe($old->id);
    expect($result->status)->toBe(EdgeDeployment::STATUS_LIVE);
    $site->refresh();
    expect($site->edgeMeta()['active_deployment_id'])->toBe($old->id);
});

test('deploy-from-commit builds a new deployment when no stored artifacts match', function () {
    Queue::fake();
    [$site] = scaffoldEdgeSiteWithCommit();

    $result = (new DeployEdgeCommit)->handle($site, '9999999999999999999999999999999999999999');

    expect($result->status)->toBe(EdgeDeployment::STATUS_BUILDING);
    expect($result->git_commit)->toBe('9999999999999999999999999999999999999999');
    Queue::assertPushed(BuildEdgeSiteJob::class, function (BuildEdgeSiteJob $job) use ($result) {
        return $job->deploymentId === $result->id
            && $job->commitOverride === '9999999999999999999999999999999999999999';
    });
});

test('deploy-from-commit rejects invalid SHA format', function () {
    [$site] = scaffoldEdgeSiteWithCommit();

    expect(fn () => (new DeployEdgeCommit)->handle($site, 'not-a-sha'))
        ->toThrow(\RuntimeException::class, 'Commit SHA must be 7–40 hex characters.');
});

test('deploy-from-commit refuses when the matched commit is already live', function () {
    [$site, $live] = scaffoldEdgeSiteWithCommit();
    $live->update(['git_commit' => 'beefdead000000000000000000000000']);

    expect(fn () => (new DeployEdgeCommit)->handle($site, 'beefdea'))
        ->toThrow(\RuntimeException::class, 'That commit is already live.');
});

test('deploy-from-commit skips pruned matches and builds fresh', function () {
    Queue::fake();
    [$site, $live, $old] = scaffoldEdgeSiteWithCommit();
    $old->update(['storage_prefix' => null, 'pruned_at' => now()]);

    $result = (new DeployEdgeCommit)->handle($site, 'abcdef1');

    expect($result->id)->not->toBe($old->id);
    expect($result->status)->toBe(EdgeDeployment::STATUS_BUILDING);
    Queue::assertPushed(BuildEdgeSiteJob::class);
});

/**
 * @return array{0: Site, 1: EdgeDeployment, 2: EdgeDeployment}
 */
function scaffoldEdgeSiteWithCommit(): array
{
    $org = Organization::factory()->create();
    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE],
    ]);
    $site = Site::factory()->create([
        'organization_id' => $org->id,
        'server_id' => $server->id,
        'type' => SiteType::Static,
        'edge_backend' => 'dply_edge',
        'status' => Site::STATUS_EDGE_ACTIVE,
        'slug' => 'commit-test',
    ]);

    $old = EdgeDeployment::query()->create([
        'site_id' => $site->id,
        'organization_id' => $org->id,
        'status' => EdgeDeployment::STATUS_SUPERSEDED,
        'storage_prefix' => 'edge/'.$org->id.'/'.$site->id.'/01COMMITOLD0000000000000000',
        'cf_kv_version' => 1,
        'git_commit' => 'abcdef1234567890abcdef1234567890abcdef12',
        'published_at' => now()->subHour(),
    ]);
    $live = EdgeDeployment::query()->create([
        'site_id' => $site->id,
        'organization_id' => $org->id,
        'status' => EdgeDeployment::STATUS_LIVE,
        'storage_prefix' => 'edge/'.$org->id.'/'.$site->id.'/01COMMITNEW0000000000000000',
        'cf_kv_version' => 1,
        'git_commit' => '1111111111111111111111111111111111111111',
        'published_at' => now(),
    ]);

    $site->update([
        'meta' => array_merge((array) $site->meta, [
            'edge' => [
                'runtime_mode' => 'static',
                'source' => ['repo' => 'acme/static', 'branch' => 'main'],
                'routing' => ['hostname' => 'commit-test.dply.host', 'spa_fallback' => true, 'headers' => []],
                'live_url' => 'https://commit-test.dply.host',
                'active_deployment_id' => $live->id,
            ],
        ]),
    ]);

    return [$site->refresh(), $live, $old];
}
