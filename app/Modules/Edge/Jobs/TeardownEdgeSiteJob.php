<?php

declare(strict_types=1);

namespace App\Modules\Edge\Jobs;

use App\Models\EdgeRealtimeApp;
use App\Models\Server;
use App\Models\Site;
use App\Modules\Edge\Services\Containers\EdgeContainerDeployer;
use App\Modules\Edge\Services\DplyDatabases;
use App\Modules\Edge\Services\EdgeCustomDomainProvisioner;
use App\Modules\Edge\Services\EdgeDeliveryContextResolver;
use App\Modules\Edge\Services\EdgeGithubWebhookProvisioner;
use App\Modules\Edge\Services\EdgeMiddlewareBundleUploader;
use App\Modules\Edge\Services\EdgeRouter;
use App\Modules\Edge\Services\EdgeSsrBundleUploader;
use App\Modules\Edge\Services\EdgeStateScript;
use App\Modules\Edge\Services\EnsureDefaultEdgeBindings;
use App\Modules\Edge\Services\Realtime\EdgeRealtimeApps;
use App\Modules\Edge\Services\Storage\EdgeBucketKeys;
use App\Modules\Edge\Support\FakeEdgeProvision;
use App\Modules\Providers\Cloudflare\EdgeCloudflareClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class TeardownEdgeSiteJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Teardown phases in the order handle() runs them; the danger page shows them as steps. */
    public const STEPS = ['webhook', 'previews', 'domains', 'scripts', 'storage', 'databases', 'deployments'];

    public function __construct(public string $siteId) {}

    public function handle(): void
    {
        $site = Site::find($this->siteId);
        if ($site === null || ! $site->usesEdgeRuntime()) {
            return;
        }

        $server = $site->server;
        $serverId = $site->server_id;

        $backend = EdgeRouter::backendFor($site);
        $site->load('edgeDeployments');

        // Stop push deploys first, then take the previews with the parent —
        // the delete confirm promises both.
        if (! $site->isEdgePreview()) {
            $this->step($site, 'webhook');
            $this->bestEffort($site, 'GitHub webhook', fn () => app(EdgeGithubWebhookProvisioner::class)->disable($site));

            $this->step($site, 'previews');
            Site::query()
                ->where('organization_id', $site->organization_id)
                ->whereJsonContains('meta->edge->preview_parent_site_id', $site->id)
                ->pluck('id')
                ->each(fn ($previewId) => $this->bestEffort($site, 'preview '.$previewId, fn () => (new self((string) $previewId))->handle()));
        }

        // Custom domains: Cloudflare custom hostname + host-map entry each.
        $this->step($site, 'domains');
        $domains = $site->edgeMeta()['routing']['custom_domains'] ?? [];
        foreach (is_array($domains) ? array_keys($domains) : [] as $hostname) {
            $this->bestEffort($site, 'custom domain '.$hostname, fn () => app(EdgeCustomDomainProvisioner::class)->remove($site, (string) $hostname));
        }

        // Drop every per-deployment SSR script in the dispatch
        // namespace BEFORE wiping the deployment rows — once the rows
        // are gone we lose the script names and the scripts would
        // sit in the namespace forever, consuming quota.
        $this->step($site, 'scripts');
        $this->bestEffort($site, 'SSR scripts', fn () => app(EdgeSsrBundleUploader::class)->deleteAllForSite($site));
        $this->bestEffort($site, 'middleware scripts', fn () => app(EdgeMiddlewareBundleUploader::class)->deleteAllForSite($site));

        // State data goes with the site, after every script that binds it.
        if (is_array($site->edgeMeta()['state_script'] ?? null) && ! FakeEdgeProvision::enabled()) {
            $this->bestEffort($site, 'state script', function () use ($site): void {
                $context = app(EdgeDeliveryContextResolver::class)->forSite($site);
                app(EdgeStateScript::class)->delete($site, new EdgeCloudflareClient($context->accountId, $context->apiToken), $context->dispatchNamespaceName);
            });
        }

        if (($site->edgeMeta()['runtime_mode'] ?? '') === 'container') {
            $this->bestEffort($site, 'container', function () use ($site): void {
                $client = EdgeCloudflareClient::fromConfig();
                $script = EdgeContainerDeployer::scriptName($site);
                $client->deleteDispatchScript((string) config('edge.cloudflare.dispatch_namespace_name'), $script);
                // wrangler names the container application after the script.
                foreach ($client->listContainerApplications() as $application) {
                    if (str_starts_with($application['name'], $script)) {
                        $client->deleteContainerApplication($application['id']);
                    }
                }
            });
        }

        // After every script that binds it.
        $this->step($site, 'storage');
        $this->bestEffort($site, 'default KV namespace', fn () => app(EnsureDefaultEdgeBindings::class)->delete($site));

        // Realtime apps: close sockets and drop the relay's KV record before
        // the row loses its site_id (nullOnDelete), or the app stays live.
        EdgeRealtimeApp::query()->where('site_id', $site->id)->each(function (EdgeRealtimeApp $app): void {
            try {
                app(EdgeRealtimeApps::class)->destroy($app);
            } catch (\Throwable $e) {
                report($e);
            }
        });

        // Databases: one only this app used goes with it (the delete dialog
        // names them); one other apps share is detached and keeps running.
        $this->step($site, 'databases');
        foreach (DplyDatabases::for($site) as $database) {
            $this->bestEffort($site, 'database '.$database->name, fn () => $database->sites()->count() > 1
                ? DplyDatabases::detach($site, $database)
                : DplyDatabases::delete($database, $site));
        }

        $this->bestEffort($site, 'storage keys', fn () => app(EdgeBucketKeys::class)->forgetSite($site));

        $this->step($site, 'deployments');
        $backend?->unpublish($site);

        $site->edgeDeployments()->delete();
        $site->delete();

        $this->deleteOrphanedEdgeServer($serverId, $server);
    }

    /** Where the danger page's progress list reads from (meta.edge.teardown). */
    private function step(Site $site, string $step): void
    {
        $teardown = is_array($site->edgeMeta()['teardown'] ?? null) ? $site->edgeMeta()['teardown'] : [];
        $site->mergeEdgeMeta(['teardown' => ['step' => $step, 'at' => now()->toIso8601String()] + $teardown]);
        $site->saveQuietly();
    }

    /** Teardown never stops for one failed cleanup; an orphan is logged instead. */
    private function bestEffort(Site $site, string $what, \Closure $cleanup): void
    {
        try {
            $cleanup();
        } catch (\Throwable $e) {
            Log::warning('Edge teardown: '.$what.' cleanup failed', [
                'site_id' => $site->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function deleteOrphanedEdgeServer(?string $serverId, ?Server $server): void
    {
        if ($serverId === null || $server === null || ! $server->isDplyEdgeHost()) {
            return;
        }

        if (Site::query()->where('server_id', $serverId)->exists()) {
            return;
        }

        $server->delete();
    }
}
