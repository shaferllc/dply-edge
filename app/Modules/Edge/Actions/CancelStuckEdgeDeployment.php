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
        self::restoreSiteStatus($site);
    }

    /**
     * Builds whose worker died: nothing retries them for Redis retry_after
     * (~2h) and nothing marks them failed, so they sit at "building" and hold
     * the org's slot. A live build is killed by its own timeout, so one still
     * in flight past timeout + 15 min (the slot TTL) has no worker left.
     */
    public function reapStuck(): int
    {
        $reaped = 0;
        $inFlight = EdgeDeployment::query()
            ->whereIn('status', [EdgeDeployment::STATUS_BUILDING, EdgeDeployment::STATUS_PUBLISHING])
            ->whereNotNull('build_started_at')
            ->where('build_started_at', '<', now()->subMinutes(15))
            ->get();

        foreach ($inFlight as $deployment) {
            $site = Site::find($deployment->site_id);
            $timeout = (int) ($site?->organization?->tierAllowances()['build_timeout_minutes'] ?? 20);
            if ($site === null || $deployment->build_started_at->gt(now()->subMinutes($timeout + 15))) {
                continue;
            }
            $deployment->markCancelledByOperator(__('The build stopped responding after :minutes minutes — the worker running it exited. Deploy again.', ['minutes' => $timeout + 15]));
            $this->killBuildContainer($deployment);
            EdgeBuildSlots::releaseFor($site->organization);
            self::restoreSiteStatus($site);
            $reaped++;
        }

        return $reaped;
    }

    /**
     * After a deploy stops without publishing: still provisioning if another
     * is in flight, active if an earlier deploy is live, otherwise failed.
     */
    public static function restoreSiteStatus(Site $site): void
    {
        $deployments = EdgeDeployment::query()->where('site_id', $site->id);
        $status = match (true) {
            (clone $deployments)->whereIn('status', [EdgeDeployment::STATUS_BUILDING, EdgeDeployment::STATUS_PUBLISHING])->exists() => Site::STATUS_EDGE_PROVISIONING,
            (clone $deployments)->where('status', EdgeDeployment::STATUS_LIVE)->exists() => Site::STATUS_EDGE_ACTIVE,
            default => Site::STATUS_EDGE_FAILED,
        };
        $site->update(['status' => $status]);
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

        // Sets meta.cancelled too, so the job cannot flip it back to publishing.
        $deployment->markCancelledByOperator($reason);

        EdgeBuildSlots::releaseFor($site->organization);
        $this->killBuildContainer($deployment);
    }

    /**
     * A new deploy makes every older in-flight build for the site pointless:
     * cancel them so the new one gets the build slot now instead of waiting.
     */
    public function supersedeInFlight(Site $site, EdgeDeployment $newest): void
    {
        $older = EdgeDeployment::query()
            ->where('site_id', $site->id)
            ->whereKeyNot($newest->getKey())
            ->whereIn('status', [EdgeDeployment::STATUS_BUILDING, EdgeDeployment::STATUS_PUBLISHING])
            ->get();

        foreach ($older as $deployment) {
            $deployment->markCancelledByOperator(__('Cancelled — a newer deploy (:id) replaced it.', ['id' => $newest->id]));
            $this->killBuildContainer($deployment);
        }

        if ($older->isNotEmpty()) {
            EdgeBuildSlots::releaseFor($site->organization);
        }
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
