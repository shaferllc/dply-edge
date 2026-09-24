<?php

declare(strict_types=1);

namespace App\Livewire\Sites;

use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Livewire\Concerns\ManagesEdgeDeploymentLifecycle;
use App\Models\EdgeDeployment;
use App\Models\Server;
use App\Models\Site;
use App\Modules\Edge\Services\EdgeDeploymentAliasGenerator;
use App\Support\Sites\SiteSettingsViewData;
use App\Support\Sites\SiteShowViewData;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class EdgeDeploymentDetail extends Component
{
    use ConfirmsActionWithModal;
    use DispatchesToastNotifications;
    use ManagesEdgeDeploymentLifecycle;

    public Server $server;

    public Site $site;

    public EdgeDeployment $deployment;

    public string $section = 'deploys';

    public string $tab = 'overview';

    public function mount(Site $site, EdgeDeployment $deployment, ?string $tab = null): void
    {
        $server = $site->server;

        if (! $site->usesEdgeRuntime() || $server === null) {
            abort(404);
        }

        if ($deployment->site_id !== $site->id) {
            abort(404);
        }

        if ($site->organization_id !== auth()->user()?->currentOrganization()?->id) {
            abort(404);
        }

        $allowedTabs = ['overview', 'aliases', 'log'];
        $queryTab = request()->query('tab');
        if (is_string($queryTab) && in_array($queryTab, $allowedTabs, true)) {
            $tab = $queryTab;
        }

        $this->server = $server;
        $this->site = $site;
        $this->deployment = $deployment;
        $this->tab = in_array($tab, $allowedTabs, true) ? (string) $tab : 'overview';
    }

    /**
     * @return list<string>
     */
    public function deploymentAliasHostnames(): array
    {
        $aliases = $this->deployment->aliasHostnames();
        if ($aliases !== []) {
            return $aliases;
        }

        // Aliases are only materialized when publish succeeds. Do not
        // preview would-be hostnames for failed or in-flight deploys.
        if (! in_array($this->deployment->status, [EdgeDeployment::STATUS_LIVE, EdgeDeployment::STATUS_SUPERSEDED], true)) {
            return [];
        }

        if ($this->deployment->published_at === null || $this->deployment->storage_prefix === null) {
            return [];
        }

        return app(EdgeDeploymentAliasGenerator::class)->aliasesFor($this->site, $this->deployment);
    }

    public function render(): View
    {
        $this->deployment->loadMissing('site');

        $activeDeploymentId = $this->site->edgeMeta()['active_deployment_id'] ?? null;
        $commitMeta = is_array($this->deployment->meta['commit'] ?? null)
            ? $this->deployment->meta['commit']
            : [];
        $buildLog = $this->tab === 'log' ? $this->deployment->readBuildLog($this->site) : null;
        $buildLogForLint = $buildLog ?? (
            $this->deployment->status === EdgeDeployment::STATUS_FAILED
                ? $this->deployment->readBuildLog($this->site)
                : null
        );

        // Poll while the deployment is mid-flight so the status badge, journey
        // card, and (on the log tab) build log update without a manual refresh.
        $isInProgress = in_array($this->deployment->status, [
            EdgeDeployment::STATUS_BUILDING,
            EdgeDeployment::STATUS_PUBLISHING,
        ], true);

        $deploymentJourney = $isInProgress
            ? SiteShowViewData::edgeDeploymentJourney($this->deployment)
            : null;

        return view('livewire.sites.edge-deployment-detail', array_merge(
            SiteSettingsViewData::for(
                $this->server,
                $this->site,
                'deploys',
                null,
                [],
                auth()->user(),
            ),
            [
                'deployment' => $this->deployment,
                'tab' => $this->tab,
                'deploymentAliases' => $this->deploymentAliasHostnames(),
                'isActiveDeployment' => $activeDeploymentId === $this->deployment->id
                    || ($activeDeploymentId === null && $this->deployment->isLive()),
                'commitMeta' => $commitMeta,
                'buildLog' => $buildLog,
                'buildLogForLint' => $buildLogForLint,
                'isInProgress' => $isInProgress,
                'deploymentJourney' => $deploymentJourney,
            ],
        ));
    }
}
