<?php

declare(strict_types=1);

namespace App\Modules\Billing\Listeners;

use App\Modules\Billing\Models\Subscription;
use App\Modules\Billing\Services\UsageInvoicer;
use Laravel\Cashier\Events\WebhookHandled;
use Laravel\Cashier\Events\WebhookReceived;

/**
 * Stripe webhooks that drive usage billing ({@see UsageInvoicer}). The Stripe
 * webhook endpoint must send `invoice.created` (not in Cashier's default list).
 *
 * - invoice.created: handled inline, so a failure returns 500 and Stripe
 *   retries while the renewal invoice is still a draft (about an hour).
 * - customer.subscription.deleted: queued, so a Stripe error can never stop
 *   Cashier from ending the local subscription.
 * - customer.subscription.created/updated: after Cashier has written the row,
 *   remember the current period.
 */
class BillUsageFromStripeWebhooks
{
    public function received(WebhookReceived $event): void
    {
        $object = (array) ($event->payload['data']['object'] ?? []);
        $type = $event->payload['type'] ?? '';
        if ($type === 'invoice.created') {
            app(UsageInvoicer::class)->onInvoiceCreated($object);
        } elseif ($type === 'customer.subscription.deleted') {
            dispatch(function () use ($object): void {
                app(UsageInvoicer::class)->onSubscriptionDeleted($object);
            });
        }
    }

    public function handled(WebhookHandled $event): void
    {
        if (! in_array($event->payload['type'] ?? '', ['customer.subscription.created', 'customer.subscription.updated'], true)) {
            return;
        }
        $object = (array) ($event->payload['data']['object'] ?? []);
        $subscription = Subscription::query()->where('stripe_id', $object['id'] ?? '')->first();
        if ($subscription !== null) {
            app(UsageInvoicer::class)->rememberPeriod($subscription, $object);
        }
    }
}
