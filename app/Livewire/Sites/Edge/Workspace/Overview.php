<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Edge\Workspace;

use App\Livewire\Concerns\Edge\ManagesEdgeRedeploy;
use App\Livewire\Concerns\Edge\MountsEdgeWorkspaceSection;
use App\Models\EdgeDeployment;
use App\Models\Server;
use App\Models\Site;
use App\Support\Sites\EdgeSiteViewData;
use App\Support\Sites\SiteShowViewData;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class Overview extends Component
{
    use ManagesEdgeRedeploy;
    use MountsEdgeWorkspaceSection;

    public function mount(Server $server, Site $site): void
    {
        $this->mountEdgeWorkspaceSection($server, $site);

        $this->site->load([
            'edgeDeployments' => fn ($query) => $query->orderByDesc('created_at')->limit(5),
        ]);
    }

    public function render(): View
    {
        // Surface the same live build-journey card the deployment-detail page
        // uses, scoped to whichever deployment is currently in flight. Lets
        // the operator watch progress without leaving the workspace overview.
        $latestDeployment = $this->site->edgeDeployments->first();
        $isInProgress = $latestDeployment !== null && in_array($latestDeployment->status, [
            EdgeDeployment::STATUS_BUILDING,
            EdgeDeployment::STATUS_PUBLISHING,
        ], true);

        $deploymentJourney = $isInProgress
            ? SiteShowViewData::edgeDeploymentJourney($latestDeployment)
            : null;

        return view('livewire.sites.edge.workspace.overview', array_merge(
            EdgeSiteViewData::context($this->site, 'general'),
            [
                'server' => $this->server,
                'site' => $this->site,
                'isInProgress' => $isInProgress,
                'inProgressDeployment' => $isInProgress ? $latestDeployment : null,
                'deploymentJourney' => $deploymentJourney,
            ],
        ));
    }
}
