<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Edge\Workspace;

use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Livewire\Concerns\Edge\ManagesEdgeDashboardBindings;
use App\Livewire\Concerns\Edge\MountsEdgeWorkspaceSection;
use App\Livewire\Concerns\Edge\PublishesEdgeHostMap;
use App\Models\EdgeDeployment;
use App\Models\Server;
use App\Models\Site;
use App\Modules\Edge\Services\EdgeDashboardBindingProvisioner;
use App\Modules\Edge\Support\EdgeEffectiveBindings;
use App\Support\Sites\EdgeSiteViewData;
use Illuminate\Contracts\View\View;
use Livewire\Component;

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

    public function useQueueBinding(string $name): void
    {
        $this->authorize('update', $this->site);
        $name = trim($name);
        if ($name === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
            return;
        }
        $this->default_queue = $name;
        $this->toastSuccess(__('Default queue set to :name.', ['name' => $name]));
    }

    protected function afterEdgeDashboardBindingAdded(string $name, string $kind): void
    {
        if ($kind === 'queue' && (trim($this->default_queue) === '' || trim($this->default_queue) === 'JOBS')) {
            $this->default_queue = $name;
        }
    }

    public function save(): void
    {
        $this->authorize('update', $this->site);
        if (! $this->isManagedEdgeDelivery()) {
            $this->toastError(__('Edge jobs require Dply-hosted Edge delivery.'));

            return;
        }

        $this->validate([
            'default_queue' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z][A-Za-z0-9_]*$/'],
        ]);

        $this->site->mergeEdgeMeta([
            'jobs' => [
                'enabled' => $this->enabled,
                'default_queue' => $this->default_queue,
            ],
        ]);
        $this->site->save();
        $this->republishEdgeHostMap();
        $this->toastSuccess(__('Edge jobs settings saved.'));
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
                'bindingsUrl' => route('sites.show', [
                    'server' => $this->server,
                    'site' => $this->site,
                    'section' => 'resources',
                ]),
            ],
        ));
    }
}
