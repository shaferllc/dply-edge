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

    /** The hook open in its dialog. */
    public ?string $openHookId = null;

    public function mount(Server $server, Site $site): void
    {
        $this->mountEdgeWorkspaceSection($server, $site);
        $this->mountEdgeBuildSettings($site);
    }

    public function openHook(string $hookId): void
    {
        $this->openHookId = $hookId;
        $this->dispatch('open-modal', 'deploy-hook');
    }

    public function openNewHook(): void
    {
        $this->authorize('update', $this->site);
        $this->edge_new_deploy_hook_name = '';
        $this->edge_just_minted_deploy_hook_url = null;
        $this->dispatch('open-modal', 'deploy-hook-new');
    }

    /** Revoke the hook open in its dialog. */
    public function revokeOpenHook(): void
    {
        if ($this->openHookId === null) {
            return;
        }
        $this->revokeEdgeDeployHook($this->openHookId);
        $this->openHookId = null;
        $this->dispatch('close-modal', 'deploy-hook');
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
