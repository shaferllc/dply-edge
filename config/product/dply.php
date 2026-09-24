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
    | Edge: usage-based billing (pass-through + margin)
    |--------------------------------------------------------------------------
    |
    | When enabled, delivery past the plan allowance is metered on top of the
    | plan. Extra sites and SSR sites are separate lines (edge_cents /
    | edge_ssr_cents in config/subscription.php). Snapshots are collected by
    | `dply:edge:collect-usage` (scheduled daily).
    |
    | Unit rates are ~Cloudflare list (cost floor). `markup_percent` is applied
    | on the metered subtotal (default 25%). Plan allowances cover quiet
    | sites; only extras and SSR add a site fee.
    |
    | Approx CF list (2026): Workers requests ~$0.30/M, R2 storage ~$0.015/GB-mo,
    | Class A $4.50/M, Class B $0.36/M. Egress is charged as CDN delivery.
    */
    'edge' => [
        'usage_billing' => [
            'enabled' => filter_var(env('DPLY_EDGE_USAGE_BILLING_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
            // Blanket margin on overage.
            'markup_percent' => (int) env('DPLY_EDGE_USAGE_MARKUP_PERCENT', 25),
            // Cost-floor unit rates (cents). Customer pays rate × (1 + markup%).
            'requests_cents_per_million' => (int) env('DPLY_EDGE_USAGE_REQUESTS_CENTS_PER_MILLION', 50),
            'egress_cents_per_gb' => (int) env('DPLY_EDGE_USAGE_EGRESS_CENTS_PER_GB', 5),
            'r2_storage_cents_per_gb_month' => (int) env('DPLY_EDGE_USAGE_R2_STORAGE_CENTS_PER_GB_MONTH', 3),
            'r2_class_a_cents_per_million' => (int) env('DPLY_EDGE_USAGE_R2_CLASS_A_CENTS_PER_MILLION', 450),
            // Cloudflare R2 Class B (reads) list price is $0.36 / million = 36
            // cents. The previous default of 360 was a 10x typo that billed
            // customers ten times the real cost.
            'r2_class_b_cents_per_million' => (int) env('DPLY_EDGE_USAGE_R2_CLASS_B_CENTS_PER_MILLION', 36),
            // 5M was break-even against the $2 platform fee on its own: 5M
            // requests is $1.50 at Cloudflare list ($0.30/M), and $2 only buys
            // ~6.7M before storage, ops and the custom hostname are paid for —
            // so any site that actually used its allowance was served at a loss.
            // 1M costs $0.30 and still sits well above what a typical static
            // site does in a month (usually under 500k).
            'included_requests_per_site' => (int) env('DPLY_EDGE_USAGE_INCLUDED_REQUESTS_PER_SITE', 1_000_000),
            'included_egress_gb_per_site' => (int) env('DPLY_EDGE_USAGE_INCLUDED_EGRESS_GB_PER_SITE', 100),
            'included_r2_storage_gb_per_site' => (int) env('DPLY_EDGE_USAGE_INCLUDED_R2_STORAGE_GB_PER_SITE', 5),
            // R2 operations included allowances — keep small sites at $0.
            // Class A = writes (PUT/POST/LIST/COPY); Class B = reads (GET/HEAD).
            // Cloudflare's free tier is 1M Class A + 10M Class B per month
            // org-wide. Class B (reads) stays generous — cache hits never touch
            // R2, so the allowance is nearly free to give. Class A (writes) is
            // sized to a real deploy cadence instead.
            // 100k writes is $0.45 at list — small next to a plan — for an
            // allowance nothing reaches: a 2,000-file site deploying ten times
            // a month writes 20k objects.
            'included_r2_class_a_ops_per_site' => (int) env('DPLY_EDGE_USAGE_INCLUDED_R2_CLASS_A_OPS_PER_SITE', 20_000),
            'included_r2_class_b_ops_per_site' => (int) env('DPLY_EDGE_USAGE_INCLUDED_R2_CLASS_B_OPS_PER_SITE', 1_000_000),
            // Container compute, per second at Cloudflare list price in
            // millicents (1/1000 ¢), marked up like the rest of usage:
            //   vCPU $0.000020/s · memory $0.0000025/GiB-s · disk $0.00000007/GB-s
            //   egress $0.025/GB. Collected by dply:edge:collect-container-usage.
            'container_vcpu_millicents_per_hour' => (int) env('DPLY_EDGE_CONTAINER_VCPU_MC_PER_HOUR', 7_200),
            'container_memory_millicents_per_gib_hour' => (int) env('DPLY_EDGE_CONTAINER_MEMORY_MC_PER_GIB_HOUR', 900),
            'container_disk_millicents_per_gb_hour' => (int) env('DPLY_EDGE_CONTAINER_DISK_MC_PER_GB_HOUR', 25),
            'container_egress_millicents_per_gb' => (int) env('DPLY_EDGE_CONTAINER_EGRESS_MC_PER_GB', 2_500),
            // D1 and Queues at Cloudflare list price, millicents, marked up:
            //   rows read $0.001/M · rows written $1.00/M · storage $0.75/GB-month
            //   queue operations $0.40/M. Collected by dply:edge:collect-data-usage.
            'd1_rows_read_millicents_per_million' => (int) env('DPLY_EDGE_D1_READ_MC_PER_MILLION', 100),
            'd1_rows_written_millicents_per_million' => (int) env('DPLY_EDGE_D1_WRITE_MC_PER_MILLION', 100_000),
            'd1_storage_millicents_per_gb_month' => (int) env('DPLY_EDGE_D1_STORAGE_MC_PER_GB_MONTH', 75_000),
            'queue_operations_millicents_per_million' => (int) env('DPLY_EDGE_QUEUE_OPS_MC_PER_MILLION', 40_000),
            // Redis started for an app. List price: $0.20 / 100K commands,
            // $0.25 / GB-month after 1 GB, $0.03 / GB after 200 GB.
            // Collected by dply:edge:collect-redis-usage. Read by EdgeRedisCost.
            // User request: "ok so how can we implement upstash and bill for it".
            'redis_commands_millicents_per_100k' => (int) env('DPLY_EDGE_REDIS_COMMANDS_MC_PER_100K', 20_000),
            'redis_storage_millicents_per_gb_month' => (int) env('DPLY_EDGE_REDIS_STORAGE_MC_PER_GB_MONTH', 25_000),
            'redis_bandwidth_millicents_per_gb' => (int) env('DPLY_EDGE_REDIS_BANDWIDTH_MC_PER_GB', 3_000),
        ],
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
