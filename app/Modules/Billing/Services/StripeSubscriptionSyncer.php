<?php

namespace App\Modules\Billing\Services;

use App\Models\Organization;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Billing\Models\SubscriptionItem;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reconciles an organization's Stripe subscription line items against a
 * {@see DesiredBillingState}:
 *
 * - One line per Edge site kind (static/hybrid `edge`, Worker SSR `edge_ssr`),
 *   quantity = live site count.
 * - A metered **Edge usage** line (monthly only).
 *
 * - Items on a **retired** price (old plan tiers, serverless, Cloud, … — see
 *   `subscription.standard.stripe.retired`) are removed, so customers stop
 *   paying for products that no longer exist.
 *
 * Prices that are neither Edge nor retired are left alone.
 *
 * Safe to invoke when Stripe is not configured — missing price IDs cause the
 * corresponding line to be skipped silently. Safe to invoke against an org
 * without a subscription — returns immediately so free orgs (no Stripe sub
 * yet) flow through without special-casing.
 */
class StripeSubscriptionSyncer
{
    /**
     * @return list<array<string, mixed>>
     */
    public function reconcile(Organization $organization, DesiredBillingState $desired): array
    {
        // Cashier is bound to this module's Subscription model in
        // AppServiceProvider, but Billable::subscription() is only typed as the
        // base class — assert the binding rather than assume it.
        $subscription = $organization->subscription('default');
        if (! $subscription instanceof Subscription || ! $subscription->valid()) {
            return [];
        }

        $changes = [];

        // Edge — flat per live site (static/hybrid vs Worker-native SSR).
        $this->reconcileManagedProductLine($subscription, $changes, 'edge', $desired->edgeBaseCount());
        $this->reconcileManagedProductLine($subscription, $changes, 'edge_ssr', $desired->edgeSsrCount);
        $this->reconcileEdgeUsageLine($subscription, $desired, $changes);
        $this->reconcileLoadBalancerLine($subscription, $desired, $changes);

        foreach ($this->retiredPricesToRemove($subscription) as $priceId) {
            $change = $this->applyDelta($subscription, $priceId, $this->currentQuantity($subscription, $priceId), 0);
            if ($change !== null) {
                $changes[] = ['tier' => 'retired'] + $change;
            }
        }

        if ($changes !== []) {
            Log::info('billing.stripe.subscription_synced', [
                'organization_id' => $organization->id,
                'changes' => $changes,
                'monthly_total_cents' => $desired->monthlyTotalCents,
            ]);
        }

        return $changes;
    }

    /**
     * @return array{action: string, from: int|null, to: int}|null
     */
    private function applyDelta(Subscription $subscription, string $priceId, ?int $currentQty, int $desiredQty): ?array
    {
        try {
            // `alwaysInvoice` => Stripe immediately bills the prorated amount
            // for the change rather than accumulating it for the next renewal.
            // Customers see "you added a site, here's the prorated charge"
            // same-day, which is especially important for yearly subscriptions
            // where renewals are far apart.
            if ($currentQty === null && $desiredQty > 0) {
                $subscription->alwaysInvoice()->addPrice($priceId, $desiredQty);

                return ['action' => 'add', 'from' => null, 'to' => $desiredQty];
            }

            if ($currentQty !== null && $desiredQty === 0) {
                $subscription->alwaysInvoice()->removePrice($priceId);

                return ['action' => 'remove', 'from' => $currentQty, 'to' => 0];
            }

            if ($currentQty !== null && $currentQty !== $desiredQty) {
                $subscription->alwaysInvoice()->updateQuantity($desiredQty, $priceId);

                return ['action' => 'update', 'from' => $currentQty, 'to' => $desiredQty];
            }
        } catch (Throwable $e) {
            Log::warning('billing.stripe.line_item_sync_failed', [
                'price_id' => $priceId,
                'error' => $e->getMessage(),
                'from' => $currentQty,
                'to' => $desiredQty,
            ]);
            throw $e;
        }

        return null;
    }

    private function currentQuantity(Subscription $subscription, string $priceId): ?int
    {
        if (! $subscription->hasPrice($priceId)) {
            return null;
        }

        $item = $subscription->items->firstWhere('stripe_price', $priceId);

        return $item instanceof SubscriptionItem ? (int) $item->quantity : null;
    }

    /**
     * @param  list<array<string, mixed>>  $changes
     */
    private function reconcileManagedProductLine(
        Subscription $subscription,
        array &$changes,
        string $product,
        int $desiredQty,
    ): void {
        $priceId = $this->managedProductPriceIdForSubscription($subscription, $product);
        if ($priceId === '') {
            return;
        }

        $current = $this->currentQuantity($subscription, $priceId);
        $change = $this->applyDelta($subscription, $priceId, $current, $desiredQty);
        if ($change !== null) {
            $changes[] = ['tier' => $product] + $change;
        }
    }

    /**
     * @param  list<array<string, mixed>>  $changes
     */
    private function reconcileEdgeUsageLine(
        Subscription $subscription,
        DesiredBillingState $desired,
        array &$changes,
    ): void {
        if ($this->isYearly($subscription)) {
            return;
        }

        $priceId = (string) (config('subscription.standard.stripe.edge_usage') ?? '');
        if ($priceId === '') {
            return;
        }

        $desiredQty = max(0, $desired->edgeUsageSubtotalCents);
        $current = $this->currentQuantity($subscription, $priceId);
        $change = $this->applyDelta($subscription, $priceId, $current, $desiredQty);
        if ($change !== null) {
            $changes[] = ['tier' => 'edge_usage'] + $change;
        }
    }

    /**
     * Load balancing endpoints — monthly only; yearly subs can't enable it.
     *
     * @param  list<array<string, mixed>>  $changes
     */
    private function reconcileLoadBalancerLine(
        Subscription $subscription,
        DesiredBillingState $desired,
        array &$changes,
    ): void {
        $priceId = (string) (config('subscription.standard.stripe.edge_lb_endpoint') ?? '');
        if ($priceId === '' || $this->isYearly($subscription)) {
            return;
        }

        $change = $this->applyDelta($subscription, $priceId, $this->currentQuantity($subscription, $priceId), $desired->edgeLbEndpointCount);
        if ($change !== null) {
            $changes[] = ['tier' => 'edge_lb_endpoint'] + $change;
        }
    }

    private function managedProductPriceIdForSubscription(Subscription $subscription, string $product): string
    {
        $key = $this->isYearly($subscription) ? $product.'_yearly' : $product;

        return (string) (config('subscription.standard.stripe.'.$key) ?? '');
    }

    /**
     * Retired prices still on the subscription, which the sync removes.
     *
     * Empty when retired prices are all the subscription holds: Stripe will
     * not remove a subscription's last item, so that one needs a person to
     * cancel it — logged rather than failing the sync.
     *
     * @return list<string>
     */
    public function retiredPricesToRemove(Subscription $subscription): array
    {
        $present = array_values(array_filter(
            SubscriptionPlanResolver::retiredPriceIds(),
            static fn (string $priceId): bool => $subscription->hasPrice($priceId),
        ));

        if ($present !== [] && count($present) >= $subscription->items()->count()) {
            Log::warning('billing.stripe.only_retired_prices', [
                'subscription_id' => $subscription->id,
                'prices' => $present,
            ]);

            return [];
        }

        return $present;
    }

    private function isYearly(Subscription $subscription): bool
    {
        return SubscriptionPlanResolver::isYearly($subscription);
    }
}
