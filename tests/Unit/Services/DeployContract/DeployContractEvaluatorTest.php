<?php

declare(strict_types=1);

use App\Enums\SiteType;
use App\Models\DeployContractRun;
use App\Models\EdgeDeployment;
use App\Models\EdgeDeployReplay;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Services\DeployContract\DeployContractEvaluator;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('evaluator persists passed run when build and review checks pass', function () {
    config([
        'deploy_contract.require_replay_when_enabled' => false,
        'edge.preview_review.block_open_comments' => false,
        'edge.preview_review.require_approval' => false,
    ]);

    [$parent, $preview] = deployContractSitePair();

    EdgeDeployment::query()->create([
        'site_id' => $preview->id,
        'organization_id' => $preview->organization_id,
        'status' => EdgeDeployment::STATUS_LIVE,
        'git_commit' => 'abc1234567890123456789012345678901234567890',
        'git_branch' => 'feature/contract',
        'storage_prefix' => 'edge/preview/live',
        'published_at' => now(),
    ]);

    $run = app(DeployContractEvaluator::class)->runAndPersist($parent, $preview);

    expect($run->status)->toBe(DeployContractRun::STATUS_PASSED)
        ->and($run->checks)->toBeArray()
        ->and(collect($run->checks)->pluck('status')->all())->not->toContain('fail');
});

test('evaluator fails when replay pass rate is below threshold', function () {
    config([
        'deploy_contract.require_replay_when_enabled' => true,
        'deploy_contract.min_replay_pass_rate' => 99,
        'edge.preview_review.require_approval' => false,
        'edge.preview_review.block_open_comments' => false,
    ]);

    [$parent, $preview] = deployContractSitePair();

    $deployment = EdgeDeployment::query()->create([
        'site_id' => $preview->id,
        'organization_id' => $preview->organization_id,
        'status' => EdgeDeployment::STATUS_LIVE,
        'git_commit' => 'abc1234567890123456789012345678901234567890',
        'git_branch' => 'feature/contract',
        'storage_prefix' => 'edge/preview/live',
        'published_at' => now(),
    ]);

    EdgeDeployReplay::query()->create([
        'organization_id' => $parent->organization_id,
        'parent_site_id' => $parent->id,
        'preview_site_id' => $preview->id,
        'preview_deployment_id' => $deployment->id,
        'status' => EdgeDeployReplay::STATUS_COMPLETED,
        'summary' => ['pass_rate' => 80, 'regressions' => 2],
        'finished_at' => now(),
    ]);

    $run = app(DeployContractEvaluator::class)->runAndPersist($parent, $preview);

    expect($run->status)->toBe(DeployContractRun::STATUS_FAILED);
});

/**
 * @return array{0: Site, 1: Site}
 */
function deployContractSitePair(): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);

    $server = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE],
    ]);

    $parent = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'type' => SiteType::Static,
        'edge_backend' => 'dply_edge',
        'status' => Site::STATUS_EDGE_ACTIVE,
        'meta' => ['runtime_profile' => 'edge_web', 'edge' => []],
    ]);

    $preview = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'type' => SiteType::Static,
        'edge_backend' => 'dply_edge',
        'status' => Site::STATUS_EDGE_ACTIVE,
        'meta' => [
            'runtime_profile' => 'edge_web',
            'edge' => ['preview_parent_site_id' => $parent->id],
        ],
    ]);

    return [$parent, $preview];
}
