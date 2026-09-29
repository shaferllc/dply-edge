<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Edge;

use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Models\Site;
use App\Modules\Edge\Actions\RedeployEdgeSite;
use Livewire\Component;

/**
 * @phpstan-require-extends Component
 *
 * @property Site $site
 */
trait ManagesEdgeRedeploy
{
    use DispatchesToastNotifications;

    public function redeployEdge(): void
    {
        if (! $this->site->usesEdgeRuntime()) {
            return;
        }
        $this->authorize('deploy', $this->site);

        try {
            (new RedeployEdgeSite)->handle($this->site);
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());

            return;
        }

        // Stay on this page: the deploy pill at the bottom shows the progress.
        $this->toastSuccess(__('Deploy queued.'));
    }
}
