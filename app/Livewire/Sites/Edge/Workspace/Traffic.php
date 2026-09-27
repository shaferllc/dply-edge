<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Edge\Workspace;

use App\Livewire\Concerns\Edge\MountsEdgeWorkspaceSection;
use App\Models\Server;
use App\Models\Site;
use App\Support\Sites\EdgeSiteViewData;
use App\Support\Sites\SiteSettingsViewData;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class Traffic extends Component
{
    use MountsEdgeWorkspaceSection;

    /** Flipped by wire:init so the tab paints before the Analytics Engine calls. */
    public bool $loaded = false;

    public function mount(Server $server, Site $site): void
    {
        $this->mountEdgeWorkspaceSection($server, $site);
    }

    public function loadTraffic(): void
    {
        $this->loaded = true;
    }

    public function render(): View
    {
        if (! $this->loaded) {
            return view('livewire.sites.edge.workspace.traffic', ['loaded' => false]);
        }

        return view('livewire.sites.edge.workspace.traffic', array_merge(
            EdgeSiteViewData::context($this->site, 'traffic'),
            SiteSettingsViewData::edgeSectionAnalytics($this->site, 'traffic'),
            [
                'server' => $this->server,
                'site' => $this->site,
                'loaded' => true,
            ],
        ));
    }
}
