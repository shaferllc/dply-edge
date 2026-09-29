<?php

declare(strict_types=1);

use App\Enums\SiteType;
use App\Livewire\Sites\Edge\Workspace\WaitingRoom;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Livewire\Livewire;

test('waiting room settings are edited one at a time and the switch saves on its own', function () {
    $org = Organization::factory()->create();
    $user = User::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);
    $server = Server::factory()->create(['organization_id' => $org->id, 'user_id' => $user->id, 'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE]]);
    $site = Site::factory()->create([
        'organization_id' => $org->id, 'server_id' => $server->id, 'user_id' => $user->id,
        'type' => SiteType::Static, 'edge_backend' => 'dply_edge', 'status' => Site::STATUS_EDGE_ACTIVE,
        'meta' => ['edge' => ['runtime_mode' => 'static']],
    ]);

    $page = Livewire::actingAs($user)->test(WaitingRoom::class, ['server' => $server, 'site' => $site])
        ->assertSee('The waiting room is off')
        ->call('editSetting', 'rate')
        ->assertSet('editingSetting', 'rate')
        ->set('new_users_per_minute', 40)
        ->assertSee('the last one waits about')
        ->assertSee('20 minutes')
        ->call('saveSetting')
        ->assertHasNoErrors()
        ->assertSet('editingSetting', null)
        ->assertSee('40 more people get in each minute');

    expect($site->fresh()->edgeMeta()['waiting_room']['new_users_per_minute'])->toBe(40);

    // Cancel drops unsaved edits.
    $page->call('editSetting', 'paths')->set('paths', '/checkout/*')->call('closeSetting')->assertSet('paths', '/*');

    $page->set('enabled', true)->assertSee('Past that, visitors wait in line');
    expect($site->fresh()->edgeMeta()['waiting_room']['enabled'])->toBeTrue();
});
