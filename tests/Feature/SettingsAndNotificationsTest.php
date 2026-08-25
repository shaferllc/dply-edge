<?php

namespace Tests\Feature\SettingsAndNotificationsTest;

use App\Livewire\Organizations\Settings as OrganizationsSettings;
use App\Livewire\Settings\Hub as SettingsHub;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('settings hub is reachable for authenticated user', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('settings.profile'))
        ->assertOk()
        ->assertSee('Identity, preferences, sessions, and account on this page', false);
});

test('settings hub livewire renders', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(SettingsHub::class)
        ->assertOk();
});

test('org admin can disable deploy email notifications', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);

    Livewire::actingAs($user)
        ->test(OrganizationsSettings::class, ['organization' => $org])
        ->set('deploy_email_notifications_enabled', false);

    $this->assertDatabaseHas('organizations', [
        'id' => $org->id,
        'deploy_email_notifications_enabled' => false,
    ]);
});
