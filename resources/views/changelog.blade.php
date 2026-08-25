<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <x-seo-meta
        title="Changelog"
        description="What's new in dply — new features, improvements, and fixes shipped to the platform." />
    <meta name="csrf-token" content="{{ csrf_token() }}">

    @include('partials.theme-head')

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=space-grotesk:400,500,700|space-mono:400,700&display=swap" rel="stylesheet" />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
    <style>[x-cloak] { display: none !important; }</style>
</head>
<body class="bg-edge-void font-display text-edge-text antialiased">
    <div class="fixed inset-0 -z-20 bg-edge-void"></div>
    <div class="fixed inset-0 -z-10 bg-mesh-brand"></div>

    <x-edge-marketing-header active="changelog" />

    <main>
        {{-- Hero + entries are rendered after the data/computation block below. --}}
        {{--
            HOW TO ADD AN ENTRY
            Copy a block below and paste it at the top of the $entries array.
            Each entry:
              'date'    => display date string, e.g. 'June 5, 2026'
              'tags'    => subset of ['new', 'improved', 'fixed', 'security']
              'title'   => short headline
              'summary' => one-sentence description
              'items'   => array of bullet strings (empty array for no bullets)
        --}}
        @php
            $entries = [
                [
                    'date'    => 'July 27, 2026',
                    'tags'    => ['improved'],
                    'title'   => 'Unified Workspace Chrome',
                    'summary' => 'Site, server, Fleet, organization, and profile pages now share one merged card layout — sand identity header, flush tabs, and hairline sections instead of stacked floating heroes.',
                    'items'   => [],
                ],
                [
                    'date'    => 'July 27, 2026',
                    'tags'    => ['improved'],
                    'title'   => 'Marketing Pages Refresh',
                    'summary' => 'Changelog, Features, and Roadmap use the same quieter feed/board chrome so public product pages match the in-app workspace look.',
                    'items'   => [],
                ],
                [
                    'date'    => 'July 27, 2026',
                    'tags'    => ['fixed'],
                    'title'   => 'Follow Directory Symlinks In Files',
                    'summary' => 'Site and server Files now treat directory symlinks (like current → releases/…) as folders you can Follow into, instead of offering View/Edit/Download on the link itself.',
                    'items'   => [],
                ],
                [
                    'date'    => 'July 10, 2026',
                    'tags'    => ['new'],
                    'title'   => 'Edge Full-Stack App Object',
                    'summary' => 'Edge deploys can declare bindings.dply and gate promotes on backend health so hybrid stacks stay honest about origin readiness.',
                    'items'   => [],
                ],
                [
                    'date'    => 'July 9, 2026',
                    'tags'    => ['new'],
                    'title'   => 'Edge Bindings Dashboard',
                    'summary' => 'Attach, create, and detach KV, R2, and D1 bindings for Edge sites from an interactive Bindings tab — wrangler.toml stays authoritative, dashboard rows are additive.',
                    'items'   => [],
                ],
                [
                    'date'    => 'July 9, 2026',
                    'tags'    => ['new'],
                    'title'   => 'Edge Deploy Duration Alerts',
                    'summary' => 'Edge can alert when a deploy takes unusually long, and deploy durations no longer always read as zero.',
                    'items'   => [],
                ],
                [
                    'date'    => 'July 7, 2026',
                    'tags'    => ['improved'],
                    'title'   => 'CLI Site Environment Writes',
                    'summary' => 'Device-flow login can grant sites.write so the dply CLI can manage site environment variables, and large --json responses no longer truncate on exit.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 14, 2026',
                    'tags'    => ['new'],
                    'title'   => 'One-Click Redis Setup',
                    'summary' => 'Connecting Redis to a site can now wire cache, sessions, and queue in one step and auto-installs Redis when none is available.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 13, 2026',
                    'tags'    => ['improved'],
                    'title'   => 'Consistent Workspace Headers',
                    'summary' => 'Page headers are now consistent across the server, project, and site workspaces, all rendered from a single shared hero card.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 12, 2026',
                    'tags'    => ['new'],
                    'title'   => 'One-Click Quick Downloads',
                    'summary' => 'Backups and site files can now be pulled down with a one-click quick download: dply builds the artifact on the server, stages it to a short-lived download bucket, and notifies you in-app and by email when it\'s ready, then streams it once over a signed, authenticated link and deletes it.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 12, 2026',
                    'tags'    => ['new'],
                    'title'   => 'Sync Environment To Worker Pool',
                    'summary' => 'Site environment variables can now be synced to the attached worker-pool members in one action, keeping app and worker boxes from drifting out of sync.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 11, 2026',
                    'tags'    => ['new'],
                    'title'   => 'Site Worker Fleet',
                    'summary' => 'Sites can attach worker-pool servers as a dedicated worker fleet, managed from the site settings page.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 11, 2026',
                    'tags'    => ['fixed'],
                    'title'   => 'Reliable Live Server Metrics',
                    'summary' => 'The server Monitor\'s live CPU, memory, and disk samples no longer go stale: the metrics agent now sends an explicit User-Agent so its uploads are no longer blocked by the edge firewall.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 11, 2026',
                    'tags'    => ['improved'],
                    'title'   => 'Richer Server Certificate Scan',
                    'summary' => 'The live server certificate scan now reports richer per-site details and clearer status on the server overview.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 10, 2026',
                    'tags'    => ['new'],
                    'title'   => 'Multi-Backend Sites With Rolling And Canary Deploys',
                    'summary' => 'Sites can now run across multiple load-balanced web backends, unlocking rolling and canary deployment methods with per-target traffic weighting and draining.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 9, 2026',
                    'tags'    => ['improved'],
                    'title'   => 'Redesigned Project Workspace Page',
                    'summary' => 'The project page is now organized into dedicated Overview, Resources, Access, Operations, and Delivery sections within a unified workspace layout.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 9, 2026',
                    'tags'    => ['new'],
                    'title'   => 'Local Claude CLI Provider Support',
                    'summary' => 'AI features can now route completions through the local `claude` CLI by setting the provider to "claude", requiring no API key.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 9, 2026',
                    'tags'    => ['new'],
                    'title'   => 'Local Claude CLI Provider Support',
                    'summary' => 'AI features can now route completions through the local `claude` CLI by setting the provider to "claude", requiring no API key.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 9, 2026',
                    'tags'    => ['new'],
                    'title'   => 'Deploy Several Sites Together',
                    'summary' => 'A new Sync tab on the site deployments page lets you select and deploy multiple related sites—such as a main site and its worker—together in one action.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 9, 2026',
                    'tags'    => ['new'],
                    'title'   => 'CPU Usage Diagnostics On Server Overview',
                    'summary' => 'Server overview now explains why CPU is busy by listing the top processes with plain-language remediation hints when CPU is elevated.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 9, 2026',
                    'tags'    => ['new'],
                    'title'   => 'PHP-FPM Pool Tuning',
                    'summary' => 'Sites now expose configurable PHP-FPM pool settings and a panel to compare environment variables against worker pool members.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 9, 2026',
                    'tags'    => ['new'],
                    'title'   => 'More PHP Runtime Settings',
                    'summary' => 'Site PHP settings now expose post max size, input time, input vars, file uploads, and timezone, which are applied to the live server\'s FastCGI config automatically.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 8, 2026',
                    'tags'    => ['new'],
                    'title'   => 'Validate Resource Reachability',
                    'summary' => 'Adds a "Validate reachability" action that probes every networked site resource binding from the site\'s server and badges each one on the Resources map.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 8, 2026',
                    'tags'    => ['fixed'],
                    'title'   => 'Resource Map Connector Alignment',
                    'summary' => 'Resource map connector lines now align correctly with nodes when the topology graph is zoomed on narrow screens.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 8, 2026',
                    'tags'    => ['new'],
                    'title'   => 'Site System User Management',
                    'summary' => 'VM-backed PHP sites can now assign the Linux account that owns their files and runs their PHP-FPM pool, with permissions resettable over SSH.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 8, 2026',
                    'tags'    => ['improved'],
                    'title'   => 'Encrypted Log Drain Transport',
                    'summary' => 'The dply Logs drain receiver now accepts app logs over TLS-terminated TCP instead of plaintext UDP, with configurable certificate, key, and passphrase.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 8, 2026',
                    'tags'    => ['new'],
                    'title'   => 'Site Database Management Tab',
                    'summary' => 'The site Database tab is now a full tabbed management surface for users, backups, and database events, with per-channel notification routing across site and server workspaces.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 8, 2026',
                    'tags'    => ['new'],
                    'title'   => 'Full Database Management Per Site',
                    'summary' => 'The site Database tab is now tabbed (Databases / Create / Notifications): manage users, rotate the primary password with a one-time credential link, back up on demand with download/delete, and drop a database — all queued over SSH with live output. A Notifications tab routes channels to database events, including a new alert when credentials are shared.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 8, 2026',
                    'tags'    => ['improved'],
                    'title'   => 'Per-Channel Notification Routing',
                    'summary' => 'The central site and server Notifications pages now route per channel — each channel is its own expandable row where you choose exactly which events it receives, so different events can go to different channels in one place. Saving only touches the channels shown (tick to add, untick to remove) and leaves others alone. You can also create a new channel inline without leaving the page.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 8, 2026',
                    'tags'    => ['new'],
                    'title'   => 'Central Server Notifications',
                    'summary' => 'A new Notifications tab on the server workspace routes notification channels to any of the server\'s events, grouped by category. It edits the same subscriptions as each feature\'s own Notifications tab, surfaces organization-wide outbound webhooks, and links to channel management.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 8, 2026',
                    'tags'    => ['new'],
                    'title'   => 'Site Error Notifications',
                    'summary' => 'Route a single site\'s error events to notification channels — from a new Notifications tab on the site Errors workspace, or from the existing site Notifications page (both edit the same subscriptions). Because a site failure also appears in its server\'s error roll-up, routing is deduped per channel and per in-app recipient — a subscriber wired to both the site and the server is alerted once.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 8, 2026',
                    'tags'    => ['new'],
                    'title'   => 'Server Error Notifications',
                    'summary' => 'Route a server\'s error events to notification channels from a new Notifications tab on the server Errors workspace, without firing alerts for historical backfilled failures.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 8, 2026',
                    'tags'    => ['improved'],
                    'title'   => 'Removed Several Cloud Providers',
                    'summary' => 'Support for Google Cloud, Scaleway, Equinix Metal, and Fly.io server providers has been removed.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 8, 2026',
                    'tags'    => ['new'],
                    'title'   => 'Server Snapshots Workspace',
                    'summary' => 'Servers gain a unified Snapshots workspace to capture full-disk provider images, take and restore site database snapshots, and manage cache (Redis/Valkey) snapshots from one place.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 8, 2026',
                    'tags'    => ['fixed'],
                    'title'   => 'Faster Config File Loading',
                    'summary' => 'Config files now load instantly in the editor via a direct SSH read instead of the queued worker round-trip that could fail to load or hang.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 7, 2026',
                    'tags'    => ['fixed'],
                    'title'   => 'Config Editor Loading Fix',
                    'summary' => 'The webserver config-file picker now loads via a background request instead of on every render, fixing intermittent poll errors and showing a "discovering files" state while the listing loads.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 7, 2026',
                    'tags'    => ['improved'],
                    'title'   => 'Server Logs HTTPS CA Verification',
                    'summary' => 'The Server Logs ClickHouse client now verifies HTTPS connections against the supplied private CA certificate, securing cross-provider log queries to the managed log store.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 7, 2026',
                    'tags'    => ['fixed'],
                    'title'   => 'Log Agent Install Reliability',
                    'summary' => 'Log agent installs no longer get stuck on "installing" and now report a clear failure when the server isn\'t a reachable VM host.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 7, 2026',
                    'tags'    => ['new'],
                    'title'   => 'Server Logs Add-On',
                    'summary' => 'Servers can now ship all host logs to a managed ClickHouse store via an installable Vector agent with a native log explorer, plus scheduler runs capture and retain their output history.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 7, 2026',
                    'tags'    => ['improved'],
                    'title'   => 'Background Supervisor Operations',
                    'summary' => 'Supervisor install, sync, and restart now run as background jobs with directory pre-flight checks, a worker backend status check, and paginated cron/daemon history.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 7, 2026',
                    'tags'    => ['fixed'],
                    'title'   => 'More Reliable Server Provisioning',
                    'summary' => 'Warm-pool servers that silently stall during provisioning are now detected and recovered automatically, so new servers finish setup reliably.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 7, 2026',
                    'tags'    => ['new'],
                    'title'   => 'Faster Managed Server Creation',
                    'summary' => 'Managed servers can now be claimed instantly from a pre-provisioned warm pool instead of waiting for a cold provision.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 6, 2026',
                    'tags'    => ['improved'],
                    'title'   => 'Faster Server Provisioning',
                    'summary' => 'Server provisioning can now download language runtimes in the background and prefetch stock packages in parallel to shorten setup time, and the server-removal flow no longer flashes a spurious 404 modal.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 6, 2026',
                    'tags'    => ['improved'],
                    'title'   => 'Faster Server Provisioning',
                    'summary' => 'Server provisioning jobs now run on a dedicated priority queue and MySQL readiness is detected faster, so new servers come online sooner.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 6, 2026',
                    'tags'    => ['improved'],
                    'title'   => 'Faster Server Provisioning',
                    'summary' => 'Servers can warm up apt packages at boot and defer certbot off the provisioning critical path, with too-small sizing now a warning instead of a hard block.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 6, 2026',
                    'tags'    => ['new'],
                    'title'   => 'Faster Hetzner Server Provisioning',
                    'summary' => 'New Hetzner servers can launch from a pre-baked base image, cutting provisioning time, with refreshed loading states across the server workspace.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 6, 2026',
                    'tags'    => ['improved'],
                    'title'   => 'Faster Server Provisioning',
                    'summary' => 'New servers launch from region-scoped baked snapshots when available and poll for their IP address faster, cutting provisioning time.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 6, 2026',
                    'tags'    => ['new'],
                    'title'   => 'Automatic Recovery For Stalled Provisions',
                    'summary' => 'Wedged server provisions now surface and recover automatically when a remote task goes silent, and machine callbacks keep working during maintenance windows.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 6, 2026',
                    'tags'    => ['fixed'],
                    'title'   => 'Reliable Server Provisioning',
                    'summary' => 'Server provisioning no longer stalls when machine callbacks hit the coming-soon gate or when an optional PHP extension is unavailable in the configured apt repositories.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 6, 2026',
                    'tags'    => ['improved'],
                    'title'   => 'Unified Button Styling
Unified Button And Binding UI',
                    'summary' => 'Standardized button and icon styling across the dashboard and added a site binding catalog powering site settings navigation.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 6, 2026',
                    'tags'    => ['improved'],
                    'title'   => 'Smarter Buttons And Driver Guards',
                    'summary' => 'Button components now render as links when given an href, and attaching a redis-driver queue, cache, or session binding now requires a Redis resource first.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 6, 2026',
                    'tags'    => ['improved'],
                    'title'   => 'Standardize Server Action Buttons',
                    'summary' => 'Server schedule and services screens now use shared button components for consistent styling across actions.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 6, 2026',
                    'tags'    => ['new'],
                    'title'   => 'Realtime Worker Observability',
                    'summary' => 'The realtime broadcasting relay now records connection, subscription, and publish events with per-message delivery counts for easier monitoring.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 6, 2026',
                    'tags'    => ['new'],
                    'title'   => 'Live App Log Streaming',
                    'summary' => 'Site logs can now stream live into an in-app App Logs panel via the dply Realtime drain, with one-command Cloudflare relay setup.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 6, 2026',
                    'tags'    => ['new'],
                    'title'   => 'Custom Site Logging Configuration',
                    'summary' => 'Sites can now define their complete logging setup—channels, default, stack, and deprecations—which dply generates and owns in config/logging.php on the next deploy.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 6, 2026',
                    'tags'    => ['new'],
                    'title'   => 'Mail And Log Drain Bindings',
                    'summary' => 'Sites can now configure mail and log drain resources with per-provider credentials and server-side test email delivery, alongside tiered realtime apps.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 6, 2026',
                    'tags'    => ['improved'],
                    'title'   => 'Streamline Site Environment Settings',
                    'summary' => 'The site environment settings page has been reorganized internally for faster loading and easier maintenance, with no change to available options.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 6, 2026',
                    'tags'    => ['new'],
                    'title'   => 'Object Storage Provider Presets',
                    'summary' => 'Connecting object storage to a site now offers AWS S3, DigitalOcean Spaces, and Hetzner provider presets with region pickers that auto-derive the endpoint, plus a custom S3 option.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 6, 2026',
                    'tags'    => ['new'],
                    'title'   => 'Site Cache Store Binding',
                    'summary' => 'You can now bind a cache store (database, redis, file, or array) to a site, and freshly provisioned servers ship the phpredis, GD, sodium, GMP, APCu, igbinary, and SQLite PHP extensions out of the box.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 6, 2026',
                    'tags'    => ['new'],
                    'title'   => 'Expose Raw Server Errors',
                    'summary' => 'Operators can now temporarily bypass the branded error page to surface real 5xx errors when debugging a failing site.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 6, 2026',
                    'tags'    => ['new'],
                    'title'   => 'Pipeline Scan Loading State',
                    'summary' => 'The deploy panel now shows a "scanning the repo" placeholder while a pipeline suggestion scan is running instead of flashing stale suggestions.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 6, 2026',
                    'tags'    => ['fixed'],
                    'title'   => 'Pipeline Optimize Feedback Fix',
                    'summary' => 'The "Optimize pipeline" action now clears the pipeline-check warning once steps are added and shows the proposed-changes preview when the repo scan finishes, instead of appearing to do nothing.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 6, 2026',
                    'tags'    => ['fixed'],
                    'title'   => 'Self-Healing Multi-Tool Deploy Steps',
                    'summary' => 'Deploy steps that run both Composer and npm now reliably find both tools instead of failing with "npm: command not found".',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 6, 2026',
                    'tags'    => ['fixed'],
                    'title'   => 'Pipeline Sub-Tab Deep Links',
                    'summary' => 'Direct links with a pipeline_tab query parameter now open the correct pipeline sub-tab when the deploy pipeline is embedded in another page.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 6, 2026',
                    'tags'    => ['fixed'],
                    'title'   => 'Reliable Env File Ownership',
                    'summary' => 'Fixed environment file pushes failing to set correct ownership due to shell quoting that corrupted the chown user argument.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 6, 2026',
                    'tags'    => ['fixed'],
                    'title'   => 'Reliable Old Release Cleanup',
                    'summary' => 'Deploys no longer fail to prune old releases when root-owned files (from certbot or managed error pages) are present.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 6, 2026',
                    'tags'    => ['fixed'],
                    'title'   => 'HTTPS Private Repo Deploy Auth',
                    'summary' => 'Deploys of private HTTPS repositories now authenticate correctly on re-deploy by passing the token-injected URL directly to git instead of relying on a stored remote, while keeping credentials out of the server\'s git config.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 6, 2026',
                    'tags'    => ['fixed'],
                    'title'   => 'HTTPS Repo Clone Authentication',
                    'summary' => 'HTTPS repository clones now authenticate correctly even when the git provider isn\'t explicitly set, by detecting it from the repository URL, and env files are written with the correct site-user ownership.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 6, 2026',
                    'tags'    => ['fixed'],
                    'title'   => 'Private HTTPS Repo Deploys',
                    'summary' => 'Deploys from private HTTPS repositories now authenticate automatically using your stored Git provider token, with tokens redacted from deploy logs.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 6, 2026',
                    'tags'    => ['improved'],
                    'title'   => 'Richer Deploy Diagnostics',
                    'summary' => 'Deploy logs now include detailed pre-clone, post-clone, and phase-probe snapshots, plus a "Scan for required variables" action in site environment settings.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 6, 2026',
                    'tags'    => ['fixed'],
                    'title'   => 'Cleaner Server Error Badge',
                    'summary' => 'The open-error count badge no longer appears on the server Errors tab while it is still a coming-soon preview.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 6, 2026',
                    'tags'    => ['fixed'],
                    'title'   => 'Refresh Feature Flags On Deploy',
                    'summary' => 'Feature flag values are now cleared on each deploy so flag changes take effect immediately after release.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 6, 2026',
                    'tags'    => ['improved'],
                    'title'   => 'Console Preview Teaser',
                    'summary' => 'The browser-based server console is now shown as a coming-soon preview while the full console feature is gated off.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 6, 2026',
                    'tags'    => ['improved'],
                    'title'   => 'Site CLI Coming Soon Preview',
                    'summary' => 'The site CLI settings tab now shows a coming-soon preview of upcoming terminal commands when the feature is not yet available for your server.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 6, 2026',
                    'tags'    => ['new'],
                    'title'   => 'In-Browser CLI Console',
                    'summary' => 'Site settings now include an in-browser CLI console for running dply commands against your site with quick-run shortcuts for common operations.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 6, 2026',
                    'tags'    => ['new'],
                    'title'   => 'Site Database Management',
                    'summary' => 'You can now create and link a database directly from a VM site\'s page, manage workers, schedules, and basic auth via a new site resource API.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 5, 2026',
                    'tags'    => ['fixed'],
                    'title'   => 'Reliable Worker Restarts On Deploy',
                    'summary' => 'Worker deploys now restart dply-managed systemd Horizon and scheduler units on each release swap so daemons no longer run stale code, with legacy supervisor restarts kept as a fallback.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 5, 2026',
                    'tags'    => ['fixed'],
                    'title'   => 'Deploy Clone Auth Fix',
                    'summary' => 'Deployments now clone the bare repository using the server\'s own authenticated remote URL, avoiding failures when the local remote uses an SSH URL the server can\'t access.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 5, 2026',
                    'tags'    => ['improved'],
                    'title'   => 'Atomic Release Deploys',
                    'summary' => 'Deploys now build immutable releases and flip an atomic current symlink across web and worker hosts, preventing long-running workers from serving stale code and breaking queued-job deserialization.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 5, 2026',
                    'tags'    => ['fixed'],
                    'title'   => 'Fix Site Setup Console Rendering',
                    'summary' => 'Corrected a Blade templating error that could prevent the pre-flight job console from rendering during site setup.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 5, 2026',
                    'tags'    => ['fixed'],
                    'title'   => 'Faster Repository Identity Lookups',
                    'summary' => 'Git provider identity lookups are now memoized per request, reducing duplicate database queries when rendering site source-control views.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 5, 2026',
                    'tags'    => ['fixed'],
                    'title'   => 'Prevent Repo Page Crashes',
                    'summary' => 'Repository and commit listings no longer error out when a stored Git token can\'t be decrypted, and duplicate identity lookups during a page render are now cached.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 5, 2026',
                    'tags'    => ['fixed'],
                    'title'   => 'Fix Setup Tab Redirect Crash',
                    'summary' => 'Fixed a crash when loading a site with a stale setup tab link after first-deploy setup had already completed.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 5, 2026',
                    'tags'    => ['new'],
                    'title'   => 'Live Setup Job Console',
                    'summary' => 'The site setup wizard now shows a live console streaming the pre-flight job\'s progress and the exact reason it stalls or fails.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 5, 2026',
                    'tags'    => ['improved'],
                    'title'   => 'Keyboard-Navigable Repository Picker',
                    'summary' => 'The repository picker now supports arrow-key navigation and Enter-to-select, and the ⌘K command palette is available on marketing pages while signed in.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 5, 2026',
                    'tags'    => ['improved'],
                    'title'   => 'Unified Git Repository Picker',
                    'summary' => 'The Git repository picker now behaves consistently across the choose-app, custom-site create, and repository connection flows.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 5, 2026',
                    'tags'    => ['new'],
                    'title'   => 'Default Branch Fallback Notice',
                    'summary' => 'Repository commit views now show a dismissible notice when a missing configured branch falls back to the repo\'s default branch.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 5, 2026',
                    'tags'    => ['fixed'],
                    'title'   => 'Repo Commits Branch Fallback',
                    'summary' => 'Repository commit views now fall back to the repository\'s default branch with a notice when the configured branch no longer exists, instead of showing an error.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 5, 2026',
                    'tags'    => ['new'],
                    'title'   => 'Repository Commit Retry And Account',
                    'summary' => 'The repository commits view now shows a retry button when commits fail to load and displays which linked account answered the read, with a quick link to change it.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 5, 2026',
                    'tags'    => ['new'],
                    'title'   => 'Repository Read Account Visibility',
                    'summary' => 'The repository overview now shows which linked Git account answered each read and persists the account choice immediately so commits, branches, and files resolve to the selected identity.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 5, 2026',
                    'tags'    => ['improved'],
                    'title'   => 'Repository URL Field Styling',
                    'summary' => 'The repository URL input now uses the shared text input component for consistent styling.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 5, 2026',
                    'tags'    => ['improved'],
                    'title'   => 'Refined Repository Account Selector',
                    'summary' => 'The linked source-control account dropdown now uses the standard styled select component for a consistent appearance.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 5, 2026',
                    'tags'    => ['fixed'],
                    'title'   => 'Repository Panel Retry Button',
                    'summary' => 'Repository commits and README error states now offer a Retry button to re-fetch the data without reloading the page.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 5, 2026',
                    'tags'    => ['fixed'],
                    'title'   => 'Recover From Stalled Setup Scans',
                    'summary' => 'A site setup pre-flight scan that stalls now shows a manual re-scan button so you can unstick the wizard and proceed to deploy.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 5, 2026',
                    'tags'    => ['fixed'],
                    'title'   => 'Stale Job Crash Prevention',
                    'summary' => 'Server remote-access tracking no longer errors when a stale release leaves a queued job\'s command class unresolved.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 5, 2026',
                    'tags'    => ['fixed'],
                    'title'   => 'Reliable Supervisor Program Installs',
                    'summary' => 'Supervisor program configs now install correctly under non-root SSH users and resolve their working directory from the attached site, preventing silent install failures and stale imported paths.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 5, 2026',
                    'tags'    => ['improved'],
                    'title'   => 'Clone Server From Configuration Tab',
                    'summary' => 'The clone-server action is now available on both the Manage and Configuration workspaces, and single-daemon re-sync reports whether the program actually started running or failed with a reason.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 5, 2026',
                    'tags'    => ['new'],
                    'title'   => 'Cron Presets And Daemon Re-Sync',
                    'summary' => 'Cron jobs gain a one-click library of common Laravel artisan and generic command presets, and daemon programs missing from Supervisor can now be re-registered on the server with a new Sync action.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 5, 2026',
                    'tags'    => ['new'],
                    'title'   => 'Server CLI Tab And Firewall Bundle Removal',
                    'summary' => 'Adds a CLI tab for installing and managing servers from your terminal, and lets you remove bundled firewall templates with per-rule status while always preserving the SSH lifeline rule.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 5, 2026',
                    'tags'    => ['new'],
                    'title'   => 'Refresh Server Private IP',
                    'summary' => 'You can now re-query DigitalOcean and Hetzner for a server\'s private IP from the connection settings, with live certificate scans now timing out gracefully instead of spinning forever.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 5, 2026',
                    'tags'    => ['new'],
                    'title'   => 'Search And Filter Sites',
                    'summary' => 'The sites list now supports search, status filtering, sorting, and a summary stat strip, with dashboard cards linking through to servers and fleet health.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 5, 2026',
                    'tags'    => ['improved'],
                    'title'   => 'Refreshed Realtime Coming-Soon Panel',
                    'summary' => 'The Realtime coming-soon page now shows a richer preview with a terminal demo and feature highlights, and the Deploy sync entry is hidden from navigation.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 5, 2026',
                    'tags'    => ['new'],
                    'title'   => 'Per-Service Pulse Server Cards',
                    'summary' => 'The Pulse dashboard now shows dedicated cards for Redis, database, and worker servers with live CPU, memory, and disk metrics, including those infrastructure hosts that don\'t run the dply app.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 5, 2026',
                    'tags'    => ['fixed'],
                    'title'   => 'Backups Empty-State Copy Fix',
                    'summary' => 'Fixed a grammatical typo in the empty-state message shown when no database backup schedules exist.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 5, 2026',
                    'tags'    => ['new'],
                    'title'   => 'Realtime Coming Soon Preview',
                    'summary' => 'The Realtime page now shows a preview of the managed Pusher-compatible WebSocket relay when the feature isn\'t yet enabled for your organization.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 5, 2026',
                    'tags'    => ['improved'],
                    'title'   => 'Drop Reverb Health Check Link',
                    'summary' => 'Removed the unused Reverb health check link from the admin Operations dashboard.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 5, 2026',
                    'tags'    => ['new'],
                    'title'   => 'Backups Dashboard And Controls',
                    'summary' => 'The Backups page now shows backup health metrics, schedules you can pause or run on demand, recent runs, and storage destinations when the feature is enabled.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 5, 2026',
                    'tags'    => ['improved'],
                    'title'   => 'Backups Marked Coming Soon',
                    'summary' => 'The Backups section now appears under the main Browse menu as a coming-soon feature, and server-side broadcast events are correctly proxied to Reverb over the site\'s vhost.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 5, 2026',
                    'tags'    => ['new'],
                    'title'   => 'Edge & Cloud Hosting Features',
                    'summary' => 'The features page now showcases Edge and Cloud hosting—container apps, serverless functions, managed realtime, and CDN storage—alongside the new PHP CLI and worker-pool details.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 5, 2026',
                    'tags'    => ['new'],
                    'title'   => 'Public Changelog Page Entries',
                    'summary' => 'Deploys now publish titled entries to the public changelog page alongside CHANGELOG.md.',
                    'items'   => [],
                ],
                [
                    'date'    => 'June 5, 2026',
                    'tags'    => ['new'],
                    'title'   => 'Changelog',
                    'summary' => 'This page.',
                    'items'   => [],
                ],
            ];

            $tagStyles = [
                'new'      => 'bg-edge-lime/10 text-edge-lime ring-1 ring-inset ring-edge-lime/30',
                'improved' => 'bg-sky-400/10 text-sky-300 ring-1 ring-inset ring-sky-400/30',
                'fixed'    => 'bg-amber-400/10 text-amber-300 ring-1 ring-inset ring-amber-400/30',
                'security' => 'bg-rose-500/100/10 text-rose-300 ring-1 ring-inset ring-rose-500/30',
            ];
            $tagLabels = [
                'new' => 'New', 'improved' => 'Improved', 'fixed' => 'Fixed', 'security' => 'Security',
            ];
            $tagDot = [
                'security' => 'bg-rose-400',
                'new'      => 'bg-edge-lime',
                'improved' => 'bg-sky-400',
                'fixed'    => 'bg-amber-400',
            ];
            $tagPriority = ['security', 'new', 'improved', 'fixed'];

            // Inline markdown: escape, then render `code` spans.
            $inline = function (string $s): string {
                return preg_replace(
                    '/`([^`]+)`/',
                    '<code class="rounded bg-edge-lime px-1.5 py-0.5 text-[0.85em] font-medium text-edge-lime">$1</code>',
                    e($s)
                );
            };

            // Group entries by month (input is already newest-first) and tally
            // per-tag counts for the live filter and month headers.
            $grandTotal   = count($entries);
            $globalCounts = ['new' => 0, 'improved' => 0, 'fixed' => 0, 'security' => 0];
            $months       = [];
            foreach ($entries as $entry) {
                $c   = \Illuminate\Support\Carbon::parse($entry['date']);
                $key = $c->format('Y-m');
                if (! isset($months[$key])) {
                    $months[$key] = [
                        'id'      => 'm-' . $key,
                        'label'   => $c->format('F Y'),
                        'short'   => $c->format('M Y'),
                        'entries' => [],
                        'total'   => 0,
                        'counts'  => ['new' => 0, 'improved' => 0, 'fixed' => 0, 'security' => 0],
                        'tags'    => [],
                    ];
                }
                $entry['day']     = $c->format('M j');
                $entry['iso']     = $c->toDateString();
                $entry['primary'] = collect($tagPriority)->first(fn ($t) => in_array($t, $entry['tags'], true));
                $months[$key]['entries'][] = $entry;
                $months[$key]['total']++;
                foreach ($entry['tags'] as $t) {
                    $globalCounts[$t]           = ($globalCounts[$t] ?? 0) + 1;
                    $months[$key]['counts'][$t] = ($months[$key]['counts'][$t] ?? 0) + 1;
                    $months[$key]['tags'][$t]   = true;
                }
            }

            $filterTags   = array_values(array_filter($tagPriority, fn ($t) => ($globalCounts[$t] ?? 0) > 0));
            $alpineTotals = json_encode(['all' => $grandTotal] + $globalCounts);
            $latestDay    = $entries[0]['date'] ?? null;
        @endphp

        <div x-data="{
            filter: 'all',
            totals: {{ $alpineTotals }},
            get visible() { return this.totals[this.filter] ?? 0; },
            has(tags) { return this.filter === 'all' || (tags !== '' && tags.split(',').includes(this.filter)); },
        }">
            <section class="px-4 py-12 pb-24 sm:px-6 sm:py-16 lg:px-8">
                <div class="mx-auto max-w-6xl">
                    {{-- One surface: sand identity + flush filters + hairline entries --}}
                    <div class="min-w-0 overflow-hidden border border-edge-line bg-edge-void">
                        <div class="border-b border-edge-line bg-edge-panel px-5 py-6 sm:px-6 sm:py-7">
                            <p class="text-xs font-semibold uppercase tracking-[0.16em] text-edge-lime">{{ config('app.name') }}</p>
                            <h1 class="mt-1.5 text-3xl font-bold tracking-tight text-edge-text sm:text-4xl">
                                {{ __('Changelog') }}
                            </h1>
                            <p class="mt-2 max-w-xl text-sm leading-relaxed text-edge-mute sm:text-base">
                                {{ __('What shipped — features, improvements, and fixes, newest first.') }}
                                @if ($latestDay)
                                    <span class="text-edge-faint">{{ __('Latest :date.', ['date' => $latestDay]) }}</span>
                                @endif
                            </p>
                        </div>

                        <div class="sticky top-16 z-10 border-b border-edge-line bg-edge-panel px-4 py-3 sm:px-5">
                            <div class="flex flex-wrap items-center gap-1.5" role="tablist" aria-label="{{ __('Filter updates') }}">
                                <button type="button" role="tab" @click="filter='all'"
                                    :aria-selected="(filter==='all').toString()"
                                    :class="filter==='all' ? 'bg-edge-lime text-edge-void ' : 'text-edge-mute hover:bg-edge-panel hover:text-edge-text'"
                                    class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold transition-colors">
                                    {{ __('All') }}
                                    <span class="tabular-nums opacity-70" x-text="totals.all"></span>
                                </button>
                                @foreach ($filterTags as $t)
                                    <button type="button" role="tab" @click="filter='{{ $t }}'"
                                        :aria-selected="(filter==='{{ $t }}').toString()"
                                        :class="filter==='{{ $t }}' ? 'bg-edge-lime text-edge-void ' : 'text-edge-mute hover:bg-edge-panel hover:text-edge-text'"
                                        class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold transition-colors">
                                        {{ $tagLabels[$t] }}
                                        <span class="tabular-nums opacity-70">{{ $globalCounts[$t] }}</span>
                                    </button>
                                @endforeach
                            </div>
                        </div>

                        <div x-show="visible === 0" x-cloak class="flex flex-col items-center justify-center px-5 py-16 text-center">
                            <span class="flex h-11 w-11 items-center justify-center bg-edge-panel text-edge-faint ring-1 ring-edge-line">
                                <x-heroicon-o-funnel class="h-5 w-5" aria-hidden="true" />
                            </span>
                            <p class="mt-4 text-sm font-semibold text-edge-text">{{ __('Nothing in this filter') }}</p>
                            <p class="mt-1 text-sm text-edge-mute">{{ __('Try All, or pick another tag.') }}</p>
                            <button type="button" @click="filter='all'" class="mt-4 text-xs font-semibold text-edge-lime hover:text-edge-text">{{ __('Show all updates') }}</button>
                        </div>

                        <div class="divide-y divide-edge-line">
                            @foreach ($months as $month)
                                <section id="{{ $month['id'] }}" class="scroll-mt-28"
                                         x-show="has('{{ implode(',', array_keys($month['tags'])) }}')">
                                    <div class="flex items-baseline justify-between gap-3 bg-edge-panel px-5 py-3 sm:px-6">
                                        <h2 class="text-sm font-semibold tracking-tight text-edge-text">{{ $month['label'] }}</h2>
                                        <span class="text-xs font-medium tabular-nums text-edge-faint"
                                              x-text="(filter==='all' ? {{ $month['total'] }} : ({{ json_encode($month['counts']) }}[filter] || 0)) + ' updates'"></span>
                                    </div>

                                    <ol class="divide-y divide-edge-line">
                                        @foreach ($month['entries'] as $entry)
                                            <li x-show="has('{{ implode(',', $entry['tags']) }}')"
                                                class="px-5 py-5 transition-colors hover:bg-edge-panel sm:px-6 sm:py-5">
                                                <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                                    <span class="inline-flex h-2 w-2 shrink-0 rounded-full {{ $tagDot[$entry['primary']] ?? 'bg-edge-mute' }}" aria-hidden="true"></span>
                                                    @foreach ($entry['tags'] as $tag)
                                                        <span class="inline-flex items-center px-2 py-0.5 text-2xs font-semibold uppercase tracking-wide {{ $tagStyles[$tag] ?? '' }}">
                                                            {{ $tagLabels[$tag] ?? $tag }}
                                                        </span>
                                                    @endforeach
                                                    <time datetime="{{ $entry['iso'] }}" class="text-xs font-medium tabular-nums text-edge-faint">
                                                        {{ $entry['day'] }}
                                                    </time>
                                                </div>
                                                <h3 class="mt-2 text-base font-semibold tracking-tight text-edge-text sm:text-lg">
                                                    {!! $inline($entry['title']) !!}
                                                </h3>
                                                <p class="mt-1.5 max-w-4xl text-sm leading-relaxed text-edge-mute">
                                                    {!! $inline($entry['summary']) !!}
                                                </p>
                                                @if (! empty($entry['items']))
                                                    <ul class="mt-3 space-y-1.5 border-l-2 border-edge-line pl-3">
                                                        @foreach ($entry['items'] as $item)
                                                            <li class="text-sm leading-relaxed text-edge-mute">{!! $item !!}</li>
                                                        @endforeach
                                                    </ul>
                                                @endif
                                            </li>
                                        @endforeach
                                    </ol>
                                </section>
                            @endforeach
                        </div>
                    </div>

                </div>
            </section>
        </div>

        {{-- CTA --}}
        <section class="border-t border-edge-line bg-edge-panel px-4 py-16 sm:px-6 lg:px-8">
            <div class="mx-auto max-w-2xl text-center">
                <h2 class="text-2xl font-bold tracking-tight text-edge-text">{{ __('Ship on infrastructure you control') }}</h2>
                <p class="mt-3 text-sm leading-relaxed text-edge-mute sm:text-base">{{ __('Try dply free — no credit card until you\'re ready to standardize.') }}</p>
                <div class="mt-7 flex flex-col items-center justify-center gap-3 sm:flex-row">
                    @auth
                        <a href="{{ route('dashboard') }}" class="inline-flex w-full items-center justify-center bg-edge-lime px-6 py-3 text-sm font-semibold text-edge-void transition-colors hover:bg-edge-lime/10 sm:w-auto">{{ __('Go to dashboard') }}</a>
                    @else
                        <a href="{{ route('register') }}" class="inline-flex w-full items-center justify-center bg-edge-lime px-6 py-3 text-sm font-semibold text-edge-void transition-colors hover:bg-edge-lime/10 sm:w-auto">{{ __('Start free trial') }}</a>
                    @endauth
                </div>
            </div>
        </section>
    </main>

    <x-edge-marketing-footer />
    @livewireScripts
</body>
</html>
