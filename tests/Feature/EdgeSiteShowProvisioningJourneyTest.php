<?php

declare(strict_types=1);

namespace Tests\Feature\EdgeSiteShowProvisioningJourneyTest;

use App\Enums\SiteType;
use App\Models\EdgeDeployment;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('edge site provisioning shows edge build journey not byo nginx copy', function () {
    [$user, $server, $site] = makeProvisioningEdgeSite();

    $this->actingAs($user)
        ->get(route('sites.show', ['server' => $server, 'site' => $site]))
        ->assertOk()
        ->assertSee('Edge deployment')
        ->assertSee('Queued / cloning repository')
        ->assertSee('Publishing to Edge CDN')
        ->assertSee('acme/web@main')
        ->assertSee('npm run build')
        ->assertSee('dist')
        ->assertDontSee('Writing site config')
        ->assertDontSee('DNS readiness')
        ->assertDontSee('web server config');
});

test('edge active site shows workspace not provisioning journey', function () {
    [$user, $server, $site] = makeActiveEdgeSite();

    $this->actingAs($user)
        ->get(route('sites.show', ['server' => $server, 'site' => $site]))
        ->assertOk()
        ->assertSee('Edge site')
        ->assertDontSee('Edge build (')
        ->assertDontSee('Site provisioning');
});

test('edge provisioning site is not ready for workspace until active', function () {
    [, , $provisioningSite] = makeProvisioningEdgeSite();
    [, , $activeSite] = makeActiveEdgeSite();

    expect($provisioningSite->isReadyForWorkspace())->toBeFalse();
    expect($activeSite->isReadyForWorkspace())->toBeTrue();
});

/**
 * @return array{0: User, 1: Server, 2: Site}
 */
function makeProvisioningEdgeSite(): array
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
        'status' => Site::STATUS_EDGE_PROVISIONING,
        'meta' => [
            'runtime_profile' => 'edge_web',
            'edge' => [
                'source' => ['repo' => 'acme/web', 'branch' => 'main'],
                'build' => [
                    'command' => 'npm run build',
                    'output_dir' => 'dist',
                ],
                'live_url' => 'https://edge-app.dply.host',
            ],
        ],
    ]);

    EdgeDeployment::query()->create([
        'site_id' => $site->id,
        'organization_id' => $org->id,
        'status' => EdgeDeployment::STATUS_BUILDING,
        'git_branch' => 'main',
        'storage_prefix' => 'edge/test/prefix',
    ]);

    return [$user, $server, $site];
}

/**
 * @return array{0: User, 1: Server, 2: Site}
 */
function makeActiveEdgeSite(): array
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
        'name' => 'Edge Live',
        'type' => SiteType::Static,
        'edge_backend' => 'dply_edge',
        'status' => Site::STATUS_EDGE_ACTIVE,
        'meta' => [
            'runtime_profile' => 'edge_web',
            'edge' => [
                'source' => ['repo' => 'acme/web', 'branch' => 'main'],
                'build' => [
                    'command' => 'npm run build',
                    'output_dir' => 'dist',
                ],
                'live_url' => 'https://edge-live.dply.host',
            ],
        ],
    ]);

    EdgeDeployment::query()->create([
        'site_id' => $site->id,
        'organization_id' => $org->id,
        'status' => EdgeDeployment::STATUS_LIVE,
        'git_branch' => 'main',
        'storage_prefix' => 'edge/test/live',
        'published_at' => now(),
    ]);

    return [$user, $server, $site];
}
