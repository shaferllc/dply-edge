<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Edge;

use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Models\EdgeDeployment;
use App\Models\Site;
use App\Modules\Edge\Jobs\TeardownEdgeSiteJob;
use App\Modules\Edge\Services\EdgeGithubWebhookProvisioner;
use App\Modules\Edge\Services\EdgeHostMapPublisher;
use Livewire\Component;

/**
 * @phpstan-require-extends Component
 *
 * @property Site $site
 */
trait ManagesEdgeDanger
{
    use DispatchesToastNotifications;

    public function openEdgeTeardownModal(): void
    {
        if (! $this->site->usesEdgeRuntime()) {
            return;
        }
        $this->authorize('delete', $this->site);
        $this->dispatch('open-modal', 'edge-teardown-confirmation');
    }

    public function tearDownEdge(string $confirmation = ''): void
    {
        if (! $this->site->usesEdgeRuntime()) {
            return;
        }
        $this->authorize('delete', $this->site);

        if (trim($confirmation) !== $this->site->name) {
            $this->toastError(__('Type the site name exactly to confirm.'));

            return;
        }

        $this->site->mergeEdgeMeta(['teardown' => ['started_at' => now()->toIso8601String()]]);
        $this->site->forceFill(['status' => Site::STATUS_EDGE_DELETING])->save();
        TeardownEdgeSiteJob::dispatch($this->site->id);

        $this->dispatch('close-modal', 'edge-teardown-confirmation');
        $this->toastSuccess(__('Deleting :name.', ['name' => $this->site->name]));
    }

    /** Visitors get the paused page; deployments, domains and previews stay. */
    public function pauseEdgeSite(): void
    {
        $this->setEdgePaused(true);
        $this->toastSuccess(__('Paused :name.', ['name' => $this->site->name]));
    }

    public function resumeEdgeSite(): void
    {
        $this->setEdgePaused(false);
        $this->toastSuccess(__('Resumed :name.', ['name' => $this->site->name]));
    }

    public function disconnectEdgeRepository(EdgeGithubWebhookProvisioner $provisioner): void
    {
        if (! $this->site->usesEdgeRuntime()) {
            return;
        }
        $this->authorize('update', $this->site);

        $provisioner->disable($this->site->fresh());
        $this->site->refresh();

        $this->toastSuccess(__('Automatic deploys stopped. The live deployment keeps serving.'));
    }

    private function setEdgePaused(bool $paused): void
    {
        if (! $this->site->usesEdgeRuntime() || $this->site->isEdgePreview()) {
            return;
        }
        $this->authorize('update', $this->site);

        $this->site->mergeEdgeMeta(['paused_at' => $paused ? now()->toIso8601String() : null]);
        $this->site->save();

        // Takes effect without a redeploy, like the maintenance toggle.
        $live = EdgeDeployment::query()
            ->where('site_id', $this->site->id)
            ->where('status', EdgeDeployment::STATUS_LIVE)
            ->latest('id')
            ->first();
        if ($live !== null) {
            try {
                app(EdgeHostMapPublisher::class)->publish($this->site->fresh(), $live);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        audit_log($this->site->organization, auth()->user(), $paused ? 'site.edge.paused' : 'site.edge.resumed', $this->site);
    }
}
