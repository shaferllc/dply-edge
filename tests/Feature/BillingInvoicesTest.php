<?php

namespace Tests\Feature\BillingInvoicesTest;

use App\Models\Organization;
use App\Models\User;
use App\Modules\Billing\Livewire\Show as BillingShow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Callers: Billing\Livewire\Show invoice strip. Legacy /invoices redirects.
 * API /api/v1/billing/invoices is unchanged (BillingApiTest).
 * User: "we can probably merge …/billing and …/billing/analytics and …/invoices
 * to simplify billing, it shlu,ld be real easy to read"
 */
test('guest cannot view invoices', function () {
    $org = Organization::factory()->create();

    $this->get(route('billing.invoices', $org))->assertRedirect();
});

test('legacy invoices url redirects to billing', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'admin']);

    $this->actingAs($user)
        ->get(route('billing.invoices', $org))
        ->assertRedirect(route('billing.show', $org));
});

test('org admin can see invoices on the billing page', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'admin']);

    Livewire::actingAs($user)
        ->test(BillingShow::class, ['organization' => $org])
        ->assertOk()
        ->assertSee('Invoices')
        ->assertSee('No invoices yet');
});

test('org member cannot view billing invoices', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'member']);

    Livewire::actingAs($user)
        ->test(BillingShow::class, ['organization' => $org])
        ->assertForbidden();
});
