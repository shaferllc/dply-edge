<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\SiteType;
use App\Models\Server;
use App\Models\Site;

/**
 * Per-section header metadata for the site settings workspace.
 *
 * Each entry returns the title/description/icon to render in the page header
 * when that section is active. Section-aware copy gives the operator a
 * specific orientation ("HTTP basic authentication", "Routing & redirects")
 * instead of the generic "Site workspace".
 */
final class SiteSettingsHeader
{
    /**
     * @return array{title: string, description: string, icon: string}
     */
    public static function for(Site $site, Server $server, string $section): array
    {
        if ($site->usesEdgeRuntime()) {
            return self::forEdge($section);
        }

        $resourceNoun = $site->runtimeTargetMode() === 'vm' ? __('site') : __('app');

        return match ($section) {
            'general' => [
                'title' => __('General'),
                'description' => __('Primary domain, working directory, and the headline metadata for this :resource.', ['resource' => $resourceNoun]),
                'icon' => 'heroicon-o-rectangle-stack',
            ],
            'routing' => [
                'title' => __('Routing'),
                'description' => __('Domains, DNS automation, aliases, redirects, preview hosts, and tenant routing for this :resource.', ['resource' => $resourceNoun]),
                'icon' => 'heroicon-o-share',
            ],
            'certificates' => [
                'title' => __('Certificates'),
                'description' => __('Issue and inspect TLS certificates that protect traffic to this :resource.', ['resource' => $resourceNoun]),
                'icon' => 'heroicon-o-shield-check',
            ],
            'deploy' => [
                'title' => __('Deployments'),
                'description' => __('Deploy history, triggers, and release rollback for this :resource.', ['resource' => $resourceNoun]),
                'icon' => 'heroicon-o-code-bracket-square',
            ],
            'pipeline' => [
                'title' => __('Pipeline'),
                'description' => __('Build steps, deploy hooks, zero-downtime rollout, and post-activate checks for this :resource.', ['resource' => $resourceNoun]),
                'icon' => 'heroicon-o-adjustments-horizontal',
            ],
            'repository' => [
                'title' => __('Repository'),
                'description' => __('Source control connection, branch tracking, and quick-deploy webhook for this :resource.', ['resource' => $resourceNoun]),
                'icon' => 'heroicon-o-folder-open',
            ],
            'runtime' => [
                'title' => __('Runtime'),
                'description' => $site->usesFunctionsRuntime()
                    ? __('How this function executes — runtime, entrypoint, and the memory, timeout, and concurrency limits applied to the action.')
                    : match (true) {
                        $site->type === SiteType::Php || (string) ($site->runtime ?? '') === 'php' => __('How this site runs on the box — PHP version, FPM workers, OPcache, and request limits. Tune them on the PHP tab.'),
                        (string) ($site->runtime ?? '') === 'ruby' => __('How this site runs on the box — Ruby version, processes, and detection. Tune them on the Ruby tab.'),
                        (string) ($site->runtime ?? '') === 'static' => __('How this static site is served — detection, document root, and static-file settings.'),
                        default => __('How this :resource runs on the box — language, live processes, and what we detected from the repo.', ['resource' => $resourceNoun]),
                    },
                'icon' => 'heroicon-o-cube-transparent',
            ],
            'access' => [
                'title' => __('Access'),
                'description' => __('Whether this function answers HTTP, who may call it, and what parameters are bound to it. Applied to the live function on save.'),
                'icon' => 'heroicon-o-globe-alt',
            ],
            'data' => [
                'title' => __('Data'),
                'description' => __('The stores this function talks to: its managed database, and the private network around it.'),
                'icon' => 'heroicon-o-circle-stack',
            ],
            'assets' => [
                'title' => __('Assets'),
                'description' => __('Published front-end files, the allowance they draw on, and the domain they are served from.'),
                'icon' => 'heroicon-o-photo',
            ],
            'system-user' => [
                'title' => __('System user'),
                'description' => __('The Linux user that owns this :resource on the server, plus permissions and sudo controls.', ['resource' => $resourceNoun]),
                'icon' => 'heroicon-o-user',
            ],
            'worker-fleet' => [
                'title' => __('Worker Servers'),
                'description' => __('Add worker VMs of this :resource — same code and queue, no webserver. Scale them here and watch the queues they drain.', ['resource' => $resourceNoun]),
                'icon' => 'heroicon-o-square-3-stack-3d',
            ],
            'laravel-stack' => [
                'title' => __('Laravel'),
                'description' => __('Octane, Reverb, Horizon, Pulse, and Pail integrations for this Laravel :resource.', ['resource' => $resourceNoun]),
                'icon' => 'heroicon-o-bolt',
            ],
            'wordpress' => [
                'title' => __('WordPress'),
                'description' => __('WordPress-specific settings and admin links for this :resource.', ['resource' => $resourceNoun]),
                'icon' => 'heroicon-o-globe-alt',
            ],
            'environment' => [
                'title' => __('Environment'),
                'description' => __('Environment variables and secrets injected when this :resource builds and runs.', ['resource' => $resourceNoun]),
                'icon' => 'heroicon-o-command-line',
            ],
            'resources' => [
                'title' => __('Resources'),
                'description' => __('A visual map of this :resource and the resources wired to it — attach a database, cache, queue, storage, mail and more.', ['resource' => $resourceNoun]),
                'icon' => 'heroicon-o-puzzle-piece',
            ],
            'logs' => [
                'title' => __('Logs'),
                'description' => __('Deploy logs, runtime logs, and per-:resource log shortcuts.', ['resource' => $resourceNoun]),
                'icon' => 'heroicon-o-clipboard-document-list',
            ],
            'notifications' => [
                'title' => __('Notifications'),
                'description' => __('Channel routing for this :resource — pick who gets paged for which deploy and uptime events.', ['resource' => $resourceNoun]),
                'icon' => 'heroicon-o-bell',
            ],
            'basic-auth' => [
                'title' => __('Authentication'),
                'description' => __('Lock this :resource behind one visitor gate at the webserver — HTTP basic auth or a branded password page — before the app (or its own login) ever runs.', ['resource' => $resourceNoun]),
                'icon' => 'heroicon-o-lock-closed',
            ],
            'cli' => [
                'title' => __('CLI'),
                'description' => __('Install the dply CLI and manage this :resource from your terminal.', ['resource' => $resourceNoun]),
                'icon' => 'heroicon-o-command-line',
            ],
            'danger' => [
                'title' => __('Danger zone'),
                'description' => __('Suspend, archive, transfer, or delete this :resource. Actions here are scoped tightly and most are irreversible.', ['resource' => $resourceNoun]),
                'icon' => 'heroicon-o-exclamation-triangle',
            ],
            default => [
                'title' => $site->name,
                'description' => __('Manage this :resource.', ['resource' => $resourceNoun]),
                'icon' => 'heroicon-o-rectangle-stack',
            ],
        };
    }

    /**
     * @return array{title: string, description: string, icon: string}
     */
    private static function forEdge(string $section): array
    {
        return match ($section) {
            'general' => [
                'title' => __('Overview'),
                'description' => __('Live URL, source repository, deploy status, and quick actions for this Edge site.'),
                'icon' => 'heroicon-o-home',
            ],
            'deploys' => [
                'title' => __('Deploys'),
                'description' => __('Build and publish history — redeploy production or roll back to a previous release.'),
                'icon' => 'heroicon-o-code-bracket-square',
            ],
            'domains' => [
                'title' => __('Routing'),
                'description' => __('Domains, redirects, rewrites, and headers.'),
                'icon' => 'heroicon-o-arrows-right-left',
            ],
            'build' => [
                'title' => __('Build'),
                'description' => __('Command and output directory for each deploy.'),
                'icon' => 'heroicon-o-wrench-screwdriver',
            ],
            'routing' => [
                'title' => __('Routing'),
                'description' => __('Domains, redirects, rewrites, and headers.'),
                'icon' => 'heroicon-o-arrows-right-left',
            ],
            'environment' => [
                'title' => __('Environment'),
                'description' => __('Set production env vars for builds and runtime.'),
                'icon' => 'heroicon-o-command-line',
            ],
            'deploy-triggers' => [
                'title' => __('Deploy triggers'),
                'description' => __('GitHub auto-deploy and CMS deploy hooks.'),
                'icon' => 'heroicon-o-bolt',
            ],
            'bindings' => [
                'title' => __('Bindings'),
                'description' => __('Attach KV, R2, D1, and queues for your worker.'),
                'icon' => 'heroicon-o-puzzle-piece',
            ],
            'members' => [
                'title' => __('Members'),
                'description' => __('Grant site access without changing org roles.'),
                'icon' => 'heroicon-o-user-group',
            ],
            'delivery' => [
                'title' => __('Delivery'),
                'description' => __('Hybrid origin, image optimization, and cache tools.'),
                'icon' => 'heroicon-o-cloud',
            ],
            'crons' => [
                'title' => __('Crons'),
                'description' => __('Scheduled worker invocations.'),
                'icon' => 'heroicon-o-clock',
            ],
            'resources' => [
                'title' => __('Resources'),
                'description' => __('Edge, app size, cache, and database. A new app starts on Flex. A size change applies on the next deploy.'),
                'icon' => 'heroicon-o-squares-2x2',
            ],
            'security' => [
                'title' => __('Security'),
                'description' => __('Hostname, TLS, and whether firewall, bot protection, and rate limits are on. Recent blocked requests from this app.'),
                'icon' => 'heroicon-o-lock-closed',
            ],
            'firewall' => [
                'title' => __('Firewall'),
                'description' => __('Allow or block by country at the Edge. Blocked visitors get HTTP 403 before your site runs.'),
                'icon' => 'heroicon-o-shield-check',
            ],
            'bot-protection' => [
                'title' => __('Bot protection'),
                'description' => __('Turnstile on forms or every HTML page. Create a widget in Turnstile, paste site + secret keys, then Save.'),
                'icon' => 'heroicon-o-finger-print',
            ],
            'rate-limits' => [
                'title' => __('Rate limits'),
                'description' => __('Cap requests per IP on a path. Excess traffic gets HTTP 429 or a bot challenge — not a waiting-room queue.'),
                'icon' => 'heroicon-o-no-symbol',
            ],
            'waiting-room' => [
                'title' => __('Waiting room'),
                'description' => __('Hold excess visitors on a “You’re in line” page on your Edge URL until capacity opens.'),
                'icon' => 'heroicon-o-queue-list',
            ],
            'forms' => [
                'title' => __('Forms'),
                'description' => __('POST to an Edge path; Dply emails the fields — no backend app required.'),
                'icon' => 'heroicon-o-inbox',
            ],
            'jobs' => [
                'title' => __('Jobs'),
                'description' => __('Point middleware/SSR at a queue binding so workers can enqueue background work.'),
                'icon' => 'heroicon-o-rectangle-stack',
            ],
            'snippets' => [
                'title' => __('Snippets'),
                'description' => __('Inject HTML into matching pages without rebuilding — banners, meta, small widgets.'),
                'icon' => 'heroicon-o-code-bracket',
            ],
            'container' => [
                'title' => __('Container'),
                'description' => __('Instance size, scaling and sleep for your PHP, Rails or Node app.'),
                'icon' => 'heroicon-o-cube',
            ],
            'tags' => [
                'title' => __('Tags'),
                'description' => __('Load analytics and pixel scripts from the Edge; optional consent gate via your CMP.'),
                'icon' => 'heroicon-o-tag',
            ],
            'error-pages' => [
                'title' => __('Error pages'),
                'description' => __('Brand 404/500 HTML and flip maintenance (503) without a redeploy.'),
                'icon' => 'heroicon-o-exclamation-circle',
            ],
            'alerts' => [
                'title' => __('Alerts'),
                'description' => __('Route Edge events to notification channels, and set RUM / error thresholds.'),
                'icon' => 'heroicon-o-bell-alert',
            ],
            'audit' => [
                'title' => __('Audit log'),
                'description' => __('Who changed what on this Edge site.'),
                'icon' => 'heroicon-o-clipboard-document-list',
            ],
            'previews' => [
                'title' => __('Previews'),
                'description' => __('PR and ad-hoc preview deploys.'),
                'icon' => 'heroicon-o-sparkles',
            ],
            'billing' => [
                'title' => __('Billing & usage'),
                'description' => __('Usage and any extra-site or SSR fee for this Edge site.'),
                'icon' => 'heroicon-o-chart-bar',
            ],
            'cache' => [
                'title' => __('Cache'),
                'description' => __('Stored public responses, and a way to drop a path or a tag.'),
                'icon' => 'heroicon-o-circle-stack',
            ],
            'traffic' => [
                'title' => __('Traffic & analytics'),
                'description' => __('CDN requests, bandwidth, performance, and a live request tail.'),
                'icon' => 'heroicon-o-signal',
            ],
            'logs' => [
                'title' => __('Build & deploy logs'),
                'description' => __('Commit, branch, build time, container health, and build output for recent deploys.'),
                'icon' => 'heroicon-o-clipboard-document-list',
            ],
            'danger' => [
                'title' => __('Danger zone'),
                'description' => __('Permanently delete this Edge site and remove all deployments from the CDN.'),
                'icon' => 'heroicon-o-exclamation-triangle',
            ],
            default => [
                'title' => __('Edge site'),
                'description' => __('Manage this Edge site.'),
                'icon' => 'heroicon-o-globe-alt',
            ],
        };
    }
}
