<?php

declare(strict_types=1);

namespace Tests\Feature\EdgeIndexTest;

use App\Enums\SiteType;
use App\Modules\Edge\Jobs\TeardownEdgeSiteJob;
use App\Modules\Edge\Livewire\Index as EdgeIndex;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Pennant\Feature;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('guest is redirected from edge index', function () {
    Feature::define('surface.edge', fn () => true);
    Feature::flushCache();

    $this->get(route('edge.index'))
        ->assertRedirect(route('login'));
});

test('returns 404 when surface edge inactive', function () {
    Feature::define('surface.edge', fn () => false);
    Feature::flushCache();

    $user = ownerWithOrg();

    $this->actingAs($user)
        ->get(route('edge.index'))
        ->assertStatus(404);
});

test('authenticated user sees edge sites index when surface edge active', function () {
    Feature::define('surface.edge', fn () => true);
    Feature::flushCache();

    $user = ownerWithOrg();

    $this->actingAs($user)
        ->get(route('edge.index'))
        ->assertOk()
        ->assertSee('Edge sites')
        ->assertSee('Launch your first Edge site');
});

test('redesigned index renders richer edge site metadata', function () {
    Feature::define('surface.edge', fn () => true);
    Feature::flushCache();

    $user = ownerWithOrg();
    $org = $user->currentOrganization();
    $site = makeEdgeSite($user, $org, 'Edge Portal');
    $site->update([
        'status' => Site::STATUS_EDGE_ACTIVE,
        'meta' => [
            'runtime_profile' => 'edge_web',
            'edge' => [
                'runtime_mode' => 'hybrid',
                'source' => ['repo' => 'acme/portal', 'branch' => 'main'],
                'build' => ['framework' => 'nextjs'],
                'live_url' => 'https://edge-portal.on-dply.site',
            ],
        ],
    ]);

    $this->actingAs($user)
        ->get(route('edge.index'))
        ->assertOk()
        ->assertSee('Edge sites')
        ->assertSee('Hybrid')
        ->assertSee('Nextjs')
        ->assertSee('edge-portal.on-dply.site');
});

test('delete modal opens for authorized edge site', function () {
    Feature::define('surface.edge', fn () => true);
    Feature::flushCache();

    $user = ownerWithOrg();
    $org = $user->currentOrganization();
    $site = makeEdgeSite($user, $org, 'Delete Me');

    Livewire::actingAs($user)
        ->test(EdgeIndex::class)
        ->call('openDeleteSiteModal', (string) $site->id)
        ->assertSet('confirmingDeleteSiteId', (string) $site->id)
        ->assertSet('deleteMode', 'now')
        ->assertDispatched('open-modal');
});

test('delete site removes edge site from index', function () {
    Feature::define('surface.edge', fn () => true);
    Feature::flushCache();
    Queue::getFacadeRoot()->except([TeardownEdgeSiteJob::class]);

    $user = ownerWithOrg();
    $org = $user->currentOrganization();
    $site = makeEdgeSite($user, $org, 'Disposable Edge Site');

    Livewire::actingAs($user)
        ->test(EdgeIndex::class)
        ->call('openDeleteSiteModal', (string) $site->id)
        ->call('deleteSite')
        ->assertSet('confirmingDeleteSiteId', null)
        ->assertRedirect(route('edge.index'));

    $this->assertDatabaseMissing('sites', ['id' => $site->id]);
});

test('delete in 30 minutes queues delayed teardown for edge site', function () {
    Feature::define('surface.edge', fn () => true);
    Feature::flushCache();
    Queue::fake();

    $user = ownerWithOrg();
    $org = $user->currentOrganization();
    $site = makeEdgeSite($user, $org, 'Delay Delete Edge Site');

    Livewire::actingAs($user)
        ->test(EdgeIndex::class)
        ->call('openDeleteSiteModal', (string) $site->id)
        ->set('deleteMode', 'in_30')
        ->call('deleteSite')
        ->assertRedirect(route('edge.index'));

    $site->refresh();
    expect(data_get($site->meta, 'edge.scheduled_deletion_at'))->not->toBeNull();
    Queue::assertPushed(TeardownEdgeSiteJob::class, function (TeardownEdgeSiteJob $job) use ($site): bool {
        if ($job->siteId !== $site->id || ! ($job->delay instanceof \DateTimeInterface)) {
            return false;
        }

        $delay = Carbon::instance($job->delay);

        return $delay->between(now()->addMinutes(28), now()->addMinutes(32));
    });
});

test('scheduled delete queues teardown for selected future date and time', function () {
    Feature::define('surface.edge', fn () => true);
    Feature::flushCache();
    Queue::fake();

    $user = ownerWithOrg();
    $org = $user->currentOrganization();
    $site = makeEdgeSite($user, $org, 'Scheduled Delete Edge Site');
    $scheduledAt = now()->addHours(3)->startOfMinute();

    Livewire::actingAs($user)
        ->test(EdgeIndex::class)
        ->call('openDeleteSiteModal', (string) $site->id)
        ->set('deleteMode', 'scheduled')
        ->set('scheduledDeleteAt', $scheduledAt->format('Y-m-d\TH:i'))
        ->call('deleteSite')
        ->assertRedirect(route('edge.index'));

    $site->refresh();
    expect(data_get($site->meta, 'edge.scheduled_deletion_at'))->not->toBeNull();
    Queue::assertPushed(TeardownEdgeSiteJob::class, function (TeardownEdgeSiteJob $job) use ($site, $scheduledAt): bool {
        if ($job->siteId !== $site->id || ! ($job->delay instanceof \DateTimeInterface)) {
            return false;
        }

        $delay = Carbon::instance($job->delay)->timezone(config('app.timezone'));

        return $delay->equalTo($scheduledAt->copy()->timezone(config('app.timezone')));
    });
});

function ownerWithOrg(): User
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    return $user;
}

function makeEdgeSite(User $user, Organization $org, string $name): Site
{
    $server = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE],
    ]);

    return Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'name' => $name,
        'type' => SiteType::Static,
        'edge_backend' => 'dply_edge',
        'status' => Site::STATUS_EDGE_PROVISIONING,
        'meta' => [
            'runtime_profile' => 'edge_web',
            'edge' => [
                'source' => ['repo' => 'acme/web', 'branch' => 'main'],
                'build' => ['framework' => 'vite'],
            ],
        ],
    ]);
}
