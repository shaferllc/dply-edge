<?php

declare(strict_types=1);

use App\Enums\SiteType;
use App\Livewire\Sites\Edge\Workspace\Previews;
use App\Models\DeployContractRun;
use App\Models\EdgeDeployment;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Services\DeployContract\DeployContractEvaluator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'deploy_contract.require_for_promote' => true,
        'deploy_contract.require_run_before_promote' => true,
        'deploy_contract.require_replay_when_enabled' => false,
        'edge.preview_review.require_approval' => false,
        'edge.preview_review.block_open_comments' => false,
    ]);
});

test('promote is blocked until deploy contract passes', function () {
    [$user, $server, $parent, $preview] = deployContractPromoteFixtures();

    EdgeDeployment::query()->create([
        'site_id' => $preview->id,
        'organization_id' => $preview->organization_id,
        'status' => EdgeDeployment::STATUS_LIVE,
        'git_commit' => 'abc1234567890123456789012345678901234567890',
        'git_branch' => 'feature/gate',
        'storage_prefix' => 'edge/preview/live',
        'published_at' => now(),
    ]);

    Livewire::actingAs($user)
        ->test(Previews::class, ['server' => $server, 'site' => $parent])
        ->call('confirmPromoteEdgePreview', (string) $preview->id)
        ->assertSet('showConfirmActionModal', false);
});

test('promote opens confirm modal after contract passes', function () {
    [$user, $server, $parent, $preview] = deployContractPromoteFixtures();

    EdgeDeployment::query()->create([
        'site_id' => $preview->id,
        'organization_id' => $preview->organization_id,
        'status' => EdgeDeployment::STATUS_LIVE,
        'git_commit' => 'abc1234567890123456789012345678901234567890',
        'git_branch' => 'feature/gate',
        'storage_prefix' => 'edge/preview/live',
        'published_at' => now(),
    ]);

    app(DeployContractEvaluator::class)->runAndPersist($parent, $preview, $user);

    Livewire::actingAs($user)
        ->test(Previews::class, ['server' => $server, 'site' => $parent])
        ->call('confirmPromoteEdgePreview', (string) $preview->id)
        ->assertSet('showConfirmActionModal', true)
        ->assertSet('confirmActionModalMethod', 'promoteEdgePreview');
});

test('waiver allows promote after failed contract', function () {
    [$user, $server, $parent, $preview] = deployContractPromoteFixtures();

    $deployment = EdgeDeployment::query()->create([
        'site_id' => $preview->id,
        'organization_id' => $preview->organization_id,
        'status' => EdgeDeployment::STATUS_LIVE,
        'storage_prefix' => 'edge/preview/live',
        'git_commit' => 'abc1234567890123456789012345678901234567890',
        'published_at' => now(),
    ]);

    DeployContractRun::query()->create([
        'organization_id' => $parent->organization_id,
        'parent_site_id' => $parent->id,
        'preview_site_id' => $preview->id,
        'preview_deployment_id' => $deployment->id,
        'status' => DeployContractRun::STATUS_FAILED,
        'checks' => [],
        'summary' => ['passed_count' => 1, 'failed_count' => 1, 'skipped_count' => 0],
        'finished_at' => now(),
    ]);

    Livewire::actingAs($user)
        ->test(Previews::class, ['server' => $server, 'site' => $parent])
        ->set('deployContractWaiverReason', 'Manual QA on staging URLs')
        ->call('confirmWaiveDeployContract', (string) $preview->id)
        ->call('confirmActionModal')
        ->assertHasNoErrors();

    expect(DeployContractRun::query()->where('preview_site_id', $preview->id)->latest()->first()?->status)
        ->toBe(DeployContractRun::STATUS_WAIVED);

    Livewire::actingAs($user)
        ->test(Previews::class, ['server' => $server, 'site' => $parent])
        ->call('confirmPromoteEdgePreview', (string) $preview->id)
        ->assertSet('showConfirmActionModal', true);
});

/**
 * @return array{0: User, 1: Server, 2: Site, 3: Site}
 */
function deployContractPromoteFixtures(): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

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

    return [$user, $server, $parent, $preview];
}
