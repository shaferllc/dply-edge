<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Edge\Workspace;

use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Livewire\Concerns\Edge\ManagesEdgeBuildSettings;
use App\Livewire\Concerns\Edge\MountsEdgeWorkspaceSection;
use App\Livewire\Forms\EdgeBuildSettingsForm;
use App\Models\Server;
use App\Models\Site;
use App\Modules\SourceControl\Services\SourceControlRepositoryBrowser;
use App\Support\Sites\EdgeSiteViewData;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class DeployTriggers extends Component
{
    use ConfirmsActionWithModal;
    use DispatchesToastNotifications;
    use ManagesEdgeBuildSettings;
    use MountsEdgeWorkspaceSection;

    public EdgeBuildSettingsForm $buildForm;

    public function mount(Server $server, Site $site): void
    {
        $this->mountEdgeWorkspaceSection($server, $site);
        $this->mountEdgeBuildSettings($site);
    }

    public function render(): View
    {
        $viewData = array_merge(
            EdgeSiteViewData::context($this->site, 'deploy-triggers'),
            [
                'server' => $this->server,
                'site' => $this->site,
            ],
        );

        if (auth()->user() !== null) {
            $viewData['linkedSourceControlAccounts'] = app(SourceControlRepositoryBrowser::class)
                ->accountsForUser(auth()->user());
        }

        return view('livewire.sites.edge.workspace.deploy-triggers', $viewData);
    }
}
