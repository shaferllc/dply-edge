<?php

namespace Tests\Feature\Livewire\Billing\StandardSubscribeTest;

use App\Modules\Billing\Livewire\Show as BillingShow;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Modules\Billing\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = User::factory()->create();
    $this->org = Organization::factory()->create();
    $this->org->users()->attach($this->admin->id, ['role' => 'admin']);

    Config::set('subscription.standard.stripe.edge', 'price_test_edge_monthly');
    Config::set('subscription.standard.stripe.edge_yearly', 'price_test_edge_yearly');
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
        ->assertSee('dply Edge site')
        ->assertSee('How billing works')
        // An Edge price is configured, so the Subscribe CTA is offered.
        ->assertSee('Pay yearly')
        ->assertDontSee('Any size, any provider')
        ->assertDontSee('One flat plan')
        ->assertDontSee('dply plan');
});

test('subscribe rejects invalid intervals', function () {
    Livewire::actingAs($this->admin)
        ->test(BillingShow::class, ['organization' => $this->org])
        ->call('subscribeStandard', 'weekly')
        ->assertHasErrors('plan');
});

test('subscribe rejects when already subscribed', function () {
    Subscription::factory()
        ->withPrice('price_test_edge_monthly')
        ->active()
        ->create(['organization_id' => $this->org->id]);

    Livewire::actingAs($this->admin)
        ->test(BillingShow::class, ['organization' => $this->org])
        ->call('subscribeStandard', 'month')
        ->assertHasErrors('billing');
});

test('subscribe fails gracefully when pricing not configured', function () {
    Config::set('subscription.standard.stripe.edge', '');
    Config::set('subscription.standard.stripe.edge_yearly', '');

    Livewire::actingAs($this->admin)
        ->test(BillingShow::class, ['organization' => $this->org])
        ->call('subscribeStandard', 'month')
        ->assertHasErrors('billing');
});

test('non admin cannot subscribe', function () {
    $member = User::factory()->create();
    $this->org->users()->attach($member->id, ['role' => 'member']);

    Livewire::actingAs($member)
        ->test(BillingShow::class, ['organization' => $this->org])
        ->assertForbidden();
});

test('switch interval rejects when no subscription', function () {
    Livewire::actingAs($this->admin)
        ->test(BillingShow::class, ['organization' => $this->org])
        ->call('switchInterval')
        ->assertRedirect();

    expect(session('billing_error'))->not->toBeNull();
});

test('switch interval rejects when target prices unconfigured', function () {
    Subscription::factory()
        ->withPrice('price_test_edge_monthly')
        ->active()
        ->create(['organization_id' => $this->org->id]);

    // Current interval resolves to monthly → target is yearly → unconfigure it.
    Config::set('subscription.standard.stripe.edge_yearly', '');

    Livewire::actingAs($this->admin)
        ->test(BillingShow::class, ['organization' => $this->org])
        ->call('switchInterval')
        ->assertRedirect();

    expect(session('billing_error'))->not->toBeNull();
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
