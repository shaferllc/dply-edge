<?php

declare(strict_types=1);

namespace App\Modules\Edge\Actions;

use App\Models\EdgeDeployment;
use App\Models\Site;
use App\Modules\Edge\Jobs\BuildEdgeSiteJob;
use App\Support\ProductLine\ProductLineKillSwitches;
use Illuminate\Support\Str;

/**
 * Queue a fresh Edge deployment for an existing site — used by
 * manual redeploys and GitHub push webhooks.
 */
class RedeployEdgeSite
{
    public function handle(Site $site, ?string $gitCommit = null): EdgeDeployment
    {
        if (! $site->usesEdgeRuntime()) {
            throw new \RuntimeException('Site is not an Edge delivery site.');
        }

        if (ProductLineKillSwitches::blocksEdgeDelivery()) {
            throw new \RuntimeException('Edge delivery is paused by platform administrators.');
        }

        $edge = $site->edgeMeta();
        $source = is_array($edge['source'] ?? null) ? $edge['source'] : [];
        $branch = (string) ($source['branch'] ?? 'main');

        $prefix = trim((string) config('edge.r2.key_prefix', 'edge/'), '/')
            .'/'.$site->organization_id.'/'.$site->id.'/'.Str::ulid();

        $deployment = EdgeDeployment::query()->create([
            'site_id' => $site->id,
            'organization_id' => $site->organization_id,
            'status' => EdgeDeployment::STATUS_BUILDING,
            'git_branch' => $branch,
            'git_commit' => $gitCommit,
            'storage_prefix' => $prefix,
        ]);

        // Clear the lingering `last_error` from the previous failed deploy.
        // It's noisy to keep showing a red "Last error" banner on the hero
        // once the operator has kicked off a fresh build — if THIS build
        // fails, markFailed() will repopulate the field.
        $meta = $site->meta;
        $edgeMeta = is_array($meta['edge'] ?? null) ? $meta['edge'] : [];
        unset($edgeMeta['last_error'], $edgeMeta['last_error_at']);
        $meta['edge'] = $edgeMeta;

        $site->update([
            'status' => Site::STATUS_EDGE_PROVISIONING,
            'meta' => $meta,
        ]);

        BuildEdgeSiteJob::dispatch($deployment->id);

        return $deployment;
    }
}
