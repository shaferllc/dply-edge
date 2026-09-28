<?php

namespace Tests\Feature\Livewire\Billing\BillingPageStatesTest;

use App\Enums\QuotaSurface;
use App\Models\Organization;
use App\Models\User;
use App\Modules\Billing\Livewire\Show as BillingShow;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Billing\Services\PlanCheckout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = User::factory()->create();
    Config::set('subscription.standard.stripe.tier_pro', 'price_test_tier_pro');
    Config::set('subscription.standard.stripe.tier_team', 'price_test_tier_team');
});

function billingOrg(User $admin, array $attributes = []): Organization
{
    $org = Organization::factory()->noPlan()->create($attributes);
    $org->users()->attach($admin->id, ['role' => 'admin']);

    return $org;
}

test('a paused org whose subscription ended can check out again instead of switching', function () {
    $org = billingOrg($this->admin, ['billing_paused_at' => now()->subDay()]);
    Subscription::factory()->withPrice('price_test_tier_pro')->create([
        'organization_id' => $org->id,
        'stripe_status' => 'canceled',
        'ends_at' => now()->subDays(2),
    ]);

    Livewire::actingAs($this->admin)
        ->test(BillingShow::class, ['organization' => $org])
        ->set('tab', 'plan')
        ->assertSee('Choose Pro')
        ->assertSee('Choose Team')
        ->assertDontSee('Switch to Pro')
        ->assertDontSee('Switch to Team')
        ->assertDontSee('Cancel subscription');
});

test('a Stripe trial shows End trial now, confirmed in the app modal before endTrial runs', function () {
    $org = billingOrg($this->admin);
    Subscription::factory()->withPrice('price_test_tier_pro')->trialing(now()->addDays(3))->create(['organization_id' => $org->id]);

    Livewire::actingAs($this->admin)
        ->test(BillingShow::class, ['organization' => $org])
        ->set('tab', 'plan')
        ->assertSee('End trial now')
        ->assertSeeHtml('wire:click="confirmEndTrial"')
        ->assertDontSeeHtml('wire:confirm')
        ->call('confirmEndTrial')
        ->assertSet('showConfirmActionModal', true)
        ->assertSet('confirmActionModalMethod', 'endTrial')
        ->assertSee('usage cap is lifted');
});

test('switching plans is confirmed in the app modal before changeTier runs', function () {
    $org = billingOrg($this->admin);
    Subscription::factory()->withPrice('price_test_tier_pro')->active()->create(['organization_id' => $org->id]);

    Livewire::actingAs($this->admin)
        ->test(BillingShow::class, ['organization' => $org])
        ->set('tab', 'plan')
        ->assertSeeHtml("wire:click=\"confirmChangeTier('team')\"")
        ->call('confirmChangeTier', 'team')
        ->assertSet('showConfirmActionModal', true)
        ->assertSet('confirmActionModalMethod', 'changeTier')
        ->assertSet('confirmActionModalArguments', ['team'])
        ->assertSee('The prorated difference is invoiced now.');
});

test('an unknown tab falls back to Usage', function () {
    $org = billingOrg($this->admin);

    Livewire::actingAs($this->admin)
        ->withQueryParams(['tab' => 'nope'])
        ->test(BillingShow::class, ['organization' => $org])
        ->assertSet('tab', 'usage')
        ->set('tab', 'bogus')
        ->assertSet('tab', 'usage');
});

test('an active paid plan has no End trial now', function () {
    $org = billingOrg($this->admin);
    Subscription::factory()->withPrice('price_test_tier_pro')->active()->create(['organization_id' => $org->id]);

    Livewire::actingAs($this->admin)
        ->test(BillingShow::class, ['organization' => $org])
        ->set('tab', 'plan')
        ->assertDontSee('End trial now')
        ->set('tab', 'payment')
        ->assertSee('Billing details')
        ->assertSee('Save billing details')
        ->set('tab', 'limits')
        ->assertSee('Usage alert')
        ->assertSee('AI, browser and vector search limit');
});

test('cancel copy says the final usage is invoiced, not that charges stop', function () {
    $org = billingOrg($this->admin);
    Subscription::factory()->withPrice('price_test_tier_pro')->active()->create(['organization_id' => $org->id]);

    Livewire::actingAs($this->admin)
        ->test(BillingShow::class, ['organization' => $org])
        ->set('tab', 'plan')
        ->assertSee('usage from this period is invoiced once when it ends')
        ->assertDontSee('no further charges')
        ->assertDontSee('billing just stops');
});

test('billing is monthly only: no annual toggle or annual price', function () {
    $org = billingOrg($this->admin);
    Subscription::factory()->withPrice('price_test_tier_pro')->active()->create(['organization_id' => $org->id]);

    Livewire::actingAs($this->admin)
        ->test(BillingShow::class, ['organization' => $org])
        ->set('tab', 'plan')
        ->assertDontSee('annual billing')
        ->assertDontSeeHtml('switch-interval')
        ->assertDontSee('/yr');
});

// Regression guard: beta status doesn't change billing (ruling r-f17p5zgeh120cm5t),
// so the page must never promise a beta org "$0, nothing due".
test('a beta org is shown its real plan state, not $0 nothing due', function () {
    Config::set('subscription.standard.beta.cutover_at', null);
    $org = billingOrg($this->admin, ['beta_joined_at' => now()->subWeek()]);
    expect($org->fresh()->isBeta())->toBeTrue();

    Livewire::actingAs($this->admin)
        ->test(BillingShow::class, ['organization' => $org])
        ->assertSee('No plan')
        ->assertDontSee('nothing due')
        ->assertDontSee('in the dply beta');
});

test('beta status grants nothing billing-wise: no seat exemption, no caps envelope', function () {
    Config::set('subscription.standard.beta.cutover_at', null);
    Config::set('dply.max_organization_members', null);
    $plain = billingOrg($this->admin, ['trial_ends_at' => now()->addDays(3)]);
    $beta = billingOrg($this->admin, ['trial_ends_at' => now()->addDays(3), 'beta_joined_at' => now()->subWeek()]);

    expect($beta->fresh()->isBeta())->toBeTrue()
        ->and($beta->fresh()->effectiveMemberSeatCap())->toBe($plain->fresh()->effectiveMemberSeatCap())->toBe(3)
        ->and($beta->fresh()->quotaLimit(QuotaSurface::Edge))->toBe($plain->fresh()->quotaLimit(QuotaSurface::Edge))
        ->and(config('subscription.standard.beta'))->not->toHaveKeys(['sites', 'edge_apps']);
});

test('a card-less trial can add a card for its current plan (the path to End trial now)', function () {
    $org = billingOrg($this->admin, ['trial_ends_at' => now()->addDays(3)]);

    Livewire::actingAs($this->admin)
        ->test(BillingShow::class, ['organization' => $org])
        ->set('tab', 'plan')
        ->assertSee('Add a card for Pro')
        ->assertSeeHtml("wire:click=\"subscribeTier('pro')\"")
        ->assertDontSee('End trial now');
});

test('an ended subscription goes back through Checkout', function () {
    $org = billingOrg($this->admin, ['billing_paused_at' => now()->subDay()]);
    Subscription::factory()->withPrice('price_test_tier_pro')->create([
        'organization_id' => $org->id,
        'stripe_status' => 'canceled',
        'ends_at' => now()->subDays(2),
    ]);
    $checkout = \Mockery::mock(PlanCheckout::class);
    $checkout->shouldReceive('url')->once()->andReturn('https://checkout.stripe.test/s/1');
    app()->instance(PlanCheckout::class, $checkout);

    Livewire::actingAs($this->admin)
        ->test(BillingShow::class, ['organization' => $org])
        ->call('subscribeTier', 'pro')
        ->assertRedirect('https://checkout.stripe.test/s/1');
});
