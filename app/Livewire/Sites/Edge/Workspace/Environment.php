<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Edge\Workspace;

use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Livewire\Concerns\Edge\ManagesEdgeBuildSettings;
use App\Livewire\Concerns\Edge\MountsEdgeWorkspaceSection;
use App\Livewire\Forms\EdgeBuildSettingsForm;
use App\Livewire\Sites\Concerns\ManagesLinkedOrganizationSecrets;
use App\Models\EdgeDeployment;
use App\Models\Server;
use App\Models\Site;
use App\Support\Sites\EdgeSiteViewData;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class Environment extends Component
{
    use ConfirmsActionWithModal;
    use DispatchesToastNotifications;
    use ManagesEdgeBuildSettings;
    use ManagesLinkedOrganizationSecrets;
    use MountsEdgeWorkspaceSection;

    public EdgeBuildSettingsForm $buildForm;

    public function mount(Server $server, Site $site): void
    {
        $this->mountEdgeWorkspaceSection($server, $site);
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

        $repoEnv = is_array($latest?->repo_config['env'] ?? null) ? $latest->repo_config['env'] : [];
        $sourcePath = is_array($latest?->repo_config) && is_string($latest->repo_config['source_path'] ?? null)
            ? (string) $latest->repo_config['source_path']
            : 'dply.yaml';

        // Detect missing secrets (declared in repo, no dashboard value)
        // so we can warn the user inline.
        $declaredSecretNames = is_array($repoEnv['secret'] ?? null) ? $repoEnv['secret'] : [];
        $dashboardKeys = $this->site->edgeEnvVars()->pluck('key')->all();
        $missingSecrets = array_values(array_filter(
            $declaredSecretNames,
            static fn ($name): bool => is_string($name) && ! in_array($name, $dashboardKeys, true),
        ));

        return view('livewire.sites.edge.workspace.environment', array_merge(
            EdgeSiteViewData::context($this->site, 'environment'),
            [
                'server' => $this->server,
                'site' => $this->site,
                'repoEnv' => $repoEnv,
                'sourcePath' => $sourcePath,
                'missingSecrets' => $missingSecrets,
            ],
        ));
    }
}
