<?php

declare(strict_types=1);

namespace App\Modules\Edge\Actions;

use App\Models\EdgeDeployment;
use App\Models\Site;
use App\Modules\Edge\Jobs\BuildEdgeSiteJob;
use App\Support\ProductLine\ProductLineKillSwitches;
use Illuminate\Support\Str;

/**
 * Deploy a specific commit SHA. Reuses stored artifacts when the same commit
 * is already in our history (cheap KV flip via RollbackEdgeDeployment);
 * otherwise creates a new deployment and builds from that ref.
 */
class DeployEdgeCommit
{
    public function handle(Site $site, string $commitSha, ?string $branchOverride = null): EdgeDeployment
    {
        if (! $site->usesEdgeRuntime()) {
            throw new \RuntimeException('Site is not an Edge delivery site.');
        }

        if (ProductLineKillSwitches::blocksEdgeDelivery()) {
            throw new \RuntimeException('Edge delivery is paused by platform administrators.');
        }

        $sha = strtolower(trim($commitSha));
        if (preg_match('/^[a-f0-9]{7,40}$/', $sha) !== 1) {
            throw new \RuntimeException('Commit SHA must be 7–40 hex characters.');
        }

        $existing = EdgeDeployment::query()
            ->where('site_id', $site->id)
            ->whereIn('status', [EdgeDeployment::STATUS_LIVE, EdgeDeployment::STATUS_SUPERSEDED])
            ->whereNotNull('storage_prefix')
            ->where('git_commit', 'like', $sha.'%')
            ->orderByDesc('published_at')
            ->first();

        if ($existing !== null) {
            $activeId = $site->edgeMeta()['active_deployment_id'] ?? null;
            if ($activeId === $existing->id) {
                throw new \RuntimeException('That commit is already live.');
            }

            return (new RollbackEdgeDeployment)->handle($site, $existing->id);
        }

        $edge = $site->edgeMeta();
        $source = is_array($edge['source'] ?? null) ? $edge['source'] : [];
        // Caller-provided branch wins so the EdgeDeployment row reflects the
        // branch the operator actually picked in the ref browser. Falls back
        // to the site's stored default for typed-SHA / API callers.
        $branchOverride = $branchOverride !== null ? trim($branchOverride) : null;
        $branch = ($branchOverride !== null && $branchOverride !== '')
            ? $branchOverride
            : (string) ($source['branch'] ?? 'main');

        $prefix = trim((string) config('edge.r2.key_prefix', 'edge/'), '/')
            .'/'.$site->organization_id.'/'.$site->id.'/'.Str::ulid();

        $deployment = EdgeDeployment::query()->create([
            'site_id' => $site->id,
            'organization_id' => $site->organization_id,
            'status' => EdgeDeployment::STATUS_BUILDING,
            'git_branch' => $branch,
            'git_commit' => $sha,
            'storage_prefix' => $prefix,
        ]);

        $site->update(['status' => Site::STATUS_EDGE_PROVISIONING]);

        BuildEdgeSiteJob::dispatch($deployment->id, $sha);

        return $deployment;
    }
}
