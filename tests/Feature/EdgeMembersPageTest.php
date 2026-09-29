<?php

declare(strict_types=1);

use App\Enums\SiteType;
use App\Livewire\Sites\Edge\Workspace\Members;
use App\Models\EdgeSiteMember;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Livewire\Livewire;

test('members lists everyone with their real access and edits app roles in a modal', function () {
    $org = Organization::factory()->create();
    $owner = User::factory()->create(['name' => 'Olive Owner']);
    $member = User::factory()->create(['name' => 'Mia Member']);
    $deployer = User::factory()->create(['name' => 'Dan Deployer']);
    $viewer = User::factory()->create(['name' => 'Vic Viewer']);
    $org->users()->attach($owner->id, ['role' => 'owner']);
    $org->users()->attach($member->id, ['role' => 'member']);
    $org->users()->attach($deployer->id, ['role' => 'deployer']);
    $org->users()->attach($viewer->id, ['role' => Organization::VIEW_ONLY_ROLE]);
    session(['current_organization_id' => $org->id]);

    $server = Server::factory()->create(['organization_id' => $org->id, 'user_id' => $owner->id, 'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE]]);
    $site = Site::factory()->create([
        'organization_id' => $org->id, 'server_id' => $server->id, 'user_id' => $owner->id,
        'type' => SiteType::Static, 'edge_backend' => 'dply_edge', 'status' => Site::STATUS_EDGE_ACTIVE,
        'meta' => ['edge' => ['runtime_mode' => 'static']],
    ]);

    $page = Livewire::actingAs($owner)->test(Members::class, ['server' => $server, 'site' => $site])
        ->assertSee('Olive Owner can do anything')
        ->assertSee('Mia Member can configure and deploy')
        ->assertSee('Dan Deployer can deploy')
        ->assertSee('Vic Viewer can only look');

    // Make the member read-only on this app.
    $page->call('editPerson', (string) $member->id)
        ->assertSet('member_role', '')
        ->set('member_role', EdgeSiteMember::ROLE_VIEWER)
        ->call('savePerson')
        ->assertHasNoErrors()
        ->assertSet('editingUserId', null)
        ->assertSee('Mia Member can only look')
        ->assertSee('Viewer on this app');

    // Back to their org role removes the app role.
    $page->call('editPerson', (string) $member->id)
        ->set('member_role', '')
        ->call('savePerson')
        ->assertSee('Mia Member can configure and deploy');
    expect($site->edgeSiteMembers()->count())->toBe(0);

    // Owners and org viewers can't be edited here.
    $page->call('editPerson', (string) $viewer->id)->assertStatus(404);
});
