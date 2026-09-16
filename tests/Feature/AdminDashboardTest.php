<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\Flags\GlobalFlags;
use App\Livewire\Admin\Flags\ProductLineFlags;
use App\Livewire\Admin\Organizations\Show as AdminOrganizationsShow;
use App\Livewire\Admin\Overview;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;
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

test('platform admin can toggle org feature flag from org detail', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create(['slug' => 'flag-toggle-org']);
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    Feature::for($org)->deactivate('workspace.ephemeral_credentials');
    expect(Feature::for($org)->active('workspace.ephemeral_credentials'))->toBeFalse();

    Livewire::actingAs($user)
        ->test(AdminOrganizationsShow::class, ['organization' => $org])
        ->call('toggleOrgFeatureFlag', 'workspace.ephemeral_credentials')
        ->assertDispatched('notify');

    expect(Feature::for($org)->active('workspace.ephemeral_credentials'))->toBeTrue();
});

test('platform admin global flags page renders config-driven flags read-only', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(GlobalFlags::class)
        ->assertOk()
        ->assertSee('global.billing_enabled')
        ->assertDontSee('requestGlobalFeatureFlagToggle');
});

test('edge product line page shows delivery emergency and surface flags', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('admin.flags.edge'))
        ->assertOk()
        ->assertSee('global.edge_delivery_enabled')
        ->assertSee('surface.edge');
});

test('orgs inherit config defaults for gated surfaces', function () {
    $org = Organization::factory()->create();

    // Cloud + Serverless are live (config default on). Edge stays parked.
    // Hyperscale providers still default off.
    expect(Feature::for($org)->active('surface.serverless'))->toBeTrue();
    expect(Feature::for($org)->active('surface.cloud'))->toBeTrue();
    expect(Feature::for($org)->active('surface.edge'))->toBeFalse();
    expect(Feature::for($org)->active('provider.aws'))->toBeFalse();
});

test('per-org override beats the config default for a surface', function () {
    $org = Organization::factory()->create();

    config(['features.surface.cloud' => false]);
    Feature::flushCache();

    expect(Feature::for($org)->active('surface.cloud'))->toBeFalse();

    Feature::for($org)->activate('surface.cloud');

    expect(Feature::for($org)->active('surface.cloud'))->toBeTrue();
});

test('clear org overrides button removes stored org values', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);

    config(['features.surface.edge' => false]);
    Feature::flushCache();
    Feature::for($org)->activate('surface.edge');

    Livewire::actingAs($user)
        ->test(ProductLineFlags::class, ['line' => 'edge'])
        ->call('requestClearOrgOverridesForFlag', 'surface.edge')
        ->call('confirmActionModal')
        ->assertDispatched('notify');

    expect(Feature::for($org)->active('surface.edge'))->toBeFalse();
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

test('legacy org tab providers redirects to vm servers tab', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();

    Livewire::actingAs($user)
        ->withQueryParams(['tab' => 'providers'])
        ->test(AdminOrganizationsShow::class, ['organization' => $org])
        ->assertSet('tab', 'vm-servers');
});
