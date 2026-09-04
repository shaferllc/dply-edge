<?php

namespace Tests\Feature\CredentialTest;

use App\Livewire\Credentials\Index as CredentialsIndex;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;
use Livewire\Livewire;
use Tests\Concerns\WithFeatures;

uses(RefreshDatabase::class);

uses(WithFeatures::class);

// DNS/CDN providers default off (config server_providers.enabled.*); enable the
// ones these tests connect so ServerProviderGate::enabled() doesn't refuse them.
beforeEach(function (): void {
    config([
        'server_providers.enabled.gandi' => true,
        'server_providers.enabled.namecheap' => true,
        'server_providers.enabled.vercel_dns' => true,
        'server_providers.enabled.cloudflare' => true,
    ]);
});

function userWithOrganization(): User
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    return $user;
}

test('credentials index redirects guest', function () {
    $response = $this->get(route('credentials.index'));

    $response->assertRedirect(route('login', absolute: false));
});

test('credentials index is displayed', function () {
    $user = userWithOrganization();
    $org = $user->currentOrganization();

    $response = $this->actingAs($user)->get(route('credentials.index'));

    $response->assertRedirect(route('organizations.credentials', $org, false));
});

test('organization credentials page is displayed', function () {
    $user = userWithOrganization();
    $org = $user->currentOrganization();

    $response = $this->actingAs($user)->get(route('organizations.credentials', $org));

    $response->assertOk();
    $response->assertSee('Credentials');
    $response->assertSee('Connect a provider');
});

test('credentials index forbidden for deployer', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'deployer']);
    session(['current_organization_id' => $org->id]);

    // /credentials is a session-scoped shortcut that redirects into the org
    // page, so the policy is asserted on the page that actually renders.
    $response = $this->actingAs($user)->get(route('organizations.credentials', $org));

    $response->assertForbidden();
});




test('credentials can be destroyed by owner', function () {
    $user = userWithOrganization();
    $org = $user->currentOrganization();
    $cred = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);

    Livewire::actingAs($user)
        ->test(CredentialsIndex::class)
        ->call('destroy', $cred->id);

    $this->assertModelMissing($cred);
});

test('credentials destroy returns 403 for ordinary org member', function () {
    $owner = userWithOrganization();
    $org = $owner->currentOrganization();
    $member = User::factory()->create();
    $org->users()->attach($member->id, ['role' => 'member']);
    $cred = ProviderCredential::factory()->create([
        'user_id' => $owner->id,
        'organization_id' => $org->id,
    ]);

    session(['current_organization_id' => $org->id]);

    try {
        Livewire::actingAs($member)
            ->test(CredentialsIndex::class, ['organization' => $org])
            ->call('destroy', $cred->id);
    } catch (AuthorizationException) {
        // Livewire may surface the policy deny as an exception.
    }

    $this->assertDatabaseHas('provider_credentials', ['id' => $cred->id]);
});

test('credentials destroy returns 403 for non member', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($otherUser->id, ['role' => 'owner']);
    $cred = ProviderCredential::factory()->create([
        'user_id' => $otherUser->id,
        'organization_id' => $org->id,
    ]);

    try {
        Livewire::actingAs($user)
            ->test(CredentialsIndex::class)
            ->call('destroy', $cred->id);
    } catch (AuthorizationException $e) {
        $this->addToAssertionCount(1);

        return;
    }

    $this->assertDatabaseHas('provider_credentials', ['id' => $cred->id]);
});

test('gandi credential can be connected', function () {
    $user = userWithOrganization();
    $org = $user->currentOrganization();

    Livewire::actingAs($user)
        ->test(CredentialsIndex::class)
        ->set('gandi_api_token', 'pat-gandi-secret')
        ->call('storeGandi')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('provider_credentials', [
        'organization_id' => $org->id,
        'provider' => 'gandi',
        'name' => 'Gandi',
    ]);
});

test('gandi credential requires a token', function () {
    $user = userWithOrganization();

    Livewire::actingAs($user)
        ->test(CredentialsIndex::class)
        ->set('gandi_api_token', '')
        ->call('storeGandi')
        ->assertHasErrors('gandi_api_token');

    $this->assertDatabaseCount('provider_credentials', 0);
});

test('namecheap credential can be connected', function () {
    $user = userWithOrganization();
    $org = $user->currentOrganization();

    Livewire::actingAs($user)
        ->test(CredentialsIndex::class)
        ->set('namecheap_name', 'Agency DNS')
        ->set('namecheap_api_user', 'acme')
        ->set('namecheap_api_key', 'nc-secret-key')
        ->call('storeNamecheap')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('provider_credentials', [
        'organization_id' => $org->id,
        'provider' => 'namecheap',
        'name' => 'Agency DNS',
    ]);
});

test('vercel dns credential stores optional team id', function () {
    $user = userWithOrganization();
    $org = $user->currentOrganization();

    Livewire::actingAs($user)
        ->test(CredentialsIndex::class)
        ->set('vercel_dns_api_token', 'vc-secret')
        ->set('vercel_dns_team_id', 'team_abc123')
        ->call('storeVercelDns')
        ->assertHasNoErrors();

    $credential = ProviderCredential::query()
        ->where('organization_id', $org->id)
        ->where('provider', 'vercel_dns')
        ->firstOrFail();

    expect($credential->credentials['team_id'])->toBe('team_abc123');
});

test('cdn tab lists only cdn capable providers', function () {
    $ids = CredentialsIndex::credentialProviderIds('cdn');

    expect($ids)->toContain('cloudflare');
    expect($ids)->toContain('vercel_dns');
    expect($ids)->not->toContain('namecheap');
    expect($ids)->not->toContain('digitalocean');
});

test('compute vm providers are grouped under vps and cloud not infrastructure hub label', function () {
    config([
        'server_providers.enabled.upcloud' => true,
        'server_providers.enabled.linode' => true,
    ]);
    Feature::define('provider.upcloud', fn (): bool => true);
    Feature::define('provider.linode', fn (): bool => true);
    Feature::flushCache();

    $nav = CredentialsIndex::credentialProviderNav();
    $groupById = [];
    foreach ($nav as $group) {
        foreach ($group['items'] as $item) {
            $groupById[$item['id']] = $group['label'];
        }
    }

    $vpsGroup = __('VPS & cloud');

    expect($groupById['upcloud'] ?? null)->toBe($vpsGroup);
    expect($groupById['linode'] ?? null)->toBe($vpsGroup);
    expect(array_column($nav, 'label'))->not->toContain(__('Infrastructure'));
});
