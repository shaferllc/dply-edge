<?php

declare(strict_types=1);

namespace App\Modules\Edge\Jobs;

use App\Models\Server;
use App\Models\Site;
use App\Modules\Edge\Services\Containers\EdgeContainerDeployer;
use App\Modules\Edge\Services\EdgeMiddlewareBundleUploader;
use App\Modules\Edge\Services\EdgeRouter;
use App\Modules\Edge\Services\EdgeSsrBundleUploader;
use App\Modules\Providers\Cloudflare\EdgeCloudflareClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class TeardownEdgeSiteJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

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

        // Drop every per-deployment SSR script in the dispatch
        // namespace BEFORE wiping the deployment rows — once the rows
        // are gone we lose the script names and the scripts would
        // sit in the namespace forever, consuming quota.
        try {
            app(EdgeSsrBundleUploader::class)->deleteAllForSite($site);
        } catch (\Throwable) {
            // Best-effort — leaving an orphan script is preferable to
            // failing the rest of the teardown.
        }

        try {
            app(EdgeMiddlewareBundleUploader::class)->deleteAllForSite($site);
        } catch (\Throwable) {
            // Same — orphan middleware scripts are non-blocking.
        }

        if (($site->edgeMeta()['runtime_mode'] ?? '') === 'container') {
            try {
                $client = EdgeCloudflareClient::fromConfig();
                $script = EdgeContainerDeployer::scriptName($site);
                $client->deleteDispatchScript((string) config('edge.cloudflare.dispatch_namespace_name'), $script);
                // wrangler names the container application after the script.
                foreach ($client->listContainerApplications() as $application) {
                    if (str_starts_with($application['name'], $script)) {
                        $client->deleteContainerApplication($application['id']);
                    }
                }
            } catch (\Throwable) {
                // Best-effort, like the SSR scripts above.
            }
        }

        $backend?->unpublish($site);

        $site->edgeDeployments()->delete();
        $site->delete();

        $this->deleteOrphanedEdgeServer($serverId, $server);
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
