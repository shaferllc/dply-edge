<?php

declare(strict_types=1);

namespace Tests\Feature\Organizations\OrganizationSettingsTest;

use App\Livewire\Organizations\Settings;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->org = Organization::factory()->create(['name' => 'Acme', 'slug' => 'acme']);
    $this->owner = User::factory()->create();
    $this->org->users()->attach($this->owner->id, ['role' => 'owner']);
});

test('each field autosaves on its own with one audit row per change', function () {
    Livewire::actingAs($this->owner)->test(Settings::class, ['organization' => $this->org])
        ->set('name', 'Acme Two')
        ->assertDispatched('org-setting-saved', field: 'name')
        ->set('slug', 'Acme-Two')
        ->assertSet('slug', 'acme-two')
        ->set('timezone', 'America/Chicago')
        ->assertHasNoErrors();

    expect($this->org->fresh()->only(['name', 'slug', 'timezone']))
        ->toBe(['name' => 'Acme Two', 'slug' => 'acme-two', 'timezone' => 'America/Chicago']);

    $log = AuditLog::where('action', 'organization.updated')->oldest('id')->first();
    expect($log->old_values)->toBe(['name' => 'Acme'])
        ->and($log->new_values)->toBe(['name' => 'Acme Two'])
        ->and(AuditLog::where('action', 'organization.updated')->count())->toBe(3);
});

test('an unchanged value or an invalid one does not save', function () {
    $email = $this->org->email;

    Livewire::actingAs($this->owner)->test(Settings::class, ['organization' => $this->org])
        ->set('name', 'Acme')
        ->assertNotDispatched('org-setting-saved')
        ->set('email', 'not-an-email')
        ->assertHasErrors('email')
        ->assertNotDispatched('org-setting-saved');

    expect($this->org->fresh()->email)->toBe($email)
        ->and(AuditLog::where('action', 'organization.updated')->count())->toBe(0);
});

test('danger zone links transfer to people and drops the VM-era copy', function () {
    $this->actingAs($this->owner)->get(route('organizations.settings', $this->org))
        ->assertOk()
        ->assertSee('id="api-tokens"', false)
        ->assertSee('Transfer ownership')
        ->assertSee(route('organizations.members', $this->org), false)
        ->assertDontSee('servers and sites');
});
