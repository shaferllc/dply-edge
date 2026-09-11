<?php

namespace App\Modules\Billing\Services;

use Stripe\Price;
use Stripe\Product;
use Stripe\StripeClient;

/**
 * Idempotently creates the Stripe products and prices that back Edge billing
 * (docs/BILLING_AND_PLANS.md). Looks objects up by `metadata.dply_role` before
 * creating; re-running is a no-op once all roles are present, and rotates
 * anything that's drifted (price amounts, product names/descriptions, the
 * parent product an existing price points at).
 *
 * Each Stripe Checkout line item displays its Product's name, so to keep the
 * invoice readable we use *separate Products* for each kind of line item:
 *
 *   - `dply Edge site` / `dply Edge SSR site` — the per-site platform fees
 *   - `dply Edge delivery usage` — metered usage (per-cent units)
 *   - `dply Enterprise` — sales-led
 *
 * Products and prices for retired product lines are not managed here any
 * more; re-running this command leaves them exactly as they are in Stripe.
 */
class StripeBillingProvisioner
{
    public const ROLE_EDGE_PRODUCT = 'standard_edge_product';

    public const ROLE_EDGE_MONTHLY = 'standard_edge';

    public const ROLE_EDGE_YEARLY = 'standard_edge_yearly';

    public const ROLE_EDGE_SSR_PRODUCT = 'standard_edge_ssr_product';

    public const ROLE_EDGE_SSR_MONTHLY = 'standard_edge_ssr';

    public const ROLE_EDGE_SSR_YEARLY = 'standard_edge_ssr_yearly';

    public const ROLE_EDGE_USAGE_PRODUCT = 'standard_edge_usage_product';

    public const ROLE_EDGE_USAGE_MONTHLY = 'standard_edge_usage';

    public const ROLE_ENTERPRISE_PRODUCT = 'enterprise_product';

    public function __construct(private StripeClient $stripe) {}

    /**
     * @return array<string, string>
     */
    public function provision(): array
    {
        $result = [];

        $standardConfig = (array) config('subscription.standard', []);
        $annualPct = (int) ($standardConfig['annual_discount_pct'] ?? 20);

        $edgeCents = (int) ($standardConfig['edge_cents'] ?? 200);
        if ($edgeCents > 0) {
            $edgeProduct = $this->upsertProduct(
                name: 'dply Edge site',
                description: 'Per-site fee for dply Edge — static, SSG, and hybrid sites on dply-owned CDN infrastructure. Covers builds, deploys, previews, and global delivery. Billed per live production site. Worker-native SSR uses a separate line.',
                role: self::ROLE_EDGE_PRODUCT,
            );
            $result[self::ROLE_EDGE_PRODUCT] = $edgeProduct->id;

            $result[self::ROLE_EDGE_MONTHLY] = $this->upsertRecurringPrice(
                productId: $edgeProduct->id,
                amount: $edgeCents,
                interval: 'month',
                nickname: 'Edge site — Monthly',
                role: self::ROLE_EDGE_MONTHLY,
            )->id;

            $result[self::ROLE_EDGE_YEARLY] = $this->upsertRecurringPrice(
                productId: $edgeProduct->id,
                amount: $this->annualAmount($edgeCents, $annualPct),
                interval: 'year',
                nickname: 'Edge site — Yearly',
                role: self::ROLE_EDGE_YEARLY,
            )->id;
        }

        $edgeSsrCents = (int) ($standardConfig['edge_ssr_cents'] ?? 700);
        if ($edgeSsrCents > 0) {
            $edgeSsrProduct = $this->upsertProduct(
                name: 'dply Edge SSR site',
                description: 'Per-site fee for Worker-native SSR on dply Edge. Covers SSR dispatch, builds, deploys, and global delivery. Billed per live production SSR site.',
                role: self::ROLE_EDGE_SSR_PRODUCT,
            );
            $result[self::ROLE_EDGE_SSR_PRODUCT] = $edgeSsrProduct->id;

            $result[self::ROLE_EDGE_SSR_MONTHLY] = $this->upsertRecurringPrice(
                productId: $edgeSsrProduct->id,
                amount: $edgeSsrCents,
                interval: 'month',
                nickname: 'Edge SSR site — Monthly',
                role: self::ROLE_EDGE_SSR_MONTHLY,
            )->id;

            $result[self::ROLE_EDGE_SSR_YEARLY] = $this->upsertRecurringPrice(
                productId: $edgeSsrProduct->id,
                amount: $this->annualAmount($edgeSsrCents, $annualPct),
                interval: 'year',
                nickname: 'Edge SSR site — Yearly',
                role: self::ROLE_EDGE_SSR_YEARLY,
            )->id;
        }

        $edgeUsageUnitCents = (int) ($standardConfig['edge_usage_unit_cents'] ?? 1);
        if ($edgeUsageUnitCents > 0) {
            $edgeUsageProduct = $this->upsertProduct(
                name: 'dply Edge delivery usage',
                description: 'Metered Edge CDN delivery — HTTP requests, bandwidth, and R2 storage beyond per-site included allowances. Billed monthly in pass-through units.',
                role: self::ROLE_EDGE_USAGE_PRODUCT,
            );
            $result[self::ROLE_EDGE_USAGE_PRODUCT] = $edgeUsageProduct->id;

            $result[self::ROLE_EDGE_USAGE_MONTHLY] = $this->upsertRecurringPrice(
                productId: $edgeUsageProduct->id,
                amount: $edgeUsageUnitCents,
                interval: 'month',
                nickname: 'Edge delivery usage — Monthly (per cent)',
                role: self::ROLE_EDGE_USAGE_MONTHLY,
            )->id;
        }

        $enterpriseProduct = $this->upsertProduct(
            name: 'dply Enterprise',
            description: 'dply for larger fleets and procurement-led rollouts. Includes everything in Standard, plus volume pricing on per-server fees, SSO, audit log access, a custom MSA, dedicated support, and rollout planning. Pricing is negotiated per deal.',
            role: self::ROLE_ENTERPRISE_PRODUCT,
        );
        $result[self::ROLE_ENTERPRISE_PRODUCT] = $enterpriseProduct->id;

        return $result;
    }

    /**
     * Format a provisioning result map into copy-paste-ready .env lines.
     *
     * @param  array<string, mixed> $result
     */
    public static function formatEnv(array $result): string
    {
        $static = [
            self::ROLE_EDGE_MONTHLY => 'STRIPE_PRICE_STANDARD_EDGE',
            self::ROLE_EDGE_YEARLY => 'STRIPE_PRICE_STANDARD_EDGE_YEARLY',
            self::ROLE_EDGE_SSR_MONTHLY => 'STRIPE_PRICE_STANDARD_EDGE_SSR',
            self::ROLE_EDGE_SSR_YEARLY => 'STRIPE_PRICE_STANDARD_EDGE_SSR_YEARLY',
            self::ROLE_EDGE_USAGE_MONTHLY => 'STRIPE_PRICE_STANDARD_EDGE_USAGE',
        ];

        $lines = [];
        foreach ($result as $role => $id) {
            // Product roles are skipped — operators don't need product IDs at runtime.
            if (isset($static[(string) $role])) {
                $lines[] = $static[(string) $role].'='.$id;
            }
        }

        return implode("\n", $lines);
    }

    private function annualAmount(int $monthlyCents, int $annualDiscountPct): int
    {
        return (int) round($monthlyCents * 12 * (100 - $annualDiscountPct) / 100);
    }

    /**
     * Look up the product by metadata role, create if missing, and patch the
     * stored name/description when they drift from what the code declares.
     * Marketing copy can change without spinning up a new Stripe product.
     */
    private function upsertProduct(string $name, string $description, string $role): Product
    {
        $existing = $this->stripe->products->search([
            'query' => sprintf('metadata[\'dply_role\']:\'%s\'', $role),
            'limit' => 1,
        ]);

        if (! empty($existing->data)) {
            $product = $existing->data[0];

            $updates = [];
            if (($product->name ?? '') !== $name) {
                $updates['name'] = $name;
            }
            if (($product->description ?? '') !== $description) {
                $updates['description'] = $description;
            }

            if ($updates !== []) {
                $product = $this->stripe->products->update($product->id, $updates);
            }

            return $product;
        }

        return $this->stripe->products->create([
            'name' => $name,
            'description' => $description,
            'metadata' => ['dply_role' => $role],
        ]);
    }

    /**
     * Look up the price by metadata role. Stripe Prices are *immutable* — if
     * the config amount no longer matches OR the price points at the wrong
     * parent product, the only correct move is to archive the old price and
     * create a new one. Existing subscriptions stay on the archived price
     * (Stripe allows that); future subscriptions land on the replacement.
     */
    private function upsertRecurringPrice(
        string $productId,
        int $amount,
        string $interval,
        string $nickname,
        string $role,
    ): Price {
        $existing = $this->stripe->prices->search([
            'query' => sprintf('metadata[\'dply_role\']:\'%s\' AND active:\'true\'', $role),
            'limit' => 1,
        ]);

        if (! empty($existing->data)) {
            $current = $existing->data[0];

            $sameAmount = (int) ($current->unit_amount ?? 0) === $amount;
            $sameInterval = ($current->recurring->interval ?? null) === $interval;
            $sameProduct = (string) ($current->product ?? '') === $productId;

            if ($sameAmount && $sameInterval && $sameProduct) {
                return $current;
            }

            // Drift detected — archive the stale price, fall through to
            // create a replacement under the right product at the right
            // amount.
            $this->stripe->prices->update($current->id, ['active' => false]);
        }

        return $this->stripe->prices->create([
            'product' => $productId,
            'unit_amount' => $amount,
            'currency' => 'usd',
            'recurring' => ['interval' => $interval],
            'nickname' => $nickname,
            'metadata' => ['dply_role' => $role],
        ]);
    }
}
