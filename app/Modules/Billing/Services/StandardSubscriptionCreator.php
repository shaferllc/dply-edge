<?php

namespace App\Modules\Billing\Services;

use App\Models\Organization;
use App\Modules\Billing\Models\Subscription;
use InvalidArgumentException;
use RuntimeException;

/**
 * Builds the Stripe line items for a plan (plan fee, extra seats) and
 * provisions a fresh subscription from them. Plans are monthly only. Usage is
 * not a line: it is invoiced in arrears, less the plan's included credit
 * (UsageInvoicer).
 */
class StandardSubscriptionCreator
{
    public const INTERVAL_MONTH = 'month';

    public const INTERVAL_YEAR = 'year';

    public function __construct(
        private OrganizationBillingStateComputer $computer,
    ) {}

    /**
     * Stripe line items for a tier state (monthly only — tiers have no yearly
     * prices). Free and Enterprise produce no lines.
     *
     * @return array<int, array{price: string, quantity: int}>
     */
    public function buildPriceList(DesiredBillingState $desired, string $interval = self::INTERVAL_MONTH): array
    {
        if ($interval !== self::INTERVAL_MONTH) {
            throw new InvalidArgumentException('Plan tiers are billed monthly only.');
        }

        $tierPriceId = (string) (config('subscription.standard.stripe.tier_'.$desired->planKey) ?? '');
        if (! in_array($desired->planKey, SubscriptionPlanResolver::PAID_TIERS, true) || $tierPriceId === '') {
            return [];
        }

        $items = [['price' => $tierPriceId, 'quantity' => 1]];
        $seatPriceId = (string) (config('subscription.standard.stripe.team_seat') ?? '');
        if ($desired->extraSeatCount > 0 && $seatPriceId !== '') {
            $items[] = ['price' => $seatPriceId, 'quantity' => $desired->extraSeatCount];
        }

        return $items;
    }

    /**
     * Create the Cashier subscription. The org must not already have a 'default'
     * subscription — call {@see Organization::subscription('default')} to gate
     * upstream and decide whether to update vs create.
     *
     * @throws InvalidArgumentException when the org already has a subscription.
     */
    public function create(Organization $organization, string $paymentMethodId, string $tier = 'pro'): Subscription
    {
        if ($organization->subscription('default') !== null) {
            throw new InvalidArgumentException(
                "Organization {$organization->id} already has a 'default' subscription; update it instead of creating."
            );
        }

        $items = $this->buildPriceList($this->computer->computeForTier($organization, $tier));

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
