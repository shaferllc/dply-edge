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
 | There is no dashboard page any more: one product surface means the edge
 | site list IS the dashboard. The route name survives so `route('dashboard')`
 | call sites keep resolving — see CLAUDE.md, "The Edge-only cut".
 */
test('dashboard sends an authenticated user to the edge surface', function () {
    $user = userWithOrganization();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertRedirect(route('edge.index'));
});

test('dashboard redirects guest to login', function () {
    $response = $this->get(route('dashboard'));

    $response->assertRedirect(route('login', absolute: false));
});
