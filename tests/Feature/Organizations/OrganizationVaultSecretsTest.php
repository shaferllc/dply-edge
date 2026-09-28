<?php

declare(strict_types=1);

namespace Tests\Feature\Organizations\OrganizationVaultSecretsTest;

use App\Models\ExternalSecretStore;
use App\Models\Organization;
use App\Models\OrganizationSecret;
use App\Models\User;
use App\Modules\Secrets\Livewire\Secrets as OrganizationsSecrets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('admin can create a write-never secret', function () {
    [$user, $org] = ownerWithOrg();

    Livewire::actingAs($user)
        ->test(OrganizationsSecrets::class, ['organization' => $org])
        ->set('vault_key', 'STRIPE_SECRET')
        ->set('vault_value', 'sk_never_echo_this')
        ->set('vault_notes', 'production')
        ->call('createVaultSecret')
        ->assertHasNoErrors()
        ->assertDispatched('close-modal', 'new-secret')
        ->assertSet('vault_value', '')
        ->assertDontSee('sk_never_echo_this')
        ->assertSee('STRIPE_SECRET')
        ->assertSee('production');

    $secret = OrganizationSecret::query()->where('organization_id', $org->id)->first();
    expect($secret)->not->toBeNull()
        ->and($secret->key)->toBe('STRIPE_SECRET')
        ->and($secret->value)->toBe('sk_never_echo_this');
});

test('duplicate key requires notes', function () {
    [$user, $org] = ownerWithOrg();
    OrganizationSecret::factory()->create([
        'organization_id' => $org->id,
        'key' => 'STRIPE_SECRET',
        'notes' => null,
    ]);

    Livewire::actingAs($user)
        ->test(OrganizationsSecrets::class, ['organization' => $org])
        ->set('vault_key', 'STRIPE_SECRET')
        ->set('vault_value', 'another')
        ->set('vault_notes', '')
        ->call('createVaultSecret')
        ->assertHasErrors(['vault_notes'])
        ->assertNotDispatched('close-modal');
});

test('member cannot create a secret', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'member']);
    session(['current_organization_id' => $org->id]);

    Livewire::actingAs($user)
        ->test(OrganizationsSecrets::class, ['organization' => $org])
        ->set('vault_key', 'STRIPE_SECRET')
        ->set('vault_value', 'x')
        ->call('createVaultSecret')
        ->assertForbidden();
});

test('shared secrets list flags unlinked secrets and rotates inline', function () {
    [$user, $org] = ownerWithOrg();
    $secret = OrganizationSecret::factory()->create([
        'organization_id' => $org->id,
        'key' => 'OLD_MAILGUN_KEY',
        'value' => 'old',
    ]);

    Livewire::actingAs($user)
        ->test(OrganizationsSecrets::class, ['organization' => $org])
        ->assertSee('OLD_MAILGUN_KEY')
        ->assertSee('Not linked')
        ->assertSee('unused')
        ->call('startRotateVaultSecret', $secret->id)
        ->assertSee('New value for OLD_MAILGUN_KEY')
        ->set('rotate_value', 'new-value')
        ->call('rotateVaultSecret')
        ->assertHasNoErrors()
        ->assertSet('rotating_secret_id', null)
        ->assertDontSee('new-value');

    expect($secret->fresh()->value)->toBe('new-value');
});

test('admin can add and remove an external store', function () {
    [$user, $org] = ownerWithOrg();

    $component = Livewire::actingAs($user)
        ->test(OrganizationsSecrets::class, ['organization' => $org])
        ->call('setTab', 'stores')
        ->assertSee('No external stores connected')
        ->set('store_driver', 'doppler')
        ->set('store_name', 'corp-doppler')
        ->set('store_form.token', 'dp.st.secret')
        ->set('store_resolution', 'onbox')
        ->call('createStore')
        ->assertHasNoErrors()
        ->assertDispatched('close-modal', 'add-store')
        ->assertSee('corp-doppler')
        ->assertSee('not supported on Edge yet');

    $store = ExternalSecretStore::query()->where('organization_id', $org->id)->firstOrFail();
    expect($store->resolution)->toBe('onbox');

    $component->call('deleteStore', $store->id);
    expect(ExternalSecretStore::query()->whereKey($store->id)->exists())->toBeFalse();
});

/**
 * @return array{0: User, 1: Organization}
 */
function ownerWithOrg(): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    return [$user, $org];
}
