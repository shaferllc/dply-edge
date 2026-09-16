<?php

declare(strict_types=1);

namespace App\Modules\Edge\Jobs;

use App\Models\EdgeDeployment;
use App\Models\Site;
use App\Modules\Billing\Jobs\SyncOrganizationBillingJob;
use App\Modules\Edge\Services\EdgeHostMapPublisher;
use App\Modules\Edge\Services\EdgeLoadBalancerProvisioner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Apply a site's load balancing settings to Cloudflare, point the host map's
 * origin at the load balancer (or back at the plain origin), and re-sync
 * billing — edgeMeta changes don't trip SiteBillingObserver.
 */
class SyncEdgeLoadBalancerJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public int $uniqueFor = 60;

    public function __construct(public string $siteId) {}

    public function uniqueId(): string
    {
        return $this->siteId;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [15, 60];
    }

    public function handle(EdgeLoadBalancerProvisioner $provisioner, EdgeHostMapPublisher $publisher): void
    {
        $site = Site::find($this->siteId);
        if ($site === null) {
            return;
        }

        try {
            $provisioner->sync($site);
        } finally {
            $live = EdgeDeployment::query()
                ->where('site_id', $site->id)
                ->where('status', EdgeDeployment::STATUS_LIVE)
                ->latest('id')
                ->first();
            if ($live !== null) {
                $publisher->publish($site->fresh(), $live);
            }

            if ($site->organization_id) {
                SyncOrganizationBillingJob::dispatch((string) $site->organization_id, 'load_balancing');
            }
        }
    }
}
