<?php

declare(strict_types=1);

use App\Enums\SiteType;
use App\Livewire\Sites\Edge\Workspace\Forms;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Livewire\Livewire;

test('adding a form from a starter saves it and cancel drops unsaved edits', function () {
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
        ->test(Forms::class, ['server' => $server, 'site' => $site])
        ->assertSee('No forms yet')
        ->call('openPicker')
        ->assertSet('pickingEndpoint', true)
        ->call('addExample', 'contact')
        ->assertSet('editingEndpoint', 0)
        ->assertSee('HTML for this form')
        ->set('endpoints.0.to_email', 'inbox@example.com')
        ->call('saveEndpoint')
        ->assertHasNoErrors()
        ->assertSet('editingEndpoint', null)
        ->assertSee('go to inbox@example.com');

    $saved = $site->fresh()->edgeMeta()['forms'];
    expect($saved['enabled'])->toBeTrue()
        ->and($saved['endpoints'][0]['path'])->toBe('/contact');

    $component
        ->call('editEndpoint', 0)
        ->set('endpoints.0.path', '/changed')
        ->call('closeEndpoint')
        ->assertSet('endpoints.0.path', '/contact');
});
