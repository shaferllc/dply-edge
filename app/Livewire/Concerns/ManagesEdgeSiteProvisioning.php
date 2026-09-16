<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Models\EdgeDeployment;
use App\Models\Site;
use App\Modules\Edge\Actions\RedeployEdgeSite;
use App\Modules\Edge\Services\EdgeSiteCanceller;
use Livewire\Attributes\On;

trait ManagesEdgeSiteProvisioning
{
    use DispatchesToastNotifications;

    #[On('site-provisioning-updated')]
    public function refreshProvisioningStatus(string $siteId): void
    {
        if ((string) $this->site->id !== $siteId) {
            return;
        }

        $this->site->refresh();
    }

    public function pollProvisioningStatus(): void
    {
        if ($this->site->isReadyForWorkspace()) {
            return;
        }

        $this->site->refresh();
    }

    public function retryProvisioning(): void
    {
        $this->authorize('update', $this->site);

        $this->site->refresh();

        if ($this->site->isReadyForWorkspace()) {
            $this->toastSuccess(__('This site is already configured.'));

            return;
        }

        try {
            (new RedeployEdgeSite)->handle($this->site->fresh());
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());

            return;
        }

        $this->site->refresh();
        $this->toastSuccess(__('Edge build queued again.'));
    }

    public function openCancelProvisioningModal(): void
    {
        $this->authorize('update', $this->site);

        $this->openConfirmActionModal(
            'cancelProvisioning',
            [],
            __('Cancel Edge build?'),
            __('This stops the build, removes any partial deployment from the edge network, and deletes the pending site. If you cancel this dialog, the build keeps running.'),
            __('Cancel and remove site'),
            true,
        );
    }

    public function cancelProvisioning(EdgeSiteCanceller $edgeCanceller): void
    {
        $this->authorize('update', $this->site);

        $this->site->refresh();

        // Flip the deployment row first so the journey UI stops showing
        // BUILDING even if teardown / the queue worker is slow. Build +
        // publish jobs honor meta.cancelled and will not resurrect status.
        $this->markInFlightDeploymentCancelled();

        // If the site has ever had a successful publish, cancelling the
        // current in-flight build should NOT delete the app — just abort
        // this deployment and leave the live one serving. Full teardown
        // is reserved for the first-deploy case where there's nothing
        // worth keeping. PublishEdgeDeploymentJob sets active_deployment_id
        // on first success, and it persists across redeploys.
        $activeDeploymentId = $this->site->edgeMeta()['active_deployment_id'] ?? null;
        if (is_string($activeDeploymentId) && $activeDeploymentId !== '') {
            $this->site->update(['status' => Site::STATUS_EDGE_ACTIVE]);
            $this->toastSuccess(__('Build cancelled. The previous deployment is still serving.'));

            return;
        }

        // Mark failed so the journey stops showing BUILDING if redirect
        // is slow; teardown runs async so Cloudflare/R2 cleanup cannot
        // leave the confirm modal stuck on "Working…".
        $this->site->update(['status' => Site::STATUS_EDGE_FAILED]);

        try {
            $edgeCanceller->cancel($this->site->fresh(['server', 'domains']));
            $this->toastSuccess(__('Build cancelled. Removing this Edge site…'));
            $this->skipRender();
            $this->redirect(route('edge.index'), navigate: false);
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());
        }
    }

    /**
     * Mark the newest in-flight deployment cancelled so workers bail out
     * and the Build Journey flips off BUILDING immediately.
     */
    protected function markInFlightDeploymentCancelled(): void
    {
        $deployment = EdgeDeployment::query()
            ->where('site_id', $this->site->id)
            ->whereIn('status', [
                EdgeDeployment::STATUS_BUILDING,
                EdgeDeployment::STATUS_PUBLISHING,
            ])
            ->orderByDesc('created_at')
            ->first();

        // Also catch a row that was just cancelled but a racing job reset
        // status — prefer the latest non-live deployment.
        if ($deployment === null) {
            $deployment = EdgeDeployment::query()
                ->where('site_id', $this->site->id)
                ->whereNotIn('status', [
                    EdgeDeployment::STATUS_LIVE,
                    EdgeDeployment::STATUS_SUPERSEDED,
                ])
                ->orderByDesc('created_at')
                ->first();
        }

        $deployment?->markCancelledByOperator(__('Cancelled by user.'));
    }
}
