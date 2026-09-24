<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\SiteType;
use App\Livewire\Sites\Edge\Workspace\Previews;
use App\Livewire\Sites\EdgeDeploymentDetail;
use App\Models\EdgeDeployment;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('failed edge deployment detail does not show stable aliases', function () {
    [$user, $server, $site] = makeEdgeDeploymentDetailFixtures();

    $failed = EdgeDeployment::query()->create([
        'site_id' => $site->id,
        'organization_id' => $site->organization_id,
        'status' => EdgeDeployment::STATUS_FAILED,
        'git_branch' => 'main',
        'storage_prefix' => 'edge/test/failed-prefix',
        'failed_at' => now(),
        'failure_reason' => 'Build command exited with code 1',
    ]);

    $this->actingAs($user)
        ->get(route('sites.edge.deployments.show', [
            'server' => $server,
            'site' => $site,
            'deployment' => $failed,
        ]))
        ->assertOk()
        ->assertSee('More detail', false)
        ->assertSee('Storage prefix', false)
        ->assertDontSee('edge-app--d-', false);

    $this->actingAs($user)
        ->get(route('sites.edge.deployments.show', [
            'server' => $server,
            'site' => $site,
            'deployment' => $failed,
            'tab' => 'aliases',
        ]))
        ->assertOk()
        ->assertSee('No aliases yet', false);
});

test('edge deployment detail page renders overview and aliases tabs', function () {
    [$user, $server, $site, $deployment] = makeEdgeDeploymentDetailFixtures();

    $this->actingAs($user)
        ->get(route('sites.edge.deployments.show', [
            'server' => $server,
            'site' => $site,
            'deployment' => $deployment,
        ]))
        ->assertOk()
        ->assertSee('More detail', false)
        ->assertSee('Full SHA', false)
        ->assertSee($deployment->git_commit, false)
        ->assertSee('Storage prefix', false)
        ->assertSee($deployment->id, false)
        ->assertSee(route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'deploys']), false);

    $this->actingAs($user)
        ->get(route('sites.edge.deployments.show', [
            'server' => $server,
            'site' => $site,
            'deployment' => $deployment,
            'tab' => 'aliases',
        ]))
        ->assertOk()
        ->assertSee('Stable per-deploy aliases', false)
        ->assertSee('Copy URL', false)
        ->assertSee('edge-app--abc1234.', false);
});

test('edge deployment detail rejects foreign organization access', function () {
    [$user, $server, $site, $deployment] = makeEdgeDeploymentDetailFixtures();
    $stranger = User::factory()->create();
    $otherOrg = Organization::factory()->create();
    $otherOrg->users()->attach($stranger->id, ['role' => 'owner']);
    session(['current_organization_id' => $otherOrg->id]);

    $this->actingAs($stranger)
        ->get(route('sites.edge.deployments.show', [
            'server' => $server,
            'site' => $site,
            'deployment' => $deployment,
        ]))
        ->assertNotFound();
});

test('rollback confirmation modal includes structured deployment diff', function () {
    [$user, $server, $site, $deployment] = makeEdgeDeploymentDetailFixtures();

    Livewire::actingAs($user)
        ->test(EdgeDeploymentDetail::class, [
            'server' => $server,
            'site' => $site,
            'deployment' => $deployment,
        ])
        ->call('confirmRollbackEdgeDeployment', $deployment->id)
        ->assertSet('showConfirmActionModal', true)
        ->assertSet('confirmActionModalMethod', 'rollbackEdgeDeployment')
        ->assertSet('confirmActionModalDetails.0.label', 'Target deployment')
        ->assertSet('confirmActionModalDetails.0.value', $deployment->id);
});

test('deploys table links deployment id to edge deployment detail', function () {
    [$user, $server, $site, $deployment] = makeEdgeDeploymentDetailFixtures();

    $this->actingAs($user)
        ->get(route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'deploys']))
        ->assertOk()
        ->assertSee(route('sites.edge.deployments.show', [
            'server' => $server,
            'site' => $site,
            'deployment' => $deployment,
        ]), false);
});

test('promote confirmation modal includes structured preview diff', function () {
    Feature::define('global.deploy_contract', false);

    [$user, $server, $site] = makeEdgeDeploymentDetailFixtures();
    $preview = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $site->organization_id,
        'name' => 'Preview branch',
        'type' => SiteType::Static,
        'edge_backend' => 'dply_edge',
        'status' => Site::STATUS_EDGE_ACTIVE,
        'meta' => [
            'runtime_profile' => 'edge_web',
            'edge' => [
                'preview_parent_site_id' => $site->id,
                'preview_branch' => 'feature/x',
                'preview_head_sha' => 'fedcba0987654321fedcba0987654321fedcba09',
                'live_url' => 'https://preview.example.on-dply.site',
            ],
        ],
    ]);

    EdgeDeployment::query()->create([
        'site_id' => $preview->id,
        'organization_id' => $preview->organization_id,
        'status' => EdgeDeployment::STATUS_LIVE,
        'git_commit' => 'fedcba0987654321fedcba0987654321fedcba09',
        'git_branch' => 'feature/x',
        'storage_prefix' => 'edge/preview/prefix',
        'published_at' => now(),
    ]);

    Livewire::actingAs($user)
        ->test(Previews::class, ['server' => $server, 'site' => $site])
        ->call('confirmPromoteEdgePreview', (string) $preview->id)
        ->assertSet('showConfirmActionModal', true)
        ->assertSet('confirmActionModalMethod', 'promoteEdgePreview')
        ->assertSet('confirmActionModalDetails.2.label', 'Commit');
});

/**
 * @return array{0: User, 1: Server, 2: Site, 3: EdgeDeployment}
 */
function makeEdgeDeploymentDetailFixtures(): array
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

    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'name' => 'Edge App',
        'slug' => 'edge-app',
        'type' => SiteType::Static,
        'edge_backend' => 'dply_edge',
        'status' => Site::STATUS_EDGE_ACTIVE,
        'meta' => [
            'runtime_profile' => 'edge_web',
            'edge' => [
                'source' => ['repo' => 'acme/web', 'branch' => 'main'],
                'build' => ['command' => 'npm run build', 'output_dir' => 'dist'],
                'live_url' => 'https://edge-app.dply.host',
            ],
        ],
    ]);

    $live = EdgeDeployment::query()->create([
        'site_id' => $site->id,
        'organization_id' => $org->id,
        'status' => EdgeDeployment::STATUS_LIVE,
        'git_commit' => 'aaa1111111111111111111111111111111111111111',
        'git_branch' => 'main',
        'storage_prefix' => 'edge/test/live-prefix',
        'published_at' => now(),
    ]);

    $site->update([
        'meta' => array_merge(is_array($site->meta) ? $site->meta : [], [
            'edge' => array_merge($site->edgeMeta(), [
                'active_deployment_id' => $live->id,
            ]),
        ]),
    ]);

    $deployment = EdgeDeployment::query()->create([
        'site_id' => $site->id,
        'organization_id' => $org->id,
        'status' => EdgeDeployment::STATUS_SUPERSEDED,
        'git_commit' => 'abc1234567890abcdef1234567890abcdef12345678',
        'git_branch' => 'main',
        'storage_prefix' => 'edge/test/older-prefix',
        'aliases' => ['edge-app--abc1234.on-dply.site', 'edge-app--d-deadbeef.on-dply.site'],
        'published_at' => now()->subHour(),
    ]);

    return [$user, $server, $site->fresh(), $deployment];
}
