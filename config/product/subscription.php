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
    | Pricing — plan tiers plus usage (docs/BILLING_AND_PLANS.md, ruling
    | r-zdescb7y05vp1bxx). Free / Pro / Team include a site count. Static and
    | hybrid sites past that count use the `edge` price. Every Worker-native
    | SSR site uses the `edge_ssr` price. Delivery past the plan is metered.
    | Stripe Checkout requires every line item to share a billing interval.
    |
    |   STRIPE_PRICE_STANDARD_EDGE=price_...               (extra static/hybrid site, monthly)
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
        // ceilings (App\Enums\QuotaSurface). Null means unlimited. Any paid
        // subscription also lifts the cap (ManagesOrganizationQuotas::quotaLimit()).
        // Starter matches a Laravel Cloud starter: unlimited apps, usage
        // credit instead of a site cap.
        //
        // Callers: ManagesOrganizationQuotas::quotaLimit, SubscriptionPlanResolver.
        'plans' => [
            'free' => ['label' => 'Free', 'price_cents' => 0, 'max_servers' => null, 'max_sites' => null, 'max_cloud_apps' => null, 'max_edge_apps' => null, 'max_functions' => null],
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
        /*
        | Plan tiers (ruling r-zdescb7y05vp1bxx, 2026-09-16). Monthly only.
        | Allowances are org-wide per month. Over them:
        |   sites          extra static/hybrid sites at edge_cents each
        |   SSR sites      every SSR site at edge_ssr_cents (never included)
        |   seats          extra_seat_cents each, or a hard cap when null
        |   build minutes  build_minute_overage_millicents (1/1000 ¢) each, or builds stop when null
        |   requests/egress  billed at dply.edge.usage_billing rates
        |   container compute  per second of vCPU / memory / disk after the
        |                  tier's compute_credit_cents
        | Free needs no card. The audit log is a tier feature.
        */
        'tiers' => [
            'free' => [
                'label' => 'Free', 'price_cents' => 0,
                // Unlimited apps, seats, and builds. Metered usage draws down
                // the credit; at spending_limit_cents new builds and container traffic pause.
                'sites' => null, 'ssr' => false, 'seats' => null, 'extra_seat_cents' => null,
                'build_minutes' => null, 'build_minute_overage_millicents' => null,
                'concurrent_builds' => 1, 'build_timeout_minutes' => 20,
                'requests' => 1_000_000, 'egress_gb' => 10,
                'custom_domains_per_site' => 10, 'addons' => false, 'audit_log' => false, 'containers' => true, 'compute_credit_cents' => 500, 'spending_limit_cents' => 500, 'build_minute_credit_millicents' => 1000, 'databases' => 1, 'queues' => 1, 'queue_concurrency' => 1, 'queue_batch_wait_seconds' => 5,
            ],
            'pro' => [
                'label' => 'Pro', 'price_cents' => 2000,
                'sites' => 10, 'ssr' => true, 'seats' => 3, 'extra_seat_cents' => null,
                'build_minutes' => 1_000, 'build_minute_overage_millicents' => 600,
                'concurrent_builds' => 2, 'build_timeout_minutes' => 45,
                'requests' => 10_000_000, 'egress_gb' => 500,
                'custom_domains_per_site' => 100, 'addons' => true, 'audit_log' => false, 'containers' => true, 'compute_credit_cents' => 500, 'databases' => 10, 'queues' => 10, 'queue_concurrency' => 10, 'queue_batch_wait_seconds' => 2,
            ],
            'team' => [
                'label' => 'Team', 'price_cents' => 4900,
                'sites' => 50, 'ssr' => true, 'seats' => 5, 'extra_seat_cents' => 500,
                'build_minutes' => 3_000, 'build_minute_overage_millicents' => 500,
                'concurrent_builds' => 5, 'build_timeout_minutes' => 60,
                'requests' => 50_000_000, 'egress_gb' => 2_000,
                'custom_domains_per_site' => 100, 'addons' => true, 'audit_log' => true, 'containers' => true, 'compute_credit_cents' => 2000, 'databases' => 50, 'queues' => 50, 'queue_concurrency' => 50, 'queue_batch_wait_seconds' => 1,
            ],
            // Sales-led: billed by hand in Stripe (subscription.enterprise), so
            // no fee or overage here — null allowances mean unlimited.
            'enterprise' => [
                'label' => 'Enterprise', 'price_cents' => 0,
                'sites' => null, 'ssr' => true, 'seats' => null, 'extra_seat_cents' => null,
                'build_minutes' => null, 'build_minute_overage_millicents' => null,
                'concurrent_builds' => 10, 'build_timeout_minutes' => 120,
                'requests' => null, 'egress_gb' => null,
                'custom_domains_per_site' => null, 'addons' => true, 'audit_log' => true, 'containers' => true, 'compute_credit_cents' => null, 'databases' => null, 'queues' => null, 'queue_concurrency' => null, 'queue_batch_wait_seconds' => 0,
            ],
        ],
        // Extra static/hybrid site past the plan's included count.
        // Edge static is genuinely flat-eligible: Cloudflare Workers Paid is
        // $5/mo per *account* (amortized across the whole fleet) and R2/Pages
        // egress is free, so the marginal cost of another static site is ~$0.
        'edge_cents' => 200,
        // Worker-native SSR Edge sites (dispatch namespace / Workers for
        // Platforms). Higher platform fee than static/hybrid.
        'edge_ssr_cents' => 700,
        // Retired. Kept so an existing Stripe price id still resolves; quantity is always 0.
        'edge_lb_endpoint_cents' => 800,
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
            'edge_lb_endpoint' => env('STRIPE_PRICE_STANDARD_EDGE_LB_ENDPOINT', ''),
            'tier_pro' => env('STRIPE_PRICE_TIER_PRO', ''),
            'tier_team' => env('STRIPE_PRICE_TIER_TEAM', ''),
            'team_seat' => env('STRIPE_PRICE_TEAM_SEAT', ''),
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
