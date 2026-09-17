<?php

declare(strict_types=1);

namespace App\Modules\Edge\Actions;

use App\Models\EdgeDeployment;
use App\Models\Site;
use App\Modules\Edge\Services\EdgeRouter;

class RollbackEdgeDeployment
{
    public function handle(Site $site, string $deploymentId): EdgeDeployment
    {
        if (! $site->usesEdgeRuntime()) {
            throw new \RuntimeException('Site is not an Edge site.');
        }

        $deployment = EdgeDeployment::query()
            ->where('site_id', $site->id)
            ->whereIn('status', [
                EdgeDeployment::STATUS_LIVE,
                EdgeDeployment::STATUS_SUPERSEDED,
            ])
            ->find($deploymentId);

        if ($deployment === null) {
            throw new \RuntimeException('Deployment not found or not eligible for rollback.');
        }

        $activeId = $site->edgeMeta()['active_deployment_id'] ?? null;
        if ($activeId === $deployment->id) {
            throw new \RuntimeException('That deployment is already live.');
        }

        // Container sites run one Worker + container per site, so the host map
        // can't point back at an old image: roll back by rebuilding that commit.
        if (($site->edgeMeta()['runtime_mode'] ?? '') === 'container') {
            if (($deployment->git_commit ?? '') === '') {
                throw new \RuntimeException('That deployment has no recorded commit to rebuild.');
            }

            return (new RedeployEdgeSite)->handle($site, (string) $deployment->git_commit);
        }

        if ($deployment->storage_prefix === null) {
            $short = substr((string) $deployment->git_commit, 0, 7);
            $ref = $short !== '' ? $short : $deployment->id;
            throw new \RuntimeException(
                'Artifacts for that deployment were pruned. Use "Deploy a specific commit" to rebuild from '.$ref.'.'
            );
        }

        $backend = EdgeRouter::backendFor($site);
        if ($backend === null) {
            throw new \RuntimeException('No edge backend available for this site.');
        }

        $result = $backend->republishDeployment($deployment, $site);

        EdgeDeployment::query()
            ->where('site_id', $site->id)
            ->where('status', EdgeDeployment::STATUS_LIVE)
            ->update(['status' => EdgeDeployment::STATUS_SUPERSEDED]);

        $deployment->update([
            'status' => EdgeDeployment::STATUS_LIVE,
            'published_at' => now(),
            'cf_kv_version' => $result['cf_kv_version'],
        ]);

        $meta = $site->edgeMeta();
        $meta['active_deployment_id'] = $deployment->id;
        if (is_string($result['live_url'] ?? null) && $result['live_url'] !== '') {
            $meta['live_url'] = $result['live_url'];
        }
        unset($meta['last_error'], $meta['last_error_at']);

        $site->update([
            'status' => Site::STATUS_EDGE_ACTIVE,
            'meta' => array_merge(is_array($site->meta) ? $site->meta : [], ['edge' => $meta]),
        ]);

        return $deployment->refresh();
    }
}
