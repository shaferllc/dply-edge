<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Livewire\Sites\EdgeDeploymentDetail;
use App\Livewire\Sites\EdgeSettings;
use App\Models\EdgeDeployment;
use App\Models\Site;
use App\Modules\Edge\Actions\PromoteEdgePreview;
use App\Modules\Edge\Actions\QueueEdgeDeployReplay;
use App\Modules\Edge\Actions\RollbackEdgeDeployment;
use App\Modules\Edge\Actions\UpdateEdgeSplitTraffic;
use App\Modules\Edge\Services\EdgePreviewReviewState;
use App\Modules\Edge\Support\EdgeDeploymentConfirmSummary;
use App\Services\DeployContract\DeployContractState;
use Livewire\Component;

/**
 * Rollback + promote confirmations shared by {@see EdgeSettings}
 * and {@see EdgeDeploymentDetail}.
 *
 * @phpstan-require-extends Component
 *
 * @property Site $site
 * @property EdgeDeployment|null $deployment Present on deployment detail surfaces only.
 *
 * @method void openConfirmActionModal(string $method, mixed $arguments = [], string $title = 'Confirm action', string $message = 'Are you sure?', string $confirmLabel = 'Confirm', bool $destructive = false, ?list<array{label: string, value: string, mono?: bool, multiline?: bool, link?: bool}> $details = null)
 */
trait ManagesEdgeDeploymentLifecycle
{
    use DispatchesToastNotifications;
    use ManagesDeployContract;

    /**
     * Opens the shared confirm-action modal before rolling production
     * back to a prior deployment.
     */
    public function confirmRollbackEdgeDeployment(string $deploymentId): void
    {
        if (! $this->site->usesEdgeRuntime()) {
            return;
        }
        $this->authorize('update', $this->site);

        $deployment = EdgeDeployment::query()
            ->where('site_id', $this->site->id)
            ->find($deploymentId);

        if ($deployment === null) {
            $this->toastError(__('Deployment not found.'));

            return;
        }

        $message = __('Production will serve this deployment\'s published artifacts. The current live deployment will be marked superseded.');

        $this->openConfirmActionModal(
            'rollbackEdgeDeployment',
            [$deploymentId],
            __('Roll back to this deployment?'),
            $message,
            __('Roll back'),
            false,
            EdgeDeploymentConfirmSummary::rollbackRows($this->site, $deployment),
        );
    }

    public function rollbackEdgeDeployment(string $deploymentId): void
    {
        if (! $this->site->usesEdgeRuntime()) {
            return;
        }
        $this->authorize('update', $this->site);

        try {
            (new RollbackEdgeDeployment)->handle($this->site, $deploymentId);
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());

            return;
        }

        $this->site->refresh();

        if ($this->deployment instanceof EdgeDeployment) {
            $this->deployment->refresh();
        }

        $this->toastSuccess(($this->site->edgeMeta()['runtime_mode'] ?? '') === 'container'
            ? __('Rolling back — rebuilding that commit. It goes live when the deploy finishes.')
            : __('Rolled back — the selected deployment is now live.'));
    }

    /**
     * Opens the confirm-action modal before copying a preview's artifacts
     * into a fresh parent prefix and pointing production at them.
     */
    public function confirmPromoteEdgePreview(string $previewSiteId): void
    {
        if (! $this->site->usesEdgeRuntime() || $this->site->isEdgePreview()) {
            return;
        }
        $this->authorize('update', $this->site);

        $preview = Site::query()->find($previewSiteId);
        if ($preview === null
            || $preview->organization_id !== $this->site->organization_id
            || ($preview->edgeMeta()['preview_parent_site_id'] ?? null) !== $this->site->id) {
            $this->toastError(__('Preview not found or not a child of this site.'));

            return;
        }

        $previewDeployment = EdgeDeployment::query()
            ->where('site_id', $preview->id)
            ->where('status', EdgeDeployment::STATUS_LIVE)
            ->latest('published_at')
            ->first();

        if ($previewDeployment === null) {
            $this->toastError(__('Preview has no live deployment to promote.'));

            return;
        }

        $reviewState = app(EdgePreviewReviewState::class);
        $review = $reviewState->forPreview($preview);
        if ($blocked = $reviewState->promoteBlockedMessage($review)) {
            $this->toastError($blocked);

            return;
        }

        $contractState = app(DeployContractState::class);
        $contract = $contractState->forPreview($this->site, $preview);
        if ($blocked = $contractState->promoteBlockedMessage($contract)) {
            $this->toastError($blocked);

            return;
        }

        $message = __('Copy this preview\'s artifacts into a fresh production prefix and flip the host map. The preview keeps running.');

        $this->openConfirmActionModal(
            'promoteEdgePreview',
            [$previewSiteId],
            __('Promote this preview to production?'),
            $message,
            __('Promote to production'),
            false,
            array_merge(
                EdgeDeploymentConfirmSummary::promoteRows($this->site, $preview, $previewDeployment),
                $reviewState->confirmModalRows($review),
                $contractState->confirmModalRows($contract),
            ),
        );
    }

    public function promoteEdgePreview(string $previewSiteId): void
    {
        if (! $this->site->usesEdgeRuntime() || $this->site->isEdgePreview()) {
            return;
        }
        $this->authorize('update', $this->site);

        try {
            $deployment = app(PromoteEdgePreview::class)->handle($this->site, $previewSiteId);
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());

            return;
        }

        $org = $this->site->organization;
        if ($org !== null) {
            audit_log($org, auth()->user(), 'site.edge.preview.promoted', $this->site, null, [
                'preview_site_id' => $previewSiteId,
                'deployment_id' => $deployment->id,
                'commit' => $deployment->git_commit,
                'branch' => $deployment->git_branch,
            ]);
        }

        $this->site->refresh();

        $this->toastSuccess(__('Preview promoted — production is now serving the preview build.'));
    }

    /**
     * Configure A/B split traffic from a preview row. Percentage 0
     * clears the split so the slider can toggle the experiment off
     * without a separate button. See {@see UpdateEdgeSplitTraffic}.
     */
    public function saveEdgeSplitTraffic(string $previewSiteId, int $percentage, bool $sticky = true): void
    {
        if (! $this->site->usesEdgeRuntime() || $this->site->isEdgePreview()) {
            return;
        }
        $this->authorize('update', $this->site);

        try {
            if ($percentage <= 0) {
                app(UpdateEdgeSplitTraffic::class)->clear($this->site);
            } else {
                app(UpdateEdgeSplitTraffic::class)->configure(
                    $this->site,
                    $previewSiteId,
                    max(1, min(99, $percentage)),
                    $sticky,
                );
            }
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());

            return;
        }

        $this->site->refresh();

        $this->toastSuccess($percentage > 0
            ? __('Split traffic: routing :pct% of production traffic to the preview.', ['pct' => $percentage])
            : __('Split traffic cleared — production is back to 100%.'));
    }

    /**
     * Sample recent production GET/HEAD traffic and replay against a live preview.
     */
    public function queueEdgeDeployReplay(string $previewSiteId): void
    {
        if (! $this->site->usesEdgeRuntime() || $this->site->isEdgePreview()) {
            return;
        }

        $this->authorize('update', $this->site);

        try {
            app(QueueEdgeDeployReplay::class)->handle(
                auth()->user(),
                $this->site,
                $previewSiteId,
            );
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());

            return;
        }

        $this->toastSuccess(__('Shadow replay queued — sampling production traffic against the preview.'));
    }
}
