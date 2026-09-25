<?php

declare(strict_types=1);

namespace Tests\Feature\ConnectProviderModalTest;

use App\Livewire\Settings\ConnectProviderModal;
use App\Models\GitProviderToken;
use App\Models\Organization;
use App\Models\User;
use App\Modules\Edge\Livewire\Create;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Concerns\WithFeatures;

uses(RefreshDatabase::class);

uses(WithFeatures::class);

usesFeatures('surface.edge');

test('connect provider modal shows oauth and personal access token entry', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(ConnectProviderModal::class)
        ->assertSee('Connect a repository provider')
        ->assertSee('Paste a personal access token')
        ->call('showPatEntry')
        ->assertSet('showPatForm', true)
        ->assertSee('Validate and save');
});

test('connect provider modal saves a validated personal access token and notifies listeners', function () {
    Http::fake([
        'https://api.github.com/user' => Http::response([
            'id' => 99,
            'login' => 'token-user',
        ], 200),
    ]);

    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(ConnectProviderModal::class)
        ->call('showPatEntry')
        ->set('patToken', 'ghp_connect_modal_token')
        ->call('savePat')
        ->assertHasNoErrors()
        ->assertDispatched('source-control-linked')
        ->assertDispatched('close-modal', 'connect-provider');

    $pat = GitProviderToken::query()->where('user_id', $user->id)->firstOrFail();
    expect($pat->nickname)->toBe('token-user');
});

test('edge create refreshes linked accounts after source control linked event', function () {
    Http::fake([
        'https://api.github.com/user/repos*' => Http::response([], 200),
    ]);

    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    GitProviderToken::create([
        'user_id' => $user->id,
        'provider' => 'github',
        'provider_id' => '7',
        'nickname' => 'edge-user',
        'access_token' => 'ghp_edge_create_refresh',
    ]);

    // Mount already hydrates linked accounts when a token exists; call refresh
    // to exercise the source-control-linked listener path.
    Livewire::actingAs($user)
        ->test(Create::class)
        ->assertCount('linkedSourceControlAccounts', 1)
        ->assertSee('Github token - edge-user')
        ->assertSee('Connected')
        ->assertSee('Connect another account')
        ->set('wizardStep', 2)
        ->call('selectSourceControlAccount', 'not-the-current-account')
        ->assertSet('wizardStep', 2)
        ->call('refreshLinkedSourceControlAccounts')
        ->assertSet('repo_source', 'connected')
        ->assertCount('linkedSourceControlAccounts', 1);
});
