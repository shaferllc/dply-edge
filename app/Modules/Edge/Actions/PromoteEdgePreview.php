<?php

declare(strict_types=1);

namespace App\Modules\Edge\Actions;

use App\Models\EdgeDeployment;
use App\Models\Site;
use App\Modules\Edge\Services\EdgeArtifactPublisher;
use App\Modules\Edge\Services\EdgeDeliveryContextResolver;
use App\Modules\Edge\Services\EdgeRouter;
use App\Services\DeployContract\DeployContractState;
use Illuminate\Support\Str;

/**
 * Take a preview site's latest live deployment, copy its R2 artifacts
 * into a fresh parent-owned prefix, then republish the parent at the
 * copied artifacts. The original preview keeps working — promote does
 * not consume it. The new parent deployment captures the preview's
 * commit/branch so deploy history shows what was promoted.
 */
class PromoteEdgePreview
{
    public function handle(Site $parent, string $previewSiteId): EdgeDeployment
    {
        if (! $parent->usesEdgeRuntime()) {
            throw new \RuntimeException('Target is not an Edge site.');
        }

        if ($parent->isEdgePreview()) {
            throw new \RuntimeException('Cannot promote into a preview — pick the parent site.');
        }

        $preview = Site::query()->find($previewSiteId);
        if ($preview === null
            || $preview->organization_id !== $parent->organization_id
            || ($preview->edgeMeta()['preview_parent_site_id'] ?? null) !== $parent->id) {
            throw new \RuntimeException('Preview not found or not a child of this site.');
        }

        $previewDeployment = EdgeDeployment::query()
            ->where('site_id', $preview->id)
            ->where('status', EdgeDeployment::STATUS_LIVE)
            ->latest('published_at')
            ->first();

        if ($previewDeployment === null) {
            throw new \RuntimeException('Preview has no live deployment to promote.');
        }

        if ($previewDeployment->storage_prefix === null || $previewDeployment->storage_prefix === '') {
            throw new \RuntimeException('Preview artifacts were pruned — redeploy the preview before promoting.');
        }

        // Deploy-contract promote gate — enforced HERE (not just in the Livewire
        // confirm UI) so every entry point (API, direct action call) honours it.
        // Reuses the same decision function the UI consults, so waivers and the
        // feature flag are respected identically; no-op when the contract is off.
        $contractState = app(DeployContractState::class);
        if ($contractState->enabled()) {
            $blocked = $contractState->promoteBlockedMessage(
                $contractState->forPreview($parent, $preview),
            );
            if ($blocked !== null) {
                throw new \RuntimeException($blocked);
            }
        }

        // Container sites have no artifacts to copy: promoting rebuilds the
        // preview's commit on the production site.
        if (($parent->edgeMeta()['runtime_mode'] ?? '') === 'container') {
            if (($previewDeployment->git_commit ?? '') === '') {
                throw new \RuntimeException('The preview deployment has no recorded commit to promote.');
            }

            return (new RedeployEdgeSite)->handle($parent, (string) $previewDeployment->git_commit);
        }

        $backend = EdgeRouter::backendFor($parent);
        if ($backend === null) {
            throw new \RuntimeException('No edge backend available for this site.');
        }

        $newPrefix = trim((string) config('edge.r2.key_prefix', 'edge/'), '/')
            .'/'.$parent->organization_id.'/'.$parent->id.'/'.Str::ulid();

        $context = app(EdgeDeliveryContextResolver::class)->forSite($parent);
        app(EdgeArtifactPublisher::class)->copyPrefix(
            $previewDeployment->storage_prefix,
            $newPrefix,
            $context->diskName,
        );

        $promotedMeta = $previewDeployment->meta;
        $promotedMeta['promoted_from'] = [
            'preview_site_id' => (string) $preview->id,
            'preview_deployment_id' => (string) $previewDeployment->id,
            'preview_storage_prefix' => $previewDeployment->storage_prefix,
            'promoted_at' => now()->toIso8601String(),
        ];

        $deployment = EdgeDeployment::query()->create([
            'site_id' => $parent->id,
            'organization_id' => $parent->organization_id,
            'status' => EdgeDeployment::STATUS_PUBLISHING,
            'git_branch' => $previewDeployment->git_branch,
            'git_commit' => $previewDeployment->git_commit,
            'storage_prefix' => $newPrefix,
            'meta' => $promotedMeta,
        ]);

        $result = $backend->republishDeployment($deployment, $parent);

        EdgeDeployment::query()
            ->where('site_id', $parent->id)
            ->where('id', '!=', $deployment->id)
            ->where('status', EdgeDeployment::STATUS_LIVE)
            ->update(['status' => EdgeDeployment::STATUS_SUPERSEDED]);

        $deployment->update([
            'status' => EdgeDeployment::STATUS_LIVE,
            'published_at' => now(),
            'cf_kv_version' => $result['cf_kv_version'],
        ]);

        $meta = $parent->edgeMeta();
        $meta['active_deployment_id'] = $deployment->id;
        if (is_string($result['live_url'] ?? null) && $result['live_url'] !== '') {
            $meta['live_url'] = $result['live_url'];
        }
        unset($meta['last_error'], $meta['last_error_at']);

        $parent->update([
            'status' => Site::STATUS_EDGE_ACTIVE,
            'meta' => array_merge(is_array($parent->meta) ? $parent->meta : [], ['edge' => $meta]),
        ]);

        return $deployment->refresh();
    }
}
