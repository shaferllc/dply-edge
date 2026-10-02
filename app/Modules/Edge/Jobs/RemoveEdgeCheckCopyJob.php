<?php

declare(strict_types=1);

namespace App\Modules\Edge\Jobs;

use App\Models\EdgeDeployment;
use App\Models\Site;
use App\Modules\Edge\Services\Containers\EdgeContainerDeployer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Takes down a deploy's check copy once every location has stopped routing
 * to it (dispatched with that delay after the handoff back). The deploy no
 * longer sleeps for this itself: that was 60s of every container deploy.
 * A newer deploy in flight owns the copy now and is left alone.
 */
final class RemoveEdgeCheckCopyJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $siteId,
        public string $deploymentId,
    ) {}

    public function handle(EdgeContainerDeployer $deployer): void
    {
        $site = Site::query()->find($this->siteId);
        if ($site === null) {
            return;
        }
        $newer = EdgeDeployment::query()
            ->where('site_id', $site->id)
            ->whereKeyNot($this->deploymentId)
            ->whereIn('status', [EdgeDeployment::STATUS_BUILDING, EdgeDeployment::STATUS_PUBLISHING])
            ->exists();
        if (! $newer) {
            $deployer->removeCheckCopy($site);
        }
    }
}
