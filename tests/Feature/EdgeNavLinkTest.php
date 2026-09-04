<?php

declare(strict_types=1);

namespace Tests\Feature\EdgeNavLinkTest;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;

uses(RefreshDatabase::class);

/*
 | The nav is asserted on /edge rather than /dashboard: the dashboard route is
 | a redirect into this surface now.
 */
test('edge surface carries the compute nav row when surface edge is active', function () {
    Feature::define('surface.edge', fn () => true);
    Feature::flushCache();
    $user = ownerWithOrg();

    $this->actingAs($user)
        ->get(route('edge.index'))
        ->assertOk()
        ->assertSee('Compute')
        ->assertSee('Edge')
        ->assertSee(route('edge.index'), false);
});

test('edge surface is not reachable when surface edge is inactive', function () {
    Feature::define('surface.edge', fn () => false);
    Feature::flushCache();

    $user = ownerWithOrg();

    $this->actingAs($user)
        ->get(route('edge.index'))
        ->assertNotFound();
});

function ownerWithOrg(): User
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    return $user;
}
