<?php

declare(strict_types=1);

namespace Tests\Feature\EdgeNavLinkTest;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;

uses(RefreshDatabase::class);

/*
 | The app list is /dashboard. Databases and queues live on each app, so the
 | Compute tab strip is not on this page.
 */
test('dashboard does not show the compute nav row', function () {
    Feature::define('surface.edge', fn () => true);
    Feature::flushCache();
    $user = ownerWithOrg();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('Compute')
        ->assertSee('Dashboard')
        ->assertSee(route('dashboard'), false);
});

test('dashboard stays reachable when the retired surface edge flag is off', function () {
    Feature::define('surface.edge', fn () => false);
    Feature::flushCache();

    $user = ownerWithOrg();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk();
});

function ownerWithOrg(): User
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    return $user;
}
