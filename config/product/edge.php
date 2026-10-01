<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Fake edge (local / testing)
    |--------------------------------------------------------------------------
    | When enabled in allowed environments, skips real Cloudflare API calls
    | and stores artifacts on local disk. See FakeEdgeBackend.
    */
    'fake' => [
        'enabled' => filter_var(env('DPLY_FAKE_EDGE', false), FILTER_VALIDATE_BOOLEAN),
        'allowed_environments' => ['local', 'testing'],
        'storage_root' => storage_path('app/edge-fake'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cloudflare R2 (S3-compatible)
    |--------------------------------------------------------------------------
    */
    'r2' => [
        'bucket' => env('DPLY_EDGE_R2_BUCKET'),
        'region' => env('DPLY_EDGE_R2_REGION', 'auto'),
        'endpoint' => env('DPLY_EDGE_R2_ENDPOINT'),
        'key' => env('DPLY_EDGE_R2_ACCESS_KEY'),
        'secret' => env('DPLY_EDGE_R2_SECRET'),
        'use_path_style_endpoint' => filter_var(env('DPLY_EDGE_R2_PATH_STYLE', true), FILTER_VALIDATE_BOOLEAN),
        'key_prefix' => env('DPLY_EDGE_R2_KEY_PREFIX', 'edge/'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cloudflare Workers + KV
    |--------------------------------------------------------------------------
    */
    'cloudflare' => [
        'account_id' => env('DPLY_EDGE_CF_ACCOUNT_ID'),
        'api_token' => env('DPLY_EDGE_CF_API_TOKEN'),
        'kv_namespace_id' => env('DPLY_EDGE_CF_KV_NAMESPACE_ID'),
        /**
         * Optional KV namespace ID for the EDGE_CACHE binding (hybrid
         * origin response cache, see B1 in docs/edge-roadmap.md). Set
         * to enable read-through caching for hybrid sites. When unset
         * the Worker deploys without the binding and the cache is a
         * silent no-op — safe to leave blank during rollout.
         */
        'cache_kv_namespace_id' => env('DPLY_EDGE_CF_CACHE_KV_NAMESPACE_ID'),
        /*
         * Workers for Platforms dispatch namespace used to host
         * per-deployment SSR Worker scripts (Phase 4b). When unset,
         * SSR Edge sites can't be created — static + hybrid still
         * work. Bootstrap with `php artisan dply:edge:infra:bootstrap`
         * (auto-creates the namespace + prints the env line) or set
         * manually after creating one in the Cloudflare dashboard.
         */
        'dispatch_namespace_name' => env('DPLY_EDGE_CF_DISPATCH_NAMESPACE', 'dply-edge-ssr'),
        'dispatch_namespace_id' => env('DPLY_EDGE_CF_DISPATCH_NAMESPACE_ID'),
        /*
         * Default compatibility flags + date for per-deployment SSR
         * scripts uploaded into the dispatch namespace. nodejs_compat
         * is required by Next.js/OpenNext; bump the date as Cloudflare
         * ships new runtime versions.
         */
        'ssr_script_compatibility_date' => env('DPLY_EDGE_CF_SSR_COMPAT_DATE', '2024-11-01'),
        'ssr_script_compatibility_flags' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('DPLY_EDGE_CF_SSR_COMPAT_FLAGS', 'nodejs_compat'))
        ))),
        'worker_script_name' => env('DPLY_EDGE_CF_WORKER_SCRIPT', 'dply-edge'),
        'worker_zone_name' => env('DPLY_EDGE_CF_ZONE_NAME'),
        'worker_routes' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('DPLY_EDGE_CF_WORKER_ROUTES', '*.on-dply.live/*'))
        ))),
        'analytics_dataset' => env('DPLY_EDGE_CF_ANALYTICS_DATASET', 'dply_edge_requests'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Metered services: Workers AI, Browser Rendering, Vectorize (EdgeMeter)
    |--------------------------------------------------------------------------
    | Every call goes through dply's proxy, which reports it to
    | /hooks/edge/{site}/meter and asks whether it may run. `enabled` is the
    | platform kill switch per service. The org cap (organizations.
    | metered_cap_cents, set on the billing page) defaults to default_cap_cents;
    | 0 turns it off, but ceiling_cents always applies, so no org can run
    | unbounded usage on the shared account.
    */
    'metered_services' => [
        'enabled' => [
            'ai' => filter_var(env('DPLY_EDGE_AI_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
            'browser' => filter_var(env('DPLY_EDGE_BROWSER_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
            'vectors' => filter_var(env('DPLY_EDGE_VECTORS_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        ],
        'default_cap_cents' => (int) env('DPLY_EDGE_METERED_DEFAULT_CAP_CENTS', 2500),
        'ceiling_cents' => (int) env('DPLY_EDGE_METERED_CEILING_CENTS', 100000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Form submission signature. Request logs and Core Web Vitals go to
    | Analytics Engine, not this webhook.
    |--------------------------------------------------------------------------
    */
    'log_ingest' => [
        'key' => env('DPLY_EDGE_LOG_INGEST_KEY'),
        // Workers run on the public internet — prefer the tunnel/public URL
        // over APP_URL (often a local *.test host that Edge cannot reach).
        'base_url' => env('DPLY_EDGE_LOG_INGEST_BASE_URL')
            ?: env('DPLY_PUBLIC_APP_URL')
            ?: env('APP_URL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cloudflare Logpush (http_requests → Dply ingest)
    |--------------------------------------------------------------------------
    */
    'logpush' => [
        'enabled' => false,
        'secret' => env('DPLY_EDGE_LOGPUSH_SECRET'),
        'destination_url' => env('DPLY_EDGE_LOGPUSH_DESTINATION_URL', rtrim((string) env('APP_URL', ''), '/').'/hooks/edge/logpush'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Edge analytics retention (prune command)
    |--------------------------------------------------------------------------
    */
    'analytics' => [
        'access_logs_days' => (int) env('DPLY_EDGE_ACCESS_LOGS_DAYS', 7),
        'access_logs_keep_per_site' => (int) env('DPLY_EDGE_ACCESS_LOGS_KEEP', 500),
        'web_vitals_days' => (int) env('DPLY_EDGE_WEB_VITALS_DAYS', 30),
        'web_vitals_keep_per_site' => (int) env('DPLY_EDGE_WEB_VITALS_KEEP', 200),
        'performance_hourly_days' => (int) env('DPLY_EDGE_PERFORMANCE_HOURLY_DAYS', 45),
        'prefer_analytics_engine' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Build runner
    |--------------------------------------------------------------------------
    */
    'build' => [
        // Node 22 (current LTS) — Node 20 EOL is April 2026 and the latest
        // pnpm/Vite/Astro toolchains now require >=22.13. Override per-env
        // with DPLY_EDGE_BUILD_IMAGE if you need to pin older Node for a
        // specific deploy.
        'docker_image' => env('DPLY_EDGE_BUILD_IMAGE', 'node:22-bookworm'),
        // Images pre-pulled on worker boot / schedule (skip per-deploy pull when present).
        // These must be the exact tags EdgeContainerDockerfile emits — warming
        // `node:22-bookworm` while generated images say `node:22-bookworm-slim`
        // pulls an image no build ever references.
        'warm_images' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env(
                'DPLY_EDGE_BUILD_WARM_IMAGES',
                'node:22-bookworm,node:22-bookworm-slim,composer:2,'
                .'php:8.4-fpm-alpine,php:8.3-fpm-alpine,php:8.2-fpm-alpine,'
                .'ruby:3.3-slim',
            )),
        ))),
        'warm_images_on_schedule' => filter_var(env('DPLY_EDGE_BUILD_WARM_IMAGES_SCHEDULE', true), FILTER_VALIDATE_BOOLEAN),
        // Skip `docker pull` when `docker image inspect` succeeds locally.
        'skip_pull_if_present' => filter_var(env('DPLY_EDGE_BUILD_SKIP_PULL_IF_PRESENT', true), FILTER_VALIDATE_BOOLEAN),
        // Long-running clone/build/publish — Horizon supervisor-build.
        'queue' => env('DPLY_EDGE_BUILD_QUEUE', 'dply-provision'),
        // Container sites (PHP / Rails on Cloudflare Containers). The build
        // step runs `wrangler deploy --dispatch-namespace` inside this image
        // (Node + Docker CLI + wrangler) against the host Docker socket; it is
        // built from docker/edge-container-deployer on first use.
        'containers' => [
            // Faster starts (Cloudflare's durable_object scheduling): offers the
            // per-app switch. On since 2026-09-30, after placehold ran on it
            // (the first failure, "There is no container application assigned
            // to this Durable Object namespace", was the Worker uploaded before
            // its application existed; the deploy now uploads again after).
            'durable_object_scheduling' => (bool) env('DPLY_CONTAINERS_DURABLE_OBJECT_SCHEDULING', true),
            'deployer_image' => env('DPLY_EDGE_CONTAINER_DEPLOYER_IMAGE', 'dply/edge-container-deployer:1'),
            // Customer Dockerfiles build in this BuildKit builder (docker-container
            // driver on edge.build.sandbox.network), not the host daemon's.
            // '' = host default builder (unsandboxed). Limits cover all
            // concurrent container builds together; they apply when the
            // builder container is created. See docs/edge-build-isolation.md.
            'builder' => env('DPLY_EDGE_CONTAINER_BUILDER', 'dply-builds'),
            'builder_image' => env('DPLY_EDGE_CONTAINER_BUILDER_IMAGE', 'moby/buildkit:v0.32.2'),
            'builder_memory' => env('DPLY_EDGE_CONTAINER_BUILDER_MEMORY', '8g'),
            'builder_cpus' => env('DPLY_EDGE_CONTAINER_BUILDER_CPUS', '4'),
            // Narrow token for wrangler in the deployer; falls back to the
            // platform DPLY_EDGE_CF_API_TOKEN. Scopes: docs/edge-build-isolation.md.
            'deploy_api_token' => env('DPLY_EDGE_CONTAINER_DEPLOY_API_TOKEN'),
            'instance_type' => env('DPLY_EDGE_CONTAINER_INSTANCE_TYPE', 'basic'),
            'max_instances' => (int) env('DPLY_EDGE_CONTAINER_MAX_INSTANCES', 5),
            'sleep_after' => env('DPLY_EDGE_CONTAINER_SLEEP_AFTER', '5m'),
            'default_port' => 8080,
            /*
             * Prebuilt PHP base with the extensions already compiled, e.g.
             * ghcr.io/dply/edge-php — tagged by PHP minor (:8.4). Set it and
             * generated Dockerfiles pull instead of spending ~5min on
             * install-php-extensions, on every build host rather than only
             * ones warmed locally. Publish with dply:edge:publish-base-images.
             * Empty = build the extension layer per host (previous behaviour).
             */
            'php_base_repo' => trim((string) env('DPLY_EDGE_CONTAINER_PHP_BASE_REPO', '')),
            // Same for Rails/Ruby: the prebuilt image carries the native gem
            // toolchain (pg, nokogiri) so bundle install doesn't compile it.
            'ruby_base_repo' => trim((string) env('DPLY_EDGE_CONTAINER_RUBY_BASE_REPO', '')),
        ],
        'timeout_seconds' => 900,
        'artifact_max_bytes' => 524_288_000,
        // Docker should always be present on a build worker, but if a box came
        // up without it we self-heal by installing Docker inline on the next
        // deploy (idempotent; needs passwordless sudo when the worker isn't
        // root). Set false to fail fast with an install hint instead.
        'docker_autoinstall' => filter_var(env('DPLY_EDGE_BUILD_DOCKER_AUTOINSTALL', true), FILTER_VALIDATE_BOOLEAN),
        'docker_install_timeout_seconds' => (int) env('DPLY_EDGE_BUILD_DOCKER_INSTALL_TIMEOUT', 600),
        // Linux user that runs Horizon (and so must reach the Docker socket).
        // Unset → detected at runtime: the worker's own user, or SUDO_USER
        // under `sudo artisan` ({@see EdgeBuildDockerBootstrap::queueUser}).
        'docker_user' => env('DPLY_EDGE_BUILD_DOCKER_USER'),
        /*
         * Where the per-deploy checkout lives before it is bind-mounted
         * into the build container. This MUST be a path the Docker daemon
         * is allowed to share: on macOS (Docker Desktop / OrbStack) a
         * mount of an unshared path such as /var/tmp silently resolves to
         * an EMPTY directory inside the container, and the build fails
         * with a misleading "npm ci needs a package-lock.json". Defaults
         * under storage/ (same convention as git_cache_dir) so it is
         * always inside the project tree.
         */
        'work_root' => env('DPLY_EDGE_BUILD_WORK_ROOT', storage_path('app/edge-builds')),
        // Persistent --mirror clone per repo so repeated builds skip
        // re-downloading the full history. Set git_cache_enabled=false
        // to bypass the mirror and clone directly (slower, but useful
        // when debugging a stale cache).
        'git_cache_enabled' => filter_var(env('DPLY_EDGE_BUILD_GIT_CACHE', true), FILTER_VALIDATE_BOOLEAN),
        'git_cache_dir' => env('DPLY_EDGE_BUILD_GIT_CACHE_DIR', storage_path('app/edge-git-cache')),
        // Host-side npm/pnpm/yarn caches bind-mounted into every build
        // container so installs reuse downloaded tarballs across deploys.
        'package_store_enabled' => filter_var(env('DPLY_EDGE_BUILD_PACKAGE_STORE', true), FILTER_VALIDATE_BOOLEAN),
        'package_store_dir' => env('DPLY_EDGE_BUILD_PACKAGE_STORE_DIR', storage_path('app/edge-pkg-store')),
        /*
         * Isolation for containers that run customer code (build script,
         * middleware bundle) — see docs/edge-build-isolation.md, which also
         * has the host firewall rules the app can't apply itself.
         * user: 'host' = the worker's uid:gid (default), 'root', or 'uid:gid'.
         * network: '' = Docker's default bridge (not recommended).
         * An existing network keeps the options it was created with.
         */
        'sandbox' => [
            'user' => env('DPLY_EDGE_BUILD_USER', 'host'),
            'memory' => env('DPLY_EDGE_BUILD_MEMORY', '4g'),
            'cpus' => env('DPLY_EDGE_BUILD_CPUS', '2'),
            'pids_limit' => env('DPLY_EDGE_BUILD_PIDS_LIMIT', '2048'),
            'network' => env('DPLY_EDGE_BUILD_NETWORK', 'dply-builds'),
            'bridge_name' => env('DPLY_EDGE_BUILD_BRIDGE', 'dply-builds0'),
            'subnet' => env('DPLY_EDGE_BUILD_SUBNET', '172.30.0.0/16'),
            // Resolved to 127.0.0.1 inside the build. IP-literal metadata
            // (169.254.169.254) needs the DOCKER-USER rule in the docs.
            'sinkhole_hosts' => ['host.docker.internal', 'gateway.docker.internal', 'metadata.google.internal', 'metadata', 'instance-data'],
        ],
        // Upload node_modules cache to R2 after publish (off the deploy
        // critical path). When false, snapshot runs inline after build.
        'async_cache_snapshot' => filter_var(env('DPLY_EDGE_BUILD_ASYNC_CACHE_SNAPSHOT', true), FILTER_VALIDATE_BOOLEAN),
        // Sparse-checkout when a monorepo repo_root is set (shallow + cone).
        'sparse_checkout' => filter_var(env('DPLY_EDGE_BUILD_SPARSE_CHECKOUT', true), FILTER_VALIDATE_BOOLEAN),
        // Prefer filtered workspace installs (`pnpm --filter`, `npm -w`) when
        // building a single package inside a monorepo.
        'monorepo_filter_install' => filter_var(env('DPLY_EDGE_BUILD_MONOREPO_FILTER', true), FILTER_VALIDATE_BOOLEAN),
    ],

    /*
    |--------------------------------------------------------------------------
    | Deploy duration regression (edge.deploy.duration_regressed)
    |--------------------------------------------------------------------------
    | After a deploy goes live we compare its wall-clock duration against the
    | median (p50) of the site's recent successful deploys. Slower than
    | `multiplier` x p50 raises `edge.deploy.duration_regressed`.
    |
    | The median — not the mean — is deliberate: a single pathological build
    | (cold Docker cache, npm registry stall) would drag a mean upward and mask
    | every later regression. `min_samples` suppresses the alert until there is
    | enough history for a p50 to mean anything; without it the very first
    | couple of deploys on a new site alert against a baseline of one.
    */
    'duration_regression' => [
        'enabled' => filter_var(env('DPLY_EDGE_DURATION_REGRESSION', true), FILTER_VALIDATE_BOOLEAN),
        'window' => (int) env('DPLY_EDGE_DURATION_REGRESSION_WINDOW', 10),
        'min_samples' => (int) env('DPLY_EDGE_DURATION_REGRESSION_MIN_SAMPLES', 5),
        'multiplier' => (float) env('DPLY_EDGE_DURATION_REGRESSION_MULTIPLIER', 1.5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    | Per-site override lives in sites.releases_to_keep (1..50). This value is
    | the fallback when a site hasn't set its own. Pruned deployments lose
    | their R2 artifacts but stay listed (with pruned_at set) for audit.
    */
    'retention' => [
        'default_keep' => (int) env('DPLY_EDGE_RETENTION_KEEP', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Origin healthcheck (hybrid sites)
    |--------------------------------------------------------------------------
    | Runs before flipping KV to point at a new deployment. Failing checks
    | mark the deployment FAILED so an unhealthy origin never receives
    | Worker-proxied traffic. See OriginHealthcheckRunner.
    */
    'origin_healthcheck' => [
        'timeout_seconds' => 10,
        'retries' => 3,
        'retry_wait_ms' => 1500,
    ],

    /*
    | Edge delivery hostnames — from config/product/testing_domains.php.
    */
    'testing_domains' => (require __DIR__.'/testing_domains.php')['edge'] ?? ['on-dply.live'],

    /*
    | DNS target for Edge delivery hostnames on DO-managed on-dply zones when
    | the zone is not the Cloudflare Worker zone. IP → A record; hostname → CNAME.
    | When unset, subdomains CNAME onto the zone apex.
    */
    'testing_dns_target' => env('DPLY_EDGE_TESTING_DNS_TARGET'),

    /*
    |--------------------------------------------------------------------------
    | Custom Hostnames (SSL for SaaS) — Phase 3b
    |--------------------------------------------------------------------------
    | When enabled, managed `dply_edge` sites register attached custom domains
    | via Cloudflare Custom Hostnames on the worker zone so TLS is issued for
    | customer hostnames that CNAME to the Edge delivery hostname. BYO
    | `org_cloudflare` sites keep customer-zone TLS (orange cloud) and skip
    | this path. Requires Custom Hostnames entitlement + API token permission
    | "SSL and Certificates → Custom Hostnames → Edit" on the worker zone.
    */
    /*
     * dply-run DNS: a customer's domain becomes a zone in dply's Cloudflare
     * account and they point its nameservers at us. `nameserver_set` is a
     * Cloudflare account custom nameserver set (1-5) such as ns1/ns2.dply.io —
     * a Business (via support) or Enterprise feature, created in the account
     * with glue records first. Unset: Cloudflare's assigned pair is shown.
     */
    'dns' => [
        // Off until the platform token has account-level Zone:Edit: turning
        // it on creates real zones in dply's Cloudflare account.
        'enabled' => filter_var(env('DPLY_EDGE_DNS_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'nameserver_set' => env('DPLY_EDGE_DNS_NS_SET') !== null ? (int) env('DPLY_EDGE_DNS_NS_SET') : null,
        // Shown in fake mode, and as the expected names in the UI copy.
        'fake_nameservers' => ['ns1.dply.io', 'ns2.dply.io'],
        // A pending zone proves nothing; drop ones never switched over.
        'pending_days' => (int) env('DPLY_EDGE_DNS_PENDING_DAYS', 14),
    ],

    'custom_hostnames' => [
        'enabled' => filter_var(env('DPLY_EDGE_CUSTOM_HOSTNAMES', true), FILTER_VALIDATE_BOOLEAN),
        // DV method: http (default), txt, or email.
        'ssl_method' => env('DPLY_EDGE_CUSTOM_HOSTNAME_SSL_METHOD', 'http'),
        // Optional override for CF custom_origin_server / CNAME target shown
        // in the UI. When empty, sites keep CNAME → {slug}.{on-dply apex}.
        'fallback_origin' => env('DPLY_EDGE_CUSTOM_HOSTNAME_FALLBACK_ORIGIN'),
    ],

    'default_backend' => 'dply_edge',

    /*
    | Edge delivery backends. Platform `dply_edge` is default; `org_cloudflare` uses
    | an org-linked Cloudflare credential bootstrapped via dply:edge:bootstrap-org.
    */
    'backends' => [
        'dply_edge' => [
            'label' => 'Dply Edge (managed)',
        ],
        'org_cloudflare' => [
            'label' => 'Your connected account',
        ],
    ],

    /*
    | Laravel filesystem disk name for Edge R2 uploads.
    | Registered in AppServiceProvider when bucket credentials are present.
    */
    'disk' => [
        'name' => 'edge_r2',
    ],

    /*
    |--------------------------------------------------------------------------
    | Usage guardrail
    |--------------------------------------------------------------------------
    | Soft cap on requests + egress per calendar month, per site. Evaluator
    | reads EdgeUsageSnapshot rows and computes a state (ok / warn / over).
    | Transitions fan out via the `edge.usage.over_budget` notification key
    | (already declared in config/notification_events.php). v1 does NOT
    | actually pause traffic at the Worker — it surfaces a banner and an
    | optional notification so flat-rate sites can't silently bleed margin.
    */
    'guardrail' => [
        'requests_per_month' => (int) env('DPLY_EDGE_GUARDRAIL_REQUESTS', 1_000_000),
        'bytes_per_month' => (int) env('DPLY_EDGE_GUARDRAIL_BYTES', 50 * 1024 * 1024 * 1024),
        'warn_at_percent' => max(1, min(99, (int) env('DPLY_EDGE_GUARDRAIL_WARN_PCT', 80))),
        // Reserved for a future cut — when true, sites in `over` state get
        // their deploy button disabled. Not consulted by the v1 evaluator.
        'auto_pause' => filter_var(env('DPLY_EDGE_GUARDRAIL_AUTO_PAUSE', false), FILTER_VALIDATE_BOOLEAN),
    ],

    /*
    | Preview review hub — approve-to-promote workflow on Edge previews.
    */
    /*
    | dply's own Valkey (packages/valkey-gateway, T-021). When api_url and
    | token are set, "Create new" Redis on the Resources page starts one of
    | these.
    */
    // Local testing only: start paid resources without a card. Ignored
    // unless APP_ENV=local (Resources::cardOnFile).
    'skip_card_check' => (bool) env('DPLY_EDGE_SKIP_CARD_CHECK', false),

    /*
    | Messages: the HTTP message queue (packages/messages-worker),
    | behind the `resource-messages` flag. `url` is what apps call (MESSAGES_URL);
    | dply manages organizations and tokens with `operator_token`. Empty url =
    | not deployed, and the page says so.
    */
    'messages' => [
        'url' => rtrim((string) env('DPLY_MESSAGES_URL', ''), '/'),
        'operator_token' => env('DPLY_MESSAGES_OPERATOR_TOKEN'),
    ],

    /*
    | Realtime: the customer relay (packages/realtime-worker, `--env apps`).
    | dply provisions an app by writing its credentials into the relay's KV
    | namespace; it never re-deploys the Worker. See docs/edge-realtime.md.
    */
    'realtime' => [
        'host' => env('EDGE_REALTIME_HOST', 'realtime-apps.on-dply.site'),
        'worker' => env('EDGE_REALTIME_WORKER', 'dply-realtime-apps'),
        'kv_namespace_id' => env('EDGE_REALTIME_KV_NAMESPACE_ID'),
        'default_max_connections' => (int) env('EDGE_REALTIME_DEFAULT_MAX_CONNECTIONS', 200),
        'max_message_bytes' => (int) env('EDGE_REALTIME_MAX_MESSAGE_BYTES', 10240),
        // Sockets per relay hub (Durable Object). An app's KV record gets
        // shards = ceil(max_connections / shard_size); 1 shard = one hub.
        'shard_size' => (int) env('EDGE_REALTIME_SHARD_SIZE', 10000),
        // Every app gets {label}.{app_host_suffix} (stored at provision, written
        // to KV). per_app_hosts decides whether the app's env and the sheet use
        // it; off, everything stays on the shared host above.
        'app_host_suffix' => env('EDGE_REALTIME_APP_HOST_SUFFIX', 'realtime.dply.io'),
        'per_app_hosts' => (bool) env('EDGE_REALTIME_PER_APP_HOSTS', false),
        // Each app's hostname is attached to the relay Worker as a Workers
        // Custom Domain (Cloudflare issues its certificate; no wildcard cert or
        // ACM). zone_id is the zone the suffix lives in (dply.io).
        'custom_domains' => (bool) env('EDGE_REALTIME_CUSTOM_DOMAINS', false),
        'zone_id' => env('EDGE_REALTIME_ZONE_ID'),
        // dply:edge:check-realtime: a round trip every minute through the shared
        // host, alerting platform admins. Runs only when kv_namespace_id is set.
        'monitor' => [
            'enabled' => (bool) env('EDGE_REALTIME_MONITOR_ENABLED', true),
            // Node >= 22 (global WebSocket); cron's PATH may lack it, so set an absolute path there.
            'node' => env('EDGE_REALTIME_MONITOR_NODE', 'node'),
        ],
    ],

    'valkey' => [
        'api_url' => env('DPLY_VALKEY_API_URL'),
        'token' => env('DPLY_VALKEY_TOKEN'),
        'domain' => env('DPLY_VALKEY_DOMAIN', 'cache.dply.local'),
        // dply databases answer on {id}.{db_domain} (5432 / 3306 / 27017; the gateway's DB_DOMAIN).
        'db_domain' => env('DPLY_VALKEY_DB_DOMAIN', 'db.dply.local'),
        'port' => (int) env('DPLY_VALKEY_PORT', 6380),
        // Cloudflare region nearest the gateway's cluster (DOKS nyc3). Container
        // apps using a dply database or Valkey run here unless they pick a region.
        'data_region' => env('DPLY_EDGE_DATA_REGION', 'ENAM'),
        // One gateway cluster per region (ValkeyRegions). The first is the
        // original single-region setup; add more with DPLY_VALKEY_REGIONS, a
        // JSON list of {key, label, cloudflare, api_url, token, domain, db_domain}.
        'regions' => array_values(array_filter([
            [
                'key' => env('DPLY_VALKEY_REGION', 'nyc3'),
                'label' => env('DPLY_VALKEY_REGION_LABEL', 'New York'),
                'cloudflare' => env('DPLY_EDGE_DATA_REGION', 'ENAM'),
                'api_url' => env('DPLY_VALKEY_API_URL'),
                'token' => env('DPLY_VALKEY_TOKEN'),
                'domain' => env('DPLY_VALKEY_DOMAIN', 'cache.dply.local'),
                'db_domain' => env('DPLY_VALKEY_DB_DOMAIN', 'db.dply.local'),
                'port' => (int) env('DPLY_VALKEY_PORT', 6380),
                // Redis REST (valkey-gateway rest.go): https://{id}.{domain}:{rest_port}
                'rest_port' => (int) env('DPLY_VALKEY_REST_PORT', 8443),
            ],
            ...(array) (json_decode((string) env('DPLY_VALKEY_REGIONS', '[]'), true) ?: []),
        ], 'is_array')),
    ],

    /*
    | Preview review hub — approve-to-promote workflow on Edge previews.
    */
    'preview_review' => [
        'min_approvals' => max(1, (int) env('DPLY_EDGE_PREVIEW_REVIEW_MIN_APPROVALS', 1)),
        'require_approval' => filter_var(env('DPLY_EDGE_PREVIEW_REVIEW_REQUIRE_APPROVAL', false), FILTER_VALIDATE_BOOLEAN),
        'block_open_comments' => filter_var(env('DPLY_EDGE_PREVIEW_REVIEW_BLOCK_OPEN_COMMENTS', true), FILTER_VALIDATE_BOOLEAN),
    ],

    /*
    | dply hosts itself: the control plane is a container app on its own
    | platform (docs/self-hosting-runbook.md). dply:self:register makes it a
    | normal Site; dply:self:deploy is the break-glass deploy that works while
    | the control plane is down. The site id is also kept in the R2 state
    | object, so setting it here only overrides that.
    */
    'self' => [
        'site_id' => env('DPLY_SELF_SITE_ID'),
        'organization_id' => env('DPLY_SELF_ORGANIZATION_ID'),
        'owner_email' => env('DPLY_SELF_OWNER_EMAIL'),
        'name' => env('DPLY_SELF_NAME', 'dply'),
        'repo' => env('DPLY_SELF_REPO', 'shaferllc/dply-edge'),
        'branch' => env('DPLY_SELF_BRANCH', 'main'),
        'hostname' => env('DPLY_SELF_HOSTNAME', 'edge.dply.io'),
        'health_path' => env('DPLY_SELF_HEALTH_PATH', '/up'),
        // Deploy history + the last Worker project, in the edge R2 bucket.
        'state_key' => env('DPLY_SELF_STATE_KEY', '_dply-self/state.json'),
    ],

];
