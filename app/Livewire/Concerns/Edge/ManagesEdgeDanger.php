<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Edge;

use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Models\Site;
use App\Modules\Edge\Jobs\TeardownEdgeSiteJob;
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

    public function tearDownEdge(): void
    {
        if (! $this->site->usesEdgeRuntime()) {
            return;
        }
        $this->authorize('delete', $this->site);

        $this->site->forceFill(['status' => Site::STATUS_EDGE_DELETING])->save();
        TeardownEdgeSiteJob::dispatch($this->site->id);

        $this->dispatch('close-modal', 'edge-teardown-confirmation');
        $this->toastSuccess(__('Deleting :name.', ['name' => $this->site->name]));
    }
}
