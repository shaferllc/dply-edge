<?php

declare(strict_types=1);

namespace Tests\Feature\RollbackEdgeDeploymentTest;

use App\Enums\SiteType;
use App\Models\EdgeDeployment;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Modules\Edge\Actions\RollbackEdgeDeployment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['edge.fake.enabled' => true]);
});

test('rollback flips host map and deployment statuses without re-upload', function () {
    [$site, $live, $old] = scaffoldEdgeSiteWithTwoDeployments();

    expect($site->edgeMeta()['active_deployment_id'])->toBe($live->id);

    $result = (new RollbackEdgeDeployment)->handle($site, $old->id);

    $site->refresh();
    $live->refresh();

    expect($result->id)->toBe($old->id);
    expect($result->status)->toBe(EdgeDeployment::STATUS_LIVE);
    expect($result->published_at)->not->toBeNull();
    expect($result->cf_kv_version)->toBe(2);
    expect($live->status)->toBe(EdgeDeployment::STATUS_SUPERSEDED);
    expect($site->edgeMeta()['active_deployment_id'])->toBe($old->id);
    expect($site->status)->toBe(Site::STATUS_EDGE_ACTIVE);

    $map = Cache::get('edge:fake:host-map', []);
    expect($map[$site->edgeHostname()]['deployment_id'] ?? null)->toBe($old->id);
    expect($map[$site->edgeHostname()]['storage_prefix'] ?? null)->toBe($old->storage_prefix);
});

test('rollback refuses when target is already live', function () {
    [$site, $live] = scaffoldEdgeSiteWithTwoDeployments();

    expect(fn () => (new RollbackEdgeDeployment)->handle($site, $live->id))
        ->toThrow(\RuntimeException::class, 'That deployment is already live.');
});

test('rollback refuses unknown deployment', function () {
    [$site] = scaffoldEdgeSiteWithTwoDeployments();

    expect(fn () => (new RollbackEdgeDeployment)->handle($site, '01ksaaaaaaaaaaaaaaaaaaaaaa'))
        ->toThrow(\RuntimeException::class, 'not eligible for rollback');
});

test('rollback refuses pruned deployment with helpful message', function () {
    [$site, $live, $old] = scaffoldEdgeSiteWithTwoDeployments();
    $old->update([
        'storage_prefix' => null,
        'pruned_at' => now(),
        'git_commit' => 'abcdef1234567890',
    ]);

    expect(fn () => (new RollbackEdgeDeployment)->handle($site, $old->id))
        ->toThrow(\RuntimeException::class, 'Artifacts for that deployment were pruned. Use "Deploy a specific commit" to rebuild from abcdef1.');
});

/**
 * @return array{0: Site, 1: EdgeDeployment, 2: EdgeDeployment}
 */
function scaffoldEdgeSiteWithTwoDeployments(): array
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
        'slug' => 'rollback-test',
    ]);

    $old = EdgeDeployment::query()->create([
        'site_id' => $site->id,
        'organization_id' => $org->id,
        'status' => EdgeDeployment::STATUS_SUPERSEDED,
        'storage_prefix' => 'edge/'.$org->id.'/'.$site->id.'/01OLD000000000000000000000',
        'cf_kv_version' => 1,
        'published_at' => now()->subHour(),
    ]);
    $live = EdgeDeployment::query()->create([
        'site_id' => $site->id,
        'organization_id' => $org->id,
        'status' => EdgeDeployment::STATUS_LIVE,
        'storage_prefix' => 'edge/'.$org->id.'/'.$site->id.'/01NEW000000000000000000000',
        'cf_kv_version' => 1,
        'published_at' => now(),
    ]);

    $site->update([
        'meta' => array_merge((array) $site->meta, [
            'edge' => [
                'runtime_mode' => 'static',
                'routing' => ['hostname' => 'rollback-test.dply.host', 'spa_fallback' => true, 'headers' => []],
                'live_url' => 'https://rollback-test.dply.host',
                'active_deployment_id' => $live->id,
            ],
        ]),
    ]);

    return [$site->refresh(), $live, $old];
}
