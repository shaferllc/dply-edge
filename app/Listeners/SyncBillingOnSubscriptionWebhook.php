<?php

namespace App\Listeners;

use App\Models\Organization;
use App\Modules\Billing\Jobs\SyncOrganizationBillingJob;
use App\Modules\Billing\Livewire\Show;
use Laravel\Cashier\Events\WebhookReceived;

/**
 * Defensive reconciliation listener. Cashier's WebhookController already upserts
 * the Subscription row from `customer.subscription.created`/`updated`; this
 * listener fires *after* that, dispatching a SyncOrganizationBillingJob so the
 * tier line items also catch up with the org's current fleet.
 *
 * Why this is needed: a customer may upgrade mid-trial after their fleet has
 * grown beyond the snapshot Stripe Checkout captured. Stripe's subscription is
 * built from the Checkout session's line items, but the org's *real* fleet at
 * webhook time is the source of truth. This listener keeps them aligned.
 */
class SyncBillingOnSubscriptionWebhook
{
    private const RELEVANT_EVENTS = [
        'customer.subscription.created',
        'customer.subscription.updated',
        // A canceled or unpaid subscription pauses the org at once.
        'customer.subscription.deleted',
    ];

    public function handle(WebhookReceived $event): void
    {
        // Any Stripe event for a customer (invoice.*, subscription.*) may change the cached invoice list / next date.
        // customer.* events carry the id as data.object.id instead.
        $object = $event->payload['data']['object'] ?? [];
        $c = $object['customer'] ?? (($object['object'] ?? null) === 'customer' ? ($object['id'] ?? null) : null);
        if (is_string($c) && $c !== '') {
            Show::forgetStripeCache($c);
        }

        $type = $event->payload['type'] ?? '';
        if (! in_array($type, self::RELEVANT_EVENTS, true)) {
            return;
        }

        $customerId = $event->payload['data']['object']['customer'] ?? null;
        if (! is_string($customerId) || $customerId === '') {
            return;
        }

        $organization = Organization::query()
            ->where('stripe_id', $customerId)
            ->first();

        if (! $organization) {
            return;
        }

        SyncOrganizationBillingJob::dispatch($organization->id, 'stripe_webhook');
    }
}
