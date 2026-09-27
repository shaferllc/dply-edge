<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Models\Organization;

/**
 * The Stripe Checkout URL for a paid tier, line items seeded from what the
 * org runs today on that tier. The trial (ruling r-f17p5zgeh120cm5t): a
 * first-time org gets trial.days with a card on file; an org still on its
 * card-less trial keeps the days it has left. Either way Checkout takes the
 * card, and a trial that ends without one cancels instead of going past due.
 *
 * Used by the billing page and by the create form (a no-plan org that hits
 * Deploy goes straight here and comes back to its draft).
 */
class PlanCheckout
{
    /** Null when the tier has no prices configured. */
    public function url(Organization $organization, string $tier, string $successUrl, string $cancelUrl): ?string
    {
        $items = app(StandardSubscriptionCreator::class)->buildPriceList(
            app(OrganizationBillingStateComputer::class)->computeForTier($organization, $tier),
        );
        if ($items === []) {
            return null;
        }

        $builder = $organization->newSubscription('default');
        foreach ($items as $item) {
            $builder->price($item['price'], $item['quantity']);
        }

        $options = ['success_url' => $successUrl, 'cancel_url' => $cancelUrl];
        $trialUntil = $organization->onGenericTrial()
            ? $organization->trial_ends_at
            : ($organization->eligibleForTrial() ? now()->addDays((int) config('subscription.standard.trial.days', 5)) : null);
        if ($trialUntil !== null) {
            $builder->trialUntil($trialUntil);
            $options['payment_method_collection'] = 'always';
            $options['subscription_data'] = ['trial_settings' => ['end_behavior' => ['missing_payment_method' => 'cancel']]];
        }

        return (string) $builder->checkout($options, [])->asStripeCheckoutSession()->url;
    }
}
