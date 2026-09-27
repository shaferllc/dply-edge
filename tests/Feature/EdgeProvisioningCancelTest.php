<?php

declare(strict_types=1);

namespace Tests\Feature\EdgeProvisioningCancelTest;

use App\Enums\SiteType;
use App\Livewire\Sites\EdgeSettings;
use App\Models\EdgeDeployment;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Modules\Edge\Jobs\BuildEdgeSiteJob;
use App\Modules\Edge\Jobs\TeardownEdgeSiteJob;
use App\Modules\Edge\Services\EdgeBuildRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Mockery;

uses(RefreshDatabase::class);

test('cancel build during edge provisioning tears down site and edge server', function () {
    Queue::getFacadeRoot()->except([TeardownEdgeSiteJob::class]);

    [$user, $server, $site] = makeProvisioningEdgeSite();
    $siteId = $site->id;
    $serverId = $server->id;
    $deploymentId = EdgeDeployment::query()->where('site_id', $siteId)->value('id');

    Livewire::actingAs($user)
        ->test(EdgeSettings::class, ['server' => $server, 'site' => $site, 'section' => 'general'])
        ->call('cancelProvisioning')
        ->assertRedirect(route('dashboard'));

    expect(Site::query()->find($siteId))->toBeNull();
    expect(Server::query()->find($serverId))->toBeNull();
    expect(EdgeDeployment::query()->find($deploymentId))->toBeNull();
});

test('cancel build marks deployment cancelled before teardown so workers cannot resurrect building', function () {
    Queue::fake();

    [$user, $server, $site] = makeProvisioningEdgeSite();
    $deployment = EdgeDeployment::query()->where('site_id', $site->id)->firstOrFail();

    Livewire::actingAs($user)
        ->test(EdgeSettings::class, ['server' => $server, 'site' => $site, 'section' => 'general'])
        ->call('cancelProvisioning')
        ->assertRedirect(route('dashboard'));

    $deployment->refresh();
    expect($deployment->wasCancelledByOperator())->toBeTrue()
        ->and($deployment->status)->toBe(EdgeDeployment::STATUS_FAILED)
        // First deploy with nothing live: the site is torn down, and reads as
        // deleting until TeardownEdgeSiteJob (queued here) removes it.
        ->and($site->fresh()->status)->toBe(Site::STATUS_EDGE_DELETING);

    Queue::assertPushed(TeardownEdgeSiteJob::class);

    $runner = Mockery::mock(EdgeBuildRunner::class);
    $runner->shouldNotReceive('build');

    app()->call([new BuildEdgeSiteJob($deployment->id), 'handle'], [
        'runner' => $runner,
    ]);

    expect($deployment->fresh()->status)->toBe(EdgeDeployment::STATUS_FAILED);
});

test('cancel in-flight redeploy aborts build without deleting the live site', function () {
    Queue::fake();

    [$user, $server, $site] = makeProvisioningEdgeSite();
    $meta = is_array($site->meta) ? $site->meta : [];
    $edge = is_array($meta['edge'] ?? null) ? $meta['edge'] : [];
    $edge['active_deployment_id'] = 'live-deploy-id';
    $meta['edge'] = $edge;
    $site->update([
        'status' => Site::STATUS_EDGE_ACTIVE,
        'meta' => $meta,
    ]);

    $deployment = EdgeDeployment::query()->where('site_id', $site->id)->firstOrFail();

    Livewire::actingAs($user)
        ->test(EdgeSettings::class, ['server' => $server, 'site' => $site, 'section' => 'general'])
        ->call('cancelProvisioning')
        ->assertHasNoErrors();

    expect(Site::query()->find($site->id))->not->toBeNull()
        ->and($site->fresh()->status)->toBe(Site::STATUS_EDGE_ACTIVE)
        ->and($deployment->fresh()->wasCancelledByOperator())->toBeTrue();

    Queue::assertNotPushed(TeardownEdgeSiteJob::class);
});

test('open cancel modal uses edge copy for edge sites', function () {
    [$user, $server, $site] = makeProvisioningEdgeSite();

    Livewire::actingAs($user)
        ->test(EdgeSettings::class, ['server' => $server, 'site' => $site, 'section' => 'general'])
        ->call('openCancelProvisioningModal')
        ->assertSet('confirmActionModalTitle', __('Cancel Edge build?'))
        ->assertSet('confirmActionModalConfirmLabel', __('Cancel and remove site'));
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
        'slug' => 'edge-app',
        'type' => SiteType::Static,
        'edge_backend' => 'dply_edge',
        'status' => Site::STATUS_EDGE_PROVISIONING,
        'meta' => [
            'runtime_profile' => 'edge_web',
            'edge' => [
                'source' => ['repo' => 'acme/web', 'branch' => 'main'],
                'build' => ['command' => 'npm run build', 'output_dir' => 'dist'],
                'routing' => ['hostname' => 'edge-app.dply.host'],
            ],
        ],
    ]);

    EdgeDeployment::query()->create([
        'site_id' => $site->id,
        'organization_id' => $org->id,
        'status' => EdgeDeployment::STATUS_BUILDING,
        'storage_prefix' => 'edge/test/prefix',
    ]);

    return [$user, $server, $site];
}
