<?php

declare(strict_types=1);

use App\Enums\SiteType;
use App\Livewire\Sites\Edge\Workspace\Snippets;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Livewire\Livewire;

test('adding a snippet into a slot saves it and cancel drops unsaved edits', function () {
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
        ->test(Snippets::class, ['server' => $server, 'site' => $site])
        ->assertSet('items', [])
        ->call('newItem', 'body')
        ->assertSet('editingItem', 0)
        ->call('saveItem')
        ->assertHasErrors(['items.0.html'])
        ->call('useExample', 'banner')
        ->set('items.0.path', '/blog/*')
        ->call('saveItem')
        ->assertHasNoErrors()
        ->assertSet('editingItem', null)
        ->assertSee('Announcement banner')
        ->assertSee('/blog/*');

    $saved = $site->fresh()->edgeMeta()['snippets'];
    expect($saved['enabled'])->toBeTrue()
        ->and($saved['items'])->toHaveCount(1)
        ->and($saved['items'][0]['phase'])->toBe('body');

    $component
        ->call('editItem', 0)
        ->set('items.0.path', '/changed/*')
        ->call('closeItem')
        ->assertSet('items.0.path', '/blog/*');
});
