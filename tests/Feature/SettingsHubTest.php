<?php

namespace Tests\Feature\SettingsHubTest;

use App\Livewire\Settings\Hub;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('guest cannot view settings hub', function () {
    $this->get(route('settings.index'))->assertRedirect();
});

test('settings hub shows breadcrumb dashboard settings profile', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('settings.profile'))
        ->assertOk()
        ->assertSeeText('Dashboard')
        ->assertSeeText('Settings')
        ->assertSeeText('Profile')
        ->assertSee('aria-current="page"', false);
});

test('settings shell uses horizontal nav when navigation layout is top', function () {
    $user = User::factory()->create([
        'ui_preferences' => ['navigation_layout' => 'top'],
    ]);

    $this->actingAs($user)
        ->get(route('settings.profile'))
        ->assertOk()
        ->assertSee('data-settings-nav-layout="top"', false);
});

test('authenticated user can save profile preferences', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(Hub::class)
        ->set('ui.newsletter', false)
        ->set('ui.theme', 'dark')
        ->call('saveProfile')
        ->assertHasNoErrors();

    $user->refresh();
    expect($user->mergedUiPreferences()['newsletter'])->toBeFalse();
    expect($user->mergedUiPreferences()['theme'])->toBe('dark');
});

test('user can save profile timezone from servers settings section', function () {
    $user = User::factory()->create(['timezone' => 'UTC']);

    Livewire::actingAs($user)
        ->test(Hub::class)
        ->set('section', 'servers')
        ->set('profileTimezone', 'America/New_York')
        ->call('saveProfileTimezone')
        ->assertHasNoErrors();

    $user->refresh();
    expect($user->timezone)->toBe('America/New_York');
});

test('discard profile timezone restores saved value', function () {
    $user = User::factory()->create(['timezone' => 'UTC']);

    Livewire::actingAs($user)
        ->test(Hub::class)
        ->set('section', 'servers')
        ->set('profileTimezone', 'America/New_York')
        ->call('discardProfileTimezoneUnsaved')
        ->assertSet('profileTimezone', 'UTC');
});

test('persist theme saves immediately without save profile', function () {
    $user = User::factory()->create();
    $user->update([
        'ui_preferences' => array_merge($user->ui_preferences ?? [], [
            'theme' => 'system',
        ]),
    ]);

    Livewire::actingAs($user->fresh())
        ->test(Hub::class)
        ->call('persistTheme', 'dark')
        ->assertHasNoErrors();

    $user->refresh();
    expect($user->mergedUiPreferences()['theme'])->toBe('dark');
});

test('persist navigation layout saves immediately without save profile', function () {
    $user = User::factory()->create();
    $user->update([
        'ui_preferences' => array_merge($user->ui_preferences ?? [], [
            'navigation_layout' => 'sidebar',
        ]),
    ]);

    Livewire::actingAs($user->fresh())
        ->test(Hub::class)
        ->call('persistNavigationLayout', 'top')
        ->assertHasNoErrors();

    $user->refresh();
    expect($user->mergedUiPreferences()['navigation_layout'])->toBe('top');
});

test('org admin can save organization server site preferences', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'admin']);
    session(['current_organization_id' => $org->id]);

    Livewire::actingAs($user)
        ->test(Hub::class)
        ->set('section', 'servers')
        ->set('organizationServerSite.email_server_passwords', false)
        ->set('organizationServerSite.set_timezone_on_new_servers', true)
        ->call('saveOrganizationServersSites')
        ->assertHasNoErrors();

    $org->refresh();
    expect($org->mergedServerSitePreferences()['email_server_passwords'])->toBeFalse();
    expect($org->mergedServerSitePreferences()['set_timezone_on_new_servers'])->toBeTrue();
});

test('non admin cannot save organization server site preferences', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'member']);
    session(['current_organization_id' => $org->id]);

    Livewire::actingAs($user)
        ->test(Hub::class)
        ->set('section', 'servers')
        ->set('organizationServerSite.email_server_passwords', false)
        ->call('saveOrganizationServersSites')
        ->assertForbidden();
});

test('org admin can save organization insights preferences', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'admin']);
    session(['current_organization_id' => $org->id]);

    Livewire::actingAs($user)
        ->test(Hub::class)
        ->set('section', 'servers')
        ->set('organizationInsights.digest_non_critical', true)
        ->set('organizationInsights.digest_frequency', 'weekly')
        ->set('organizationInsights.quiet_hours_enabled', true)
        ->set('organizationInsights.quiet_hours_start', 23)
        ->set('organizationInsights.quiet_hours_end', 6)
        ->call('saveOrganizationInsights')
        ->assertHasNoErrors();

    $org->refresh();
    $prefs = $org->mergedInsightsPreferences();
    expect($prefs['digest_non_critical'])->toBeTrue();
    expect($prefs['digest_frequency'])->toBe('weekly');
    expect($prefs['quiet_hours_enabled'])->toBeTrue();
    expect($prefs['quiet_hours_start'])->toBe(23);
    expect($prefs['quiet_hours_end'])->toBe(6);
});

test('team admin can save team preferences without org admin role', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'member']);
    $team = Team::factory()->create(['organization_id' => $org->id]);
    $team->users()->attach($user->id, ['role' => 'admin']);
    session(['current_organization_id' => $org->id]);

    Livewire::actingAs($user)
        ->test(Hub::class)
        ->set('section', 'servers')
        ->set('selectedTeamId', $team->id)
        ->set('teamServerSite.default_server_sort', 'name')
        ->set('teamServerSite.show_server_updates_in_list', true)
        ->call('saveTeamServersSites')
        ->assertHasNoErrors();

    $team->refresh();
    expect($team->mergedTeamPreferences()['default_server_sort'])->toBe('name');
    expect($team->mergedTeamPreferences()['show_server_updates_in_list'])->toBeTrue();
});

test('team member cannot save team preferences', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'member']);
    $team = Team::factory()->create(['organization_id' => $org->id]);
    $team->users()->attach($user->id, ['role' => 'member']);
    session(['current_organization_id' => $org->id]);

    Livewire::actingAs($user)
        ->test(Hub::class)
        ->set('section', 'servers')
        ->set('selectedTeamId', $team->id)
        ->set('teamServerSite.default_server_sort', 'name')
        ->call('saveTeamServersSites')
        ->assertForbidden();
});
