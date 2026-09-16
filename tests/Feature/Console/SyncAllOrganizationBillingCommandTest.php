<?php

namespace Tests\Feature\Console\SyncAllOrganizationBillingCommandTest;

use App\Models\Organization;
use App\Modules\Billing\Jobs\SyncOrganizationBillingJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('no op when no orgs have subscriptions', function () {
    Bus::fake();
    Organization::factory()->count(3)->create();

    $this->artisan('dply:billing:sync-all')
        ->expectsOutputToContain('No subscribed organizations found')
        ->assertOk();

    Bus::assertNotDispatched(SyncOrganizationBillingJob::class);
});

test('dispatches a sync for each subscribed org', function () {
    Bus::fake();

    $org1 = Organization::factory()->create();
    $org2 = Organization::factory()->create();

    // Unsubscribed control: should be skipped entirely.
    Organization::factory()->create();

    insertRawSubscription($org1->id);
    insertRawSubscription($org2->id);

    $this->artisan('dply:billing:sync-all')->assertOk();

    Bus::assertDispatchedTimes(SyncOrganizationBillingJob::class, 2);
    Bus::assertDispatched(
        SyncOrganizationBillingJob::class,
        fn (SyncOrganizationBillingJob $job) => $job->organizationId === $org1->id,
    );
    Bus::assertDispatched(
        SyncOrganizationBillingJob::class,
        fn (SyncOrganizationBillingJob $job) => $job->organizationId === $org2->id,
    );
});

test('dry run lists orgs without dispatching', function () {
    Bus::fake();
    $org = Organization::factory()->create();
    insertRawSubscription($org->id);

    $this->artisan('dply:billing:sync-all', ['--dry-run' => true])
        ->expectsOutputToContain('would sync org='.$org->id)
        ->expectsOutputToContain('Dry-run: 1 org')
        ->assertOk();

    Bus::assertNotDispatched(SyncOrganizationBillingJob::class);
});

/**
 * Insert a subscriptions row directly via DB so the test doesn't need
 * Cashier's Subscription model to support ULID primary keys (see
 * SyncOrganizationBillingJobTest class docblock for context).
 */
function insertRawSubscription(string $organizationId): void
{
    DB::table('subscriptions')->insert([
        'id' => (string) Str::ulid(),
        'organization_id' => $organizationId,
        'type' => 'default',
        'stripe_id' => 'sub_test_'.Str::random(16),
        'stripe_status' => 'active',
        'stripe_price' => 'price_test_standard_base',
        'quantity' => null,
        'trial_ends_at' => null,
        'ends_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}
