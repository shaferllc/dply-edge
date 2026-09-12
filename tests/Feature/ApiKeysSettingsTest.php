<?php

namespace Tests\Feature\ApiKeysSettingsTest;

use App\Livewire\Settings\ApiKeys;
use App\Models\ApiToken;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function ownerWithOrg(): User
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    return $user;
}

test('api keys page loads for authenticated user', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('profile.api-keys'))
        ->assertOk();
});

test('org admin can create token with granular permissions', function () {
    $user = ownerWithOrg();
    $org = $user->currentOrganization();
    expect($org)->not->toBeNull();

    Livewire::actingAs($user)
        ->test(ApiKeys::class)
        ->set('organization_id', $org->id)
        ->set('token_name', 'CI')
        ->set('selected_abilities', ['servers.read', 'edge.deploy'])
        ->call('createToken')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('api_tokens', [
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'name' => 'CI',
    ]);

    $token = ApiToken::query()->where('name', 'CI')->first();
    expect($token)->not->toBeNull();
    expect($token->abilities ?? [])->toEqualCanonicalizing(['servers.read', 'edge.deploy']);
});

test('create requires at least one permission', function () {
    $user = ownerWithOrg();
    $org = $user->currentOrganization();

    Livewire::actingAs($user)
        ->test(ApiKeys::class)
        ->set('organization_id', $org->id)
        ->set('token_name', 'Empty')
        ->set('selected_abilities', [])
        ->call('createToken')
        ->assertHasErrors(['selected_abilities']);
});

test('invalid whitelist ip fails validation', function () {
    $user = ownerWithOrg();
    $org = $user->currentOrganization();

    Livewire::actingAs($user)
        ->test(ApiKeys::class)
        ->set('organization_id', $org->id)
        ->set('token_name', 'Bad IP')
        ->set('token_allowed_ips_text', 'not-an-ip')
        ->set('selected_abilities', ['servers.read'])
        ->call('createToken')
        ->assertHasErrors(['token_allowed_ips_text']);
});

test('comma separated ips are accepted', function () {
    expect(ApiToken::parseAllowedIpsInput('203.0.113.1, 203.0.113.2', 'ips'))->toEqual(['203.0.113.1', '203.0.113.2']);
});

test('non admin sees no organization selector options', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'member']);
    session(['current_organization_id' => $org->id]);

    Livewire::actingAs($user)
        ->test(ApiKeys::class)
        ->assertSet('organization_id', null);
});

test('create blocked when paid plan required and org not on pro', function () {
    config(['dply.api_tokens_require_paid_plan' => true]);

    $user = ownerWithOrg();
    $org = $user->currentOrganization();
    expect($org)->not->toBeNull();

    Livewire::actingAs($user)
        ->test(ApiKeys::class)
        ->set('organization_id', $org->id)
        ->set('token_name', 'CI')
        ->set('selected_abilities', ['servers.read'])
        ->call('createToken')
        ->assertHasErrors(['token_name']);
});

test('revoke token uses confirmation modal before deleting', function () {
    $user = ownerWithOrg();
    $org = $user->currentOrganization();
    expect($org)->not->toBeNull();

    ['token' => $token] = ApiToken::createToken($user, $org, 'CLI token', null, ['servers.read']);

    Livewire::actingAs($user)
        ->test(ApiKeys::class)
        ->call(
            'openConfirmActionModal',
            'revokeToken',
            [$token->id],
            'Revoke token',
            'Revoke this token? It will stop working immediately.',
            'Revoke',
            true
        )
        ->assertSet('showConfirmActionModal', true)
        ->assertSet('confirmActionModalMethod', 'revokeToken')
        ->assertSet('confirmActionModalArguments', [$token->id]);

    $this->assertDatabaseHas('api_tokens', ['id' => $token->id]);

    Livewire::actingAs($user)
        ->test(ApiKeys::class)
        ->call(
            'openConfirmActionModal',
            'revokeToken',
            [$token->id],
            'Revoke token',
            'Revoke this token? It will stop working immediately.',
            'Revoke',
            true
        )
        ->call('confirmActionModal');

    $this->assertDatabaseMissing('api_tokens', ['id' => $token->id]);
});
