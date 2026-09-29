<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Edge\Workspace;

use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Livewire\Concerns\Edge\ManagesEdgeDashboardBindings;
use App\Livewire\Concerns\Edge\MountsEdgeWorkspaceSection;
use App\Livewire\Concerns\Edge\PublishesEdgeHostMap;
use App\Models\EdgeDeployment;
use App\Models\EdgeQueue;
use App\Models\Server;
use App\Models\Site;
use App\Modules\Edge\Services\EdgeDashboardBindingProvisioner;
use App\Modules\Edge\Services\EdgeQueueConsumers;
use App\Modules\Edge\Support\EdgeContainerConnections;
use App\Modules\Edge\Support\EdgeEffectiveBindings;
use App\Modules\Edge\Support\EdgeQueueWorkers;
use App\Support\Sites\EdgeSiteViewData;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Background work for one app, told as it runs today: the Projects → Queues
 * it is attached to (and which app consumes each — one per queue, see
 * EdgeQueueConsumers), its Laravel queue:work workers, and the Edge Worker
 * env.NAME queue bindings. Editing workers and attachments stays on
 * Overview → Resources for now.
 *
 * The old `jobs` meta (enabled / default_queue) is still published in the
 * host map but nothing reads it; the page no longer edits it.
 */
class Jobs extends Component
{
    use ConfirmsActionWithModal;
    use DispatchesToastNotifications;
    use ManagesEdgeDashboardBindings;
    use MountsEdgeWorkspaceSection;
    use PublishesEdgeHostMap;

    public bool $enabled = false;

    public string $default_queue = 'JOBS';

    public function mount(Server $server, Site $site): void
    {
        $this->mountEdgeWorkspaceSection($server, $site);
        $this->refreshEdgeDashboardBindingsFromMeta();
        $cfg = is_array($site->edgeMeta()['jobs'] ?? null) ? $site->edgeMeta()['jobs'] : [];
        $this->enabled = (bool) ($cfg['enabled'] ?? false);
        $this->default_queue = trim((string) ($cfg['default_queue'] ?? 'JOBS')) ?: 'JOBS';
    }

    public function openManageBindingsModal(): void
    {
        $this->authorize('update', $this->site);
        $this->refreshEdgeDashboardBindingsFromMeta();
        $this->resetErrorBag();
        $this->new_kind = 'queue';
        $this->create_resource = true;
        $this->new_value = '';
        $default = trim($this->default_queue);
        $names = array_column($this->dashboard_bindings, 'name');
        $this->new_name = ($default !== '' && ! in_array($default, $names, true))
            ? $default
            : 'JOBS';
        $this->dispatch('open-modal', 'edge-jobs-bindings-modal');
    }

    public function closeManageBindingsModal(): void
    {
        $this->dispatch('close-modal', 'edge-jobs-bindings-modal');
    }

    public function addQueueBinding(EdgeDashboardBindingProvisioner $provisioner): void
    {
        $this->new_kind = 'queue';
        $this->addBinding($provisioner);
    }

    protected function afterEdgeDashboardBindingAdded(string $name, string $kind): void
    {
        if ($kind === 'queue' && (trim($this->default_queue) === '' || trim($this->default_queue) === 'JOBS')) {
            $this->default_queue = $name;
        }
    }

    /**
     * Live worker state, read from the app after the page renders (HTTP to the
     * container). Null until loaded; an error string when the app is unreachable.
     *
     * @var array{up: int, total: int, failed: ?int}|null
     */
    public ?array $live = null;

    public ?string $liveError = null;

    public function loadLive(): void
    {
        $this->authorize('view', $this->site);
        if (EdgeQueueWorkers::runningInstances($this->site) === 0) {
            return;
        }
        try {
            $status = EdgeQueueWorkers::status($this->site);
            $failed = null;
            try {
                $failed = (int) (EdgeQueueWorkers::command($this->site, 'failed-jobs')['total'] ?? 0);
            } catch (\Throwable) {
                // Failed-job store unreadable: the worker count still shows.
            }
            $this->live = [
                'up' => collect($status)->where('status', 'running')->count(),
                'total' => count($status),
                'failed' => $failed,
            ];
        } catch (\Throwable $e) {
            $this->liveError = $e->getMessage();
        }
    }

    /**
     * Each Projects queue this app is attached to, and who runs its jobs.
     *
     * @return list<array{name: string, queue: string, asleep: bool, role: string, owner: ?string, owner_url: ?string}>
     */
    private function attachedQueues(): array
    {
        $organization = $this->site->organization;
        $rows = [];
        foreach (EdgeContainerConnections::for($this->site) as $connection) {
            if ($connection['kind'] !== 'queue') {
                continue;
            }
            $owner = $organization ? EdgeQueueConsumers::owner($organization, $connection['target']) : null;
            $label = $organization
                ? EdgeQueue::query()->where('organization_id', $organization->id)->where('cloudflare_name', $connection['target'])->value('name')
                : null;
            $rows[] = [
                'name' => $connection['name'],
                'queue' => (string) ($label ?: $connection['target']),
                'asleep' => (bool) $connection['asleep'],
                'role' => match (true) {
                    $owner === null => 'none',
                    $owner->is($this->site) => 'runs',
                    default => 'sends',
                },
                'owner' => $owner?->name,
                'owner_url' => $owner && $owner->server_id
                    ? route('sites.show', ['server' => $owner->server_id, 'site' => $owner, 'section' => 'jobs'])
                    : null,
            ];
        }

        return $rows;
    }

    public function render(): View
    {
        $live = EdgeDeployment::query()
            ->where('site_id', $this->site->id)
            ->where('status', EdgeDeployment::STATUS_LIVE)
            ->latest('id')
            ->first();
        $bindings = EdgeEffectiveBindings::for($this->site, $live);
        $queues = [];
        foreach ($bindings as $binding) {
            if ($binding['kind'] === 'queue') {
                $queues[] = $binding;
            }
        }

        $dashboardQueues = array_values(array_filter(
            $this->dashboard_bindings,
            static fn (array $b): bool => $b['kind'] === 'queue',
        ));

        return view('livewire.sites.edge.workspace.jobs', array_merge(
            EdgeSiteViewData::context($this->site, 'jobs'),
            [
                'server' => $this->server,
                'site' => $this->site,
                'managedDelivery' => $this->isManagedEdgeDelivery(),
                'queueBindings' => $queues,
                'dashboardQueueBindings' => $dashboardQueues,
                'hasWorker' => $this->edgeSiteHasWorker(),
                'attachedQueues' => $this->attachedQueues(),
                'isContainer' => ($this->site->edgeMeta()['runtime_mode'] ?? '') === 'container',
                'workers' => EdgeQueueWorkers::for($this->site),
                'workersUnavailable' => EdgeQueueWorkers::unavailableReason($this->site),
                'workerInstances' => EdgeQueueWorkers::runningInstances($this->site),
                'runsScheduler' => EdgeQueueWorkers::runsScheduler($this->site),
                'bindingsUrl' => route('sites.show', [
                    'server' => $this->server,
                    'site' => $this->site,
                    'section' => 'general',
                ]),
            ],
        ));
    }
}
