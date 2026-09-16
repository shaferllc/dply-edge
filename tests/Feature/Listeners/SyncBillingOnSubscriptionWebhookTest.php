<?php

namespace Tests\Feature\Listeners\SyncBillingOnSubscriptionWebhookTest;

use App\Listeners\SyncBillingOnSubscriptionWebhook;
use App\Models\Organization;
use App\Modules\Billing\Jobs\SyncOrganizationBillingJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Laravel\Cashier\Events\WebhookReceived;

uses(RefreshDatabase::class);

test('dispatches sync on subscription created event', function () {
    Bus::fake();
    $org = Organization::factory()->create(['stripe_id' => 'cus_test_123']);

    fireWebhook('customer.subscription.created', $org->stripe_id);

    Bus::assertDispatched(
        SyncOrganizationBillingJob::class,
        fn (SyncOrganizationBillingJob $job) => $job->organizationId === $org->id,
    );
});

test('dispatches sync on subscription updated event', function () {
    Bus::fake();
    $org = Organization::factory()->create(['stripe_id' => 'cus_test_456']);

    fireWebhook('customer.subscription.updated', $org->stripe_id);

    Bus::assertDispatchedTimes(SyncOrganizationBillingJob::class, 1);
});

test('ignores unrelated events', function () {
    Bus::fake();
    $org = Organization::factory()->create(['stripe_id' => 'cus_test_789']);

    fireWebhook('invoice.payment_succeeded', $org->stripe_id);

    Bus::assertNotDispatched(SyncOrganizationBillingJob::class);
});

test('no op when no organization matches customer id', function () {
    Bus::fake();

    fireWebhook('customer.subscription.updated', 'cus_unknown');

    Bus::assertNotDispatched(SyncOrganizationBillingJob::class);
});

test('no op when payload lacks customer id', function () {
    Bus::fake();
    Organization::factory()->create(['stripe_id' => 'cus_test_x']);

    app(SyncBillingOnSubscriptionWebhook::class)->handle(new WebhookReceived([
        'type' => 'customer.subscription.created',
        'data' => ['object' => []],
    ]));

    Bus::assertNotDispatched(SyncOrganizationBillingJob::class);
});

function fireWebhook(string $type, string $customerId): void
{
    app(SyncBillingOnSubscriptionWebhook::class)->handle(new WebhookReceived([
        'type' => $type,
        'data' => ['object' => ['customer' => $customerId]],
    ]));
}
