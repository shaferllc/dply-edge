<?php

namespace Tests\Feature\OrganizationTest;

use App\Livewire\Organizations\Create as OrganizationsCreate;
use App\Livewire\Organizations\Index as OrganizationsIndex;
use App\Livewire\Organizations\Settings as OrganizationsSettings;
use App\Models\ApiToken;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

test('organizations index is displayed', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);

    $response = $this->actingAs($user)->get(route('organizations.index'));

    $response->assertOk();
    $response->assertSee($org->name);
});

test('organizations index prompts create when empty', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('organizations.index'));

    $response->assertOk();
    $response->assertSee('Create your first organization');
});

test('organization create page is displayed', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('organizations.create'));

    $response->assertOk();
    $response->assertSee('New organization');
});

test('organization can be created', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(OrganizationsCreate::class)
        ->set('name', 'Acme Corp')
        ->call('store')
        ->assertRedirect();

    $this->assertDatabaseHas('organizations', ['name' => 'Acme Corp']);
    $org = Organization::where('name', 'Acme Corp')->first();
    expect($org->hasMember($user))->toBeTrue();
    expect($org->users()->where('user_id', $user->id)->first()->pivot->role)->toBe('owner');
});

test('organization show is displayed for member', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'member']);

    $response = $this->actingAs($user)->get(route('organizations.show', $org));

    $response->assertOk();
    $response->assertSee($org->name);
    $response->assertSee('People');
    $response->assertDontSee('Needs attention'); // members see no admin checks
});

test('organization settings page shows email defaults and api tokens for admins', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);

    $response = $this->actingAs($user)->get(route('organizations.settings', $org));

    $response->assertOk();
    $response->assertSee('Data &amp; email', false);
    $response->assertSee('Deploy-finish emails');
    $response->assertSee('API tokens');
});

test('the retired automation url redirects to organization settings', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);

    $this->actingAs($user)
        ->get(route('organizations.automation', $org))
        ->assertRedirect(route('organizations.settings', $org));
});

test('organization settings prompt revoke api token opens confirm modal', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);

    ['token' => $token] = ApiToken::createToken($user, $org, 'CI token', null, ['*'], null);

    Livewire::actingAs($user)
        ->test(OrganizationsSettings::class, ['organization' => $org])
        ->call('promptRevokeApiToken', (string) $token->id)
        ->assertSet('showConfirmActionModal', true)
        ->assertSet('confirmActionModalMethod', 'revokeApiToken');

    $this->assertDatabaseHas('api_tokens', ['id' => $token->id]);
});

test('organization show returns 403 for non member', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();

    $response = $this->actingAs($user)->get(route('organizations.show', $org));

    $response->assertForbidden()
        ->assertSee('forbidden-experience', false)
        ->assertSee(__('Run policy audit'), false)
        ->assertSee(__('Access forbidden'), false);
});

test('organization switch updates session', function () {
    $user = User::factory()->create();
    $org1 = Organization::factory()->create();
    $org2 = Organization::factory()->create();
    $org1->users()->attach($user->id, ['role' => 'owner']);
    $org2->users()->attach($user->id, ['role' => 'member']);

    Livewire::actingAs($user)
        ->test(OrganizationsIndex::class)
        ->call('switchOrganization', $org2->id)
        ->assertRedirect();

    expect(session('current_organization_id'))->toEqual((string) $org2->id);
});

test('organization switch returns 403 for non member', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    session()->forget('current_organization_id');

    try {
        Livewire::actingAs($user)
            ->test(OrganizationsIndex::class)
            ->call('switchOrganization', $org->id);
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(403);

        return;
    }
    $this->assertNotEquals((string) $org->id, session('current_organization_id'), 'Non-member must not be able to switch to organization.');
});
