<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Edge\Workspace;

use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Livewire\Concerns\Edge\MountsEdgeWorkspaceSection;
use App\Models\EdgeDeployment;
use App\Models\Server;
use App\Models\Site;
use App\Modules\Edge\Services\Containers\EdgeContainerDeployer;
use App\Modules\Edge\Support\EdgeContainerSettings;
use App\Modules\Edge\Support\EdgeEffectiveCrons;
use App\Modules\Edge\Support\EdgeQueueWorkers;
use App\Support\Sites\EdgeSiteViewData;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Http;
use Livewire\Component;

/**
 * Scheduled tasks, told per runtime. Every entry becomes a Cloudflare Cron
 * Trigger on the site's Worker (at most 5 schedules per Worker):
 *
 *   - container: the handler is an artisan command / rake task the Worker
 *     runs in the app (EdgeContainerDeployer::cronHandlers), plus Laravel's
 *     `schedule:run` every minute when the scheduler is on;
 *   - SSR / middleware: only the schedule is pushed; the script's
 *     scheduled() handler tells them apart by event.cron.
 *
 * Repo-declared crons (dply.yaml) are read-only; dashboard rows live on
 * edgeMeta `crons_overrides` and merge additively at deploy time.
 */
class Crons extends Component
{
    use ConfirmsActionWithModal;
    use DispatchesToastNotifications;
    use MountsEdgeWorkspaceSection;

    /** Cloudflare's limit on Cron Triggers per Worker. */
    private const MAX_SCHEDULES = 5;

    /** @var list<array{schedule: string, handler: string}> */
    public array $dashboard_crons = [];

    public string $new_schedule = '';

    public string $new_handler = '';

    /** Dashboard row open in the modal, -1 for a new one, null when closed. */
    public ?int $editingCron = null;

    public ?string $runOutput = null;

    public ?string $runCommand = null;

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

        $this->refreshFromMeta();
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
        $this->runOutput = null;
        $this->dispatch('open-modal', 'edge-cron-run');

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
        return EdgeDeployment::query()
            ->where('site_id', $this->site->id)
            ->where('status', EdgeDeployment::STATUS_LIVE)
            ->latest('id')
            ->first()
            ?: EdgeDeployment::query()
                ->where('site_id', $this->site->id)
                ->whereNotNull('repo_config')
                ->latest('id')
                ->first();
    }

    public function render(): View
    {
        $latestLive = $this->configDeployment();
        $isContainer = $this->isContainer();
        $effective = EdgeEffectiveCrons::for($this->site, $latestLive);

        // The scheduler takes a slot first on containers (EdgeContainerDeployer::cronHandlers).
        $scheduler = $isContainer && EdgeContainerSettings::for($this->site)['scheduler'];
        $schedulerInWorker = $scheduler && EdgeQueueWorkers::runsScheduler($this->site);
        $slots = ($scheduler && ! $schedulerInWorker) ? ['* * * * *'] : [];

        $rows = [];
        foreach ($effective as $cron) {
            if (! in_array($cron['schedule'], $slots, true)) {
                $slots[] = $cron['schedule'];
            }
            $dashboardIndex = null;
            if ($cron['source'] === 'dashboard') {
                foreach ($this->dashboard_crons as $i => $d) {
                    if ($d['schedule'] === $cron['schedule'] && ($d['handler'] !== '' ? $d['handler'] : null) === $cron['handler']) {
                        $dashboardIndex = $i;
                        break;
                    }
                }
            }
            $rows[] = $cron + [
                'when' => self::describe($cron['schedule']),
                'dropped' => array_search($cron['schedule'], $slots, true) >= self::MAX_SCHEDULES,
                'index' => $dashboardIndex,
            ];
        }

        return view('livewire.sites.edge.workspace.crons', array_merge(
            EdgeSiteViewData::context($this->site, 'crons'),
            [
                'server' => $this->server,
                'site' => $this->site,
                'rows' => $rows,
                'isContainer' => $isContainer,
                'scheduler' => $scheduler,
                'schedulerInWorker' => $schedulerInWorker,
                'canRunNow' => $this->canRunNow(),
                'framework' => match (true) {
                    $this->site->isLaravelFrameworkDetected() => 'laravel',
                    $this->site->isRailsFrameworkDetected() => 'rails',
                    default => 'other',
                },
                'commandLabel' => match (true) {
                    $this->site->isLaravelFrameworkDetected() => __('Artisan command'),
                    $this->site->isRailsFrameworkDetected() => __('Rake task'),
                    default => __('Command'),
                },
                'maxSchedules' => self::MAX_SCHEDULES,
                'usedSchedules' => count($slots),
                'sourcePath' => is_array($latestLive?->repo_config) && is_string($latestLive->repo_config['source_path'] ?? null)
                    ? $latestLive->repo_config['source_path']
                    : 'dply.yaml',
            ],
        ));
    }
}
