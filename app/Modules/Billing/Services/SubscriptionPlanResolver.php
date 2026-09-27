<?php

namespace App\Modules\Billing\Services;

use App\Enums\QuotaSurface;
use InvalidArgumentException;
use Laravel\Cashier\Subscription as CashierSubscription;

/**
 * The one place that answers "which plan does this Stripe price bill?",
 * "is this subscription yearly?" and "which prices are retired?", so every
 * caller agrees. Also reads the legacy `subscription.standard.plans.free`
 * quota record ({@see QuotaSurface}); the plans themselves are
 * `subscription.standard.tiers`.
 */
class SubscriptionPlanResolver
{
    /**
     * Self-serve plans, cheapest first (ruling r-2zxevg4sj675qn1m). Each has
     * a `tier_<key>` Stripe price. Enterprise is sales-led; `none` is no plan.
     */
    public const PAID_TIERS = ['starter', 'pro', 'team'];

    /**
     * Every price id that bills $tier: the current `STRIPE_PRICE_*` one, then
     * the grandfathered ones (`STRIPE_PRICE_*_LEGACY`, comma-separated).
     * After a plan-price change, list the archived id there or its
     * subscribers stop reading as that plan (docs/pricing-review.md §7).
     *
     * @return list<string>
     */
    public static function tierPriceIds(string $tier): array
    {
        $legacy = config('subscription.standard.stripe.legacy_tiers.'.$tier, []);

        return self::configuredPrices([
            config('subscription.standard.stripe.tier_'.$tier),
            ...array_map('trim', is_array($legacy) ? $legacy : explode(',', (string) $legacy)),
        ]);
    }

    /**
     * The plan a Stripe price bills: from config (current or legacy id),
     * else from the price's `metadata.dply_role` (`tier_<plan>`, which
     * StripeBillingProvisioner sets and an archived price keeps). Null when
     * neither says.
     *
     * @param  array<string, mixed>  $metadata
     */
    public static function tierOfPrice(string $priceId, array $metadata = []): ?string
    {
        foreach (self::PAID_TIERS as $tier) {
            if (in_array($priceId, self::tierPriceIds($tier), true)) {
                return $tier;
            }
        }
        $role = (string) ($metadata['dply_role'] ?? '');
        $tier = str_starts_with($role, 'tier_') ? substr($role, 5) : null;

        return in_array($tier, self::PAID_TIERS, true) ? $tier : null;
    }

    /**
     * Prices no config list knows: not a plan (current or legacy), seat,
     * Enterprise, pre-tier or retired price. A live subscription carrying
     * one is most likely on an archived plan price nobody listed as legacy.
     *
     * @param  list<string>  $priceIds
     * @return list<string>
     */
    public static function unrecognisedPrices(array $priceIds): array
    {
        $known = [
            ...array_merge(...array_map(self::tierPriceIds(...), self::PAID_TIERS)),
            ...self::configuredPrices([
                config('subscription.standard.stripe.team_seat'),
                config('subscription.standard.stripe.edge_usage'),
                config('subscription.enterprise.stripe_price_id'),
                // Pre-tier per-site prices: the syncer moves these onto a plan.
                config('subscription.standard.stripe.edge'),
                config('subscription.standard.stripe.edge_yearly'),
                config('subscription.standard.stripe.edge_ssr'),
                config('subscription.standard.stripe.edge_ssr_yearly'),
                config('subscription.standard.stripe.edge_lb_endpoint'),
            ]),
            ...self::retiredSiteFeePriceIds(),
            ...self::retiredPriceIds(),
        ];

        return array_values(array_diff($priceIds, $known));
    }

    /**
     * Configured yearly Edge site prices — static/hybrid and SSR. A
     * subscription carrying either is billed yearly.
     *
     * @return list<string>
     */
    public static function yearlyPriceIds(): array
    {
        return self::configuredPrices([
            config('subscription.standard.stripe.edge_yearly'),
            config('subscription.standard.stripe.edge_ssr_yearly'),
        ]);
    }

    public static function isYearly(CashierSubscription $subscription): bool
    {
        foreach (self::yearlyPriceIds() as $priceId) {
            if ($subscription->hasPrice($priceId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Prices of retired product lines (old plan tiers, serverless, Cloud,
     * managed servers, Realtime, Lookout, Queue, server logs). The syncer
     * removes them from subscriptions so customers stop paying for them.
     *
     * @return list<string>
     */
    public static function retiredPriceIds(): array
    {
        // A price listed as a plan (current or legacy) is never stripped.
        return array_values(array_diff(
            self::configuredPrices((array) config('subscription.standard.stripe.retired', [])),
            ...array_map(self::tierPriceIds(...), self::PAID_TIERS),
        ));
    }

    /**
     * Retired per-site fee prices (extra site, SSR site, load balancing),
     * removed from subscriptions without proration or credit.
     *
     * @return list<string>
     */
    public static function retiredSiteFeePriceIds(): array
    {
        return self::configuredPrices((array) config('subscription.standard.stripe.retired_site_fees', []));
    }

    /**
     * @param  array<mixed>  $ids
     * @return list<string>
     */
    private static function configuredPrices(array $ids): array
    {
        return array_values(array_filter(
            array_map(static fn (mixed $id): string => is_string($id) ? $id : '', $ids),
            static fn (string $id): bool => $id !== '',
        ));
    }

    /**
     * Resolve a plan by its key (e.g. 'free').
     *
     * @return array{key: string, label: string, price_cents: int, max_sites: ?int, max_edge_apps: ?int, max_functions: ?int}
     */
    public function resolveByKey(string $key): array
    {
        $plans = (array) config('subscription.standard.plans', []);
        if (! array_key_exists($key, $plans)) {
            throw new InvalidArgumentException("Unknown subscription plan: {$key}");
        }

        return $this->normalize($key, (array) $plans[$key]);
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array{key: string, label: string, price_cents: int, max_sites: ?int, max_edge_apps: ?int, max_functions: ?int}
     */
    private function normalize(string $key, array $plan): array
    {
        $ceiling = static function (mixed $value): ?int {
            return $value === null ? null : (int) $value;
        };

        return [
            'key' => $key,
            'label' => (string) ($plan['label'] ?? ucfirst($key)),
            'price_cents' => (int) ($plan['price_cents'] ?? 0),
            // Per-surface app ceilings ({@see \App\Enums\QuotaSurface}). A key
            // absent from config means unlimited for that surface.
            'max_sites' => $ceiling($plan['max_sites'] ?? null),
            'max_edge_apps' => $ceiling($plan['max_edge_apps'] ?? null),
            'max_functions' => $ceiling($plan['max_functions'] ?? null),
        ];
    }
}
