# Changelog

## [Unreleased]
### Added
- The app card on Overview counts down to sleep ("Sleeps in 7:32 without a request"), shows Asleep or Always awake, and shows how long the app has been awake and when it last got a request.
### Fixed
- Uptime checks no longer keep a container app awake: when the app's last request was the check itself, the next check waits so the app can reach its sleep timeout.
### Added
- Key-value stores: a container app's store host now pages every key (`?prefix=`, `?cursor=`, `&detail=1` for expiry and metadata), reads up to 100 keys in one `POST`, and takes `x-dply-expires-at`, `x-dply-metadata` and `x-dply-cache-ttl` headers.
- Key-value stores: the Keys tab shows each key's expiry and metadata, choosing a key loads its value for editing, and keys can be deleted by prefix in the background.
- Key-value stores can be read and written from outside the app with the API (`/api/v1/edge/kv`) and `dply kv`, limited to 60 calls a minute per organization.
### Fixed
- `dply/laravel` 1.1.0: `Cache::flush()` on a key-value store removes every key, not only the first 100. The store implements `touch()`, so it loads on Laravel 13. `increment()` and `decrement()` now throw instead of losing updates. `dply-rails` 0.2.0 does the same for `clear`, `read_multi` and counters.
- `dply db`, `dply queues`: flags such as `--json` were dropped before reaching the command.
### Fixed
- dply's own traffic no longer shows in Live requests or counts toward request and bandwidth totals. This covers container control calls (`/_dply/instances`, `/_dply/workers`, …), the `/__dply/` page-speed beacon, and uptime checks, which now send a `dply-uptime/1.0` user agent. Image requests through `/_dply/image` still count.
### Changed
- Scheduled tasks moved onto Overview. Add as many as you need from Add resource → Scheduled task, and they show as a Scheduled tasks box under the app on the map. That box opens the list, where you can edit a task or run it now. The separate Scheduler option is now the first choice inside Scheduled task for Laravel apps, and the Crons page redirects to Overview.
### Changed
- Site, server, Fleet, organization, and profile pages now share one merged card layout — sand identity header, flush tabs, and hairline sections instead of stacked floating heroes.
### Changed
- Changelog, Features, and Roadmap use the same quieter feed/board chrome so public product pages match the in-app workspace look.
### Fixed
- Site and server Files now treat directory symlinks (like current → releases/…) as folders you can Follow into, instead of offering View/Edit/Download on the link itself.
### Added
- Edge deploys can declare bindings.dply and gate promotes on backend health so hybrid stacks stay honest about origin readiness.
### Added
- Attach, create, and detach KV, R2, and D1 bindings for Edge sites from an interactive Bindings tab — wrangler.toml stays authoritative, dashboard rows are additive.
### Added
- Edge can alert when a deploy takes unusually long, and deploy durations no longer always read as zero.
### Changed
- Device-flow login can grant sites.write so the dply CLI can manage site environment variables, and large --json responses no longer truncate on exit.
### Added
- The browser console is now live. Run shell commands on any ready server from a floating drawer available on every page (backtick toggles it) or from the full Console tab in the server workspace, with quick actions, command autocomplete, rolling per-session history, and a per-command audit record. Deployers stay read-only.
### Added
- Connecting Redis to a site can now wire cache, sessions, and queue in one step and auto-installs Redis when none is available.
### Changed
- Page headers are now consistent across the server, project, and site workspaces, all rendered from a single shared hero card.
### Added
- Backups and site files can now be pulled down with a one-click quick download: dply builds the artifact on the server, stages it to a short-lived download bucket, and notifies you in-app and by email when it's ready, then streams it once over a signed, authenticated link and deletes it.
### Added
- Site environment variables can now be synced to the attached worker-pool members in one action, keeping app and worker boxes from drifting out of sync.
### Added
- Sites can attach worker-pool servers as a dedicated worker fleet, managed from the site settings page.
### Fixed
- The server Monitor's live CPU, memory, and disk samples no longer go stale: the metrics agent now sends an explicit User-Agent so its uploads are no longer blocked by the edge firewall.
### Changed
- The live server certificate scan now reports richer per-site details and clearer status on the server overview.
### Added
- Sites can now run across multiple load-balanced web backends, unlocking rolling and canary deployment methods with per-target traffic weighting and draining.
### Changed
- The project page is now organized into dedicated Overview, Resources, Access, Operations, and Delivery sections within a unified workspace layout.
### Added
- AI features can now route completions through the local `claude` CLI by setting the provider to "claude", requiring no API key.
### Added
- AI features can now route completions through the local `claude` CLI by setting the provider to "claude", requiring no API key.
### Added
- A new Sync tab on the site deployments page lets you select and deploy multiple related sites—such as a main site and its worker—together in one action.
### Added
- Server overview now explains why CPU is busy by listing the top processes with plain-language remediation hints when CPU is elevated.
### Added
- Sites now expose configurable PHP-FPM pool settings and a panel to compare environment variables against worker pool members.
### Added
- Site PHP settings now expose post max size, input time, input vars, file uploads, and timezone, which are applied to the live server's FastCGI config automatically.
### Added
- Adds a "Validate reachability" action that probes every networked site resource binding from the site's server and badges each one on the Resources map.
### Fixed
- Resource map connector lines now align correctly with nodes when the topology graph is zoomed on narrow screens.
### Added
- VM-backed PHP sites can now assign the Linux account that owns their files and runs their PHP-FPM pool, with permissions resettable over SSH.
### Changed
- The dply Logs drain receiver now accepts app logs over TLS-terminated TCP instead of plaintext UDP, with configurable certificate, key, and passphrase.
### Added
- The site Database tab is now a full tabbed management surface for users, backups, and database events, with per-channel notification routing across site and server workspaces.
### Added
- The site Database tab is now a full, tabbed management surface (Databases / Create / Notifications): add/remove database users, rotate the primary user's password (with a fresh one-time credential link), back up on demand with inline download/delete, and drop a database from the site context — all queued over SSH with live console output. A Notifications tab routes channels to database events, including a new "Database credentials shared" alert fired whenever a one-time credential link is generated.
- New central Notifications tab on the server workspace: route notification channels to any of the server's events, grouped by category (Server, Health, Errors, Backups, Patches, Networking…). It shares the same subscriptions as each feature's own Notifications tab, surfaces the organization-wide outbound webhook destinations read-only, and links out to channel management.
### Changed
- The central site and server Notifications pages now route per channel — each channel is its own expandable row where you pick exactly which events it receives, so different events can go to different channels in one place. Saving reconciles only the channels shown (ticking adds, unticking removes) and never touches channels it didn't list, keeping it in sync with the per-feature Notifications tabs. You can also create a new notification channel inline from these pages without leaving. Both pages are now organized into tabs (Subscriptions / Integration webhooks); the deploy webhook's IP allow list moved to Repository settings where the rest of the deploy webhook lives.
- Route a single site's error events to notification channels from a new Notifications tab on the site Errors workspace, or from the existing site Settings → Notifications page (both edit the same subscriptions). A site failure also appears in its server's error roll-up, so routing is deduped per channel and per in-app recipient — a subscriber wired to both the site and the server is alerted once.
- Route a server's error events to notification channels from a new Notifications tab on the server Errors workspace, without firing alerts for historical backfilled failures.
### Removed
- Support for Google Cloud, Scaleway, Equinix Metal, and Fly.io server providers has been removed.
### Added
- Servers gain a unified Snapshots workspace to capture full-disk provider images, take and restore site database snapshots, and manage cache (Redis/Valkey) snapshots from one place.
### Fixed
- Config files now load instantly in the editor via a direct SSH read instead of the queued worker round-trip that could fail to load or hang.
### Fixed
- The webserver config-file picker now loads via a background request instead of on every render, fixing intermittent poll errors and showing a "discovering files" state while the listing loads.
### Changed
- The Server Logs ClickHouse client now verifies HTTPS connections against the supplied private CA certificate, securing cross-provider log queries to the managed log store.
### Fixed
- Log agent installs no longer get stuck on "installing" and now report a clear failure when the server isn't a reachable VM host.
### Added
- Servers can now ship all host logs to a managed ClickHouse store via an installable Vector agent with a native log explorer, plus scheduler runs capture and retain their output history.
### Changed
- Supervisor install, sync, and restart now run as background jobs with directory pre-flight checks, a worker backend status check, and paginated cron/daemon history.
### Fixed
- Warm-pool servers that silently stall during provisioning are now detected and recovered automatically, so new servers finish setup reliably.
### Added
- Managed servers can now be claimed instantly from a pre-provisioned warm pool instead of waiting for a cold provision.
### Changed
- Server provisioning can now download language runtimes in the background and prefetch stock packages in parallel to shorten setup time, and the server-removal flow no longer flashes a spurious 404 modal.
### Changed
- Server provisioning jobs now run on a dedicated priority queue and MySQL readiness is detected faster, so new servers come online sooner.
### Changed
- Servers can warm up apt packages at boot and defer certbot off the provisioning critical path, with too-small sizing now a warning instead of a hard block.
### Added
- New Hetzner servers can launch from a pre-baked base image, cutting provisioning time, with refreshed loading states across the server workspace.
### Changed
- New servers launch from region-scoped baked snapshots when available and poll for their IP address faster, cutting provisioning time.
### Added
- Wedged server provisions now surface and recover automatically when a remote task goes silent, and machine callbacks keep working during maintenance windows.
### Fixed
- Server provisioning no longer stalls when machine callbacks hit the coming-soon gate or when an optional PHP extension is unavailable in the configured apt repositories.
### Changed
- Standardized button and icon styling across the dashboard and added a site binding catalog powering site settings navigation.
### Changed
- Button components now render as links when given an href, and attaching a redis-driver queue, cache, or session binding now requires a Redis resource first.
### Changed
- Server schedule and services screens now use shared button components for consistent styling across actions.
### Added
- The realtime broadcasting relay now records connection, subscription, and publish events with per-message delivery counts for easier monitoring.
### Added
- Site logs can now stream live into an in-app App Logs panel via the dply Realtime drain, with one-command Cloudflare relay setup.
### Added
- Sites can now define their complete logging setup—channels, default, stack, and deprecations—which dply generates and owns in config/logging.php on the next deploy.
### Added
- Sites can now configure mail and log drain resources with per-provider credentials and server-side test email delivery, alongside tiered realtime apps.
### Changed
- The site environment settings page has been reorganized internally for faster loading and easier maintenance, with no change to available options.
### Added
- Connecting object storage to a site now offers AWS S3, DigitalOcean Spaces, and Hetzner provider presets with region pickers that auto-derive the endpoint, plus a custom S3 option.
### Added
- You can now bind a cache store (database, redis, file, or array) to a site, and freshly provisioned servers ship the phpredis, GD, sodium, GMP, APCu, igbinary, and SQLite PHP extensions out of the box.
### Added
- Operators can now temporarily bypass the branded error page to surface real 5xx errors when debugging a failing site.
### Added
- The deploy panel now shows a "scanning the repo" placeholder while a pipeline suggestion scan is running instead of flashing stale suggestions.
### Fixed
- The "Optimize pipeline" action now clears the pipeline-check warning once steps are added and shows the proposed-changes preview when the repo scan finishes, instead of appearing to do nothing.
### Fixed
- Deploy steps that run both Composer and npm now reliably find both tools instead of failing with "npm: command not found".
### Fixed
- Direct links with a pipeline_tab query parameter now open the correct pipeline sub-tab when the deploy pipeline is embedded in another page.
### Fixed
- Fixed environment file pushes failing to set correct ownership due to shell quoting that corrupted the chown user argument.
### Fixed
- Deploys no longer fail to prune old releases when root-owned files (from certbot or managed error pages) are present.
### Fixed
- Deploys of private HTTPS repositories now authenticate correctly on re-deploy by passing the token-injected URL directly to git instead of relying on a stored remote, while keeping credentials out of the server's git config.
### Fixed
- HTTPS repository clones now authenticate correctly even when the git provider isn't explicitly set, by detecting it from the repository URL, and env files are written with the correct site-user ownership.
### Fixed
- Deploys from private HTTPS repositories now authenticate automatically using your stored Git provider token, with tokens redacted from deploy logs.
### Changed
- Deploy logs now include detailed pre-clone, post-clone, and phase-probe snapshots, plus a "Scan for required variables" action in site environment settings.
### Fixed
- The open-error count badge no longer appears on the server Errors tab while it is still a coming-soon preview.
### Fixed
- Feature flag values are now cleared on each deploy so flag changes take effect immediately after release.
### Changed
- The browser-based server console is now shown as a coming-soon preview while the full console feature is gated off.
### Changed
- The site CLI settings tab now shows a coming-soon preview of upcoming terminal commands when the feature is not yet available for your server.
### Added
- Site settings now include an in-browser CLI console for running dply commands against your site with quick-run shortcuts for common operations.
### Added
- You can now create and link a database directly from a VM site's page, manage workers, schedules, and basic auth via a new site resource API.
### Fixed
- Worker deploys now restart dply-managed systemd Horizon and scheduler units on each release swap so daemons no longer run stale code, with legacy supervisor restarts kept as a fallback.
### Fixed
- Deployments now clone the bare repository using the server's own authenticated remote URL, avoiding failures when the local remote uses an SSH URL the server can't access.
### Changed
- Deploys now build immutable releases and flip an atomic current symlink across web and worker hosts, preventing long-running workers from serving stale code and breaking queued-job deserialization.
### Fixed
- Corrected a Blade templating error that could prevent the pre-flight job console from rendering during site setup.
### Fixed
- Git provider identity lookups are now memoized per request, reducing duplicate database queries when rendering site source-control views.
### Fixed
- Repository and commit listings no longer error out when a stored Git token can't be decrypted, and duplicate identity lookups during a page render are now cached.
### Fixed
- Fixed a crash when loading a site with a stale setup tab link after first-deploy setup had already completed.
### Added
- The site setup wizard now shows a live console streaming the pre-flight job's progress and the exact reason it stalls or fails.
### Changed
- The repository picker now supports arrow-key navigation and Enter-to-select, and the ⌘K command palette is available on marketing pages while signed in.
### Changed
- The Git repository picker now behaves consistently across the choose-app, custom-site create, and repository connection flows.
### Added
- Repository commit views now show a dismissible notice when a missing configured branch falls back to the repo's default branch.
### Fixed
- Repository commit views now fall back to the repository's default branch with a notice when the configured branch no longer exists, instead of showing an error.
### Added
- The repository commits view now shows a retry button when commits fail to load and displays which linked account answered the read, with a quick link to change it.
### Added
- The repository overview now shows which linked Git account answered each read and persists the account choice immediately so commits, branches, and files resolve to the selected identity.
### Changed
- The repository URL input now uses the shared text input component for consistent styling.
### Changed
- The linked source-control account dropdown now uses the standard styled select component for a consistent appearance.
### Fixed
- Repository commits and README error states now offer a Retry button to re-fetch the data without reloading the page.
### Fixed
- A site setup pre-flight scan that stalls now shows a manual re-scan button so you can unstick the wizard and proceed to deploy.
### Fixed
- Server remote-access tracking no longer errors when a stale release leaves a queued job's command class unresolved.
### Fixed
- Supervisor program configs now install correctly under non-root SSH users and resolve their working directory from the attached site, preventing silent install failures and stale imported paths.
### Changed
- The clone-server action is now available on both the Manage and Configuration workspaces, and single-daemon re-sync reports whether the program actually started running or failed with a reason.
### Added
- Cron jobs gain a one-click library of common Laravel artisan and generic command presets, and daemon programs missing from Supervisor can now be re-registered on the server with a new Sync action.
### Added
- Adds a CLI tab for installing and managing servers from your terminal, and lets you remove bundled firewall templates with per-rule status while always preserving the SSH lifeline rule.
### Added
- You can now re-query DigitalOcean and Hetzner for a server's private IP from the connection settings, with live certificate scans now timing out gracefully instead of spinning forever.
### Added
- The sites list now supports search, status filtering, sorting, and a summary stat strip, with dashboard cards linking through to servers and fleet health.
### Changed
- The Realtime coming-soon page now shows a richer preview with a terminal demo and feature highlights, and the Deploy sync entry is hidden from navigation.
### Added
- The Pulse dashboard now shows dedicated cards for Redis, database, and worker servers with live CPU, memory, and disk metrics, including those infrastructure hosts that don't run the dply app.
### Fixed
- Fixed a grammatical typo in the empty-state message shown when no database backup schedules exist.
### Added
- The Realtime page now shows a preview of the managed Pusher-compatible WebSocket relay when the feature isn't yet enabled for your organization.
### Removed
- Removed the unused Reverb health check link from the admin Operations dashboard.
### Added
- The Backups page now shows backup health metrics, schedules you can pause or run on demand, recent runs, and storage destinations when the feature is enabled.
### Changed
- The Backups section now appears under the main Browse menu as a coming-soon feature, and server-side broadcast events are correctly proxied to Reverb over the site's vhost.
### Added
- The features page now showcases Edge and Cloud hosting—container apps, serverless functions, managed realtime, and CDN storage—alongside the new PHP CLI and worker-pool details.
### Added
- Deploys now publish titled entries to the public changelog page alongside CHANGELOG.md.
### Added
- TLS sites now 301-redirect HTTP to HTTPS while still serving ACME challenges, and deploys auto-generate commit messages and changelog entries.
