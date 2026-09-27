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
    | Pricing — three plans + included usage credit + one margin
    | (docs/adr/pricing-model-2026-09.md, ruling r-2zxevg4sj675qn1m). Each plan
    | is one Stripe price; Team adds a per-seat price. Usage is billed in
    | arrears per Stripe period at provider cost + dply.edge.usage_billing.
    | margin_percent (App\Modules\Billing\Support\UsagePrice), less the
    | plan's usage_credit_cents. Sites are unlimited (fair use). Monthly only.
    |
    |   STRIPE_PRICE_STARTER=price_...     (Starter plan, monthly)
    |   STRIPE_PRICE_TIER_PRO=price_...    (Pro plan, monthly)
    |   STRIPE_PRICE_TIER_TEAM=price_...   (Team plan, monthly)
    |   STRIPE_PRICE_TEAM_SEAT=price_...   (Team extra seat, monthly)
    |   STRIPE_PRICE_ENTERPRISE=price_...  (manual Stripe sub for sales-led deals)
    */

    'standard' => [
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
        | No Free plan (ruling r-f17p5zgeh120cm5t, 2026-09-26). A new org gets
        | a trial of `tier` for `days`, card required (Stripe trial; it bills
        | on the next day unless canceled). While on trial, usage past
        | spending_limit_cents pauses builds and container traffic. An org
        | with no plan (trial over, unpaid, canceled) is paused; its data is
        | deleted keep_data_days later, only once purge_enabled is on.
        */
        'trial' => [
            'days' => 5,
            'tier' => 'pro',
            'spending_limit_cents' => 500,
            'card_required' => true,
            'keep_data_days' => 7,
            'purge_enabled' => (bool) env('DPLY_BILLING_PURGE_ENABLED', false),
        ],
        /*
        | Plans (ruling r-2zxevg4sj675qn1m). Prices, seats and the included
        | usage credit are per-plan values the owner can change here:
        |   seats / extra_seat_cents   extra seats bill on Team; null = hard cap
        |   usage_credit_cents         usage at customer price up to this is
        |                              covered each period (min(credit, usage))
        |   fair_use_apps              hidden anti-abuse cap on non-preview apps
        |                              (ruling r-bc0k0cta8e50x8vr); null = none
        | The rest are non-price limits, enforced where each thing happens.
        | custom_domains is org-wide (the only domain cap).
        */
        'tiers' => [
            // No plan: the trial ended or the subscription lapsed. Nothing
            // runs (EnforceOrganizationBillingCommand pauses the org), so every
            // allowance is zero. Not offered; never shown as a plan.
            'none' => [
                'label' => 'No plan', 'price_cents' => 0, 'seats' => null, 'extra_seat_cents' => null,
                'usage_credit_cents' => 0, 'fair_use_apps' => 0, 'spending_limit_cents' => 0,
                'ssr' => false, 'containers' => false, 'addons' => false, 'audit_log' => false,
                'concurrent_builds' => 0, 'build_timeout_minutes' => 0, 'custom_domains' => 0,
                'databases' => 0, 'queues' => 0, 'queue_concurrency' => 1, 'queue_batch_wait_seconds' => 5,
                'app_instances' => 1, 'worker_instances' => 0, 'worker_autoscale' => false, 'worker_groups' => 0,
                'realtime_max_connections' => 0,
            ],
            'starter' => [
                'label' => 'Starter', 'price_cents' => 500, 'seats' => 1, 'extra_seat_cents' => null,
                'usage_credit_cents' => 500, 'fair_use_apps' => 25,
                'ssr' => true, 'containers' => true, 'addons' => true, 'audit_log' => false,
                'concurrent_builds' => 1, 'build_timeout_minutes' => 20, 'custom_domains' => 3,
                'databases' => 2, 'queues' => 2, 'queue_concurrency' => 5, 'queue_batch_wait_seconds' => 2,
                // Container apps: one instance per app; queue workers: one, no autoscaling.
                'app_instances' => 1, 'worker_instances' => 1, 'worker_autoscale' => false, 'worker_groups' => 0,
                'realtime_max_connections' => 200,
            ],
            'pro' => [
                'label' => 'Pro', 'price_cents' => 2000, 'seats' => 3, 'extra_seat_cents' => null,
                'usage_credit_cents' => 2000, 'fair_use_apps' => 250,
                'ssr' => true, 'containers' => true, 'addons' => true, 'audit_log' => false,
                'concurrent_builds' => 2, 'build_timeout_minutes' => 45, 'custom_domains' => 20,
                'databases' => 10, 'queues' => 10, 'queue_concurrency' => 10, 'queue_batch_wait_seconds' => 2,
                // Queue workers per app: instances across all groups (null = no cap), autoscaling, extra groups.
                'app_instances' => null, 'worker_instances' => 5, 'worker_autoscale' => true, 'worker_groups' => 2,
                // The largest concurrent-socket size one Realtime app may have.
                'realtime_max_connections' => 1_000,
            ],
            'team' => [
                'label' => 'Team', 'price_cents' => 4900, 'seats' => 10, 'extra_seat_cents' => 500,
                'usage_credit_cents' => 5000, 'fair_use_apps' => 1_000,
                'ssr' => true, 'containers' => true, 'addons' => true, 'audit_log' => true,
                'concurrent_builds' => 5, 'build_timeout_minutes' => 60, 'custom_domains' => 100,
                'databases' => 50, 'queues' => 50, 'queue_concurrency' => 50, 'queue_batch_wait_seconds' => 1,
                'app_instances' => null, 'worker_instances' => 10, 'worker_autoscale' => true, 'worker_groups' => 4,
                'realtime_max_connections' => 5_000,
            ],
            // Sales-led: billed by hand in Stripe (subscription.enterprise), so
            // no fee, credit or usage here — null limits mean unlimited.
            'enterprise' => [
                'label' => 'Enterprise', 'price_cents' => 0, 'seats' => null, 'extra_seat_cents' => null,
                'usage_credit_cents' => null, 'fair_use_apps' => null,
                'ssr' => true, 'containers' => true, 'addons' => true, 'audit_log' => true,
                'concurrent_builds' => 10, 'build_timeout_minutes' => 120, 'custom_domains' => null,
                'databases' => null, 'queues' => null, 'queue_concurrency' => null, 'queue_batch_wait_seconds' => 0,
                'app_instances' => null, 'worker_instances' => null, 'worker_autoscale' => true, 'worker_groups' => 4,
                // One app is one Durable Object, which tops out in the tens of
                // thousands of sockets — so Enterprise is capped, not unlimited.
                'realtime_max_connections' => 20_000,
            ],
        ],
        // Retired per-site fees: their Stripe prices are removed from
        // subscriptions by the syncer (no proration, no credit).
        // Edge delivery usage was billed in 1-cent Stripe units (legacy line).
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
            'tier_starter' => env('STRIPE_PRICE_STARTER', ''),
            'tier_pro' => env('STRIPE_PRICE_TIER_PRO', ''),
            'tier_team' => env('STRIPE_PRICE_TIER_TEAM', ''),
            'team_seat' => env('STRIPE_PRICE_TEAM_SEAT', ''),
            // Retired per-site prices (extra site, SSR site, load balancing).
            // The syncer removes them without proration or credit.
            'retired_site_fees' => [
                env('STRIPE_PRICE_STANDARD_EDGE'), env('STRIPE_PRICE_STANDARD_EDGE_YEARLY'),
                env('STRIPE_PRICE_STANDARD_EDGE_SSR'), env('STRIPE_PRICE_STANDARD_EDGE_SSR_YEARLY'),
                env('STRIPE_PRICE_STANDARD_EDGE_LB_ENDPOINT'),
            ],
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
