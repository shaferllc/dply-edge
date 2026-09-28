<?php

namespace Tests\Feature\Livewire\Billing\BillingStripeCacheTest;

use App\Models\Organization;
use App\Models\User;
use App\Modules\Billing\Livewire\Show as BillingShow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Cashier\Events\WebhookReceived;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('invoices load lazily from the per-customer cache and a webhook busts it', function () {
    $admin = User::factory()->create();
    $org = Organization::factory()->newSignup()->create(['stripe_id' => 'cus_cache_test', 'pm_last_four' => '4242']);
    $org->users()->attach($admin->id, ['role' => 'admin']);

    // Seeded cache stands in for Stripe: a hit means no API call is made.
    Cache::put('billing:stripe:cus_cache_test:invoices', [
        ['date' => now()->getTimestamp(), 'total' => '$20.00', 'url' => 'https://invoice.stripe.test/1'],
    ], 600);

    Livewire::actingAs($admin)
        ->test(BillingShow::class, ['organization' => $org])
        ->set('tab', 'invoices')
        ->assertSee('Loading invoices')
        ->assertDontSee('$20.00')
        ->call('loadInvoices')
        ->assertSee('$20.00')
        ->assertSee('https://invoice.stripe.test/1');

    event(new WebhookReceived(['type' => 'invoice.paid', 'data' => ['object' => ['customer' => 'cus_cache_test']]]));

    expect(Cache::has('billing:stripe:cus_cache_test:invoices'))->toBeFalse();
});
