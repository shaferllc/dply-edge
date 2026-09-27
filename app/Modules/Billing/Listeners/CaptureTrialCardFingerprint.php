<?php

declare(strict_types=1);

namespace App\Modules\Billing\Listeners;

use App\Models\Organization;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Events\WebhookReceived;
use Throwable;

/**
 * One trial per card (ruling r-f17p5zgeh120cm5t). Checkout grants the trial
 * before the card is known, so once the subscription exists
 * (customer.subscription.created, one of Cashier's default webhook events,
 * or checkout.session.completed where the endpoint sends it) read its card
 * fingerprint: a card that already took a trial on
 * another org ends this trial now (it starts paid, like an org that is not
 * eligible); otherwise the card is recorded as having had its trial.
 */
final class CaptureTrialCardFingerprint
{
    public function handle(WebhookReceived $event): void
    {
        $type = $event->payload['type'] ?? '';
        if (! in_array($type, ['checkout.session.completed', 'customer.subscription.created'], true)) {
            return;
        }
        $object = (array) ($event->payload['data']['object'] ?? []);
        $customer = $object['customer'] ?? null;
        $subscriptionId = $type === 'checkout.session.completed' ? ($object['subscription'] ?? null) : ($object['id'] ?? null);
        if (! is_string($customer) || $customer === '' || ! is_string($subscriptionId) || $subscriptionId === '') {
            return;
        }
        $organization = Organization::query()->where('stripe_id', $customer)->first();
        if ($organization === null) {
            return;
        }

        try {
            $stripe = Cashier::stripe();
            $subscription = $stripe->subscriptions->retrieve($subscriptionId, ['expand' => ['default_payment_method']]);
            $fingerprint = $subscription->default_payment_method->card->fingerprint ?? null;
            if (is_string($fingerprint) && $fingerprint !== '' && self::refuseTrial($organization, $fingerprint, $subscription->status === 'trialing')) {
                $stripe->subscriptions->update($subscriptionId, ['trial_end' => 'now']);
                audit_log($organization, null, 'billing.trial_refused_card_reused');
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** True when this trialing org's card already had a trial on another org; records the card otherwise. */
    public static function refuseTrial(Organization $organization, string $fingerprint, bool $trialing): bool
    {
        if (! $trialing) {
            return false;
        }
        if (Organization::query()->whereKeyNot($organization->getKey())->where('trial_card_fingerprint', $fingerprint)->exists()) {
            return true;
        }
        $organization->forceFill(['trial_card_fingerprint' => $fingerprint])->save();

        return false;
    }
}
