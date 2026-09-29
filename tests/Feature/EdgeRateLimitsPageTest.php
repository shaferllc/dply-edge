<?php

declare(strict_types=1);

use App\Enums\SiteType;
use App\Livewire\Sites\Edge\Workspace\RateLimits;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Livewire\Livewire;

test('rules are added from a preset in a modal, validated, and the first one turns limits on', function () {
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

    $page = Livewire::actingAs($user)->test(RateLimits::class, ['server' => $server, 'site' => $site])
        ->assertSee('No rate limits yet')
        ->call('newRule')
        ->call('usePreset', 'login')
        ->assertSee('about one request every 12 seconds')
        ->set('rule_path', 'login')
        ->call('saveRule')
        ->assertHasErrors('rule_path')
        ->set('rule_path', '/login')
        ->call('saveRule')
        ->assertHasNoErrors()
        ->assertSet('editingRule', null)
        ->assertSee('On /login, one visitor gets 5 requests a minute')
        ->assertSee('Then bot check')
        ->assertSee('Bot protection keys are missing');

    $saved = $site->fresh()->edgeMeta()['rate_limit'];
    expect($saved['enabled'])->toBeTrue()
        ->and($saved['rules'])->toBe([['path' => '/login', 'limit' => 5, 'window_seconds' => 60, 'action' => 'challenge']]);

    $page->call('editRule', 0)->call('removeEditingRule')->assertSee('No rate limits yet');
    expect($site->fresh()->edgeMeta()['rate_limit']['rules'])->toBe([]);
});
