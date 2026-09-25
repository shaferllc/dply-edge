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

    public int $min_instances = 0;

    public string $sleep_after = '10m';

    public bool $migrate_on_boot = true;

    public string $jurisdiction = '';

    /** @var list<string> */
    public array $regions = [];

    public bool $scheduler = false;

    public bool $sticky_sessions = true;

    public bool $dedicated_jobs = false;

    public bool $jobs_always_on = false;

    /** @var list<array{days: string, start: string, end: string, timezone: string, min: int, max: int}> */
    public array $schedules = [];

    public string $rollout_mode = 'gradual';

    /** @var list<int> */
    public array $rollout_step_percentage = [];

    public int $rollout_active_grace_period = 0;

    public string $rollout_steps = '';

    /** @var list<array{at: ?string, level: string, message: string}>|null */
    public ?array $logs = null;

    public ?string $logsError = null;

    public function mount(Server $server, Site $site): void
    {
        $this->mountEdgeWorkspaceSection($server, $site);
        foreach (EdgeContainerSettings::for($site) as $key => $value) {
            $this->{$key} = $value;
        }
        $this->rollout_steps = implode(', ', $this->rollout_step_percentage);
    }

    public function save(bool $redeploy = false): void
    {
        $this->authorize('update', $this->site);
        $this->validate([
            'instance_type' => ['required', Rule::in([...array_keys(EdgeContainerSettings::INSTANCE_TYPES), 'custom'])],
            'max_instances' => ['required', 'integer', 'between:1,'.EdgeContainerSettings::MAX_INSTANCES],
            'min_instances' => ['required', 'integer', 'min:0', 'lte:max_instances'],
            'sleep_after' => ['required', Rule::in(EdgeContainerSettings::SLEEP_AFTER)],
            'jurisdiction' => [Rule::in(EdgeContainerSettings::JURISDICTIONS)],
            'rollout_mode' => ['required', Rule::in(EdgeContainerSettings::ROLLOUT_MODES)],
            'rollout_active_grace_period' => ['integer', 'between:0,'.EdgeContainerSettings::ROLLOUT_GRACE_MAX],
            'schedules.*.days' => ['required', fn (string $attribute, mixed $value, \Closure $fail) => EdgeContainerSettings::isScheduleDays((string) $value) ? null : $fail(__('Pick days or a date.'))],
            'schedules.*.start' => ['required', 'date_format:H:i'],
            'schedules.*.end' => ['required', 'date_format:H:i', 'after:schedules.*.start'],
            'schedules.*.timezone' => ['required', 'timezone'],
            'schedules.*.max' => ['required', 'integer', 'between:1,'.EdgeContainerSettings::MAX_INSTANCES],
            'schedules.*.min' => ['required', 'integer', 'min:0', 'lte:schedules.*.max'],
        ], [
            'schedules.*.end.after' => __('A window ends later the same day. For overnight, add two windows.'),
        ]);
        $stepsError = EdgeContainerSettings::rolloutStepsError($this->rollout_steps);
        if ($stepsError !== null) {
            $this->addError('rollout_steps', $stepsError);

            return;
        }

        $current = is_array($this->site->edgeMeta()['container'] ?? null) ? $this->site->edgeMeta()['container'] : [];
        $current['instance_type'] = $this->instance_type;
        $current['max_instances'] = $this->max_instances;
        $current['min_instances'] = $this->min_instances;
        $current['dedicated_jobs'] = $this->dedicated_jobs;
        $current['jobs_always_on'] = $this->jobs_always_on;
        $current['schedules'] = EdgeContainerSettings::normalizeSchedules($this->schedules);
        $current['sleep_after'] = $this->sleep_after;
        $current['migrate_on_boot'] = $this->migrate_on_boot;
        $current['jurisdiction'] = $this->jurisdiction;
        $current['regions'] = EdgeContainerSettings::normalizeRegions($this->regions, $this->jurisdiction);
        $current['scheduler'] = $this->scheduler;
        $current['rollout_mode'] = $this->rollout_mode;
        $current['rollout_step_percentage'] = EdgeContainerSettings::parseRolloutSteps($this->rollout_steps);
        $current['rollout_active_grace_period'] = $this->rollout_active_grace_period;
        $this->site->mergeEdgeMeta(['container' => $current]);
        $this->site->save();

        if ($redeploy) {
            $this->redeployEdge();

            return;
        }

        $this->toastSuccess(__('Saved. Changes apply on the next deploy.'));
    }

    private function peakInstances(): int
    {
        return EdgeContainerSettings::peakInstances([
            'max_instances' => $this->max_instances,
            'schedules' => EdgeContainerSettings::normalizeSchedules($this->schedules),
        ]);
    }

    public function addSchedule(): void
    {
        $this->schedules[] = [
            'days' => 'weekdays',
            'start' => '09:00',
            'end' => '17:00',
            'timezone' => auth()->user()?->timezone ?: config('app.timezone', 'UTC'),
            'min' => max(1, $this->min_instances),
            'max' => $this->max_instances,
        ];
    }

    public function removeSchedule(int $index): void
    {
        unset($this->schedules[$index]);
        $this->schedules = array_values($this->schedules);
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
        if ($this->instance_type === 'custom') {
            $shape = EdgeContainerSettings::shape($this->site);
            [$vcpu, $memory, $disk] = [$shape['vcpu'], $shape['memory_gib'], $shape['disk_gb']];
        } else {
            [$vcpu, $memory, $disk] = EdgeContainerSettings::INSTANCE_TYPES[$this->instance_type] ?? EdgeContainerSettings::INSTANCE_TYPES['basic'];
        }

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
                'minPerMonth' => $cost->perMinuteMillicents($vcpu, $memory, $disk) * 60 * 730 * $this->min_instances / 100_000,
                'requestsPerInstance' => EdgeContainerSettings::requestsPerInstance($this->site),
                'maxPerMonth' => $cost->perMinuteMillicents($vcpu, $memory, $disk) * 60 * 730 * $this->peakInstances() / 100_000,
            ],
        ));
    }
}
