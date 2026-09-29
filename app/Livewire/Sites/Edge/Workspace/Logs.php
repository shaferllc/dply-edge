<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Edge\Workspace;

use App\Livewire\Concerns\Edge\ManagesEdgeLogs;
use App\Livewire\Concerns\Edge\MountsEdgeWorkspaceSection;
use App\Models\EdgeDeployment;
use App\Models\Server;
use App\Models\Site;
use App\Modules\Edge\Services\Containers\EdgeContainerDeployer;
use App\Support\Sites\EdgeSiteViewData;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Throwable;

/**
 * Logs: one sentence about the latest deploys and what the app is printing,
 * then rows. A deploy opens its build log in a dialog; a container app's
 * recent output (last 15 minutes, from Workers Logs) opens in another.
 */
class Logs extends Component
{
    use ManagesEdgeLogs;
    use MountsEdgeWorkspaceSection;

    /** @var list<array<string, mixed>>|null null until loaded via wire:init */
    public ?array $appLogs = null;

    public ?string $appLogsError = null;

    /** Which lines the app-output dialog starts on: all or errors. */
    public string $appFilter = 'all';

    public ?string $openDeployment = null;

    public function mount(Server $server, Site $site): void
    {
        $this->mountEdgeWorkspaceSection($server, $site);

        $this->site->load([
            'edgeDeployments' => fn ($query) => $query->orderByDesc('created_at')->limit(10),
        ]);

        // ?deployment= (the deploy pill's "Full log"): open that deploy's log.
        $linked = request()->query('deployment');
        if (is_string($linked) && EdgeDeployment::query()->where('site_id', $this->site->id)->whereKey($linked)->exists()) {
            $this->openDeploy($linked);
        }
    }

    public function loadAppLogs(): void
    {
        if (! $this->isContainer()) {
            return;
        }
        try {
            $this->appLogs = EdgeContainerDeployer::appLogLines($this->site);
            $this->appLogsError = null;
        } catch (Throwable $e) {
            $this->appLogs = [];
            $this->appLogsError = $e->getMessage();
        }
    }

    public function openAppLogs(string $filter = 'all'): void
    {
        $this->appFilter = $filter === 'errors' ? 'errors' : 'all';
        $this->dispatch('open-modal', 'app-logs');
    }

    public function openDeploy(string $deploymentId): void
    {
        $this->openDeployment = $deploymentId;
        $this->loadEdgeDeploymentBuildLog($deploymentId);
        $this->dispatch('open-modal', 'deploy-log');
    }

    /** While the open deploy is still building, re-read its log. */
    public function refreshOpenDeployment(): void
    {
        if ($this->openDeployment === null) {
            return;
        }
        unset($this->edgeDeploymentBuildLogsLoaded[$this->openDeployment]);
        $this->loadEdgeDeploymentBuildLog($this->openDeployment);
        $this->refreshEdgeLogDeployments();
    }

    private function isContainer(): bool
    {
        return ($this->site->edgeMeta()['runtime_mode'] ?? '') === 'container';
    }

    /** @param  array<string, mixed>  $line */
    public static function isErrorLine(array $line): bool
    {
        return in_array(strtolower((string) ($line['level'] ?? '')), ['error', 'fatal', 'critical'], true)
            || preg_match('/\b(ERROR|CRITICAL|EMERGENCY|Exception|Fatal error|SQLSTATE|Traceback|panic:)\b/', (string) ($line['message'] ?? '')) === 1;
    }

    public function render(): View
    {
        $open = $this->openDeployment !== null
            ? EdgeDeployment::query()->where('site_id', $this->site->id)->find($this->openDeployment)
            : null;

        return view('livewire.sites.edge.workspace.logs', array_merge(
            EdgeSiteViewData::context($this->site, 'logs'),
            [
                'server' => $this->server,
                'site' => $this->site,
                'openDeploy' => $open,
                'openLog' => $open !== null ? $this->edgeDeploymentBuildLog($open->id) : null,
                'isContainer' => $this->isContainer(),
            ],
        ));
    }
}
