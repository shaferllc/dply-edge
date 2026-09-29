<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Edge\Workspace;

use App\Livewire\Concerns\Edge\ManagesEdgeRedeploy;
use App\Livewire\Concerns\Edge\MountsEdgeWorkspaceSection;
use App\Models\EdgeDeployment;
use App\Models\Server;
use App\Models\Site;
use App\Support\Sites\EdgeSiteViewData;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

class Overview extends Component
{
    use ManagesEdgeRedeploy;
    use MountsEdgeWorkspaceSection;

    public function mount(Server $server, Site $site): void
    {
        $this->mountEdgeWorkspaceSection($server, $site);
    }

    /**
     * The deploy pill says a deploy started or ended somewhere in the org.
     * Re-render (hero, service map, the "Deploying" note) only for this app;
     * the pill itself shows the steps in between.
     */
    #[On('edge-deploy-changed')]
    public function deployChanged(string $siteId = ''): void
    {
        if ($siteId !== (string) $this->site->id) {
            $this->skipRender();
        }
    }

    public function render(): View
    {
        // Loaded per render, not in mount(): Livewire does not restore model
        // relations on hydrate, so a mount()-time eager load is gone on the
        // next wire:poll and every tick lazy-loaded ALL deployments.
        $this->site->load([
            'edgeDeployments' => fn ($query) => $query->limit(5),
        ]);

        // Surface the same live build-journey card the deployment-detail page
        // uses, scoped to whichever deployment is currently in flight. Lets
        // the operator watch progress without leaving the workspace overview.
        // The card (BuildJourney) builds its own journey data and polls itself.
        $latestDeployment = $this->site->edgeDeployments->first();
        $isInProgress = $latestDeployment !== null && in_array($latestDeployment->status, [
            EdgeDeployment::STATUS_BUILDING,
            EdgeDeployment::STATUS_PUBLISHING,
        ], true);

        return view('livewire.sites.edge.workspace.overview', array_merge(
            EdgeSiteViewData::context($this->site, 'general'),
            [
                'server' => $this->server,
                'site' => $this->site,
                'isInProgress' => $isInProgress,
                'inProgressDeployment' => $isInProgress ? $latestDeployment : null,
            ],
        ));
    }
}
