<?php

declare(strict_types=1);

use App\Enums\SiteType;
use App\Livewire\Sites\Edge\Workspace\Tags;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Livewire\Livewire;

test('adding a tool from the modal saves it and cancel drops unsaved edits', function () {
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
        'type' => SiteType::Static,
        'edge_backend' => 'dply_edge',
        'status' => Site::STATUS_EDGE_ACTIVE,
    ]);

    $component = Livewire::actingAs($user)
        ->test(Tags::class, ['server' => $server, 'site' => $site])
        ->assertSee('No tags yet')
        ->call('openPicker')
        ->call('addVendor', 'ga4')
        ->assertSet('editingTool', 0)
        ->set('tools.0.id', 'G-ABC1234567')
        ->set('tools.0.path', '/blog/*')
        ->call('saveTool')
        ->assertHasNoErrors()
        ->assertSet('editingTool', null)
        ->assertSee('Google Analytics')
        ->assertSee('on /blog/*');

    expect($site->fresh()->edgeMeta()['tags']['tools'])->toHaveCount(1);

    $component
        ->call('editTool', 0)
        ->set('tools.0.path', '/changed/*')
        ->call('closeTool')
        ->assertSet('tools.0.path', '/blog/*');
});
