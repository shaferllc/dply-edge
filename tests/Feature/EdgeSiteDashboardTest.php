<?php

declare(strict_types=1);

namespace Tests\Feature\EdgeSiteDashboardTest;

use App\Enums\SiteType;
use App\Livewire\Sites\Edge\Workspace\Previews;
use App\Livewire\Sites\EdgeSettings;
use App\Models\EdgeDeployment;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Modules\Edge\Jobs\BuildEdgeSiteJob;
use App\Modules\Edge\Jobs\TeardownEdgeSiteJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('dashboard renders edge panel for edge site', function () {
    [$user, $server, $site] = makeEdgeSite();

    Livewire::actingAs($user)
        ->test(EdgeSettings::class, ['server' => $server, 'site' => $site, 'section' => 'general'])
        ->assertSee('Edge App')
        ->assertSee('https://edge-app.dply.host')
        ->assertSee('acme/web@main')
        ->assertDontSee('{{ $edgeBranch }}')
        ->assertSee('acme/web');
});

/*
 | The overview owns identity, live URL, source and the latest deploy. The
 | delivery label ("Managed Edge" / "Managed Edge (local)") is still computed
 | by EdgeSiteViewData but no view prints it any more, and redeploying moved
 | to the Deploys section — so neither is asserted here.
 */
test('dashboard shows fake edge banner when fake mode enabled', function () {
    config(['edge.fake.enabled' => true]);
    [$user, $server, $site] = makeEdgeSite();

    Livewire::actingAs($user)
        ->test(EdgeSettings::class, ['server' => $server, 'site' => $site, 'section' => 'general'])
        ->assertSee('Fake edge — local mode');
});

test('dashboard hides the fake edge banner when the platform is configured', function () {
    config([
        'edge.fake.enabled' => false,
        'edge.r2.bucket' => 'dply-edge',
        'edge.r2.key' => 'access',
        'edge.r2.secret' => 'secret',
        'edge.cloudflare.account_id' => 'acct',
        'edge.cloudflare.api_token' => 'token',
        'edge.cloudflare.kv_namespace_id' => 'kv',
    ]);
    [$user, $server, $site] = makeEdgeSite();

    Livewire::actingAs($user)
        ->test(EdgeSettings::class, ['server' => $server, 'site' => $site, 'section' => 'general'])
        ->assertDontSee('Fake edge — local mode');
});

test('redeploy button dispatches build job', function () {
    Queue::fake();
    [$user, $server, $site] = makeEdgeSite();

    Livewire::actingAs($user)
        ->test(EdgeSettings::class, ['server' => $server, 'site' => $site, 'section' => 'general'])
        ->call('redeployEdge');

    Queue::assertPushed(BuildEdgeSiteJob::class);
});

/*
 | Non-edge sites are not reachable at all now: the workspace controller only
 | serves Edge sites, so a PHP site 404s rather than rendering a workspace
 | without the Edge panel.
 */
test('workspace is not reachable for a non edge site', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'type' => SiteType::Php,
    ]);

    $this->actingAs($user)
        ->get(route('sites.show', ['server' => $server, 'site' => $site]))
        ->assertNotFound();
});

test('preview teardown dispatches job', function () {
    Queue::fake();
    [$user, $server, $parent] = makeEdgeSite();
    $preview = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $parent->organization_id,
        'name' => 'preview',
        'type' => SiteType::Static,
        'edge_backend' => 'dply_edge',
        'meta' => [
            'runtime_profile' => 'edge_web',
            'edge' => [
                'preview_parent_site_id' => $parent->id,
                'preview_branch' => 'feature/x',
            ],
        ],
    ]);

    Livewire::actingAs($user)
        ->test(Previews::class, ['server' => $server, 'site' => $parent])
        ->call('tearDownEdgePreview', $preview->id);

    Queue::assertPushed(TeardownEdgeSiteJob::class, fn (TeardownEdgeSiteJob $job): bool => $job->siteId === $preview->id);
});

/**
 * @return array{0: User, 1: Server, 2: Site}
 */
function makeEdgeSite(): array
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
        'type' => SiteType::Static,
        'edge_backend' => 'dply_edge',
        'status' => Site::STATUS_EDGE_ACTIVE,
        'meta' => [
            'runtime_profile' => 'edge_web',
            'edge' => [
                'source' => ['repo' => 'acme/web', 'branch' => 'main'],
                'live_url' => 'https://edge-app.dply.host',
            ],
        ],
    ]);

    EdgeDeployment::query()->create([
        'site_id' => $site->id,
        'organization_id' => $org->id,
        'status' => EdgeDeployment::STATUS_LIVE,
        'storage_prefix' => 'edge/test/prefix',
        'published_at' => now(),
    ]);

    $deployment = EdgeDeployment::query()->where('site_id', $site->id)->latest('id')->first();
    $site->update([
        'meta' => array_merge(is_array($site->meta) ? $site->meta : [], [
            'edge' => array_merge($site->edgeMeta(), [
                'active_deployment_id' => $deployment?->id,
            ]),
        ]),
    ]);

    return [$user, $server, $site->fresh()];
}
