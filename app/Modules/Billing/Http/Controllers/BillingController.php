<?php

namespace App\Modules\Billing\Http\Controllers;

use App\Http\Controllers\Controller;

use App\Models\Organization;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class BillingController extends Controller
{
    /**
     * Show the organization billing page (subscription status, payment method, subscribe/portal links).
     */
    public function show(Organization $organization): View
    {
        $this->authorize('update', $organization);

        $subscription = $organization->subscription('default');
        $status = $subscription !== null ? (string) data_get($subscription->getAttributes(), 'stripe_status') : null;
        $planName = null;
        if ($subscription !== null) {
            $firstItem = $subscription->items->first();
            $priceId = (string) (data_get($subscription->getAttributes(), 'stripe_price')
                ?: data_get($firstItem?->getAttributes(), 'stripe_price'));
            if ($priceId) {
                $plans = config('subscription.plans', []);
                foreach ($plans as $plan) {
                    if (($plan['price_id'] ?? '') === $priceId) {
                        $planName = $plan['name'] ?? $priceId;
                        break;
                    }
                }
                $planName = $planName ?? $priceId;
            }
        }

        $paymentMethod = $organization->defaultPaymentMethod();
        $paymentSummary = 'No payment method';
        if ($organization->pm_last_four) {
            $paymentSummary = '•••• '.$organization->pm_last_four;
        } elseif ($paymentMethod && method_exists($paymentMethod, 'asStripePaymentMethod')) {
            $pm = $paymentMethod->asStripePaymentMethod();
            if (isset($pm->card->last4)) {
                $paymentSummary = '•••• '.$pm->card->last4;
            }
        }

        /** @var array<string, array<string, mixed>> $planDefinitions */
        $planDefinitions = config('subscription.plans', []);
        $plans = collect($planDefinitions)->filter(fn (array $p): bool => ! empty($p['price_id']));
        $canManageBilling = $organization->hasStripeId();

        return view('billing.show', [
            'organization' => $organization,
            'subscription' => $subscription,
            'status' => $status,
            'planName' => $planName,
            'paymentSummary' => $paymentSummary,
            'plans' => $plans,
            'canManageBilling' => $canManageBilling,
        ]);
    }

    /**
     * Start a Stripe Checkout session for the given plan.
     */
    public function checkout(Request $request, Organization $organization): RedirectResponse
    {
        $this->authorize('update', $organization);

        $validated = $request->validate([
            'plan' => 'required|string',
        ]);

        $plans = config('subscription.plans', []);
        $plan = $plans[$validated['plan']] ?? null;
        if (! $plan || empty($plan['price_id'])) {
            return redirect()->route('subscription.show', $organization)
                ->with('error', 'Invalid or missing plan.');
        }

        audit_log($organization, $request->user(), 'billing.checkout_started', null, null, ['plan' => $validated['plan']]);

        $subscriptionUrl = route('subscription.show', $organization);

        $checkout = $organization->newSubscription('default', $plan['price_id'])
            ->checkout([
                'success_url' => $subscriptionUrl.'?checkout=success',
                'cancel_url' => $subscriptionUrl.'?checkout=cancelled',
            ], []);

        return $checkout->redirect();
    }

    /**
     * Redirect to Stripe Customer Billing Portal.
     */
    public function portal(Request $request, Organization $organization): RedirectResponse
    {
        $this->authorize('update', $organization);

        if (! $organization->hasStripeId()) {
            return redirect()->route('subscription.show', $organization)
                ->with('error', 'No billing account yet. Add a card first.');
        }

        audit_log($organization, $request->user(), 'billing.portal_accessed');

        $returnUrl = route('subscription.show', $organization);

        return $organization->redirectToBillingPortal($returnUrl);
    }
}
