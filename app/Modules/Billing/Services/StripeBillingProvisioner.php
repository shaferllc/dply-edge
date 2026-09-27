<?php

namespace App\Modules\Billing\Services;

use Stripe\Price;
use Stripe\Product;
use Stripe\StripeClient;

/**
 * Idempotently creates the Stripe products and prices that back billing
 * (docs/adr/pricing-model-2026-09.md). Looks objects up by
 * `metadata.dply_role` before creating; re-running is a no-op once all roles
 * are present, and rotates anything that's drifted (price amounts, product
 * names/descriptions, the parent product an existing price points at).
 *
 * One product per plan (Starter, Pro, Team), Team's extra seat, and
 * Enterprise (sales-led, no price). Usage is not a price: UsageInvoicer adds
 * it to each renewal invoice as plain invoice items.
 *
 * The retired per-site prices (extra site, SSR site, delivery usage, load
 * balancing) are not managed here any more; re-running leaves them exactly as
 * they are in Stripe, and the syncer removes them from subscriptions.
 */
class StripeBillingProvisioner
{
    public const ROLE_TIER_STARTER_PRODUCT = 'tier_starter_product';

    public const ROLE_TIER_STARTER_MONTHLY = 'tier_starter';

    public const ROLE_TIER_PRO_PRODUCT = 'tier_pro_product';

    public const ROLE_TIER_PRO_MONTHLY = 'tier_pro';

    public const ROLE_TIER_TEAM_PRODUCT = 'tier_team_product';

    public const ROLE_TIER_TEAM_MONTHLY = 'tier_team';

    public const ROLE_TEAM_SEAT_PRODUCT = 'team_seat_product';

    public const ROLE_TEAM_SEAT_MONTHLY = 'team_seat';

    public const ROLE_ENTERPRISE_PRODUCT = 'enterprise_product';

    public function __construct(private StripeClient $stripe) {}

    /**
     * @return array<string, string>
     */
    public function provision(): array
    {
        $result = [];

        $standardConfig = (array) config('subscription.standard', []);

        // Plans (monthly only) and Team's per-seat price.
        foreach ([
            'starter' => [self::ROLE_TIER_STARTER_PRODUCT, self::ROLE_TIER_STARTER_MONTHLY],
            'pro' => [self::ROLE_TIER_PRO_PRODUCT, self::ROLE_TIER_PRO_MONTHLY],
            'team' => [self::ROLE_TIER_TEAM_PRODUCT, self::ROLE_TIER_TEAM_MONTHLY],
        ] as $key => [$productRole, $priceRole]) {
            $tier = (array) ($standardConfig['tiers'][$key] ?? []);
            if ((int) ($tier['price_cents'] ?? 0) <= 0) {
                continue;
            }
            $product = $this->upsertProduct(
                name: 'dply '.$tier['label'],
                description: sprintf(
                    'dply %s plan — unlimited sites, %s, and $%s of usage included each month. Usage past that is billed monthly.',
                    $tier['label'],
                    trans_choice('{1} 1 seat|[2,*] :count seats', (int) $tier['seats'], ['count' => (int) $tier['seats']]),
                    number_format((int) ($tier['usage_credit_cents'] ?? 0) / 100, 0),
                ),
                role: $productRole,
            );
            $result[$productRole] = $product->id;
            $result[$priceRole] = $this->upsertRecurringPrice(
                productId: $product->id,
                amount: (int) $tier['price_cents'],
                interval: 'month',
                nickname: $tier['label'].' — Monthly',
                role: $priceRole,
            )->id;
        }

        $seatCents = (int) ($standardConfig['tiers']['team']['extra_seat_cents'] ?? 0);
        if ($seatCents > 0) {
            $seatProduct = $this->upsertProduct(
                name: 'dply Team seat',
                description: 'Additional member seat on the dply Team plan, beyond the seats it includes.',
                role: self::ROLE_TEAM_SEAT_PRODUCT,
            );
            $result[self::ROLE_TEAM_SEAT_PRODUCT] = $seatProduct->id;
            $result[self::ROLE_TEAM_SEAT_MONTHLY] = $this->upsertRecurringPrice(
                productId: $seatProduct->id,
                amount: $seatCents,
                interval: 'month',
                nickname: 'Team seat — Monthly',
                role: self::ROLE_TEAM_SEAT_MONTHLY,
            )->id;
        }

        $enterpriseProduct = $this->upsertProduct(
            name: 'dply Enterprise',
            description: 'dply for larger teams and procurement-led rollouts: volume usage pricing, SSO, audit log access, a custom MSA, dedicated support, and rollout planning. Pricing is negotiated per deal.',
            role: self::ROLE_ENTERPRISE_PRODUCT,
        );
        $result[self::ROLE_ENTERPRISE_PRODUCT] = $enterpriseProduct->id;

        return $result;
    }

    /**
     * Format a provisioning result map into copy-paste-ready .env lines.
     *
     * @param  array<string, mixed>  $result
     */
    public static function formatEnv(array $result): string
    {
        $static = [
            self::ROLE_TIER_STARTER_MONTHLY => 'STRIPE_PRICE_STARTER',
            self::ROLE_TIER_PRO_MONTHLY => 'STRIPE_PRICE_TIER_PRO',
            self::ROLE_TIER_TEAM_MONTHLY => 'STRIPE_PRICE_TIER_TEAM',
            self::ROLE_TEAM_SEAT_MONTHLY => 'STRIPE_PRICE_TEAM_SEAT',
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
