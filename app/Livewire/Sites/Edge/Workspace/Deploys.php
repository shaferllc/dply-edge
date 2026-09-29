<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Edge\Workspace;

use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Livewire\Concerns\Edge\ManagesEdgeDeployCommit;
use App\Livewire\Concerns\Edge\ManagesEdgeRedeploy;
use App\Livewire\Concerns\Edge\MountsEdgeWorkspaceSection;
use App\Livewire\Concerns\ManagesEdgeDeploymentLifecycle;
use App\Models\EdgeDeployment;
use App\Models\Server;
use App\Models\Site;
use App\Support\Sites\EdgeSiteViewData;
use App\Support\Sites\SiteShowViewData;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class Deploys extends Component
{
    use ConfirmsActionWithModal;
    use DispatchesToastNotifications;
    use ManagesEdgeDeployCommit;
    use ManagesEdgeDeploymentLifecycle;
    use ManagesEdgeRedeploy;
    use MountsEdgeWorkspaceSection;

    /** The deploy open in its dialog. */
    public ?string $openDeployId = null;

    public function mount(Server $server, Site $site): void
    {
        $this->mountEdgeWorkspaceSection($server, $site);
    }

    public function openDeploy(string $deploymentId): void
    {
        $this->openDeployId = $deploymentId;
        $this->dispatch('open-modal', 'deploy-detail');
    }

    /** Open "deploy a commit" with this deploy's commit filled in. */
    public function rebuildFrom(string $deploymentId): void
    {
        $this->authorize('deploy', $this->site);
        $commit = EdgeDeployment::query()->where('site_id', $this->site->id)->whereKey($deploymentId)->value('git_commit');
        if (is_string($commit) && $commit !== '') {
            $this->edge_deploy_commit_sha = $commit;
        }
        $this->dispatch('close-modal', 'deploy-detail');
        $this->dispatch('open-modal', 'deploy-ref');
    }

    public function render(): View
    {
        // Loaded per render, not in mount(): Livewire does not restore model
        // relations on hydrate, so a mount()-time eager load is gone on the
        // next wire:poll and every tick lazy-loaded ALL deployments.
        $this->site->load([
            'edgeDeployments' => fn ($query) => $query->limit(20),
        ]);

        $latestDeployment = $this->site->edgeDeployments->first();
        $isInProgress = $latestDeployment !== null && in_array($latestDeployment->status, [
            EdgeDeployment::STATUS_BUILDING,
            EdgeDeployment::STATUS_PUBLISHING,
        ], true);

        $deploymentJourney = $isInProgress
            ? SiteShowViewData::edgeDeploymentJourney($latestDeployment)
            : null;

        return view('livewire.sites.edge.workspace.deploys', array_merge(
            EdgeSiteViewData::context($this->site, 'deploys'),
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
