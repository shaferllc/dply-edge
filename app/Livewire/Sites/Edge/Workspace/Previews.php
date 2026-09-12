<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Edge\Workspace;

use App\Modules\Edge\Actions\CreateEdgePreviewSite;
use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Livewire\Concerns\Edge\ManagesEdgeBuildSettings;
use App\Livewire\Concerns\Edge\ManagesEdgeDeployCommit;
use App\Livewire\Concerns\Edge\ManagesEdgePreviews;
use App\Livewire\Concerns\Edge\MountsEdgeWorkspaceSection;
use App\Livewire\Concerns\ManagesEdgeDeploymentLifecycle;
use App\Livewire\Forms\EdgeBuildSettingsForm;
use App\Models\EdgeDeployment;
use App\Models\EdgeDeployReplay;
use App\Models\Server;
use App\Models\Site;
use App\Services\DeployContract\DeployContractState;
use App\Modules\Edge\Support\EdgePreviewPolicy;
use App\Support\Sites\EdgeSiteViewData;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class Previews extends Component
{
    use ConfirmsActionWithModal;
    use DispatchesToastNotifications;
    use ManagesEdgeBuildSettings;
    use ManagesEdgeDeployCommit;
    use ManagesEdgeDeploymentLifecycle;
    use ManagesEdgePreviews;
    use MountsEdgeWorkspaceSection;

    public EdgeBuildSettingsForm $buildForm;

    public function mount(Server $server, Site $site): void
    {
        $this->mountEdgeWorkspaceSection($server, $site);
        $this->site->loadMissing('edgeSiteAccessRule');
        $this->mountEdgeBuildSettings($site);
    }

    public function render(): View
    {
        $latest = EdgeDeployment::query()
            ->where('site_id', $this->site->id)
            ->where('status', EdgeDeployment::STATUS_LIVE)
            ->latest('id')
            ->first()
            ?: EdgeDeployment::query()
                ->where('site_id', $this->site->id)
                ->whereNotNull('repo_config')
                ->latest('id')
                ->first();

        $repoPreviews = is_array($latest?->repo_config['previews'] ?? null) ? $latest->repo_config['previews'] : [];
        $repoCommentWidget = is_array($latest?->repo_config['comment_widget'] ?? null) ? $latest->repo_config['comment_widget'] : [];
        $sourcePath = is_array($latest?->repo_config) && is_string($latest->repo_config['source_path'] ?? null)
            ? (string) $latest->repo_config['source_path']
            : 'dply.yaml';

        $previewIds = CreateEdgePreviewSite::listForParent($this->site)->pluck('id');
        $latestReplays = EdgeDeployReplay::query()
            ->where('parent_site_id', $this->site->id)
            ->whereIn('preview_site_id', $previewIds)
            ->orderByDesc('created_at')
            ->get()
            ->unique('preview_site_id')
            ->keyBy('preview_site_id');

        $contractState = app(DeployContractState::class);
        $deployContracts = collect();
        foreach (CreateEdgePreviewSite::listForParent($this->site) as $previewSite) {
            $deployContracts->put(
                (string) $previewSite->id,
                $contractState->forPreview($this->site, $previewSite),
            );
        }

        return view('livewire.sites.edge.workspace.previews', array_merge(
            EdgeSiteViewData::context($this->site, 'edge-previews'),
            [
                'server' => $this->server,
                'site' => $this->site,
                'repoPreviews' => $repoPreviews,
                'repoCommentWidget' => $repoCommentWidget,
                'previewPolicy' => EdgePreviewPolicy::for($this->site),
                'sourcePath' => $sourcePath,
                'latestReplays' => $latestReplays,
                'deployReplayEnabled' => true,
                'deployContractEnabled' => true,
                'deployContracts' => $deployContracts,
            ],
        ));
    }
}
