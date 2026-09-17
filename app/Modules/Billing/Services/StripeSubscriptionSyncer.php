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
 * - A subscription without its tier price (pre-tier per-site, monthly or
 *   yearly) is swapped wholesale onto the tier's line items.
 * - Otherwise each line converges on its quantity: extra sites (`edge`), SSR
 *   sites (`edge_ssr`), extra seats (`team_seat`), load balancer endpoints and
 *   usage cents (`edge_usage`). Changing tier is the billing page's job.
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
    public function __construct(private ?StandardSubscriptionCreator $creator = null) {}

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

        if ($desired->planKey === 'enterprise') {
            return []; // hand-invoiced in Stripe
        }

        $tierPriceId = (string) (config('subscription.standard.stripe.tier_'.$desired->planKey) ?? '');
        if ($tierPriceId === '') {
            // Tier prices not provisioned yet: reconciling lines against a
            // tier state would strip per-site lines from a pre-tier sub.
            Log::warning('billing.stripe.tier_price_missing', ['organization_id' => $organization->id, 'tier' => $desired->planKey]);

            return [];
        }

        if (! $subscription->hasPrice($tierPriceId)) {
            // Pre-tier per-site subscription (possibly yearly): move it onto
            // the tier in one swap, invoiced now (owner: auto-move, 2026-09-16).
            $changes[] = $this->moveToTier($subscription, $desired);
        } else {
            foreach ([
                'edge' => $desired->extraSiteCount,
                'edge_ssr' => $desired->edgeSsrCount,
                'team_seat' => $desired->extraSeatCount,
                'edge_lb_endpoint' => $desired->edgeLbEndpointCount,
                'edge_usage' => $desired->usageLineCents(),
            ] as $product => $quantity) {
                $this->reconcileLine($subscription, $changes, $product, $quantity);
            }
        }

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
     * @return array<string, mixed>
     */
    private function moveToTier(Subscription $subscription, DesiredBillingState $desired): array
    {
        $items = ($this->creator ?? app(StandardSubscriptionCreator::class))->buildPriceList($desired);
        $from = $subscription->items->pluck('stripe_price')->all();

        try {
            $subscription->swapAndInvoice(collect($items)->mapWithKeys(
                static fn (array $item): array => [$item['price'] => ['quantity' => $item['quantity']]],
            )->all());
        } catch (Throwable $e) {
            Log::warning('billing.stripe.move_to_tier_failed', [
                'subscription_id' => $subscription->id,
                'tier' => $desired->planKey,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        return ['tier' => 'plan', 'action' => 'move', 'from' => $from, 'to' => $desired->planKey];
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
    private function reconcileLine(Subscription $subscription, array &$changes, string $product, int $desiredQty): void
    {
        $priceId = (string) (config('subscription.standard.stripe.'.$product) ?? '');
        if ($priceId === '') {
            return;
        }

        $change = $this->applyDelta($subscription, $priceId, $this->currentQuantity($subscription, $priceId), $desiredQty);
        if ($change !== null) {
            $changes[] = ['tier' => $product] + $change;
        }
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
}
