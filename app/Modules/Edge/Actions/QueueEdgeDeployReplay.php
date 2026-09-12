<?php

declare(strict_types=1);

namespace App\Modules\Edge\Actions;

use App\Modules\Edge\Jobs\RunEdgeDeployReplayJob;
use App\Models\EdgeDeployment;
use App\Models\EdgeDeployReplay;
use App\Models\Site;
use App\Models\User;
use App\Modules\Edge\Services\EdgeDeployReplaySampler;
use RuntimeException;

class QueueEdgeDeployReplay
{
    public function __construct(
        private EdgeDeployReplaySampler $sampler,
    ) {}

    public function handle(User $user, Site $parent, string $previewSiteId, int $sampleLimit = 20, int $windowMinutes = 60): EdgeDeployReplay
    {
        if (! $parent->usesEdgeRuntime() || $parent->isEdgePreview()) {
            throw new RuntimeException('Deploy replay runs from the production Edge site against a preview.');
        }

        $preview = Site::query()->find($previewSiteId);
        if ($preview === null
            || $preview->organization_id !== $parent->organization_id
            || ($preview->edgeMeta()['preview_parent_site_id'] ?? null) !== $parent->id) {
            throw new RuntimeException('Preview not found or not a child of this site.');
        }

        $liveDeployment = EdgeDeployment::query()
            ->where('site_id', $preview->id)
            ->where('status', EdgeDeployment::STATUS_LIVE)
            ->latest('published_at')
            ->first();

        if ($liveDeployment === null || $liveDeployment->storage_prefix === null) {
            throw new RuntimeException('Preview has no live deployment — redeploy the preview first.');
        }

        $samples = $this->sampler->sample($parent, $sampleLimit, $windowMinutes);
        if ($samples === []) {
            throw new RuntimeException('No recent GET/HEAD production traffic to replay. Wait for traffic or widen the sample window.');
        }

        $replay = EdgeDeployReplay::query()->create([
            'organization_id' => $parent->organization_id,
            'parent_site_id' => $parent->id,
            'preview_site_id' => $preview->id,
            'preview_deployment_id' => $liveDeployment->id,
            'triggered_by_user_id' => $user->id,
            'status' => EdgeDeployReplay::STATUS_QUEUED,
            'sample_limit' => max(1, min(50, $sampleLimit)),
            'window_minutes' => max(5, min(24 * 60, $windowMinutes)),
            'samples' => $samples,
        ]);

        RunEdgeDeployReplayJob::dispatch($replay->id);

        return $replay;
    }
}
