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
        $this->authorize('update', $this->site);

        try {
            (new RedeployEdgeSite)->handle($this->site);
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());

            return;
        }

        $this->toastSuccess(__('Deploy queued.'));

        // Land on Deploys so the build journey + history are visible (BYO opens
        // the Deploy Console; Edge progress lives on this section).
        $section = $this->currentEdgeSection();
        if ($section !== null && $section !== 'deploys') {
            $this->redirect(route('sites.show', [
                'server' => $this->server,
                'site' => $this->site,
                'section' => 'deploys',
            ]), navigate: true);
        }
    }

    /**
     * The workspace section the host component is rendering. Only the full Edge
     * settings page tracks one; the embedded panels do not, and they never
     * redirect after a queued deploy.
     */
    protected function currentEdgeSection(): ?string
    {
        return null;
    }
}
