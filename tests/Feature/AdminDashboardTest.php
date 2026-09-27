<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\Organizations\Show as AdminOrganizationsShow;
use App\Livewire\Admin\Overview;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('guest cannot access platform admin', function () {
    $this->get(route('admin.overview'))->assertRedirect(route('login', absolute: false));
});

test('authenticated user can open platform admin overview in testing environment', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    $this->actingAs($user)->get(route('admin.overview'))->assertOk()
        ->assertSee(__('Overview'))
        ->assertSee(__('Operations'))
        ->assertSee(__('Audit log'));
});

test('legacy admin dashboard route redirects to overview', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('admin.dashboard'))
        ->assertRedirect(route('admin.overview'));
});

test('admin organizations index lists organizations', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create(['name' => 'Acme Fleet Org']);

    $this->actingAs($user)->get(route('admin.organizations.index'))
        ->assertOk()
        ->assertSee('Acme Fleet Org');
});

test('overview page shows core KPIs', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(Overview::class)
        ->assertSee(__('Users'))
        ->assertSee(__('Organizations'));
});

test('org detail page opens and shows members, including from a legacy tab link', function () {
    $user = User::factory()->create(['name' => 'Member Person']);
    $org = Organization::factory()->create(['name' => 'Detail Org']);
    $org->users()->attach($user->id, ['role' => 'owner']);

    Livewire::actingAs($user)
        ->withQueryParams(['tab' => 'providers'])
        ->test(AdminOrganizationsShow::class, ['organization' => $org])
        ->assertOk()
        ->assertSee('Detail Org')
        ->assertSee('Member Person');
});
