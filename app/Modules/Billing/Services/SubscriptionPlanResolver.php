<?php

namespace App\Modules\Billing\Services;

use App\Enums\QuotaSurface;
use InvalidArgumentException;
use Laravel\Cashier\Subscription as CashierSubscription;

/**
 * Reads a plan record from config `subscription.standard.plans`. dply-edge has
 * no paid plan tiers — the only record is `free`, whose per-surface ceilings
 * are the "no card to start" allowance ({@see QuotaSurface}).
 *
 * Also the one place that answers "is this subscription yearly?" and "which
 * prices are retired?", so every caller agrees.
 */
class SubscriptionPlanResolver
{
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
        return self::configuredPrices((array) config('subscription.standard.stripe.retired', []));
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
