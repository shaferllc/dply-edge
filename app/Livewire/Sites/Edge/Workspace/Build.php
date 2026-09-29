<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Edge\Workspace;

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

class Build extends Component
{
    use DispatchesToastNotifications;
    use ManagesEdgeBuildSettings;
    use MountsEdgeWorkspaceSection;

    public EdgeBuildSettingsForm $buildForm;

    public function mount(Server $server, Site $site): void
    {
        $this->mountEdgeWorkspaceSection($server, $site);

        $this->site->load([
            'edgeDeployments' => fn ($query) => $query
                ->whereNotNull('repo_config')
                ->orderByDesc('created_at')
                ->limit(5),
        ]);

        $this->mountEdgeBuildSettings($site);
    }

    /** The setting open in the dialog: push, command, output, root, spa, releases, footer. */
    public string $editing = '';

    public function openSetting(string $setting): void
    {
        $this->authorize('update', $this->site);
        $this->closeSetting(false);
        $this->editing = in_array($setting, ['push', 'command', 'output', 'root', 'spa', 'releases', 'footer'], true) ? $setting : 'command';
        $this->dispatch('open-modal', 'build-setting');
    }

    /** Cancel: put the form back to what's saved. */
    public function closeSetting(bool $dispatch = true): void
    {
        $this->resetErrorBag();
        $this->mountEdgeBuildSettings($this->site->fresh());
        $this->editing = '';
        if ($dispatch) {
            $this->dispatch('close-modal', 'build-setting');
        }
    }

    public function saveSetting(): void
    {
        match ($this->editing) {
            'releases' => $this->saveEdgeReleasesToKeep(),
            'footer' => $this->saveEdgeDeployFooter(),
            default => $this->saveEdgeBuildSettings(),
        };
        if ($this->getErrorBag()->isEmpty()) {
            $this->site->refresh();
            $this->editing = '';
            $this->dispatch('close-modal', 'build-setting');
        }
    }

    public function render(): View
    {
        $viewData = array_merge(
            EdgeSiteViewData::context($this->site, 'build'),
            [
                'server' => $this->server,
                'site' => $this->site,
            ],
        );

        if (auth()->user() !== null) {
            $viewData['linkedSourceControlAccounts'] = app(SourceControlRepositoryBrowser::class)
                ->accountsForUser(auth()->user());
        }

        return view('livewire.sites.edge.workspace.build', $viewData);
    }
}
