<?php

namespace Tests\Feature\DashboardTest;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function userWithOrganization(): User
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    return $user;
}

/*
 | The edge app list is the dashboard, at /dashboard (route name dashboard).
 */
test('dashboard is the edge app list', function () {
    $user = userWithOrganization();

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('New app');
});

test('dashboard redirects guest to login', function () {
    $response = $this->get(route('dashboard'));

    $response->assertRedirect(route('login', absolute: false));
});
