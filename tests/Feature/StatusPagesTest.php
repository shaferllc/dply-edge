<?php

namespace Tests\Feature\StatusPagesTest;

use App\Http\Middleware\RedirectGuestsToComingSoon;
use App\Livewire\Status\PublicPage;
use App\Livewire\StatusPages\Index as StatusPagesIndex;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteUptimeMonitor;
use App\Models\StatusPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function userWithOrg(string $role = 'owner'): User
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => $role]);
    session(['current_organization_id' => $org->id]);

    return $user;
}

test('guest can view public status page when public', function () {
    $user = userWithOrg();
    $org = $user->currentOrganization();

    $page = StatusPage::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'name' => 'API',
        'is_public' => true,
    ]);

    // Guest pages are caught by RedirectGuestsToComingSoon middleware in
    // non-local envs; bypass for tests asserting actual page render.
    $response = $this->withoutMiddleware([RedirectGuestsToComingSoon::class])
        ->get(route('status.public', $page));

    $response->assertOk();
    $response->assertSee('API');
});

test('guest cannot view private status page', function () {
    $user = userWithOrg();
    $org = $user->currentOrganization();

    $page = StatusPage::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'is_public' => false,
    ]);

    $this->withoutMiddleware([RedirectGuestsToComingSoon::class])
        ->get(route('status.public', $page))
        ->assertNotFound();
});

test('user can create status page', function () {
    $user = userWithOrg();
    $org = $user->currentOrganization();

    Livewire::actingAs($user)
        ->test(StatusPagesIndex::class)
        ->set('name', 'Production API')
        ->call('createPage');

    $this->assertDatabaseHas('status_pages', [
        'organization_id' => $org->id,
        'name' => 'Production API',
    ]);
});

test('public page shows monitor state', function () {
    $user = userWithOrg();
    $org = $user->currentOrganization();

    $page = StatusPage::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'is_public' => true,
    ]);

    $server = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'name' => 'Web 1',
        'health_status' => Server::HEALTH_REACHABLE,
    ]);

    $page->monitors()->create([
        'monitorable_type' => Server::class,
        'monitorable_id' => $server->id,
        'sort_order' => 0,
    ]);

    Livewire::test(PublicPage::class, ['statusPage' => $page->fresh()])
        ->assertSee('Web 1')
        ->assertSee('Operational');
});

test('public page shows site uptime monitor state', function () {
    $user = userWithOrg();
    $org = $user->currentOrganization();

    $page = StatusPage::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'is_public' => true,
    ]);

    $server = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'name' => 'Host',
        'health_status' => Server::HEALTH_REACHABLE,
    ]);

    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'status' => Site::STATUS_NGINX_ACTIVE,
    ]);

    $uptime = SiteUptimeMonitor::factory()->create([
        'site_id' => $site->id,
        'label' => 'API health',
        'last_checked_at' => now(),
        'last_ok' => true,
    ]);

    $page->monitors()->create([
        'monitorable_type' => SiteUptimeMonitor::class,
        'monitorable_id' => $uptime->id,
        'sort_order' => 0,
    ]);

    Livewire::test(PublicPage::class, ['statusPage' => $page->fresh()])
        ->assertSee('API health')
        ->assertSee('Operational');
});
