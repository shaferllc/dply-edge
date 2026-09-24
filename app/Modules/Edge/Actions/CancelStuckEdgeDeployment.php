<?php

declare(strict_types=1);

namespace App\Modules\Edge\Actions;

use App\Models\EdgeDeployment;
use App\Models\Site;
use App\Modules\Edge\Services\Containers\EdgeContainerDeployer;
use App\Modules\Edge\Support\EdgeBuildSlots;
use Illuminate\Support\Facades\Process;

/**
 * Mark an in-flight Edge deployment as failed and queue a fresh build.
 * Used by the "Restart build" affordance on the build journey card when
 * the queue worker / Docker process has hung and the only recovery path
 * is to abandon the row + start over.
 *
 * We cannot reach into a running queue job to kill it cooperatively, so
 * cancelling also clears the two things a killed worker leaves behind:
 *
 *  - its per-org build-slot lock, which `handle()`'s `finally` never ran to
 *    release. On a 1-slot tier that lock blocks *every* later build for
 *    `build_timeout + 15min`, and the retries are silent — no failure row,
 *    nothing queued between them.
 *  - its deployer container, which `docker run` leaves running when the
 *    client dies; it carries on building and pushing to Cloudflare for a
 *    deployment already marked failed.
 *
 * If the old job *does* eventually complete, the new deployment will already
 * have superseded it in the host map by the time it tries to publish.
 */
class CancelStuckEdgeDeployment
{
    public function handle(Site $site, EdgeDeployment $deployment, ?string $reason = null): EdgeDeployment
    {
        $this->failInFlight($site, $deployment, $reason ?? 'Cancelled by operator — build appeared stuck.');

        return (new RedeployEdgeSite)->handle($site, $deployment->git_commit);
    }

    /**
     * Stop an in-flight build without queueing another one.
     */
    public function abandon(Site $site, EdgeDeployment $deployment): void
    {
        $this->failInFlight($site, $deployment, 'Cancelled by operator.');
    }

    private function failInFlight(Site $site, EdgeDeployment $deployment, string $reason): void
    {
        if (! $site->usesEdgeRuntime()) {
            throw new \RuntimeException('Site is not an Edge delivery site.');
        }

        if ($deployment->site_id !== $site->id) {
            throw new \RuntimeException('Deployment does not belong to this site.');
        }

        if (! in_array($deployment->status, [
            EdgeDeployment::STATUS_BUILDING,
            EdgeDeployment::STATUS_PUBLISHING,
        ], true)) {
            throw new \RuntimeException('Only in-flight deployments can be cancelled.');
        }

        $deployment->update([
            'status' => EdgeDeployment::STATUS_FAILED,
            'failed_at' => now(),
            'failure_reason' => $reason,
        ]);

        EdgeBuildSlots::releaseFor($site->organization);
        $this->killBuildContainer($deployment);
    }

    /**
     * The deployer container outlives the worker that started it, so the
     * cancelled build keeps consuming Docker (and the registry) until it
     * finishes. Best effort: a missing container is the normal case.
     */
    private function killBuildContainer(EdgeDeployment $deployment): void
    {
        Process::timeout(30)->run(['docker', 'kill', EdgeContainerDeployer::buildContainerName($deployment)]);
    }
}
