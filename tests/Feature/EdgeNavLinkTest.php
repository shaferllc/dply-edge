<?php

declare(strict_types=1);

namespace Tests\Feature\EdgeNavLinkTest;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 | The app list is /dashboard. Databases and queues live on each app, so the
 | Compute tab strip is not on this page.
 */
test('dashboard does not show the compute nav row', function () {
    $user = ownerWithOrg();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('Compute')
        ->assertSee('Dashboard')
        ->assertSee(route('dashboard'), false);
});

function ownerWithOrg(): User
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    return $user;
}
