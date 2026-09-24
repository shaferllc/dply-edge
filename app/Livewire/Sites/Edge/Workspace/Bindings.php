<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Edge\Workspace;

use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Livewire\Concerns\Edge\ManagesEdgeDashboardBindings;
use App\Livewire\Concerns\Edge\MountsEdgeWorkspaceSection;
use App\Models\Server;
use App\Models\Site;
use App\Modules\Edge\Support\EdgeEffectiveBindings;
use App\Support\Sites\EdgeSiteViewData;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Bindings editor — interactive counterpart to the read-only runtime-bindings
 * panel. Repo wrangler.toml rows are read-only; dashboard rows live on edgeMeta
 * and merge at deploy time via {@see EdgeEffectiveBindings}.
 */
class Bindings extends Component
{
    use ConfirmsActionWithModal;
    use DispatchesToastNotifications;
    use ManagesEdgeDashboardBindings;
    use MountsEdgeWorkspaceSection;

    public function mount(Server $server, Site $site): void
    {
        $this->mountEdgeWorkspaceSection($server, $site);
        $this->refreshEdgeDashboardBindingsFromMeta();
    }

    public function render(): View
    {
        return view('livewire.sites.edge.workspace.bindings', array_merge(
            EdgeSiteViewData::context($this->site, 'bindings'),
            [
                'server' => $this->server,
                'site' => $this->site,
                'repoBindings' => $this->edgeRepoBindings(),
                'kinds' => EdgeEffectiveBindings::KINDS,
                'hasWorker' => $this->edgeSiteHasWorker(),
            ],
        ));
    }
}
