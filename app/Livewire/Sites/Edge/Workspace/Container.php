<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Edge\Workspace;

use App\Livewire\Concerns\Edge\ManagesEdgeRedeploy;
use App\Livewire\Concerns\Edge\MountsEdgeWorkspaceSection;
use App\Models\EdgeContainerUsage;
use App\Models\EdgeDeployment;
use App\Models\Server;
use App\Models\Site;
use App\Modules\Billing\Services\EdgeContainerComputeCost;
use App\Modules\Edge\Services\Containers\EdgeContainerDeployer;
use App\Modules\Edge\Support\EdgeContainerSettings;
use App\Modules\Providers\Cloudflare\EdgeCloudflareClient;
use App\Support\Sites\EdgeSiteViewData;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * Container sizing and lifecycle for a container site. Applied on the next
 * deploy (EdgeContainerDeployer reads EdgeContainerSettings).
 */
class Container extends Component
{
    use ManagesEdgeRedeploy;
    use MountsEdgeWorkspaceSection;

    public string $instance_type = 'basic';

    public int $max_instances = 5;

    public string $sleep_after = '10m';

    public bool $migrate_on_boot = true;

    public string $jurisdiction = '';

    public bool $scheduler = false;

    /** @var list<array{at: ?string, level: string, message: string}>|null */
    public ?array $logs = null;

    public ?string $logsError = null;

    public function mount(Server $server, Site $site): void
    {
        $this->mountEdgeWorkspaceSection($server, $site);
        foreach (EdgeContainerSettings::for($site) as $key => $value) {
            $this->{$key} = $value;
        }
    }

    public function save(bool $redeploy = false): void
    {
        $this->authorize('update', $this->site);
        $this->validate([
            'instance_type' => ['required', Rule::in(array_keys(EdgeContainerSettings::INSTANCE_TYPES))],
            'max_instances' => ['required', 'integer', 'between:1,'.EdgeContainerSettings::MAX_INSTANCES],
            'sleep_after' => ['required', Rule::in(EdgeContainerSettings::SLEEP_AFTER)],
            'jurisdiction' => [Rule::in(EdgeContainerSettings::JURISDICTIONS)],
        ]);

        $this->site->mergeEdgeMeta(['container' => [
            'instance_type' => $this->instance_type,
            'max_instances' => $this->max_instances,
            'sleep_after' => $this->sleep_after,
            'migrate_on_boot' => $this->migrate_on_boot,
            'jurisdiction' => $this->jurisdiction,
            'scheduler' => $this->scheduler,
        ]]);
        $this->site->save();

        if ($redeploy) {
            $this->redeployEdge();

            return;
        }

        $this->toastSuccess(__('Saved. Changes apply on the next deploy.'));
    }

    public function loadLogs(): void
    {
        $this->authorize('view', $this->site);
        try {
            $this->logs = array_reverse(EdgeCloudflareClient::fromConfig()->workerLogs(EdgeContainerDeployer::scriptName($this->site)));
            $this->logsError = null;
        } catch (\Throwable $e) {
            $this->logs = null;
            $this->logsError = $e->getMessage();
        }
    }

    protected function currentEdgeSection(): ?string
    {
        return 'container';
    }

    public function render(EdgeContainerComputeCost $cost): View
    {
        $usage = EdgeContainerUsage::query()
            ->where('site_id', $this->site->id)
            ->where('date', '>=', now()->startOfMonth()->toDateString())
            ->selectRaw('COALESCE(SUM(cpu_seconds),0) cpu, COALESCE(SUM(memory_gib_seconds),0) mem, COALESCE(SUM(disk_gb_seconds),0) disk, COALESCE(SUM(tx_bytes),0) tx')
            ->first();
        [$vcpu, $memory, $disk] = EdgeContainerSettings::INSTANCE_TYPES[$this->instance_type] ?? EdgeContainerSettings::INSTANCE_TYPES['basic'];

        return view('livewire.sites.edge.workspace.container', array_merge(
            EdgeSiteViewData::context($this->site, 'container'),
            [
                'server' => $this->server,
                'site' => $this->site,
                'instanceTypes' => EdgeContainerSettings::INSTANCE_TYPES,
                'sleepOptions' => EdgeContainerSettings::SLEEP_AFTER,
                'scriptName' => EdgeContainerDeployer::scriptName($this->site),
                'monthCents' => $cost->cents((float) ($usage->cpu ?? 0), (float) ($usage->mem ?? 0), (float) ($usage->disk ?? 0), (int) ($usage->tx ?? 0)),
                'cpuHours' => (float) ($usage->cpu ?? 0) / 3600,
                'memoryGibHours' => (float) ($usage->mem ?? 0) / 3600,
                'perMinute' => $cost->perMinuteMillicents($vcpu, $memory, $disk) / 100_000,
                'health' => EdgeDeployment::query()->where('site_id', $this->site->id)->where('status', EdgeDeployment::STATUS_LIVE)->latest('published_at')->first()?->meta['container']['health'] ?? null,
                'maxPerMonth' => $cost->perMinuteMillicents($vcpu, $memory, $disk) * 60 * 730 * $this->max_instances / 100_000,
            ],
        ));
    }
}
