<?php

declare(strict_types=1);

use App\Enums\SiteType;
use App\Livewire\Sites\Edge\Workspace\Firewall;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Livewire\Livewire;

test('the country rule is edited in a modal, needs a country, and reads as a sentence', function () {
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

    Livewire::actingAs($user)->test(Firewall::class, ['server' => $server, 'site' => $site])
        ->assertSee('there’s no country rule')
        ->call('editRule')
        ->set('country_mode', 'allow')
        ->call('saveRule')
        ->assertHasErrors('selected_codes')
        ->assertSet('editing', true)
        ->call('addCountry', 'us')
        ->call('addCountry', 'CA')
        ->call('saveRule')
        ->assertHasNoErrors()
        ->assertSet('editing', false)
        ->assertSee('Only visitors from')
        ->assertSee('Canada and United States')
        ->assertSee('Only let in visitors from 2 countries')
        ->call('editRule')
        ->set('country_mode', 'block')
        ->call('closeRule')
        ->assertSet('country_mode', 'allow');

    expect($site->fresh()->edgeMeta()['firewall'])->toBe(['country_mode' => 'allow', 'countries' => ['CA', 'US']]);
});
