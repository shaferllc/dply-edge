<?php

namespace App\Support;

use App\Models\Server;
use App\Models\Site;
use App\Modules\Edge\Support\EdgeSiteHasWorker;

/**
 * Sidebar nav items for the site workspace (Settings, Web server config, etc.).
 *
 * Each item shape: {id, label, icon, group, route?, parent?}.
 * `route` (optional) names a dedicated route the sidebar.blade.php should link to;
 * absent items fall back to `sites.show?section={id}` (the default settings tab router).
 */
final class SiteSettingsSidebar
{
    /**
     * @return list<array{id: string, label: string, icon: string, group: string, route?: string, parent?: string}>
     */
    public static function items(Site $site, Server $server): array
    {
        if ($site->usesEdgeRuntime()) {
            return self::edgeItems($site);
        }

        $supportsSsh = $server->hostCapabilities()->supportsSsh();

        if ($site->isCustom()) {
            return self::customItems($site);
        }

        $showWebserverConfigEditor = $supportsSsh
            && ! $site->usesFunctionsRuntime()
            && ! $site->usesDockerRuntime()
            && ! $site->usesKubernetesRuntime();

        $runtimeMode = $site->runtimeTargetMode();
        $isContainerWorkspace = in_array($runtimeMode, ['docker', 'kubernetes', 'serverless'], true);

        // Background items (cron / daemons) require SSH on the host. Container and
        // serverless workspaces don't have crontabs or supervisor — skip the group
        // entirely for them so the heading doesn't render empty.
        $showBackgroundGroup = $supportsSsh && ! $isContainerWorkspace;

        // Container / serverless workspaces run behind the dply cloud — host
        // webserver, system user, basic auth, certificates, and framework-
        // specific stack tabs (Laravel/Rails/WordPress) all belong either
        // to the cloud or to the operator's artifact, not this workspace.
        // BACKGROUND (Schedule / Workers) sits between RUNTIME and OBSERVABILITY
        // so the page reads: configure → run → observe → destroy.
        //
        // The `routing` item below is DIFFERENT from the VM `routing` (which
        // edits nginx server blocks). Here it manages dply's edge proxy:
        // hostname & DNS, custom domains pointed at the function, path
        // redirects, response headers + CORS, the invocation URL. Same group
        // key ("networking"), different surface.
        $base = $isContainerWorkspace
            ? [
                ['id' => 'general', 'label' => __('Overview'), 'icon' => 'heroicon-o-home', 'group' => 'general'],
                ['id' => 'settings', 'label' => __('Settings'), 'icon' => 'heroicon-o-cog-6-tooth', 'group' => 'general'],
                ['id' => 'routing', 'label' => __('Routing'), 'icon' => 'heroicon-o-share', 'group' => 'networking', 'route' => 'sites.routing'],
                // Access is Routing's sibling: Routing is where the function
                // lives, Access is who may call it (exposure, shared secret,
                // CORS, bound parameters). Split out of Runtime.
                ['id' => 'access', 'label' => __('Access'), 'icon' => 'heroicon-o-globe-alt', 'group' => 'networking'],
                // Deployments owns the deploy tab strip (Overview / Deploy /
                // Releases / History / Settings). Pipeline + Repository live
                // under Settings; the old standalone routes redirect there.
                ['id' => 'deploy', 'label' => __('Deployments'), 'icon' => 'heroicon-o-code-bracket-square', 'group' => 'deploy', 'route' => 'sites.deployments.index'],
                ['id' => 'repository', 'label' => __('Repository'), 'icon' => 'heroicon-o-code-bracket', 'group' => 'deploy', 'route' => 'sites.repository'],
                ['id' => 'runtime', 'label' => __('Runtime'), 'icon' => 'heroicon-o-cube-transparent', 'group' => 'runtime'],
                ['id' => 'environment', 'label' => __('Environment'), 'icon' => 'heroicon-o-command-line', 'group' => 'runtime', 'route' => 'sites.environment'],
                // Data and Assets used to be two of the five panels stacked on
                // Overview. Each is a page's worth of controls on its own.
                ['id' => 'data', 'label' => __('Data'), 'icon' => 'heroicon-o-circle-stack', 'group' => 'runtime'],
                ['id' => 'assets', 'label' => __('Assets'), 'icon' => 'heroicon-o-photo', 'group' => 'runtime'],
                ['id' => 'resources', 'label' => __('Resources'), 'icon' => 'heroicon-o-puzzle-piece', 'group' => 'runtime', 'route' => 'sites.resources'],
                ['id' => 'schedule', 'label' => __('Schedule'), 'icon' => 'heroicon-o-calendar-days', 'group' => 'background', 'route' => 'sites.schedule'],
                ['id' => 'workers', 'label' => __('Workers'), 'icon' => 'heroicon-o-bolt', 'group' => 'background', 'route' => 'sites.workers'],
                ['id' => 'logs', 'label' => __('Logs'), 'icon' => 'heroicon-o-clipboard-document-list', 'group' => 'observability', 'route' => 'sites.logs', 'feature' => 'workspace.site_logs', 'preview_feature' => 'workspace.site_logs_preview'],
                ['id' => 'platform', 'label' => __('Platform'), 'icon' => 'heroicon-o-cube', 'group' => 'observability'],
                ['id' => 'notifications', 'label' => __('Notifications'), 'icon' => 'heroicon-o-bell', 'group' => 'observability', 'feature' => 'workspace.site_notifications', 'preview_feature' => 'workspace.site_notifications_preview'],
                ['id' => 'monitor', 'label' => __('Monitor'), 'icon' => 'heroicon-o-chart-bar', 'group' => 'observability', 'route' => 'sites.monitor', 'feature' => 'workspace.site_monitor', 'preview_feature' => 'workspace.site_monitor_preview'],
                ['id' => 'errors', 'label' => __('Errors'), 'icon' => 'heroicon-o-exclamation-triangle', 'group' => 'observability', 'route' => 'sites.errors', 'feature' => 'workspace.site_errors', 'preview_feature' => 'workspace.site_errors_preview'],
                ['id' => 'cli', 'label' => __('CLI'), 'icon' => 'heroicon-o-command-line', 'group' => 'general', 'feature' => 'workspace.site_cli', 'preview_feature' => 'workspace.site_cli_preview'],
                ['id' => 'danger', 'label' => __('Danger zone'), 'icon' => 'heroicon-o-archive-box', 'group' => 'danger'],
            ]
            : [
                ['id' => 'general', 'label' => __('General'), 'icon' => 'heroicon-o-rectangle-stack', 'group' => 'general'],
                ['id' => 'settings', 'label' => __('Settings'), 'icon' => 'heroicon-o-cog-6-tooth', 'group' => 'general'],
                ['id' => 'routing', 'label' => __('Routing'), 'icon' => 'heroicon-o-share', 'group' => 'networking'],
                ['id' => 'certificates', 'label' => __('Certificates'), 'icon' => 'heroicon-o-shield-check', 'group' => 'networking'],
                ['id' => 'backends', 'label' => __('Backends'), 'icon' => 'heroicon-o-server-stack', 'group' => 'networking', 'feature' => 'workspace.site_backends', 'preview_feature' => 'workspace.site_backends_preview'],
                ['id' => 'deploy', 'label' => __('Deployments'), 'icon' => 'heroicon-o-code-bracket-square', 'group' => 'deploy', 'route' => 'sites.deployments.index'],
                ['id' => 'repository', 'label' => __('Repository'), 'icon' => 'heroicon-o-code-bracket', 'group' => 'deploy', 'route' => 'sites.repository'],
                ['id' => 'runtime', 'label' => __('Runtime'), 'icon' => 'heroicon-o-cube-transparent', 'group' => 'runtime'],
                // First-class Environment + Resources. Environment deep-links to
                // the Deploy hub's Environment tab (where the editor lives); both
                // render inside the same workspace sidebar chrome.
                ['id' => 'environment', 'label' => __('Environment'), 'icon' => 'heroicon-o-command-line', 'group' => 'runtime', 'route' => 'sites.environment'],
                ['id' => 'resources', 'label' => __('Resources'), 'icon' => 'heroicon-o-puzzle-piece', 'group' => 'runtime', 'route' => 'sites.resources'],
                ['id' => 'system-user', 'label' => __('System user'), 'icon' => 'heroicon-o-user', 'group' => 'runtime', 'feature' => 'workspace.site_system_user', 'preview_feature' => 'workspace.site_system_user_preview'],
                ['id' => 'laravel-stack', 'label' => __('Laravel'), 'icon' => 'heroicon-o-bolt', 'group' => 'runtime'],
                ['id' => 'rails-stack', 'label' => __('Rails'), 'icon' => 'heroicon-o-bolt', 'group' => 'runtime'],
                ['id' => 'wordpress', 'label' => __('WordPress'), 'icon' => 'heroicon-o-globe-alt', 'group' => 'runtime'],
                // Environment moved to the Deployments hub (Deploy → Environment
                // tab) for VM sites — it lives next to the deploy controls and
                // the missing-env deploy gate. Container/serverless sidebars keep
                // their own entry since their deploy view has no tab strip.
                // SSH file browser, locked to the site's directory root. Has its
                // own dedicated route; gated below so it's hidden on hosts without
                // SSH (where the browser can't read anything).
                ['id' => 'database', 'label' => __('Database'), 'icon' => 'heroicon-o-circle-stack', 'group' => 'runtime', 'route' => 'sites.database'],
                ['id' => 'files', 'label' => __('Files'), 'icon' => 'heroicon-o-folder', 'group' => 'runtime', 'route' => 'sites.files', 'feature' => 'workspace.site_files', 'preview_feature' => 'workspace.site_files_preview'],
                ['id' => 'logs', 'label' => __('Logs'), 'icon' => 'heroicon-o-clipboard-document-list', 'group' => 'observability', 'route' => 'sites.logs', 'feature' => 'workspace.site_logs', 'preview_feature' => 'workspace.site_logs_preview'],
                ['id' => 'notifications', 'label' => __('Notifications'), 'icon' => 'heroicon-o-bell', 'group' => 'observability', 'feature' => 'workspace.site_notifications', 'preview_feature' => 'workspace.site_notifications_preview'],
                ['id' => 'monitor', 'label' => __('Monitor'), 'icon' => 'heroicon-o-chart-bar', 'group' => 'observability', 'route' => 'sites.monitor', 'feature' => 'workspace.site_monitor', 'preview_feature' => 'workspace.site_monitor_preview'],
                ['id' => 'errors', 'label' => __('Errors'), 'icon' => 'heroicon-o-exclamation-triangle', 'group' => 'observability', 'route' => 'sites.errors', 'feature' => 'workspace.site_errors', 'preview_feature' => 'workspace.site_errors_preview'],
                ['id' => 'basic-auth', 'label' => __('Authentication'), 'icon' => 'heroicon-o-lock-closed', 'group' => 'access'],
                ['id' => 'cli', 'label' => __('CLI'), 'icon' => 'heroicon-o-command-line', 'group' => 'general', 'feature' => 'workspace.site_cli', 'preview_feature' => 'workspace.site_cli_preview'],
                ['id' => 'danger', 'label' => __('Danger zone'), 'icon' => 'heroicon-o-archive-box', 'group' => 'danger'],
            ];

        // Platform (the OpenWhisk inspector), Access, Data, and Assets only
        // apply to DigitalOcean Functions hosts, not docker / kubernetes
        // containers, which share this branch.
        if (! $site->usesFunctionsRuntime()) {
            $functionsOnly = ['platform', 'access', 'data', 'assets'];
            $base = array_values(array_filter($base, fn (array $item): bool => ! in_array($item['id'], $functionsOnly, true)));
        }

        // Serverless keeps a dedicated Workers page; the Resources Livewire
        // surface only admits container + VM runtimes and 404s for functions.
        if ($site->usesFunctionsRuntime()) {
            $base = array_values(array_filter($base, fn (array $item): bool => $item['id'] !== 'resources'));
        }

        // Worker hosts run Caddy purely to attach testing URLs to background/
        // queue workloads — page caching and CDN/edge delivery don't apply, so
        // those tabs are omitted (and their routes 404 — see Caching/Cdn::mount).
        $showCachingAndCdn = ! $server->isWorkerHost();

        $withWebserver = $showWebserverConfigEditor
            ? collect($base)
                ->flatMap(function (array $item) use ($showCachingAndCdn): array {
                    if ($item['id'] !== 'routing') {
                        return [$item];
                    }

                    $expanded = [
                        $item,
                        [
                            'id' => 'webserver-config',
                            'label' => __('Web server config'),
                            'icon' => 'heroicon-o-cog-6-tooth',
                            'group' => 'networking',
                            'route' => 'sites.webserver-config',
                        ],
                    ];

                    if ($showCachingAndCdn) {
                        $expanded[] = [
                            'id' => 'caching',
                            'label' => __('Caching'),
                            'icon' => 'heroicon-o-bolt-slash',
                            'group' => 'networking',
                            'route' => 'sites.caching',
                            'feature' => 'workspace.site_caching',
                            'preview_feature' => 'workspace.site_caching_preview',
                        ];
                        $expanded[] = [
                            'id' => 'cdn',
                            'label' => __('CDN / Edge'),
                            'icon' => 'heroicon-o-globe-alt',
                            'group' => 'networking',
                            'route' => 'sites.cdn',
                            'feature' => 'workspace.site_cdn',
                            'preview_feature' => 'workspace.site_cdn_preview',
                        ];
                    }

                    return $expanded;
                })
                ->values()
                ->all()
            : $base;

        $withBackground = $showBackgroundGroup
            ? self::insertBackgroundGroup($withWebserver)
            : $withWebserver;

        // Framework-specific stack tabs (Laravel/Rails/WordPress) only apply to
        // VM workspaces where dply manages the stack directly. Container/
        // serverless workspaces never include these items in the base.
        return collect($withBackground)
            ->filter(fn (array $item): bool => ($item['id'] ?? null) !== 'laravel-stack' || $site->isLaravelFrameworkDetected())
            ->filter(fn (array $item): bool => ($item['id'] ?? null) !== 'rails-stack' || $site->isRailsFrameworkDetected())
            ->filter(fn (array $item): bool => ($item['id'] ?? null) !== 'wordpress' || $site->isWordPressDetected())
            ->filter(fn (array $item): bool => ($item['id'] ?? null) !== 'services' || Site::supportsSystemdServices($site, $server))
            ->filter(fn (array $item): bool => ($item['id'] ?? null) !== 'files' || $supportsSsh)
            ->filter(fn (array $item): bool => ($item['id'] ?? null) !== 'database')
            // Hide gated items when neither the full feature nor its coming-soon
            // preview is active (e.g. Schedule, Backups).
            ->filter(fn (array $item): bool => self::sidebarItemVisible($item))
            ->map(fn (array $item): array => self::markPreviewOnly($item))
            ->values()
            ->all();
    }

    /**
     * Insert the Background group (cron, daemons, queue workers) right before the
     * Access group so the sidebar order is observability → background → access → danger.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private static function insertBackgroundGroup(array $items): array
    {
        // The Schedule and Backups items navigate to the server-level pages — they're
        // provided here as a convenience entry point. Cron / daemons use site-scoped routes.
        $background = [
            ['id' => 'schedule', 'label' => __('Schedule'), 'icon' => 'heroicon-o-calendar-days', 'group' => 'background', 'route' => 'sites.schedule', 'feature' => 'workspace.schedule'],
            ['id' => 'daemons', 'label' => __('Workers'), 'icon' => 'heroicon-o-server-stack', 'group' => 'background', 'route' => 'sites.daemons'],
            // Worker SERVERS (the app's worker pool) — detect + scale up/down. A
            // Settings section (no route); the panel shows attached pools or an
            // empty state. Distinct from 'daemons' (Supervisor processes on THIS box).
            ['id' => 'worker-fleet', 'label' => __('Worker Servers'), 'icon' => 'heroicon-o-square-3-stack-3d', 'group' => 'background'],
            ['id' => 'services', 'label' => __('Services'), 'icon' => 'heroicon-o-cpu-chip', 'group' => 'background', 'route' => 'sites.services'],
            ['id' => 'backups', 'label' => __('Backups'), 'icon' => 'heroicon-o-archive-box', 'group' => 'background', 'route' => 'sites.backups', 'feature' => 'workspace.backups', 'preview_feature' => 'workspace.backups_preview'],
        ];

        $insertIndex = null;
        foreach ($items as $index => $item) {
            if (($item['group'] ?? null) === 'access') {
                $insertIndex = $index;
                break;
            }
        }

        if ($insertIndex === null) {
            return [...$items, ...$background];
        }

        return [
            ...array_slice($items, 0, $insertIndex),
            ...$background,
            ...array_slice($items, $insertIndex),
        ];
    }

    /**
     * Edge-native workspace — git builds, CDN delivery, custom domains.
     * No VM runtime, SSH, nginx, or certificate automation tabs.
     *
     * @return list<array{id: string, label: string, icon: string, group: string}>
     */
    private static function edgeItems(Site $site): array
    {
        $edgeMeta = $site->edgeMeta();
        $isPreviewChild = ! empty($edgeMeta['preview_parent_site_id']);

        $hasWorker = EdgeSiteHasWorker::for($site);

        // Five groups, in this order: Ship → Traffic → Protect → Extend →
        // Manage. Only the FIRST group is open on a first visit (the sidebar
        // partial seeds the rest collapsed), which is the whole point — 20 of
        // these 26 sections are set-once, and they used to cost the same nav
        // weight as the six that carry the daily work.
        //
        // The grouping is by WHY you open a section, not by subsystem. Hence
        // Build & deploy logs sits under Ship (you read it while shipping)
        // while request Logs sit under Traffic (you read them when something
        // looks wrong). Bindings / Crons / Jobs need a per-site Worker.
        $items = [
            ['id' => 'general', 'label' => __('Overview'), 'icon' => 'heroicon-o-home', 'group' => 'ship'],
            ['id' => 'deploys', 'label' => __('Deploys'), 'icon' => 'heroicon-o-code-bracket-square', 'group' => 'ship'],
            ['id' => 'build', 'label' => __('Build'), 'icon' => 'heroicon-o-wrench-screwdriver', 'group' => 'ship'],
            ['id' => 'environment', 'label' => __('Environment'), 'icon' => 'heroicon-o-command-line', 'group' => 'ship'],
        ];

        if (! $isPreviewChild) {
            $items[] = ['id' => 'previews', 'label' => __('Previews'), 'icon' => 'heroicon-o-sparkles', 'group' => 'ship'];
        }

        if (($site->edgeMeta()['runtime_mode'] ?? '') === 'container') {
            $items[] = ['id' => 'container', 'label' => __('Container'), 'icon' => 'heroicon-o-cube', 'group' => 'ship'];
        }

        $items[] = ['id' => 'deploy-triggers', 'label' => __('Deploy triggers'), 'icon' => 'heroicon-o-bolt', 'group' => 'ship'];
        $items[] = ['id' => 'logs', 'label' => __('Build & deploy logs'), 'icon' => 'heroicon-o-clipboard-document-list', 'group' => 'ship'];

        // ── Traffic ──────────────────────────────────────────────────────
        $items[] = ['id' => 'routing', 'label' => __('Routing'), 'icon' => 'heroicon-o-arrows-right-left', 'group' => 'traffic'];

        if (! $isPreviewChild) {
            $items[] = ['id' => 'cache', 'label' => __('Cache'), 'icon' => 'heroicon-o-circle-stack', 'group' => 'traffic'];
            // Hybrid origin and image resizing. A container already serves the
            // whole app, and Convert to hybrid would overwrite that runtime.
            if (($site->edgeMeta()['runtime_mode'] ?? '') !== 'container') {
                $items[] = ['id' => 'delivery', 'label' => __('Delivery'), 'icon' => 'heroicon-o-cloud', 'group' => 'traffic'];
            }
            $items[] = ['id' => 'traffic', 'label' => __('Traffic & analytics'), 'icon' => 'heroicon-o-signal', 'group' => 'traffic'];
        }

        // ── Protect ──────────────────────────────────────────────────────
        $items = [
            ...$items,
            ['id' => 'firewall', 'label' => __('Firewall'), 'icon' => 'heroicon-o-shield-check', 'group' => 'protect'],
            ['id' => 'bot-protection', 'label' => __('Bot protection'), 'icon' => 'heroicon-o-finger-print', 'group' => 'protect'],
            ['id' => 'rate-limits', 'label' => __('Rate limits'), 'icon' => 'heroicon-o-no-symbol', 'group' => 'protect'],
            ['id' => 'waiting-room', 'label' => __('Waiting room'), 'icon' => 'heroicon-o-queue-list', 'group' => 'protect'],
            ['id' => 'members', 'label' => __('Members'), 'icon' => 'heroicon-o-user-group', 'group' => 'protect'],
        ];

        // ── Extend ───────────────────────────────────────────────────────
        if ($hasWorker) {
            $items[] = ['id' => 'bindings', 'label' => __('Bindings'), 'icon' => 'heroicon-o-puzzle-piece', 'group' => 'extend'];
            $items[] = ['id' => 'crons', 'label' => __('Crons'), 'icon' => 'heroicon-o-clock', 'group' => 'extend'];
            $items[] = ['id' => 'jobs', 'label' => __('Jobs'), 'icon' => 'heroicon-o-rectangle-stack', 'group' => 'extend'];
        }

        $items = [
            ...$items,
            ['id' => 'error-pages', 'label' => __('Error pages'), 'icon' => 'heroicon-o-exclamation-circle', 'group' => 'extend'],
            ['id' => 'forms', 'label' => __('Forms'), 'icon' => 'heroicon-o-inbox', 'group' => 'extend'],
            ['id' => 'snippets', 'label' => __('Snippets'), 'icon' => 'heroicon-o-code-bracket', 'group' => 'extend'],
            ['id' => 'tags', 'label' => __('Tags'), 'icon' => 'heroicon-o-tag', 'group' => 'extend'],
        ];

        // ── Manage ───────────────────────────────────────────────────────
        $items[] = ['id' => 'alerts', 'label' => __('Alerts'), 'icon' => 'heroicon-o-bell-alert', 'group' => 'manage'];
        $items[] = ['id' => 'audit', 'label' => __('Audit log'), 'icon' => 'heroicon-o-clipboard-document-list', 'group' => 'manage'];

        if (! $isPreviewChild) {
            $items[] = ['id' => 'billing', 'label' => __('Billing & usage'), 'icon' => 'heroicon-o-chart-bar', 'group' => 'manage'];
        }

        $items[] = ['id' => 'danger', 'label' => __('Danger zone'), 'icon' => 'heroicon-o-exclamation-triangle', 'group' => 'manage'];

        return $items;
    }

    /**
     * Tight sidebar for Custom (headless) sites — no webserver, SSL, caching,
     * insights, or web-shaped runtime tabs. Daemons / Cron / Queue Workers
     * are first-class since they're the typical workload.
     *
     * @return list<array{id: string, label: string, icon: string, group: string, route?: string, parent?: string}>
     */
    private static function customItems(Site $site): array
    {
        $items = [
            ['id' => 'general', 'label' => __('Overview'), 'icon' => 'heroicon-o-home', 'group' => 'general'],
            ['id' => 'settings', 'label' => __('Settings'), 'icon' => 'heroicon-o-cog-6-tooth', 'group' => 'general'],
            // Deployments hosts the Repository + Pipeline editors under its
            // Settings tab — see SiteSettingsSidebar comment in the base map.
            ['id' => 'deploy', 'label' => __('Deployments'), 'icon' => 'heroicon-o-code-bracket-square', 'group' => 'deploy'],
        ];

        $items = [...$items,
            ['id' => 'environment', 'label' => __('Environment'), 'icon' => 'heroicon-o-command-line', 'group' => 'runtime', 'route' => 'sites.environment'],
            ['id' => 'resources', 'label' => __('Resources'), 'icon' => 'heroicon-o-puzzle-piece', 'group' => 'runtime', 'route' => 'sites.resources'],
            ['id' => 'logs', 'label' => __('Logs'), 'icon' => 'heroicon-o-clipboard-document-list', 'group' => 'observability', 'route' => 'sites.logs', 'feature' => 'workspace.site_logs', 'preview_feature' => 'workspace.site_logs_preview'],
            ['id' => 'notifications', 'label' => __('Notifications'), 'icon' => 'heroicon-o-bell', 'group' => 'observability', 'feature' => 'workspace.site_notifications', 'preview_feature' => 'workspace.site_notifications_preview'],
            ['id' => 'daemons', 'label' => __('Workers'), 'icon' => 'heroicon-o-server-stack', 'group' => 'background', 'route' => 'sites.daemons'],
            ['id' => 'cli', 'label' => __('CLI'), 'icon' => 'heroicon-o-command-line', 'group' => 'general', 'feature' => 'workspace.site_cli', 'preview_feature' => 'workspace.site_cli_preview'],
            ['id' => 'danger', 'label' => __('Danger zone'), 'icon' => 'heroicon-o-archive-box', 'group' => 'danger'],
        ];

        return $items;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private static function sidebarItemVisible(array $item): bool
    {
        return true;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private static function markPreviewOnly(array $item): array
    {
        return $item;
    }

    /**
     * In-page runtime tabs for the combined Runtime workspace (Overview + language).
     *
     * @return array<string, string> tab key => label
     */
    public static function runtimeTabsFor(Site $site): array
    {
        $tabs = ['overview' => __('Overview')];

        $languageTab = match ((string) ($site->runtime ?? '')) {
            'php' => 'php',
            'ruby' => 'ruby',
            'static' => 'static',
            default => null,
        };

        if ($languageTab !== null) {
            $tabs[$languageTab] = match ($languageTab) {
                'php' => __('PHP'),
                'ruby' => __('Ruby'),
                'static' => __('Static'),
            };
        }

        return $tabs;
    }
}
