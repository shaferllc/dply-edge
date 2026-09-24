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

    public function mount(Server $server, Site $site): void
    {
        $this->mountEdgeWorkspaceSection($server, $site);
    }

    public function render(): View
    {
        return view('livewire.sites.edge.workspace.traffic', array_merge(
            EdgeSiteViewData::context($this->site, 'traffic'),
            SiteSettingsViewData::edgeSectionAnalytics($this->site, 'traffic'),
            [
                'server' => $this->server,
                'site' => $this->site,
            ],
        ));
    }
}
