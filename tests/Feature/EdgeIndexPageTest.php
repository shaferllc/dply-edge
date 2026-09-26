<?php

declare(strict_types=1);

namespace Tests\Feature\EdgeIndexPageTest;

use App\Enums\SiteType;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Modules\Edge\Livewire\Index as EdgeIndex;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('empty state when no edge sites', function () {
    $user = ownerWithOrg();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Dashboard')
        ->assertSee('Launch your first Edge site')
        ->assertSee('What Edge gives you')
        ->assertSee('Deploy an edge app')
        ->assertSee('Browse templates')
        ->assertSee(route('edge.create'), false)
        ->assertSee(route('edge.templates'), false)
        ->assertDontSee('No edge sites found');
});

test('lists only edge sites for current org', function () {
    $user = ownerWithOrg();
    $org = $user->currentOrganization();
    $otherOrg = Organization::factory()->create();

    makeEdgeSite($user, $org, 'My Edge App');
    makeEdgeSite($user, $otherOrg, 'Other Org Edge');
    makeVmSite($user, $org, 'PHP Site');

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('My Edge App')
        ->assertDontSee('Other Org Edge')
        ->assertDontSee('PHP Site');
});

test('filter by failed status', function () {
    $user = ownerWithOrg();
    $org = $user->currentOrganization();
    makeEdgeSite($user, $org, 'Healthy', Site::STATUS_EDGE_ACTIVE);
    makeEdgeSite($user, $org, 'Broken', Site::STATUS_EDGE_FAILED);

    Livewire::actingAs($user)
        ->test(EdgeIndex::class)
        ->set('filter', 'failed')
        ->assertSee('Broken')
        ->assertDontSee('Healthy');
});

test('status pill renders for active site with live url', function () {
    $user = ownerWithOrg();
    $org = $user->currentOrganization();
    $site = makeEdgeSite($user, $org, 'Live App', Site::STATUS_EDGE_ACTIVE);
    $site->update(['meta' => [
        'runtime_profile' => 'edge_web',
        'edge' => [
            'source' => ['repo' => 'acme/web', 'branch' => 'main'],
            'live_url' => 'https://live-app.dply.host',
        ],
    ]]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertSee('Active')
        ->assertSee('live-app.dply.host')
        ->assertSee('acme/web@main')
        ->assertSee('Requests')
        ->assertSee('Bandwidth')
        ->assertSee('Last deploy')
        ->assertDontSee('All sites');
});

test('lists preview with pr badge', function () {
    $user = ownerWithOrg();
    $org = $user->currentOrganization();
    $server = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE],
    ]);
    Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'name' => 'PR #42 — Web',
        'type' => SiteType::Static,
        'edge_backend' => 'dply_edge',
        'status' => Site::STATUS_EDGE_ACTIVE,
        'meta' => [
            'runtime_profile' => 'edge_web',
            'edge' => [
                'source' => ['repo' => 'acme/web', 'branch' => 'feature/x'],
                'preview_branch' => 'feature/x',
                'preview_pr_number' => 42,
            ],
        ],
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('PR #42');
});

test('filter previews only shows preview sites', function () {
    $user = ownerWithOrg();
    $org = $user->currentOrganization();
    $server = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE],
    ]);
    $parent = makeEdgeSite($user, $org, 'parent-prod', Site::STATUS_EDGE_ACTIVE);
    Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'name' => 'pr-preview',
        'type' => SiteType::Static,
        'edge_backend' => 'dply_edge',
        'status' => Site::STATUS_EDGE_ACTIVE,
        'meta' => [
            'runtime_profile' => 'edge_web',
            'edge' => [
                'source' => ['repo' => 'acme/web', 'branch' => 'feature/x'],
                'preview_parent_site_id' => $parent->id,
                'preview_branch' => 'feature/x',
            ],
        ],
    ]);

    Livewire::actingAs($user)
        ->test(EdgeIndex::class)
        ->set('filter', 'previews')
        ->assertSee('pr-preview')
        ->assertDontSee('parent-prod');
});

test('filter counts match actual sites', function () {
    $user = ownerWithOrg();
    $org = $user->currentOrganization();
    makeEdgeSite($user, $org, 'A', Site::STATUS_EDGE_ACTIVE);
    makeEdgeSite($user, $org, 'B', Site::STATUS_EDGE_PROVISIONING);
    makeEdgeSite($user, $org, 'C', Site::STATUS_EDGE_FAILED);

    Livewire::actingAs($user)
        ->test(EdgeIndex::class)
        ->assertViewHas('totals', fn ($t): bool => $t['all'] === 3
            && $t['failed'] === 1
            && $t['provisioning'] === 1);
});

function ownerWithOrg(): User
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    return $user;
}

function makeEdgeSite(User $user, Organization $org, string $name, string $status = Site::STATUS_EDGE_PROVISIONING): Site
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
        'status' => $status,
        'meta' => [
            'runtime_profile' => 'edge_web',
            'edge' => [
                'source' => ['repo' => 'acme/web', 'branch' => 'main'],
            ],
        ],
    ]);
}

function makeVmSite(User $user, Organization $org, string $name): Site
{
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);

    return Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'name' => $name,
        'type' => SiteType::Php,
    ]);
}
