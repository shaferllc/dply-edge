<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Billing;

use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use App\Modules\Billing\Livewire\Show as BillingShow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Callers: merged Billing\Livewire\Show. Legacy /billing/analytics redirects.
 * No API/schema change (API /api/v1/billing* stays on BillingAnalytics).
 * User: "we can probably merge …/billing and …/billing/analytics and …/invoices
 * to simplify billing, it shlu,ld be real easy to read"
 */
test('billing page includes the compact cost forecast', function () {
    $admin = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($admin->id, ['role' => 'admin']);

    Server::factory()->for($org)->create([
        'status' => Server::STATUS_READY,
        'created_at' => now()->subDays(5),
    ]);

    Livewire::actingAs($admin)
        ->test(BillingShow::class, ['organization' => $org])
        ->assertOk()
        ->assertSee('Cost forecast')
        ->assertSee('Projected this month')
        ->assertSee('Invoices')
        ->assertDontSee('Historical spend')
        ->assertDontSee('Spend by category')
        ->assertDontSee('MRR')
        ->assertDontSee('Recurring revenue')
        ->assertDontSee('Stripe sync events');
});

test('legacy billing analytics url redirects to billing', function () {
    $admin = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($admin->id, ['role' => 'admin']);

    $this->actingAs($admin)
        ->get(route('billing.analytics', $org))
        ->assertRedirect(route('billing.show', $org));
});

test('billing page requires org update permission', function () {
    $member = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($member->id, ['role' => 'member']);

    Livewire::actingAs($member)
        ->test(BillingShow::class, ['organization' => $org])
        ->assertForbidden();
});
