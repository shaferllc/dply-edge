<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Edge\Workspace;

use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Livewire\Concerns\Edge\MountsEdgeWorkspaceSection;
use App\Models\EdgeDeployment;
use App\Models\Server;
use App\Models\Site;
use App\Modules\Edge\Services\Containers\EdgeContainerDeployer;
use App\Modules\Edge\Support\EdgeContainerSettings;
use App\Modules\Edge\Support\EdgeCronExpression;
use App\Modules\Edge\Support\EdgeEffectiveCrons;
use App\Modules\Edge\Support\EdgeQueueWorkers;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Scheduled tasks, told per runtime. Lives on Overview beside Resources: it
 * owns the sheets (list, edit, run) and Resources draws the map box from
 * self::schedule(); "Add a resource" → Scheduled task fires edge-cron-new.
 *
 *   - container: every task shares one every-minute Cron Trigger and the
 *     Worker runs the due ones in the app (EdgeContainerDeployer::cronHandlers),
 *     so up to EdgeCronExpression::MAX_TASKS tasks, in the grammar that class
 *     checks; plus Laravel's `schedule:run` when the scheduler is on;
 *   - SSR / middleware: each distinct schedule is its own Cron Trigger, and
 *     the script's scheduled() tells them apart by event.cron, so Cloudflare's
 *     limit of EdgeEffectiveCrons::MAX_WORKER_SCHEDULES applies.
 *
 * Repo-declared crons (dply.yaml) are read-only; dashboard rows live on
 * edgeMeta `crons_overrides` and merge additively at deploy time.
 */
class Crons extends Component
{
    use DispatchesToastNotifications;
    use MountsEdgeWorkspaceSection;

    /** @var list<array{schedule: string, handler: string}> */
    public array $dashboard_crons = [];

    public string $new_schedule = '';

    public string $new_handler = '';

    /** Dashboard row open in the modal, -1 for a new one, null when closed. */
    public ?int $editingCron = null;

    public ?string $runOutput = null;

    public ?string $runCommand = null;

    /** @var array<string, string> Arguments the command said it is missing, as the sheet fills them in. */
    public array $runArgs = [];

    /** @var list<array{name: string, description: string, app: bool}>|null The live app's commands, once loaded. */
    public ?array $appCommands = null;

    public ?string $appCommandsError = null;

    public function mount(Server $server, Site $site): void
    {
        $this->mountEdgeWorkspaceSection($server, $site);
        $this->refreshFromMeta();
    }

    private function refreshFromMeta(): void
    {
        $overrides = is_array($this->site->edgeMeta()['crons_overrides'] ?? null) ? $this->site->edgeMeta()['crons_overrides'] : [];
        $this->dashboard_crons = array_values(array_map(
            static fn ($e): array => [
                'schedule' => (string) $e['schedule'],
                'handler' => (string) ($e['handler'] ?? ''),
            ],
            array_filter($overrides, static fn ($e): bool => is_array($e) && is_string($e['schedule'] ?? null) && $e['schedule'] !== ''),
        ));
    }

    /** The scheduler was added or removed (Resources): its row here follows. */
    #[On('edge-scheduler-changed')]
    public function schedulerChanged(): void {}

    #[On('edge-cron-new')]
    public function newCron(): void
    {
        $this->authorize('update', $this->site);
        $this->resetErrorBag();
        $this->new_schedule = '0 6 * * *';
        $this->new_handler = '';
        $this->editingCron = -1;
        $this->dispatch('open-modal', 'edge-cron');
    }

    public function editCron(int $index): void
    {
        $this->authorize('update', $this->site);
        $this->refreshFromMeta();
        abort_unless(isset($this->dashboard_crons[$index]), 404);
        $this->resetErrorBag();
        $this->new_schedule = $this->dashboard_crons[$index]['schedule'];
        $this->new_handler = $this->dashboard_crons[$index]['handler'];
        $this->editingCron = $index;
        $this->dispatch('open-modal', 'edge-cron');
    }

    /**
     * Ask the live app for its artisan commands / rake tasks, for the Command
     * field. On demand, because it wakes a sleeping container; cached per
     * deployment, since the list only changes when the code does.
     */
    public function loadAppCommands(): void
    {
        $this->authorize('update', $this->site);
        abort_unless($this->isContainer(), 404);
        $this->appCommandsError = null;

        $key = 'edge:cron-commands:'.$this->site->id.':'.($this->configDeployment()?->id ?? 0);
        try {
            $this->appCommands = Cache::remember($key, now()->addDay(), function (): array {
                $body = EdgeQueueWorkers::command($this->site, 'commands');

                return array_values(array_map(static fn ($c): array => [
                    'name' => (string) ($c['name'] ?? ''),
                    'description' => (string) ($c['description'] ?? ''),
                    'app' => (bool) ($c['app'] ?? false),
                ], array_filter((array) ($body['commands'] ?? []), static fn ($c): bool => is_array($c) && ($c['name'] ?? '') !== '')));
            });
        } catch (\Throwable $e) {
            $this->appCommandsError = $e->getMessage();
        }
    }

    public function closeCron(): void
    {
        $this->editingCron = null;
        $this->dispatch('close-modal', 'edge-cron');
    }

    public function saveCron(): void
    {
        $this->authorize('update', $this->site);

        $schedule = preg_replace('/\s+/', ' ', trim($this->new_schedule)) ?? '';
        $handler = trim($this->new_handler);

        if ($schedule === '') {
            $this->addError('new_schedule', __('Schedule is required.'));

            return;
        }
        if (! $this->looksLikeCron($schedule)) {
            $this->addError('new_schedule', __('Schedule must be a 5-field cron expression (e.g. */5 * * * *).'));

            return;
        }
        if ($this->isContainer() && ! EdgeCronExpression::supported($schedule)) {
            $this->addError('new_schedule', __('Use numbers, *, ranges (1-5), lists (1,15), steps (*/10) and names like MON or JAN. L, W, # and ? aren’t supported.'));

            return;
        }

        $this->refreshFromMeta();
        if ($this->editingCron === -1 && $this->isContainer() && count($this->dashboard_crons) >= EdgeCronExpression::MAX_TASKS) {
            $this->addError('new_schedule', __('An app can run up to :max scheduled tasks.', ['max' => EdgeCronExpression::MAX_TASKS]));

            return;
        }
        $row = ['schedule' => $schedule, 'handler' => $handler];
        if ($this->editingCron !== null && isset($this->dashboard_crons[$this->editingCron])) {
            $this->dashboard_crons[$this->editingCron] = $row;
        } else {
            $this->dashboard_crons[] = $row;
        }
        $this->persist();
        $this->closeCron();
        $this->toastSuccess(__('Saved — applied on the next deploy.'));
    }

    public function removeEditingCron(): void
    {
        if ($this->editingCron !== null && $this->editingCron >= 0) {
            $this->removeCron($this->editingCron);
        }
        $this->closeCron();
    }

    public function removeCron(int $index): void
    {
        $this->authorize('update', $this->site);

        if (! isset($this->dashboard_crons[$index])) {
            return;
        }
        array_splice($this->dashboard_crons, $index, 1);
        $this->persist();
        $this->toastSuccess(__('Removed — applied on the next deploy.'));
    }

    /**
     * Run one scheduled command now in the live container, the way the Cron
     * Trigger does (POST /_dply/schedule). dply/laravel runs it as an artisan
     * command and dply-rails as a rake task; a Node app answers the route
     * itself. Only commands already on this app's list can run.
     */
    public function runNow(string $command): void
    {
        $this->authorize('update', $this->site);
        abort_unless($this->canRunNow(), 404);
        abort_unless(in_array($command, $this->runnableCommands(), true), 404);

        $this->runCommand = $command;
        $this->runArgs = [];
        $this->dispatch('open-modal', 'edge-cron-run');
        $this->execute($command);
    }

    /** Run the listed command again with the arguments the sheet asked for. */
    public function runWithArgs(): void
    {
        $this->authorize('update', $this->site);
        abort_unless($this->canRunNow() && is_string($this->runCommand), 404);
        abort_unless(in_array($this->runCommand, $this->runnableCommands(), true), 404);

        $this->execute($this->commandWithArgs());
    }

    /**
     * Put the arguments into the scheduled task itself, so its next run has
     * them. Only dashboard tasks: a dply.yaml task changes in the repo.
     */
    public function saveArgsToTask(): void
    {
        $this->authorize('update', $this->site);
        abort_unless(is_string($this->runCommand), 404);

        $this->refreshFromMeta();
        $full = $this->commandWithArgs();
        $changed = 0;
        foreach ($this->dashboard_crons as $i => $row) {
            if ($row['handler'] === $this->runCommand) {
                $this->dashboard_crons[$i]['handler'] = $full;
                $changed++;
            }
        }
        if ($changed === 0) {
            $this->toastError(__('That task is in :file; add the arguments there.', ['file' => 'dply.yaml']));

            return;
        }
        $this->persist();
        $this->runCommand = $full;
        $this->runArgs = [];
        $this->toastSuccess(__('Saved. The task runs :command from the next deploy.', ['command' => $full]));
    }

    /** The command plus the sheet's arguments, quoted where Artisan needs it. */
    private function commandWithArgs(): string
    {
        $parts = [(string) $this->runCommand];
        foreach ($this->runArgs as $value) {
            $value = trim((string) $value);
            if ($value !== '') {
                $parts[] = preg_match('#^[\w@.:/+=,-]+$#', $value) === 1 ? $value : '"'.addcslashes($value, '"\\').'"';
            }
        }

        return implode(' ', $parts);
    }

    private function execute(string $command): void
    {
        $this->runOutput = null;
        $url = $this->site->edgeLiveUrl();
        if (! is_string($url) || $url === '') {
            $this->runOutput = __('This app has no live URL yet. Deploy it first.');

            return;
        }
        try {
            $response = Http::timeout(120)
                ->withHeaders(['x-dply-queue-token' => EdgeContainerDeployer::queueToken($this->site)])
                ->post(rtrim($url, '/').'/_dply/schedule', ['handler' => $command]);
            $body = $response->json();
            $this->runOutput = match (true) {
                $response->status() === 404 => $this->site->isLaravelFrameworkDetected() || $this->site->isRailsFrameworkDetected()
                    ? __('The app has no :path route yet. Redeploy so the dply package is in the image.', ['path' => EdgeContainerDeployer::SCHEDULE_PATH])
                    : __('The app has no :path route. Handle it in your app — see the example on this page.', ['path' => EdgeContainerDeployer::SCHEDULE_PATH]),
                is_array($body) => trim((string) ($body['output'] ?? $body['error'] ?? ''))
                    ?: ($response->successful()
                        ? __(':command finished with nothing to print.', ['command' => $command])
                        : __('The app answered HTTP :status.', ['status' => $response->status()])),
                default => __('The app answered HTTP :status.', ['status' => $response->status()])
                    .(trim((string) $response->body()) !== '' ? "\n\n".mb_substr(trim((string) $response->body()), 0, 2000) : ''),
            };
            // Symfony Console's own message: ask for exactly those, keeping what was typed.
            if (is_array($body) && preg_match('/Not enough arguments \(missing: (.+)\)/', (string) ($body['error'] ?? ''), $m) === 1) {
                preg_match_all('/"([^"]+)"/', $m[1], $names);
                foreach ($names[1] as $name) {
                    $this->runArgs[$name] ??= '';
                }
            }
        } catch (\Throwable $e) {
            $this->runOutput = $e->getMessage();
        }
    }

    private function isContainer(): bool
    {
        return ($this->site->edgeMeta()['runtime_mode'] ?? '') === 'container';
    }

    private function canRunNow(): bool
    {
        return $this->isContainer();
    }

    /** @return list<string> */
    private function runnableCommands(): array
    {
        $commands = array_values(array_filter(array_column(EdgeEffectiveCrons::for($this->site, $this->configDeployment()), 'handler')));
        if (EdgeContainerSettings::for($this->site)['scheduler']) {
            $commands[] = 'schedule:run';
        }

        return $commands;
    }

    private function persist(): void
    {
        $previous = is_array($this->site->edgeMeta()['crons_overrides'] ?? null) ? $this->site->edgeMeta()['crons_overrides'] : [];

        $this->site->mergeEdgeMeta([
            'crons_overrides' => array_map(
                static fn (array $e): array => [
                    'schedule' => $e['schedule'],
                    'handler' => $e['handler'] !== '' ? $e['handler'] : null,
                ],
                $this->dashboard_crons,
            ),
        ]);
        $this->site->save();
        $this->dispatch('edge-crons-updated');

        audit_log(
            $this->site->organization,
            auth()->user(),
            'site.edge.crons.updated',
            $this->site,
            ['crons_overrides' => $previous],
            ['crons_overrides' => $this->dashboard_crons],
        );
    }

    private function looksLikeCron(string $expr): bool
    {
        $fields = preg_split('/\s+/', trim($expr));

        return is_array($fields) && count($fields) === 5;
    }

    /** A cron expression in words for the common shapes; the raw expression otherwise. Times are UTC. */
    public static function describe(string $cron): string
    {
        $f = preg_split('/\s+/', trim($cron)) ?: [];
        if (count($f) !== 5) {
            return $cron;
        }
        [$min, $hour, $dom, $mon, $dow] = $f;
        $at = fn (): string => sprintf('%02d:%02d UTC', (int) $hour, (int) $min);
        $days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
        $digits = fn (string ...$v): bool => collect($v)->every(fn ($x) => ctype_digit($x));

        return match (true) {
            $cron === '* * * * *' => __('every minute'),
            preg_match('#^\*/(\d+)$#', $min, $m) === 1 && $hour.$dom.$mon.$dow === '****' => __('every :n minutes', ['n' => $m[1]]),
            $digits($min) && $hour.$dom.$mon.$dow === '****' => (int) $min === 0 ? __('every hour') : __('every hour at :mm past', ['mm' => sprintf('%02d', (int) $min)]),
            $digits($min) && preg_match('#^\*/(\d+)$#', $hour, $h) === 1 && $dom.$mon.$dow === '***' => __('every :n hours', ['n' => $h[1]]),
            $digits($min, $hour) && $dom.$mon.$dow === '***' => __('every day at :time', ['time' => $at()]),
            $digits($min, $hour, $dow) && $dom.$mon === '**' && (int) $dow <= 7 => __('every :day at :time', ['day' => __($days[(int) $dow]), 'time' => $at()]),
            $digits($min, $hour, $dom) && $mon.$dow === '**' => __('on day :d of every month at :time', ['d' => $dom, 'time' => $at()]),
            default => $cron,
        };
    }

    private function configDeployment(): ?EdgeDeployment
    {
        return self::configDeploymentFor($this->site);
    }

    private static function configDeploymentFor(Site $site): ?EdgeDeployment
    {
        return EdgeDeployment::query()
            ->where('site_id', $site->id)
            ->where('status', EdgeDeployment::STATUS_LIVE)
            ->latest('id')
            ->first()
            ?: EdgeDeployment::query()
                ->where('site_id', $site->id)
                ->whereNotNull('repo_config')
                ->latest('id')
                ->first();
    }

    /**
     * The schedules as they will deploy, for the Overview box and the sheet,
     * each marked `dropped` (with why) when it won't run.
     *
     *   - container: one shared trigger; tasks past MAX_TASKS, or with an
     *     expression the Worker can't read, won't run. `used`/`max` count tasks.
     *   - SSR / middleware: a trigger per distinct schedule; past Cloudflare's
     *     limit won't run. `used`/`max` count schedules.
     *
     * @return array{rows: list<array<string, mixed>>, scheduler: bool, schedulerInWorker: bool, used: int, max: int, container: bool}
     */
    public static function schedule(Site $site, ?EdgeDeployment $deployment = null): array
    {
        $deployment ??= self::configDeploymentFor($site);
        $container = ($site->edgeMeta()['runtime_mode'] ?? '') === 'container';
        $scheduler = $container && EdgeContainerSettings::for($site)['scheduler'];
        $schedulerInWorker = $scheduler && EdgeQueueWorkers::runsScheduler($site);
        $max = $container ? EdgeCronExpression::MAX_TASKS : EdgeEffectiveCrons::MAX_WORKER_SCHEDULES;
        $slots = [];
        $tasks = 0;
        $overrides = array_values(array_filter(
            is_array($site->edgeMeta()['crons_overrides'] ?? null) ? $site->edgeMeta()['crons_overrides'] : [],
            static fn ($e): bool => is_array($e) && is_string($e['schedule'] ?? null) && $e['schedule'] !== '',
        ));

        $rows = [];
        foreach (EdgeEffectiveCrons::for($site, $deployment) as $cron) {
            $dropped = null;
            if ($container) {
                // Same order and rules as EdgeContainerDeployer::cronHandlers.
                if (! EdgeCronExpression::supported($cron['schedule'])) {
                    $dropped = 'unsupported';
                } elseif ($tasks >= $max) {
                    $dropped = 'limit';
                } else {
                    $tasks++;
                }
            } else {
                if (! in_array($cron['schedule'], $slots, true)) {
                    $slots[] = $cron['schedule'];
                }
                $dropped = array_search($cron['schedule'], $slots, true) >= $max ? 'limit' : null;
            }
            $dashboardIndex = null;
            if ($cron['source'] === 'dashboard') {
                foreach ($overrides as $i => $d) {
                    if ($d['schedule'] === $cron['schedule'] && (($d['handler'] ?? '') !== '' ? $d['handler'] : null) === $cron['handler']) {
                        $dashboardIndex = $i;
                        break;
                    }
                }
            }
            $rows[] = $cron + [
                'when' => self::describe($cron['schedule']),
                'dropped' => $dropped !== null,
                'dropped_reason' => $dropped,
                'index' => $dashboardIndex,
            ];
        }

        return ['rows' => $rows, 'scheduler' => $scheduler, 'schedulerInWorker' => $schedulerInWorker, 'used' => $container ? $tasks : count($slots), 'max' => $max, 'container' => $container];
    }

    public function render(): View
    {
        $latestLive = $this->configDeployment();
        $schedule = self::schedule($this->site, $latestLive);

        return view('livewire.sites.edge.workspace.crons', [
            'site' => $this->site,
            'rows' => $schedule['rows'],
            'isContainer' => $this->isContainer(),
            'scheduler' => $schedule['scheduler'],
            'schedulerInWorker' => $schedule['schedulerInWorker'],
            'canRunNow' => $this->canRunNow(),
            'runIsDashboardTask' => is_string($this->runCommand) && collect($this->dashboard_crons)->contains('handler', $this->runCommand),
            'framework' => match (true) {
                $this->site->isLaravelFrameworkDetected() => 'laravel',
                $this->site->isRailsFrameworkDetected() => 'rails',
                default => 'other',
            },
            'commandLabel' => __('Command'),
            'maxSchedules' => $schedule['max'],
            'usedSchedules' => $schedule['used'],
            'sourcePath' => is_array($latestLive?->repo_config) && is_string($latestLive->repo_config['source_path'] ?? null)
                ? $latestLive->repo_config['source_path']
                : 'dply.yaml',
        ]);
    }
}
