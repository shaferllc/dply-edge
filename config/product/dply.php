<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Queue lanes
    |--------------------------------------------------------------------------
    | Everything used to share one queue, which meant a user watching a deploy
    | progress bar waited behind fleet-wide SSH sweeps — a serverless deploy is
    | two queued hops (provision the namespace, then deploy), and each hop went
    | to the back of the same line. Two lanes, one worker each:
    |
    |   interactive — someone is staring at a spinner waiting for this.
    |   background  — health probes, systemd inventory, uptime checks, error
    |                 sweeps, broadcast fan-out. Slow and nobody is watching.
    |
    | `interactive` must stay the connection's default queue (queue.php →
    | REDIS_QUEUE) so anything not explicitly routed lands in the fast lane.
    */
    'queues' => [
        'interactive' => env('DPLY_INTERACTIVE_QUEUE', 'dply'),
        'background' => env('DPLY_BACKGROUND_QUEUE', 'dply-background'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Coming-soon gate
    |--------------------------------------------------------------------------
    | Redirect logged-out visitors to the marketing "coming soon" page.
    | COMING_SOON=true forces it on (even locally, for preview). Default is off
    | — the public site is live. See App\Http\Middleware\RedirectGuestsToComingSoon.
    */
    'coming_soon' => filter_var(env('COMING_SOON', false), FILTER_VALIDATE_BOOLEAN),

    /*
    | Where customers reach us: the pricing page, help menus, email footers
    | and the docs all point here (ruling r-jnv0r3qf1xk49kmc).
    */
    'support_email' => env('DPLY_SUPPORT_EMAIL', 'hello@dply.io'),

    /*
    | Where vulnerability reports go: security.txt, the /compliance page and
    | the docs. General support stays on support_email.
    */
    'security_email' => env('DPLY_SECURITY_EMAIL', 'security@dply.io'),

    /*
    | IP allow-list for the coming-soon gate. These addresses (and any logged-in
    | user) see the FULL site; everyone else only sees the coming-soon page.
    | Supports IPv4, IPv6, and CIDR ranges. Sources are merged: the base list
    | below + the comma-separated COMING_SOON_ALLOWED_IPS env var + the
    | admin-managed rows (coming_soon_allowed_ips table).
    */
    'coming_soon_allowed_ips' => array_values(array_unique(array_filter(array_map(
        static fn ($v): string => trim((string) $v),
        array_merge(
            [
                // Base allow-list (operator addresses).
                '2600:1701:408:173e:28cc:b5fa:9fd3:c347',
                '66.10.105.85',
            ],
            explode(',', (string) env('COMING_SOON_ALLOWED_IPS', '')),
        )
    )))),

    /*
    | IP allow-list for the Lookout debug page. These addresses (and any
    | platform admin) may see the interactive stack-trace/debug page for a
    | production 500; everyone else gets the branded error. Kept separate from
    | the coming-soon list on purpose. Merged: the base list below + the
    | comma-separated DEBUG_ALLOWED_IPS env var + the admin-managed rows
    | (debug_allowed_ips table). Supports IPv4, IPv6, and CIDR ranges.
    */
    'debug_allowed_ips' => array_values(array_unique(array_filter(array_map(
        static fn ($v): string => trim((string) $v),
        explode(',', (string) env('DEBUG_ALLOWED_IPS', '')),
    )))),

    /*
    |--------------------------------------------------------------------------
    | Require verified email (dashboard and gated actions)
    |--------------------------------------------------------------------------
    | When false, unverified users are treated as verified for access control.
    | Defaults to off in the local environment; set DPLY_REQUIRE_EMAIL_VERIFICATION
    | to override explicitly (e.g. true locally to match production behavior).
    */
    'require_email_verification' => env('DPLY_REQUIRE_EMAIL_VERIFICATION') !== null
        ? filter_var(env('DPLY_REQUIRE_EMAIL_VERIFICATION'), FILTER_VALIDATE_BOOL)
        : env('APP_ENV', 'production') !== 'local',

    /*
    |--------------------------------------------------------------------------
    | Provision auto-retry on transient failures
    |--------------------------------------------------------------------------
    | When true, a failed setup task whose output matches transient patterns
    | (apt fetch timeout, dpkg lock contention, network blip) reschedules itself with a
    | backoff up to MAX_AUTO_RETRY_ATTEMPTS. Default on — disable with
    | DPLY_AUTO_RETRY_ENABLED=false when iterating on the bash script locally.
    */
    'auto_retry_enabled' => filter_var(env('DPLY_AUTO_RETRY_ENABLED', true), FILTER_VALIDATE_BOOL),

    /*
    |--------------------------------------------------------------------------
    | Community / docs links (optional)
    |--------------------------------------------------------------------------
    | Used on profile for “contribute a translation” style links.
    */
    'community_github_url' => env('DPLY_COMMUNITY_GITHUB_URL'),

    /*
    |--------------------------------------------------------------------------
    | Organization member cap (null = unlimited)
    |--------------------------------------------------------------------------
    | Counts active members plus non-expired pending invitations.
    | When Stripe seat billing is active, the effective cap is the lower of this
    | value and subscription seat quantity (see Organization::effectiveMemberSeatCap).
    */
    'max_organization_members' => env('DPLY_MAX_ORG_MEMBERS') !== null
        ? (int) env('DPLY_MAX_ORG_MEMBERS')
        : null,

    /*
    |--------------------------------------------------------------------------
    | Site URL health checks (HTTPS against primary domain)
    |--------------------------------------------------------------------------
    */
    'site_health_check_enabled' => filter_var(env('DPLY_SITE_HEALTH_CHECK', true), FILTER_VALIDATE_BOOL),

    'deploy_notifications' => filter_var(env('DPLY_DEPLOY_NOTIFICATIONS', true), FILTER_VALIDATE_BOOL),

    // Queued notifications (UniversalEventNotification, deploy mail, …) —
    // Horizon supervisor-fast. Keep off dply / dply-provision so Edge builds
    // never block the notification backlog.
    'notification_queue' => env('DPLY_NOTIFICATION_QUEUE', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Deploy hook default timeout (per-hook override on site_deploy_hooks)
    |--------------------------------------------------------------------------
    */
    'default_deploy_hook_timeout_seconds' => max(30, min(3600, (int) env('DPLY_DEPLOY_HOOK_TIMEOUT', 900))),

    /*
    |--------------------------------------------------------------------------
    | Remote cleanup when a site is deleted (CleanupRemoteSiteArtifactsJob)
    |--------------------------------------------------------------------------
    */
    'delete_remote_repository_on_site_delete' => true,

    'delete_remote_certbot_certificate_on_site_delete' => filter_var(env('DPLY_DELETE_REMOTE_CERT_ON_SITE_DELETE', false), FILTER_VALIDATE_BOOL),

    /*
    |--------------------------------------------------------------------------
    | Deploy email digest (hourly flush via scheduler when > 0)
    |--------------------------------------------------------------------------
    */
    'deploy_digest_hours' => max(0, min(24, (int) env('DPLY_DEPLOY_DIGEST_HOURS', 0))),

    /*
    |--------------------------------------------------------------------------
    | API tokens: default TTL when expiry left blank (deploy scope only)
    |--------------------------------------------------------------------------
    */
    'api_token_deploy_default_ttl_days' => max(1, min(365, (int) env('DPLY_API_TOKEN_DEPLOY_TTL_DAYS', 14))),

    /*
    |--------------------------------------------------------------------------
    | API tokens: require Pro subscription to create (profile / granular UI)
    |--------------------------------------------------------------------------
    | When true, only organizations on an active Pro Stripe price may create
    | new tokens from Settings → API keys. Revoking still works.
    */
    'api_tokens_require_paid_plan' => filter_var(env('DPLY_API_TOKENS_REQUIRE_PAID_PLAN', false), FILTER_VALIDATE_BOOL),

    /*
    |--------------------------------------------------------------------------
    | Worker pool agent event ingest base URL
    |--------------------------------------------------------------------------
    | Defaults to app.url. Override when pool members must reach dply on a
    | different public host (e.g. a dev tunnel).
    */
    'worker_pool_event_ingest_base' => env('DPLY_POOL_EVENT_INGEST_BASE'),
    'worker_pool_event_url' => env('DPLY_POOL_EVENT_URL', ''),
    'worker_pool_event_token' => env('DPLY_POOL_EVENT_TOKEN', ''),

    /*
    |--------------------------------------------------------------------------
    | Demo DigitalOcean flow (php artisan dply:demo-do-server)
    |--------------------------------------------------------------------------
    | Token is never stored here — use --token or DPLY_DEMO_DO_TOKEN / DIGITALOCEAN_TOKEN.
    |
    | Provisioning runs as demo_user_email and attaches the droplet to that user’s first
    | organization (by membership created_at), so you can watch the same org in the UI.
    | demo_org_slug is only used when --org-slug is omitted and the user belongs to no org yet
    | (e.g. CI), or when you pass --org-slug explicitly.
    */
    'demo_user_email' => env('DPLY_DEMO_USER_EMAIL', 'tom.shafer@gmail.com'),
    'demo_org_slug' => env('DPLY_DEMO_ORG_SLUG', 'dply-automated-demo'),
    'demo_do_region' => env('DPLY_DEMO_DO_REGION', 'nyc1'),
    'demo_do_size' => env('DPLY_DEMO_DO_SIZE', 's-1vcpu-1gb'),

    /*
    |--------------------------------------------------------------------------
    | Provider API tokens for snapshot / demo CLI commands
    |--------------------------------------------------------------------------
    | Read via config() in Artisan commands — never call env() outside config.
    */
    'demo_do_token' => env('DPLY_DEMO_DO_TOKEN'),
    'digitalocean_token' => env('DIGITALOCEAN_TOKEN'),
    'snapshot_do_token' => env('DPLY_SNAPSHOT_DO_TOKEN'),
    'snapshot_hetzner_tokens' => array_values(array_filter([
        env('DPLY_SNAPSHOT_HETZNER_TOKEN'),
        env('DPLY_MANAGED_HETZNER_API_TOKEN'),
        env('HETZNER_API_TOKEN'),
        env('HETZNER_TOKEN'),
    ])),

    'changelog_timeout' => max(30, (int) env('DPLY_CHANGELOG_TIMEOUT', 90)),

    /*
    |--------------------------------------------------------------------------
    | Public control-plane URL for TaskRunner signed webhooks
    |--------------------------------------------------------------------------
    | When workers or cloud VMs must POST to your app (e.g. stack provision
    | callbacks) but APP_URL is internal (http://127.0.0.1), set this to the
    | HTTPS URL the machine can reach (tunnel, load balancer, etc.). Signed
    | webhook routes are generated with this root when set.
    */
    'public_app_url' => env('DPLY_PUBLIC_APP_URL'),

    /*
    |--------------------------------------------------------------------------
    | Server removal: default scheduled deletion day offset
    |--------------------------------------------------------------------------
    | When scheduling server removal from the UI, the date picker defaults to
    | today plus this many days (user can change the date).
    */
    'server_scheduled_deletion_default_days' => max(1, min(365, (int) env('DPLY_SERVER_SCHEDULED_DELETION_DEFAULT_DAYS', 7))),

    /*
    |--------------------------------------------------------------------------
    | Server removal: notify organization owners and admins
    |--------------------------------------------------------------------------
    | When true, scheduling or completing server removal sends mail to org
    | members with owner or admin roles (see DeleteServerAction and Livewire
    | server removal flows).
    */
    'server_deletion_notify_org_admins' => filter_var(env('DPLY_SERVER_DELETION_NOTIFY_ADMINS', true), FILTER_VALIDATE_BOOL),

    /*
    |--------------------------------------------------------------------------
    | Server removal: optional documentation URL (checklist in remove modal)
    |--------------------------------------------------------------------------
    */
    'server_deletion_docs_url' => env('DPLY_SERVER_DELETION_DOCS_URL'),

    /*
    |--------------------------------------------------------------------------
    | Supervisor (Daemons): scheduled health checks
    |--------------------------------------------------------------------------
    | When enabled, `dply:supervisor-check-health` SSHes to ready servers that
    | have active programs and stores a snapshot in `servers.meta.supervisor_health`.
    | Org owners/admins can receive mail when managed programs look unhealthy.
    */
    'supervisor_health_check_enabled' => filter_var(env('DPLY_SUPERVISOR_HEALTH_CHECK_ENABLED', true), FILTER_VALIDATE_BOOL),

    'supervisor_health_notify_org_admins' => filter_var(env('DPLY_SUPERVISOR_HEALTH_NOTIFY_ADMINS', true), FILTER_VALIDATE_BOOL),

    /*
    |--------------------------------------------------------------------------
    | Site scaffolding (Laravel + WordPress one-click installs)
    |--------------------------------------------------------------------------
    | Gates the new "scaffold a fresh app" branch of the Site Create wizard
    | plus the WordPress Site Settings section. Default off until the
    | back-end pipelines (PR 5–6) and journey UI (PR 7) ship; flips on once
    | the pipeline is reliable end-to-end.
    */
    'scaffold_v1_enabled' => filter_var(env('DPLY_SCAFFOLD_V1_ENABLED', true), FILTER_VALIDATE_BOOL),

    /*
    |--------------------------------------------------------------------------
    | Choose-an-application flow (VM post-creation app picker)
    |--------------------------------------------------------------------------
    | Gates the new flow where a VM site is created bare (domain + server) in
    | STATUS_AWAITING_APP and the user then picks what runs on it (Git repo,
    | WordPress, Laravel, Statamic, static, blank) on a dedicated
    | sites.choose-app page. Default off; when off the existing import/scaffold
    | wizard remains the fallback. VM hosts only for now — container/serverless
    | keep their dedicated create flows. See docs/CHOOSE_APP_FLOW.md.
    */
    'choose_app_enabled' => filter_var(env('DPLY_CHOOSE_APP_ENABLED', true), FILTER_VALIDATE_BOOL),

    /*
    |--------------------------------------------------------------------------
    | Edge: usage billing — provider cost + one margin
    |--------------------------------------------------------------------------
    |
    | Pricing model (docs/adr/pricing-model-2026-09.md, ruling r-2zxevg4sj675qn1m).
    | Every rate below is what the meter COSTS dply, in millicents (1/1000 ¢;
    | 100,000 = $1). The customer price is cost × (1 + margin_percent / 100),
    | applied in one place: App\Modules\Billing\Support\UsagePrice. Nothing else
    | marks up. Each plan's included usage credit
    | (subscription.standard.tiers.*.usage_credit_cents) comes off the bill;
    | there are no per-meter allowances. Snapshots come from the
    | dply:edge:collect-* commands.
    |
    | Sources: Cloudflare list prices (2026) unless the comment says otherwise.
    */
    'edge' => [
        'usage_billing' => [
            'enabled' => filter_var(env('DPLY_EDGE_USAGE_BILLING_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
            // The single knob. Changing it reprices every meter, the pricing
            // page, the docs tables (dply:billing:price-table) and invoices.
            'margin_percent' => (float) env('DPLY_USAGE_MARGIN_PERCENT', 30),

            // Delivery. Workers Standard: $0.30 per million requests.
            'requests_millicents_per_million' => (float) env('DPLY_USAGE_REQUESTS_MC_PER_MILLION', 30_000),
            // Bandwidth. NOT a Cloudflare cost: Workers/R2 egress is
            // unmetered at Cloudflare. $0.06/GB is a dply-set CUSTOMER PRICE
            // (ruling r-jnv0r3qf1xk49kmc: "bandwidth stays $0.06/GB"), so it is
            // listed in fixed_price_meters below: the margin is not added and
            // changing margin_percent leaves it at $0.06. The env var sets the
            // price, not a cost.
            'egress_millicents_per_gb' => (float) env('DPLY_USAGE_EGRESS_MC_PER_GB', 6_000),
            // Meters whose value is the customer price, not a cost
            // (UsagePrice::cost() backs the cost out at the current margin).
            // Bandwidth, plus every meter that runs on dply's own hosts or has
            // no per-unit provider price (build time, databases, realtime):
            // their prices are set from the estimates in dply.unit_costs
            // (php artisan dply:billing:unit-costs, docs/pricing-review.md §9)
            // until real costs are measured. Valkey is priced the same way,
            // in EdgeValkey::CLASSES.
            'fixed_price_meters' => [
                'egress_millicents_per_gb',
                'build_millicents_per_minute',
                'database_compute_millicents_per_cu_second',
                'database_storage_millicents_per_gb_month',
                'realtime_connection_minute_millicents',
                'realtime_message_millicents_per_million',
            ],
            // Site/build artifact storage (R2): $0.015/GB-month, Class A
            // (writes) $4.50/M, Class B (reads) $0.36/M.
            'r2_storage_millicents_per_gb_month' => (float) env('DPLY_USAGE_R2_STORAGE_MC_PER_GB_MONTH', 1_500),
            'r2_class_a_millicents_per_million' => (float) env('DPLY_USAGE_R2_CLASS_A_MC_PER_MILLION', 450_000),
            'r2_class_b_millicents_per_million' => (float) env('DPLY_USAGE_R2_CLASS_B_MC_PER_MILLION', 36_000),

            // Build time, billed per second (EdgeDeployment.build_seconds).
            // A CUSTOMER PRICE (fixed_price_meters): builds run on dply's own
            // build host, estimated at ~$0.002/min at 15% utilisation
            // (dply.unit_costs.build). $0.005/min matches Render's build-minute
            // price. The env var sets the price.
            'build_millicents_per_minute' => (float) env('DPLY_USAGE_BUILD_MC_PER_MINUTE', 500),

            // Container apps and queue workers (Cloudflare Containers), per
            // second awake: vCPU $0.000020/s, memory $0.0000025/GiB-s, disk
            // $0.00000007/GB-s. Collected by dply:edge:collect-container-usage
            // (per-second counters). Container egress (tx_bytes) is recorded
            // but not billed: visitor responses already bill once as delivery
            // bandwidth (see EdgeContainerComputeCost).
            'container_vcpu_millicents_per_second' => (float) env('DPLY_USAGE_CONTAINER_VCPU_MC_PER_SECOND', 2.0),
            'container_memory_millicents_per_gib_second' => (float) env('DPLY_USAGE_CONTAINER_MEMORY_MC_PER_GIB_SECOND', 0.25),
            'container_disk_millicents_per_gb_second' => (float) env('DPLY_USAGE_CONTAINER_DISK_MC_PER_GB_SECOND', 0.007),
            // Monthly cap per app instance: this many hours of the size's
            // 100%-CPU price (UsagePrice::containerCapHours). Break-even is
            // 720 / (1 + margin) h (554 h at 30%), so the code never lets it
            // fall below 5% over cost, whatever this says.
            'container_monthly_cap_hours' => (float) env('DPLY_USAGE_CONTAINER_CAP_HOURS', 600),

            // D1: rows read $0.001/M, rows written $1.00/M, storage
            // $0.75/GB-month. Queues: $0.40 per million operations.
            // Collected by dply:edge:collect-data-usage.
            'd1_rows_read_millicents_per_million' => (float) env('DPLY_EDGE_D1_READ_MC_PER_MILLION', 100),
            'd1_rows_written_millicents_per_million' => (float) env('DPLY_EDGE_D1_WRITE_MC_PER_MILLION', 100_000),
            'd1_storage_millicents_per_gb_month' => (float) env('DPLY_EDGE_D1_STORAGE_MC_PER_GB_MONTH', 75_000),
            'queue_operations_millicents_per_million' => (float) env('DPLY_EDGE_QUEUE_OPS_MC_PER_MILLION', 40_000),

            // Workers KV: reads $0.50/M; writes, deletes and lists $5.00/M;
            // storage $0.50/GB-month. Collected by dply:edge:collect-kv-usage.
            'kv_reads_millicents_per_million' => (float) env('DPLY_USAGE_KV_READS_MC_PER_MILLION', 50_000),
            'kv_writes_millicents_per_million' => (float) env('DPLY_USAGE_KV_WRITES_MC_PER_MILLION', 500_000),
            'kv_storage_millicents_per_gb_month' => (float) env('DPLY_USAGE_KV_STORAGE_MC_PER_GB_MONTH', 50_000),

            // dply databases (Postgres/MySQL/MongoDB pods on dply's cluster),
            // per compute-unit second awake (1 CU = 1 vCPU + 4 GB) plus
            // storage per GB-month, prorated by the second the volume is held.
            // CUSTOMER PRICES (fixed_price_meters), set from the DigitalOcean
            // node estimate in dply.unit_costs: $0.12 per CU-hour (cost ~$0.077
            // at 70% packing; Laravel Cloud Postgres $0.135) and $0.20 per
            // GB-month (volume $0.10 + backup copy ~$0.02). Collected by
            // dply:edge:collect-valkey-usage. The env vars set prices.
            'database_compute_millicents_per_cu_second' => (float) env('DPLY_USAGE_DATABASE_MC_PER_CU_SECOND', 12_000 / 3600),
            'database_storage_millicents_per_gb_month' => (float) env('DPLY_USAGE_DATABASE_STORAGE_MC_PER_GB_MONTH', 20_000),

            // Realtime (docs/edge-realtime.md). CUSTOMER PRICES
            // (fixed_price_meters); the relay has no per-unit provider price.
            // Real cost (dply:billing:unit-costs): a publish is a Worker
            // request + a DO request (~$0.46/M); delivered frames are free
            // (outgoing WebSocket messages, hibernation). Messages $0.62/M
            // covers a publish nobody receives at 1.3x and is 4x under Ably
            // ($2.50/M). A connection costs its upgrade (~$0.45/M), spread
            // over its minutes: $0.25/M connection-minutes covers sessions of
            // 2.3+ minutes at 1.3x (Ably $1.00/M). Collected by
            // dply:edge:collect-realtime-usage. The env vars set prices.
            'realtime_connection_minute_millicents' => (float) env('DPLY_USAGE_REALTIME_CONNECTION_MINUTE_MC', 0.025),
            'realtime_message_millicents_per_million' => (float) env('DPLY_USAGE_REALTIME_MESSAGES_MC_PER_MILLION', 62_000),

            // Workers CPU $0.02 per million CPU-ms. Durable Objects: requests
            // $0.15/M, duration $12.50 per million GB-s, rows read $0.001/M,
            // rows written $1.00/M, storage $0.20/GB-month. Object-storage
            // buckets (R2): $0.015/GB-month, Class A $4.50/M, Class B $0.36/M.
            // Images: $0.50 per 1,000 unique transformations.
            // Collected by dply:edge:collect-platform-usage.
            'workers_cpu_millicents_per_million_ms' => (float) env('DPLY_EDGE_WORKERS_CPU_MC_PER_MILLION_MS', 2_000),
            'do_requests_millicents_per_million' => (float) env('DPLY_EDGE_DO_REQUESTS_MC_PER_MILLION', 15_000),
            'do_duration_millicents_per_million_gb_s' => (float) env('DPLY_EDGE_DO_DURATION_MC_PER_MILLION_GB_S', 1_250_000),
            'do_rows_read_millicents_per_million' => (float) env('DPLY_EDGE_DO_ROWS_READ_MC_PER_MILLION', 100),
            'do_rows_written_millicents_per_million' => (float) env('DPLY_EDGE_DO_ROWS_WRITTEN_MC_PER_MILLION', 100_000),
            'do_storage_millicents_per_gb_month' => (float) env('DPLY_EDGE_DO_STORAGE_MC_PER_GB_MONTH', 20_000),
            'r2_bucket_storage_millicents_per_gb_month' => (float) env('DPLY_EDGE_R2_BUCKET_STORAGE_MC_PER_GB_MONTH', 1_500),
            'r2_bucket_class_a_millicents_per_million' => (float) env('DPLY_EDGE_R2_BUCKET_CLASS_A_MC_PER_MILLION', 450_000),
            'r2_bucket_class_b_millicents_per_million' => (float) env('DPLY_EDGE_R2_BUCKET_CLASS_B_MC_PER_MILLION', 36_000),
            'images_transformations_millicents_per_million' => (float) env('DPLY_EDGE_IMAGES_TRANSFORMATIONS_MC_PER_MILLION', 50_000_000),

            // Metered through dply's proxy (EdgeMeter), Cloudflare list
            // prices verified 2026-09-27: Workers AI $0.011 per 1,000
            // neurons; Browser Rendering $0.09 per browser-hour; Vectorize
            // $0.01 per million queried dimensions and $0.05 per 100 million
            // stored dimensions. Account-level free allocations and Browser's
            // $2 concurrency charge are not passed on.
            'ai_neurons_millicents_per_thousand' => (float) env('DPLY_EDGE_AI_NEURONS_MC_PER_THOUSAND', 1_100),
            'browser_millicents_per_hour' => (float) env('DPLY_EDGE_BROWSER_MC_PER_HOUR', 9_000),
            'vector_queried_millicents_per_million_dims' => (float) env('DPLY_EDGE_VECTOR_QUERIED_MC_PER_MILLION_DIMS', 1_000),
            'vector_stored_millicents_per_hundred_million_dims' => (float) env('DPLY_EDGE_VECTOR_STORED_MC_PER_100M_DIMS', 5_000),
        ],
    ],

    // dply databases of 1, 2 and 4 CU (EdgeDplyDatabase::LARGE_SIZES) need the
    // db-large / db-xl node pools (deploy/valkey/terraform). Off until the
    // owner has applied that terraform (docs/launch-checklist.md).
    'databases' => [
        'large_sizes_enabled' => filter_var(env('DPLY_DATABASE_LARGE_SIZES', false), FILTER_VALIDATE_BOOL),
    ],

    /*
    |--------------------------------------------------------------------------
    | Unit costs: what dply's own infrastructure really costs (ESTIMATES)
    |--------------------------------------------------------------------------
    |
    | Inputs for `php artisan dply:billing:unit-costs`, which turns them into a
    | real cost per database CU-hour and GB-month, Valkey GB-hour, build minute
    | and realtime message / connection-minute, and compares each with the
    | price (docs/pricing-review.md §9, §10). Nothing bills from these.
    |
    | Every figure is an ESTIMATE made 2026-09-27 from deploy/valkey/terraform
    | (the DOKS cluster), deploy/DO_MIGRATION.md (control plane), config/horizon
    | (4 concurrent builds) and public list prices fetched that day:
    | digitalocean.com/pricing/{droplets,kubernetes,volumes}, DOKS allocatable
    | memory (docs.digitalocean.com/products/kubernetes/details/limits),
    | Cloudflare Workers / Workers for Platforms / Durable Objects pricing,
    | stripe.com/billing/pricing. Replace with invoices once they exist.
    | Money in dollars per month unless the key says otherwise.
    */
    'unit_costs' => [
        'digitalocean' => [
            // Node pools of the dply-pods cluster (nyc3). `nodes` is the
            // minimum the pool keeps (fixed cost); allocatable is what pods can
            // request after DOKS's system reserve.
            'pools' => [
                'cache' => ['size' => 's-2vcpu-4gb', 'monthly' => 24, 'vcpu' => 2, 'ram_gb' => 4, 'allocatable_gib' => 2.5, 'nodes' => 2],
                'db' => ['size' => 's-2vcpu-4gb', 'monthly' => 24, 'vcpu' => 2, 'ram_gb' => 4, 'allocatable_gib' => 2.5, 'nodes' => 2],
                'pro-16' => ['size' => 'm-2vcpu-16gb', 'monthly' => 84, 'vcpu' => 2, 'ram_gb' => 16, 'allocatable_gib' => 13, 'nodes' => 0],
                'pro-64' => ['size' => 'm-8vcpu-64gb', 'monthly' => 336, 'vcpu' => 8, 'ram_gb' => 64, 'allocatable_gib' => 58, 'nodes' => 0],
                // Databases over 0.5 CU (scale from zero). Memory binds, not
                // CPU: an awake database asks one core at most, and 13 GiB
                // holds three awake 1 CU (8 vCPU); 28 GiB holds one awake 4 CU.
                'db-large' => ['size' => 's-8vcpu-16gb', 'monthly' => 96, 'vcpu' => 8, 'ram_gb' => 16, 'allocatable_gib' => 13, 'nodes' => 0],
                'db-xl' => ['size' => 'm-4vcpu-32gb', 'monthly' => 168, 'vcpu' => 4, 'ram_gb' => 32, 'allocatable_gib' => 28, 'nodes' => 0],
            ],
            // Which pool each database size (EdgeAppDatabase::POSTGRES_SIZES)
            // runs on: packages/valkey-gateway/placement.go databasePool.
            'database_pools' => ['0.25' => 'db', '0.5' => 'db', '1' => 'db-large', '2' => 'db-large', '4' => 'db-xl'],
            // Share of a pool's allocatable memory that paying tenants fill on
            // average (warm pool pods, fragmentation, headroom).
            // db-large assumes the db pool's 70% once several databases share
            // it. db-xl fits one awake 4 CU (16 of 28 GiB): 0.57 is a whole
            // node per awake 4 CU. The first large database alone keeps a
            // whole node up, awake or parked (docs/pricing-review.md §9).
            'packing' => ['cache' => 0.7, 'db' => 0.7, 'pro-16' => 0.9, 'pro-64' => 0.9, 'db-large' => 0.7, 'db-xl' => 16 / 28],
            'ha_control_plane' => 40,
            'load_balancer' => 12,
            'registry' => 5,
            'volume_per_gb_month' => 0.10,
            // Database dumps kept in Spaces ($5 per 250 GB, then $0.02/GB).
            'backup_per_gb_month' => 0.02,
        ],

        // Edge builds run in `docker run` on the control-plane worker
        // (s-4vcpu-8gb), HORIZON_BUILD_MAX_PROCESSES = 4 at a time.
        'build' => ['size' => 's-4vcpu-8gb', 'monthly' => 48, 'hosts' => 1, 'concurrent_builds' => 4, 'utilisation' => 0.15],

        // The control plane apart from the build worker (DO_MIGRATION.md
        // topology, no replica): web s-2vcpu-4gb, Postgres s-4vcpu-8gb, Redis
        // s-2vcpu-4gb, weekly backups (+20%), Spaces for escrow and dumps.
        'control_plane' => ['web' => 24, 'postgres' => 48, 'redis' => 24, 'backups' => 19.20, 'spaces' => 5],

        'cloudflare' => [
            // Workers Paid: includes Containers, KV, D1, Queues, DO base.
            'workers_paid' => 5,
            // Workers for Platforms (SSR dispatch namespace): 20M requests,
            // 60M CPU-ms and 1,000 scripts included; $0.02/script after.
            'workers_for_platforms' => 25,
            'wfp_scripts_included' => 1_000,
            'wfp_script_monthly' => 0.02,
            // Cloudflare for SaaS custom hostnames: 100 free, then $0.10/mo.
            'custom_hostnames_included' => 100,
            'custom_hostname_monthly' => 0.10,
        ],

        // Everything else paid monthly. No error tracker is installed today
        // (Sentry Team would be $26). Email goes out through Cloudflare Email
        // Sending; the domains are dply.io, dply.cloud (~$60/yr together).
        'services' => ['email' => 5, 'error_tracking' => 0, 'domains' => 5, 'github' => 0],

        // Realtime relay (packages/realtime-worker, hibernating Durable
        // Object). A publish = one Worker request + one DO request + this much
        // DO wall time at this much memory; a connection = its upgrade (one
        // Worker + one DO request) spread over its average length. Delivered
        // frames and pings are free. `deliveries_per_publish` only shows the
        // typical case; prices are set against 0 (nobody listening).
        'realtime' => ['publish_wall_ms' => 10, 'do_memory_gb' => 0.125, 'avg_connection_minutes' => 3, 'deliveries_per_publish' => 1],

        // Card processing 2.9% + 30c, plus Stripe Billing 0.7% of billed volume.
        'stripe' => ['percent' => 3.6, 'fixed_cents' => 30],

        // Markup the price must keep over the estimated cost (1.3 = +30%, the
        // usage margin). dply:billing:unit-costs flags anything under it.
        'min_markup' => 1.3,
    ],

    /*
    |--------------------------------------------------------------------------
    | Local workspace pruning
    |--------------------------------------------------------------------------
    |
    | Control-plane build scratch under storage/app accumulates and never self-
    | prunes: serverless build artifacts (one zip per deploy), per-site git
    | checkout caches, and task-runner temp. The scheduled command
    | `dply:prune-local-workspaces` removes entries older than these ages.
    */
    'quick_login_enabled' => filter_var(env('DPLY_QUICK_LOGIN_ENABLED', false), FILTER_VALIDATE_BOOL),

    // Optional override for the in-browser CLI console. When unset, CliConsole
    // uses packages/dply-cli/bin/dply.mjs via Node. Point at a .mjs or binary.
    'cli_binary' => env('DPLY_CLI_BINARY'),

    'local_workspace_prune' => [
        'enabled' => filter_var(env('DPLY_LOCAL_WORKSPACE_PRUNE_ENABLED', true), FILTER_VALIDATE_BOOL),
        // Built artifact zips are byproducts once uploaded to the provider; keep
        // a short window for post-mortem on a failed deploy, then reclaim.
        'artifacts_max_age_hours' => max(1, (int) env('DPLY_LOCAL_ARTIFACTS_MAX_AGE_HOURS', 48)),
        // Git checkout caches speed up incremental redeploys; prune ones no
        // deploy has touched in a week (they re-clone on next use).
        'repositories_max_age_hours' => max(1, (int) env('DPLY_LOCAL_REPOSITORIES_MAX_AGE_HOURS', 168)),
        // Task-runner temp is short-lived scratch.
        'task_runner_max_age_hours' => max(1, (int) env('DPLY_LOCAL_TASK_RUNNER_MAX_AGE_HOURS', 24)),
    ],

    // Remote counterpart to local_workspace_prune: every task dply runs uploads a
    // <id>.sh/.log (+ .pid) into ~/.dply-task-runner on the box and never removes
    // it, so the dir grows without bound. A scheduled per-server SSH prune
    // age-deletes them; the age guard means a script for an in-flight or recently
    // backgrounded task is never touched, so this can't race a running deploy.
    'remote_task_runner_prune' => [
        'enabled' => filter_var(env('DPLY_REMOTE_TASK_RUNNER_PRUNE_ENABLED', true), FILTER_VALIDATE_BOOL),
        'max_age_hours' => max(1, (int) env('DPLY_REMOTE_TASK_RUNNER_MAX_AGE_HOURS', 48)),
    ],

    /*
    |--------------------------------------------------------------------------
    | Nginx overwrite guard
    |--------------------------------------------------------------------------
    | Before dply overwrites a site's nginx vhost it parses the current on-box
    | config (via dply/nginx-config) and reports any directives a hand-edit added
    | that the regenerated config would destroy. Modes:
    |   'warn'  — log + emit the foreign directives to the deploy console, then
    |             write anyway (default; never blocks a deploy).
    |   'abort' — refuse the write and throw, so a manually-customized vhost is
    |             never clobbered until the operator folds the change into dply.
    |   'off'   — skip the read-back entirely.
    | `nginx -t` on the box remains the authority on syntax; this only guards
    | against silently discarding manual edits.
    */
    'nginx_overwrite_guard' => env('DPLY_NGINX_OVERWRITE_GUARD', 'warn'),

];
