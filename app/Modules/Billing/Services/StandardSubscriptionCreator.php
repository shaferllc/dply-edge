<?php

namespace App\Modules\Billing\Services;

use App\Models\Organization;
use App\Modules\Billing\Models\Subscription;
use InvalidArgumentException;
use RuntimeException;

/**
 * Provisions a fresh Standard Stripe subscription for an organization,
 * seeded with line items derived from its live Edge sites:
 *
 * - One line per Edge site kind in use (static/hybrid `edge`, Worker SSR
 *   `edge_ssr`).
 * - A metered **Edge usage** line (monthly only).
 *
 * Stripe Checkout requires every line item in a subscription to share a
 * billing interval, so each priced item has both a monthly and a yearly
 * Stripe Price. The creator picks the right set based on the chosen interval.
 */
class StandardSubscriptionCreator
{
    public const INTERVAL_MONTH = 'month';

    public const INTERVAL_YEAR = 'year';

    public function __construct(
        private OrganizationBillingStateComputer $computer,
    ) {}

    /**
     * @return array<int, array{price: string, quantity: int}>
     */
    public function buildPriceList(DesiredBillingState $desired, string $interval = self::INTERVAL_MONTH): array
    {
        $items = [];

        $edgeBaseCount = $desired->edgeBaseCount();
        if ($edgeBaseCount > 0) {
            $edgePriceId = $this->managedProductPriceIdForInterval('edge', $interval);
            if ($edgePriceId !== '') {
                $items[] = ['price' => $edgePriceId, 'quantity' => $edgeBaseCount];
            }
        }

        if ($desired->edgeSsrCount > 0) {
            $edgeSsrPriceId = $this->managedProductPriceIdForInterval('edge_ssr', $interval);
            if ($edgeSsrPriceId !== '') {
                $items[] = ['price' => $edgeSsrPriceId, 'quantity' => $desired->edgeSsrCount];
            }
        }

        if ($interval === self::INTERVAL_MONTH && $desired->edgeUsageSubtotalCents > 0) {
            $usagePriceId = $this->edgeUsagePriceId();
            if ($usagePriceId !== '') {
                $items[] = ['price' => $usagePriceId, 'quantity' => $desired->edgeUsageSubtotalCents];
            }
        }

        return $items;
    }

    public function edgePriceIdForInterval(string $interval): string
    {
        return $this->managedProductPriceIdForInterval('edge', $interval);
    }

    public function edgeSsrPriceIdForInterval(string $interval): string
    {
        return $this->managedProductPriceIdForInterval('edge_ssr', $interval);
    }

    public function edgeUsagePriceId(): string
    {
        return (string) (config('subscription.standard.stripe.edge_usage') ?? '');
    }

    private function managedProductPriceIdForInterval(string $product, string $interval): string
    {
        return (string) match ($interval) {
            self::INTERVAL_MONTH => config('subscription.standard.stripe.'.$product) ?? '',
            self::INTERVAL_YEAR => config('subscription.standard.stripe.'.$product.'_yearly') ?? '',
            default => throw new InvalidArgumentException("Unknown billing interval: {$interval}"),
        };
    }

    /**
     * Create the Cashier subscription. The org must not already have a 'default'
     * subscription — call {@see Organization::subscription('default')} to gate
     * upstream and decide whether to update vs create.
     *
     * @throws InvalidArgumentException when the org already has a subscription.
     */
    public function create(Organization $organization, string $paymentMethodId, string $interval = self::INTERVAL_MONTH): Subscription
    {
        if ($organization->subscription('default') !== null) {
            throw new InvalidArgumentException(
                "Organization {$organization->id} already has a 'default' subscription; update it instead of creating."
            );
        }

        $desired = $this->computer->compute($organization);
        $items = $this->buildPriceList($desired, $interval);

        if ($items === []) {
            // No live Edge sites owes nothing — Stripe rejects empty
            // subscriptions, so there is nothing to create.
            throw new RuntimeException(
                "Organization {$organization->id} has no billable units; no subscription to create."
            );
        }

        $builder = $organization->newSubscription('default');
        foreach ($items as $item) {
            $builder->price($item['price'], $item['quantity']);
        }

        /** @var Subscription $subscription */
        $subscription = $builder->create($paymentMethodId);

        return $subscription;
    }
}
