<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Subscription plans and Stripe price IDs.
    |--------------------------------------------------------------------------
    |
    | Set in .env:
    |   STRIPE_KEY=pk_...
    |   STRIPE_SECRET=sk_...
    |   STRIPE_WEBHOOK_SECRET=whsec_...
    |
    | Pricing — dply-edge sells one product (docs/BILLING_AND_PLANS.md): a flat
    | fee per live production Edge site plus metered Edge delivery usage. No
    | plan tiers. Stripe Checkout requires every line item to share a billing
    | interval, so each per-site price has a monthly and a yearly variant; the
    | yearly variant is `annual_discount_pct` off the monthly × 12.
    |
    |   STRIPE_PRICE_STANDARD_EDGE=price_...               (flat per static/hybrid Edge site, monthly)
    |   STRIPE_PRICE_STANDARD_EDGE_YEARLY=price_...
    |   STRIPE_PRICE_STANDARD_EDGE_SSR=price_...           (Worker-native SSR Edge site, monthly)
    |   STRIPE_PRICE_STANDARD_EDGE_SSR_YEARLY=price_...
    |   STRIPE_PRICE_STANDARD_EDGE_USAGE=price_...         (metered Edge delivery, per-cent unit)
    |
    |   STRIPE_PRICE_ENTERPRISE=price_...              (manual Stripe sub for sales-led deals)
    */

    'standard' => [
        'annual_discount_pct' => 20,
        // Edge sites younger than this are excluded from the bill. Absorbs the
        // "spin up + test + kill in five minutes" case so customers aren't
        // nickel-and-dimed for transient sites.
        'min_billable_age_days' => (int) env('SUBSCRIPTION_MIN_BILLABLE_AGE_DAYS', 1),
        // No paid plan tiers. The one record is `free`: its per-surface
        // ceilings (App\Enums\QuotaSurface) are the "no card to start"
        // allowance — `max_edge_apps` is the three free Edge sites. Any paid
        // subscription lifts the cap (ManagesOrganizationQuotas::quotaLimit()).
        'plans' => [
            'free' => ['label' => 'Free', 'price_cents' => 0, 'max_servers' => 1, 'max_sites' => 1, 'max_cloud_apps' => 1, 'max_edge_apps' => 3, 'max_functions' => 3],
        ],
        // Closed-beta envelope. An org with organizations.beta_joined_at set is a
        // beta participant: the platform fee is waived and these caps replace
        // the free allowance until the global cutover. `cutover_at` is the
        // global beta end date (Y-m-d or full datetime, null = no end set yet);
        // at cutover beta orgs fall to the free allowance.
        'beta' => [
            'sites' => (int) env('SUBSCRIPTION_BETA_SITES', 25),
            'cloud_apps' => (int) env('SUBSCRIPTION_BETA_CLOUD_APPS', 10),
            'edge_apps' => (int) env('SUBSCRIPTION_BETA_EDGE_APPS', 25),
            'functions' => (int) env('SUBSCRIPTION_BETA_FUNCTIONS', 25),
            'cutover_at' => env('SUBSCRIPTION_BETA_CUTOVER_AT'),
            'invite_expiry_days' => (int) env('SUBSCRIPTION_BETA_INVITE_EXPIRY_DAYS', 30),
        ],
        // Flat per-site fee for first-party dply Edge (static/SSG + hybrid).
        // Edge static is genuinely flat-eligible: Cloudflare Workers Paid is
        // $5/mo per *account* (amortized across the whole fleet) and R2/Pages
        // egress is free, so the marginal cost of another static site is ~$0.
        'edge_cents' => 200,
        // Worker-native SSR Edge sites (dispatch namespace / Workers for
        // Platforms). Higher platform fee than static/hybrid.
        'edge_ssr_cents' => 700,
        // Edge delivery usage is billed in 1-cent Stripe units (quantity = cents).
        'edge_usage_unit_cents' => 1,
        /*
        | Cost observatory — reference rates for billing analytics.
        */
        'observatory' => [
            'eur_to_usd_rate' => (float) env('SUBSCRIPTION_EUR_TO_USD_RATE', 1.08),

            /*
            | Reference FX rates, expressed as USD per 1 unit of the currency
            | (so EUR 1.08 means €1 = $1.08 — same direction as the legacy
            | eur_to_usd_rate above, which stays the source for EUR).
            |
            | These are STATIC display rates, refreshed by editing env — dply
            | does not quote live FX. They exist so a provider price billed in
            | euros reads in the currency you think in; every amount dply
            | actually charges is USD.
            */
            'currency_rates' => [
                'USD' => 1.0,
                'EUR' => (float) env('SUBSCRIPTION_EUR_TO_USD_RATE', 1.08),
                'GBP' => (float) env('SUBSCRIPTION_GBP_TO_USD_RATE', 1.27),
                'CAD' => (float) env('SUBSCRIPTION_CAD_TO_USD_RATE', 0.73),
                'AUD' => (float) env('SUBSCRIPTION_AUD_TO_USD_RATE', 0.66),
            ],

            /* Which of the above to show under the monthly cost estimate. */
            'display_currencies' => ['USD', 'EUR', 'GBP', 'CAD', 'AUD'],
        ],
        'stripe' => [
            'edge' => env('STRIPE_PRICE_STANDARD_EDGE', ''),
            'edge_yearly' => env('STRIPE_PRICE_STANDARD_EDGE_YEARLY', ''),
            'edge_ssr' => env('STRIPE_PRICE_STANDARD_EDGE_SSR', ''),
            'edge_ssr_yearly' => env('STRIPE_PRICE_STANDARD_EDGE_SSR_YEARLY', ''),
            'edge_usage' => env('STRIPE_PRICE_STANDARD_EDGE_USAGE', ''),
            // Prices of retired product lines (plan tiers, serverless, Cloud,
            // managed servers, Realtime, Lookout, Queue, server logs). Nothing
            // bills them any more; StripeSubscriptionSyncer removes any it
            // finds on a subscription so customers stop paying for them.
            'retired' => [
                env('STRIPE_PRICE_STANDARD_STARTER'), env('STRIPE_PRICE_STANDARD_STARTER_YEARLY'),
                env('STRIPE_PRICE_STANDARD_PRO'), env('STRIPE_PRICE_STANDARD_PRO_YEARLY'),
                env('STRIPE_PRICE_STANDARD_BUSINESS'), env('STRIPE_PRICE_STANDARD_BUSINESS_YEARLY'),
                env('STRIPE_PRICE_STANDARD_SERVERLESS'), env('STRIPE_PRICE_STANDARD_SERVERLESS_YEARLY'),
                env('STRIPE_PRICE_STANDARD_SERVERLESS_USAGE'),
                env('STRIPE_PRICE_STANDARD_CLOUD'), env('STRIPE_PRICE_STANDARD_CLOUD_YEARLY'),
                env('STRIPE_PRICE_STANDARD_CLOUD_USAGE'),
                env('STRIPE_PRICE_STANDARD_MANAGED_SERVER'),
                env('STRIPE_PRICE_STANDARD_REALTIME'), env('STRIPE_PRICE_STANDARD_REALTIME_YEARLY'),
                env('STRIPE_PRICE_STANDARD_REALTIME_STARTER'), env('STRIPE_PRICE_STANDARD_REALTIME_STARTER_YEARLY'),
                env('STRIPE_PRICE_STANDARD_REALTIME_GROWTH'), env('STRIPE_PRICE_STANDARD_REALTIME_GROWTH_YEARLY'),
                env('STRIPE_PRICE_STANDARD_REALTIME_SCALE'), env('STRIPE_PRICE_STANDARD_REALTIME_SCALE_YEARLY'),
                env('STRIPE_PRICE_STANDARD_LOOKOUT_STARTER'), env('STRIPE_PRICE_STANDARD_LOOKOUT_STARTER_YEARLY'),
                env('STRIPE_PRICE_STANDARD_LOOKOUT_GROWTH'), env('STRIPE_PRICE_STANDARD_LOOKOUT_GROWTH_YEARLY'),
                env('STRIPE_PRICE_STANDARD_LOOKOUT_SCALE'), env('STRIPE_PRICE_STANDARD_LOOKOUT_SCALE_YEARLY'),
                env('STRIPE_PRICE_STANDARD_QUEUE_STANDARD'), env('STRIPE_PRICE_STANDARD_QUEUE_STANDARD_YEARLY'),
                env('STRIPE_PRICE_STANDARD_QUEUE_PRO'), env('STRIPE_PRICE_STANDARD_QUEUE_PRO_YEARLY'),
                env('STRIPE_PRICE_STANDARD_SERVER_LOG_USAGE'),
            ],
        ],
    ],

    'enterprise' => [
        'stripe_price_id' => env('STRIPE_PRICE_ENTERPRISE', ''),
    ],
];
