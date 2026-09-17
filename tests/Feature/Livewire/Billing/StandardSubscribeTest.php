<?php

namespace Tests\Feature\Livewire\Billing\StandardSubscribeTest;

use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Modules\Billing\Livewire\Show as BillingShow;
use App\Modules\Billing\Models\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = User::factory()->create();
    $this->org = Organization::factory()->create();
    $this->org->users()->attach($this->admin->id, ['role' => 'admin']);

    Config::set('subscription.standard.stripe.edge', 'price_test_edge_monthly');
    Config::set('subscription.standard.stripe.tier_pro', 'price_test_tier_pro');
    Config::set('subscription.standard.stripe.tier_team', 'price_test_tier_team');
});

test('billing page renders edge-site billing with no server plan residue', function () {
    $server = Server::factory()->for($this->org)->create(['status' => Server::STATUS_READY]);
    Site::factory()->for($this->org)->for($server)->create([
        'status' => Site::STATUS_EDGE_ACTIVE,
        'edge_backend' => 'dply_edge',
        'created_at' => now()->subDays(5),
    ]);

    Livewire::actingAs($this->admin)
        ->test(BillingShow::class, ['organization' => $this->org])
        ->assertOk()
        ->assertSee('Edge sites')
        ->assertSee('Choose Pro')
        ->assertSee('Choose Team')
        ->assertSee('How billing works')
        ->assertSee('Cost forecast')
        ->assertSee('Invoices')
        ->assertSee('Add a credit card')
        ->assertDontSee('Pay yearly')
        ->assertDontSee('Any size, any provider')
        ->assertDontSee('One flat plan')
        ->assertDontSee('dply plan');
});

test('subscribe rejects unknown tiers', function () {
    Livewire::actingAs($this->admin)
        ->test(BillingShow::class, ['organization' => $this->org])
        ->call('subscribeTier', 'enterprise')
        ->assertHasErrors('plan');
});

test('subscribe rejects when already subscribed', function () {
    Subscription::factory()
        ->withPrice('price_test_edge_monthly')
        ->active()
        ->create(['organization_id' => $this->org->id]);

    Livewire::actingAs($this->admin)
        ->test(BillingShow::class, ['organization' => $this->org])
        ->call('subscribeTier', 'pro')
        ->assertHasErrors('billing');
});

test('subscribe fails gracefully when pricing not configured', function () {
    Config::set('subscription.standard.stripe.tier_pro', '');

    Livewire::actingAs($this->admin)
        ->test(BillingShow::class, ['organization' => $this->org])
        ->call('subscribeTier', 'pro')
        ->assertHasErrors('billing');
});

test('non admin cannot subscribe', function () {
    $member = User::factory()->create();
    $this->org->users()->attach($member->id, ['role' => 'member']);

    Livewire::actingAs($member)
        ->test(BillingShow::class, ['organization' => $this->org])
        ->assertForbidden();
});

test('change tier rejects when no subscription', function () {
    Livewire::actingAs($this->admin)
        ->test(BillingShow::class, ['organization' => $this->org])
        ->call('changeTier', 'team')
        ->assertRedirect();

    expect(session('billing_error'))->not->toBeNull();
});

test('change tier refuses pro when the org has more members than pro seats', function () {
    Subscription::factory()->withPrice('price_test_tier_team')->active()->create(['organization_id' => $this->org->id]);
    User::factory()->count(3)->create()->each(fn (User $u) => $this->org->users()->attach($u->id, ['role' => 'member']));

    Livewire::actingAs($this->admin)
        ->test(BillingShow::class, ['organization' => $this->org])
        ->call('changeTier', 'pro')
        ->assertRedirect();

    expect(session('billing_error'))->toContain('Pro includes 3 seats');
});

test('cancel rejects when no active subscription', function () {
    Livewire::actingAs($this->admin)
        ->test(BillingShow::class, ['organization' => $this->org])
        ->call('cancelSubscription')
        ->assertRedirect();

    expect(session('billing_error'))->not->toBeNull();
});

test('cancel rejects when already canceled', function () {
    Subscription::factory()
        ->withPrice('price_test_edge_monthly')
        ->create([
            'organization_id' => $this->org->id,
            'stripe_status' => 'canceled',
            'ends_at' => now()->addDays(10), // grace period
        ]);

    Livewire::actingAs($this->admin)
        ->test(BillingShow::class, ['organization' => $this->org])
        ->assertSet('onGracePeriod', true)
        ->call('cancelSubscription')
        ->assertRedirect();

    expect(session('billing_error'))->not->toBeNull();
});

test('resume rejects when not in grace period', function () {
    Subscription::factory()
        ->withPrice('price_test_edge_monthly')
        ->active()
        ->create(['organization_id' => $this->org->id]);

    Livewire::actingAs($this->admin)
        ->test(BillingShow::class, ['organization' => $this->org])
        ->call('resumeSubscription')
        ->assertRedirect();

    expect(session('billing_error'))->not->toBeNull();
});
