<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Edge\Workspace;

use App\Livewire\Concerns\Edge\ManagesEdgeRedeploy;
use App\Livewire\Concerns\Edge\MountsEdgeWorkspaceSection;
use App\Livewire\Concerns\Edge\PublishesEdgeHostMap;
use App\Livewire\Sites\Edge\Workspace\Concerns\Resources\ManagesAiResource;
use App\Livewire\Sites\Edge\Workspace\Concerns\Resources\ManagesExternalRedisResource;
use App\Livewire\Sites\Edge\Workspace\Concerns\Resources\ManagesPoolResource;
use App\Livewire\Sites\Edge\Workspace\Concerns\Resources\ManagesQueueResource;
use App\Livewire\Sites\Edge\Workspace\Concerns\Resources\ManagesRealtimeBilling;
use App\Livewire\Sites\Edge\Workspace\Concerns\Resources\ManagesRealtimeResource;
use App\Livewire\Sites\Edge\Workspace\Concerns\Resources\ManagesSqlResource;
use App\Livewire\Sites\Edge\Workspace\Concerns\Resources\ManagesStateResource;
use App\Livewire\Sites\Edge\Workspace\Concerns\Resources\ManagesStorageResources;
use App\Livewire\Sites\Edge\Workspace\Concerns\Resources\ManagesVectorsResource;
use App\Models\EdgeDataUsage;
use App\Models\EdgeDeployment;
use App\Models\EdgeKvUsage;
use App\Models\EdgePostgresUsage;
use App\Models\EdgeRealtimeApp;
use App\Models\EdgeRedisUsage;
use App\Models\EdgeSiteEnvVar;
use App\Models\Server;
use App\Models\Site;
use App\Modules\Billing\Services\EdgeAppDatabaseCost;
use App\Modules\Billing\Services\EdgeContainerComputeCost;
use App\Modules\Billing\Services\EdgeDataUsageCost;
use App\Modules\Billing\Services\EdgeKvCost;
use App\Modules\Billing\Support\UsagePrice;
use App\Modules\Edge\Console\ScaleEdgeQueueWorkersCommand;
use App\Modules\Edge\Jobs\RestoreEdgeDplyPostgresJob;
use App\Modules\Edge\Jobs\TransferEdgeDplyDatabaseJob;
use App\Modules\Edge\Services\Containers\EdgeContainerDeployer;
use App\Modules\Edge\Services\EdgeAppDatabase;
use App\Modules\Edge\Services\EdgeQueueConsumers;
use App\Modules\Edge\Services\EdgeValkeyUsageCollector;
use App\Modules\Edge\Services\Realtime\EdgeRealtimeApps;
use App\Modules\Edge\Support\EdgeContainerConnections;
use App\Modules\Edge\Support\EdgeContainerPlans;
use App\Modules\Edge\Support\EdgeContainerSettings;
use App\Modules\Edge\Support\EdgeDplyDatabase;
use App\Modules\Edge\Support\EdgeDplyDatabaseStats;
use App\Modules\Edge\Support\EdgeEffectiveBindings;
use App\Modules\Edge\Support\EdgeQueueWorkers;
use App\Modules\Edge\Support\EdgeSizeLadder;
use App\Modules\Edge\Support\EdgeValkey;
use App\Modules\Providers\Cloudflare\EdgeCloudflareClient;
use App\Modules\Providers\Valkey\ValkeyGatewayClient;
use App\Support\Http\PublicOutboundUrl;
use App\Support\Http\UnsafeOutboundUrlException;
use App\Support\Sites\EdgeServiceMap;
use App\Support\Sites\EdgeSiteViewData;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Post-create resource map. A new container app starts on Flex.
 * Size and attached resources are chosen here.
 */
class Resources extends Component
{
    use ManagesAiResource;
    use ManagesEdgeRedeploy;
    use ManagesExternalRedisResource;
    use ManagesPoolResource;
    use ManagesQueueResource;
    use ManagesRealtimeBilling;
    use ManagesRealtimeResource;
    use ManagesSqlResource;
    use ManagesStateResource;
    use ManagesStorageResources;
    use ManagesVectorsResource;
    use MountsEdgeWorkspaceSection;
    use PublishesEdgeHostMap;

    /** @var list<int> */
    private const INSTANCE_COUNTS = [1, 2, 3, 4, 5, 8, 10, 20];

    public string $panel = '';

    public bool $confirmRemoveBrowser = false;

    public string $browserDemoUrl = 'https://example.com';

    public string $browserDemoKind = '';

    public string $browserDemoPreview = '';

    /** @var list<string> */
    public array $browserDemoLog = [];

    public string $sleepAfter = '10m';

    public string $jurisdiction = '';

    /** @var list<string> */
    public array $regions = [];

    public bool $scheduler = false;

    public bool $stickySessions = true;

    public bool $dedicatedJobs = false;

    /**
     * Queue workers draft (EdgeQueueWorkers): saved with Save and redeploy.
     *
     * @var array<string, mixed>
     */
    public array $workers = [];

    /** @var array{queues: array<string, int>, failed: ?int}|null */
    public ?array $workersBacklog = null;

    public ?string $workersBacklogError = null;

    /** @var list<array{name: string, status: string, since: ?int, exit_code: ?int}>|null */
    public ?array $workersStatus = null;

    /** @var array{total: int, jobs: list<array<string, mixed>>}|null */
    public ?array $failedJobs = null;

    public ?string $failedJobsError = null;

    public ?string $failedJobsNotice = null;

    public bool $confirmFlushFailed = false;

    public ?string $workersStatusError = null;

    /** @var array<string, array{location: string, region: string, db_ms: ?float, redis_ms: ?float, at: ?string}> */
    public array $workersPlacement = [];

    public bool $migrateOnBoot = false;

    public string $databaseCommandOutput = '';

    public string $pendingDatabaseCommand = '';

    public string $rolloutMode = 'gradual';

    public string $rolloutSteps = '';

    public int $rolloutGraceSeconds = 0;

    public int $customVcpu = 1;

    public int $customMemoryGib = 3;

    public int $customDiskGb = 6;

    public int $awakeHours = 8;

    public bool $pending = false;

    public string $draftInstanceType = 'basic';

    public int $draftMaxInstances = 1;

    public string $draftCacheMode = 'off';

    public string $draftDatabase = 'sql';

    public bool $databaseVisible = true;

    public string $draftPostgresPlan = 'sleep';

    public string $draftPostgresSize = '0.25';

    public int $draftPostgresSuspend = 300;

    /** dply Postgres volume size in GB (EdgeDplyDatabase::DISKS). */
    public int $draftPostgresDisk = 1;

    /** Point-in-time restore target (datetime-local, UTC); progress is on the database record. */
    public string $postgresRestoreAt = '';

    public ?string $postgresRestoreResult = null;

    public string $connectionKind = '';

    public string $connectionMode = 'create';

    public string $connectionLabel = '';

    public string $connectionPick = '';

    public string $redisPlan = 'payg';

    /** dply Valkey size (EdgeValkey::CLASSES) for a new Redis. */
    public string $valkeyClass = EdgeValkey::DEFAULT_CLASS;

    /** Idle seconds before a flex Valkey sleeps (EdgeValkey::SLEEPS). */
    public int $valkeySleep = EdgeValkey::DEFAULT_SLEEP;

    /**
     * Change a dply Valkey's size or sleep time. It restarts on its next
     * connection with its data.
     */
    /**
     * The app's Valkey password, read from REDIS_URL only when asked for, so
     * it is not in the page until someone who can edit the app clicks Show.
     */
    public function valkeyPassword(string $host): string
    {
        $this->authorize('update', $this->site);

        return $this->readValkeyPassword($host);
    }

    /** Server-side use only (stats, backlog): never returned to the browser without update. */
    private function readValkeyPassword(string $host): string
    {
        $connection = collect(EdgeContainerConnections::for($this->site))->firstWhere('host', $host);
        if (! is_array($connection) || ! EdgeValkey::isTarget($connection['target'])) {
            return '';
        }
        $url = (string) ($this->site->edgeEnvVars()->where('scope', 'production')->where('key', 'REDIS_URL')->first()?->value ?? '');

        return rawurldecode((string) (parse_url($url, PHP_URL_PASS) ?? ''));
    }

    public function saveValkey(string $host, string $class, int $sleep): void
    {
        $this->authorize('update', $this->site);
        $rows = EdgeContainerConnections::for($this->site);
        foreach ($rows as $index => $connection) {
            if ($connection['host'] !== $host || ! EdgeValkey::isTarget($connection['target']) || ! isset(EdgeValkey::offered()[$class])) {
                continue;
            }
            $url = $this->productionRedisUrl();
            try {
                // An asleep store keeps its short sleep (update would send 0 for Pro).
                EdgeValkey::setAsleep($connection['target'], $url, $class, $sleep, $connection['asleep']);
            } catch (\Throwable $e) {
                $this->toastError($e->getMessage());

                return;
            }
            $rows[$index]['plan'] = $class;
            $this->site->mergeEdgeMeta(['connections' => $rows, 'valkey_sleep' => [$connection['target'] => EdgeValkey::sleepAfter($class, $sleep)] + (array) ($this->site->edgeMeta()['valkey_sleep'] ?? [])]);
            $this->site->save();
            $this->toastSuccess(__('Saved. It restarts with its data on the next connection.'));

            return;
        }
    }

    private function productionRedisUrl(): string
    {
        return (string) ($this->site->edgeEnvVars()->where('scope', 'production')->where('key', 'REDIS_URL')->first()?->value ?? '');
    }

    public string $deleteConnectionHost = '';

    public string $explainConnectionHost = '';

    public string $serviceDemoPath = '/';

    public string $serviceDemoStatus = '';

    public string $serviceDemoPreview = '';

    /** @var list<string> */
    public array $serviceDemoLog = [];

    public string $kvHost = '';

    public string $imagesHost = '';

    /** dply Valkey whose settings modal is open. */
    public string $valkeyHost = '';

    /** Last Test-tab result for $valkeyHost (EdgeValkey::probe). */
    public ?array $valkeyTest = null;

    /** Last Test-tab result measured inside the app (dply/laravel redis-probe). */
    public ?array $valkeyAppTest = null;

    /** Statistics tab: gateway status (never wakes it) and live INFO numbers (wakes it). */
    public ?array $valkeyStatus = null;

    public ?array $valkeyStats = null;

    public ?string $valkeyStatsError = null;

    /** @var list<array{at: int, micros: int, command: string, key: string}>|null */
    public ?array $valkeySlowlog = null;

    public function updatedValkeyHost(): void
    {
        if ($this->valkeyHost !== '') {
            $this->refreshValkeyAwake();
        }
        $this->valkeyTest = null;
        $this->valkeyAppTest = null;
        $this->valkeyStatus = null;
        $this->valkeyStats = null;
        $this->valkeyStatsError = null;
    }

    /**
     * Awake time is collected hourly; this brings this app's up to date (the
     * modal does it on open, the Refresh button on demand). The collector is
     * locked and counts only the change since last time, so it never bills
     * twice.
     */
    public function refreshValkeyAwake(): void
    {
        $this->authorize('view', $this->site);
        try {
            app(EdgeValkeyUsageCollector::class)->collect(false, (string) $this->site->id);
        } catch (\Throwable) {
            // The hourly run catches up.
        }
    }

    public function addWorkers(): void
    {
        $this->authorize('update', $this->site);
        $saved = is_array($this->site->edgeMeta()['container']['workers'] ?? null);
        $this->workers = array_merge(EdgeQueueWorkers::normalize($this->workers), ['enabled' => true]);
        if (! $saved) {
            // First time: as many processes as the instance comfortably runs.
            $this->workers['processes'] = EdgeQueueWorkers::recommendedProcesses($this->site);
        }
        $this->panel = '';
        $this->refreshPending();
    }

    public ?string $schedulerOutput = null;

    /** The Laravel scheduler as a resource: every minute, in a worker when the app has them. */
    public function addScheduler(): void
    {
        $this->authorize('update', $this->site);
        $this->scheduler = true;
        $this->panel = '';
        $this->refreshPending();
    }

    public function removeScheduler(): void
    {
        $this->authorize('update', $this->site);
        $this->scheduler = false;
        $this->schedulerOutput = null;
        $this->refreshPending();
    }

    /** Run schedule:run once now, in the live app, and show what it printed. */
    public function runSchedulerNow(): void
    {
        $this->authorize('update', $this->site);
        $url = $this->site->edgeLiveUrl();
        if (! is_string($url) || $url === '') {
            $this->schedulerOutput = __('This app has no live URL yet. Deploy it first.');

            return;
        }
        try {
            $response = Http::timeout(120)
                ->withHeaders(['x-dply-queue-token' => EdgeContainerDeployer::queueToken($this->site)])
                ->post(rtrim($url, '/').'/_dply/schedule', ['handler' => 'schedule:run']);
            $body = $response->json();
            $this->schedulerOutput = is_array($body)
                ? trim((string) ($body['output'] ?? $body['error'] ?? '')) ?: __('schedule:run finished with nothing to print.')
                : __('The app answered HTTP :status. Deploy once with the scheduler on so dply/laravel is in the image.', ['status' => $response->status()]);
        } catch (\Throwable $e) {
            $this->schedulerOutput = $e->getMessage();
        }
    }

    /** Another group of workers for other queues, sized and scaled on its own. */
    public function addWorkerGroup(): void
    {
        $this->authorize('update', $this->site);
        $groups = array_values((array) ($this->workers['groups'] ?? []));
        if (count($groups) >= EdgeQueueWorkers::MAX_GROUPS) {
            return;
        }
        $taken = array_map(static fn ($g): string => (string) ($g['queues'] ?? ''), $groups);
        $groups[] = [
            'key' => '',
            'queues' => in_array('high', $taken, true) ? 'group'.(count($groups) + 1) : 'high',
            'instances' => 1,
            'processes' => EdgeQueueWorkers::recommendedProcesses($this->site),
            'autoscale' => false,
            'max_instances' => 1,
            'scale_per' => 10,
            'max_wait' => 60,
        ];
        $this->workers['groups'] = $groups;
        $this->refreshPending();
    }

    public function removeWorkerGroup(int $index): void
    {
        $this->authorize('update', $this->site);
        $groups = array_values((array) ($this->workers['groups'] ?? []));
        unset($groups[$index]);
        $this->workers['groups'] = array_values($groups);
        $this->refreshPending();
    }

    public function removeWorkers(): void
    {
        $this->authorize('update', $this->site);
        // Back to the saved settings, off: undoing an unsaved add leaves nothing pending.
        $this->workers = array_merge(EdgeQueueWorkers::for($this->site), ['enabled' => false]);
        $this->workersBacklog = null;
        $this->workersStatus = null;
        $this->refreshPending();
    }

    /**
     * Jobs waiting on the workers' queues, read from Redis or the database
     * with the app's own login. Reading a sleeping database wakes it.
     */
    public function loadWorkersBacklog(): void
    {
        $this->authorize('view', $this->site);
        $this->loadWorkersStatus();
        $settings = EdgeQueueWorkers::for($this->site);
        $queues = explode(',', $settings['queues']);
        $this->workersBacklogError = null;
        try {
            if (EdgeQueueWorkers::connection($this->site) === 'redis') {
                $connection = collect(EdgeContainerConnections::for($this->site))->first(fn (array $c): bool => $c['kind'] === 'redis' && EdgeValkey::isTarget($c['target']));
                $password = is_array($connection) ? $this->readValkeyPassword($connection['host']) : '';
                if ($password === '') {
                    throw new \RuntimeException(__('The backlog can be read from dply Valkey. Deploy once so REDIS_URL is set.'));
                }
                $this->workersBacklog = ['queues' => EdgeValkey::queueLengths($connection['target'], $password, $queues), 'failed' => null];
            } else {
                $record = $this->dplyDatabaseRecord();
                if ($record === null) {
                    throw new \RuntimeException(__('The backlog can be read from a dply database.'));
                }
                $this->workersBacklog = EdgeDplyDatabaseStats::queueBacklog((string) $record['engine'], (string) $record['host'], (string) $record['remote_id'], $this->readDatabasePassword(), $queues);
            }
        } catch (\Throwable $e) {
            $this->workersBacklogError = $e->getMessage();
        }
    }

    /** Each deployed worker's state, from the live app. Unsaved worker changes are not deployed yet. */
    private function loadWorkersStatus(): void
    {
        $this->workersStatus = null;
        $this->workersStatusError = null;
        if (EdgeQueueWorkers::runningInstances($this->site) === 0) {
            return;
        }
        try {
            $this->workersStatus = EdgeQueueWorkers::status($this->site);
            try {
                $this->workersPlacement = EdgeQueueWorkers::placements($this->site);
            } catch (\Throwable) {
                $this->workersPlacement = []; // logs unreadable: status still shows
            }
        } catch (\Throwable $e) {
            $this->workersStatusError = __('Could not reach the app for worker status: :error', ['error' => $e->getMessage()]);
        }
    }

    /** @var list<array{at: ?string, level: string, message: string}>|null */
    public ?array $workerLogs = null;

    public ?string $workerLogsError = null;

    public function openWorkerLogs(): void
    {
        $this->panel = 'worker-logs';
        $this->loadWorkerLogs();
    }

    public function loadWorkerLogs(): void
    {
        $this->authorize('view', $this->site);
        try {
            $this->workerLogs = EdgeQueueWorkers::logs($this->site);
            $this->workerLogsError = null;
        } catch (\Throwable $e) {
            $this->workerLogs = null;
            $this->workerLogsError = $e->getMessage();
        }
    }

    /** The failed jobs panel: the app's own failed-job store, read through the live app. */
    public function openFailedJobs(): void
    {
        $this->authorize('view', $this->site);
        $this->panel = 'failed-jobs';
        $this->confirmFlushFailed = false;
        $this->failedJobsNotice = null;
        $this->loadFailedJobs();
    }

    public function loadFailedJobs(): void
    {
        $this->authorize('view', $this->site);
        $this->failedJobsError = null;
        try {
            $body = $this->appCommand('failed-jobs');
            $this->failedJobs = ['total' => (int) ($body['total'] ?? 0), 'jobs' => array_values(array_filter((array) ($body['jobs'] ?? []), 'is_array'))];
        } catch (\Throwable $e) {
            $this->failedJobs = null;
            $this->failedJobsError = $e->getMessage();
        }
    }

    /** Retry one failed job, or every one when $id is null. */
    public function retryFailedJobs(?string $id = null): void
    {
        $this->authorize('update', $this->site);
        $this->runFailedJobsAction('retry', $id === null ? [] : [$id], $id === null ? __('Every failed job is back on its queue.') : __('The job is back on its queue.'));
    }

    public function forgetFailedJob(string $id): void
    {
        $this->authorize('update', $this->site);
        $this->runFailedJobsAction('forget', [$id], __('Deleted the failed job.'));
    }

    public function flushFailedJobs(): void
    {
        $this->authorize('update', $this->site);
        if (! $this->confirmFlushFailed) {
            $this->confirmFlushFailed = true;

            return;
        }
        $this->confirmFlushFailed = false;
        $this->runFailedJobsAction('flush-failed', [], __('Deleted every failed job.'));
    }

    /** @param  list<string>  $ids */
    private function runFailedJobsAction(string $command, array $ids, string $done): void
    {
        $this->failedJobsError = null;
        try {
            $this->appCommand($command, ['ids' => $ids]);
            $this->failedJobsNotice = $done;
        } catch (\Throwable $e) {
            $this->failedJobsError = $e->getMessage();

            return;
        }
        $this->loadFailedJobs();
        $this->workersBacklog = null;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function appCommand(string $command, array $input = []): array
    {
        return EdgeQueueWorkers::command($this->site, $command, $input);
    }

    /** Stop every worker (running jobs finish) until resumed; or resume. */
    public function pauseWorkers(bool $paused = true): void
    {
        $this->authorize('update', $this->site);
        try {
            $failed = collect(EdgeQueueWorkers::pause($this->site, $paused))->reject(fn (array $w): bool => $w['ok']);
        } catch (\Throwable $e) {
            $this->toastError(__('Could not reach the app: :error', ['error' => $e->getMessage()]));

            return;
        }
        $this->site->refresh();
        $this->workers['paused'] = $paused;
        $this->refreshPending();
        if ($failed->isNotEmpty()) {
            $this->workersStatusError = $failed->map(fn (array $w): string => $w['name'].': '.$w['error'])->implode(' · ');
        }
        $this->toastSuccess($paused ? __('Workers paused. Running jobs finish first.') : __('Workers resumed.'));
        $this->loadWorkersStatus();
    }

    /** Queue one test job through the app and let the workers pick it up. */
    public function sendTestJob(): void
    {
        $this->authorize('update', $this->site);
        try {
            $sent = EdgeQueueWorkers::sendTestJobs($this->site);
        } catch (\Throwable $e) {
            $this->toastError(__('Could not queue a test job: :error', ['error' => $e->getMessage()]));

            return;
        }
        $expected = EdgeQueueWorkers::connection($this->site);
        if ($expected !== null && $sent['connection'] !== $expected) {
            $this->toastError(__('The app queued it on :actual, but the workers read :expected. Redeploy so the app dispatches to :expected.', ['actual' => $sent['connection'], 'expected' => $expected]));

            return;
        }
        $this->toastSuccess(__('Test job queued on :queue. It shows in Logs as it runs.', ['queue' => $sent['queue']]));
    }

    public function startWorkers(): void
    {
        $this->authorize('update', $this->site);
        try {
            $failed = collect(EdgeQueueWorkers::start($this->site))->reject(fn (array $w): bool => $w['ok']);
        } catch (\Throwable $e) {
            $this->toastError(__('Could not reach the app to start the workers: :error', ['error' => $e->getMessage()]));

            return;
        }
        if ($failed->isNotEmpty()) {
            $this->workersStatusError = $failed->map(fn (array $w): string => $w['name'].': '.($w['error'] ?? __('did not start')))->implode(' · ');
            $this->toastError(__('Some workers did not start.'));

            return;
        }
        $this->toastSuccess(__('Workers started.'));
        $this->loadWorkersStatus();
    }

    /** Database panel: gateway state (never wakes it) and the agent's backup report. */
    public ?array $databaseStatus = null;

    public ?array $databaseBackup = null;

    /** Database panel: live numbers from the database itself (wakes it). */
    public ?array $databaseStats = null;

    public ?string $databaseStatsError = null;

    /**
     * The app's dply database record, or null when it has none (or SQLite).
     *
     * @return array<string, mixed>|null
     */
    private function dplyDatabaseRecord(): ?array
    {
        $record = $this->site->edgeMeta()['database'] ?? null;

        return is_array($record) && ($record['provider'] ?? '') === 'dply' && ($record['remote_id'] ?? '') !== '' ? $record : null;
    }

    public function loadDatabaseStatus(): void
    {
        $this->authorize('view', $this->site);
        $record = $this->dplyDatabaseRecord();
        if ($record === null) {
            return;
        }
        $client = ValkeyGatewayClient::fromConfig(EdgeDplyDatabase::regionOf($record));
        try {
            $this->databaseStatus = $client->get((string) $record['remote_id']);
        } catch (\Throwable $e) {
            $this->databaseStatsError = $e->getMessage();
        }
        try {
            $this->databaseBackup = $client->backupStatus((string) $record['remote_id']);
        } catch (\Throwable) {
            // An asleep database's agent is not running; the stored record still shows.
        }
    }

    public function loadDatabaseStats(): void
    {
        $this->authorize('view', $this->site);
        $record = $this->dplyDatabaseRecord();
        // MongoDB stats come from its agent, not the app's login.
        $password = ($record['engine'] ?? '') === 'mongodb' ? '' : $this->readDatabasePassword();
        if ($record === null || ($password === '' && ($record['engine'] ?? '') !== 'mongodb')) {
            $this->databaseStatsError = __('No password on this app yet. Deploy once so the database address is set.');

            return;
        }
        try {
            $this->databaseStats = EdgeDplyDatabaseStats::read((string) $record['engine'], (string) $record['host'], (string) $record['remote_id'], $password, EdgeDplyDatabase::regionOf($record));
            $this->databaseStatsError = null;
        } catch (\Throwable $e) {
            $this->databaseStatsError = $e->getMessage();
        }
        $this->loadDatabaseStatus();
    }

    /** Database panel: queries, health, extensions (dbagent insights). */
    public ?array $databaseInsights = null;

    public ?string $databaseInsightsError = null;

    public string $databaseConsoleSql = '';

    public string $databaseConsoleCollection = '';

    public string $databaseConsoleFilter = '{}';

    public ?array $databaseConsoleResult = null;

    public ?string $databaseConsoleError = null;

    /** @var list<array{file: string, key: string, bytes: int, at: string, url: string}>|null */
    public ?array $databaseExports = null;

    public string $databaseImportFile = '';

    public ?string $databaseUploadCommand = null;

    /**
     * Insights. Opening the panel reads the snapshot the agent took before its
     * last stop, which never wakes the database; $live (Refresh) wakes it.
     */
    public function loadDatabaseInsights(bool $live = false): void
    {
        $this->authorize($live ? 'update' : 'view', $this->site);
        $record = $this->dplyDatabaseRecord();
        if ($record === null) {
            return;
        }
        try {
            $this->databaseInsights = ValkeyGatewayClient::fromConfig(EdgeDplyDatabase::regionOf($record))->insights((string) $record['remote_id'], ! $live);
            $this->databaseInsightsError = null;
        } catch (\Throwable $e) {
            $this->databaseInsightsError = $this->databaseAgentMessage($e);
        }
    }

    public function resetDatabaseQueries(): void
    {
        if ($this->databaseAgent('queries-reset') !== null) {
            $this->toastSuccess(__('Query counts start fresh from now.'));
            $this->loadDatabaseInsights(true);
        }
    }

    public function cancelDatabaseQuery(int $pid): void
    {
        $out = $this->databaseAgent('cancel', ['pid' => $pid]);
        if ($out !== null) {
            ($out['ok'] ?? false) ? $this->toastSuccess(__('Cancelled.')) : $this->toastError(__('That query had already finished.'));
            $this->loadDatabaseInsights(true);
        }
    }

    public function enableDatabaseExtension(string $name): void
    {
        if ($this->databaseAgent('extension', ['name' => $name]) !== null) {
            $this->toastSuccess(__(':name is on.', ['name' => $name]));
            $this->loadDatabaseInsights(true);
        }
    }

    /** One read-only statement (a find for MongoDB), at most 200 rows. */
    public function runDatabaseConsole(): void
    {
        $this->authorize('update', $this->site);
        $record = $this->dplyDatabaseRecord();
        if ($record === null) {
            return;
        }
        $body = ($record['engine'] ?? '') === 'mongodb'
            ? ['collection' => trim($this->databaseConsoleCollection), 'filter' => trim($this->databaseConsoleFilter) ?: '{}']
            : ['sql' => $this->databaseConsoleSql];
        try {
            $this->databaseConsoleResult = ValkeyGatewayClient::fromConfig(EdgeDplyDatabase::regionOf($record))->action((string) $record['remote_id'], 'query', $body);
            $this->databaseConsoleError = null;
        } catch (\Throwable $e) {
            $this->databaseConsoleResult = null;
            $this->databaseConsoleError = $this->databaseAgentMessage($e);
        }
    }

    /**
     * Turn the read-only login (app_ro) on with a fresh password, returned
     * once to the page, or off. Postgres and MongoDB only: the MySQL gateway
     * checks passwords itself.
     */
    public function setDatabaseReadonlyLogin(bool $on): string
    {
        $password = $on ? bin2hex(random_bytes(16)) : '';
        if ($this->databaseAgent('readonly', ['password' => $password]) === null) {
            return '';
        }
        $this->site->mergeEdgeMeta(['database' => array_merge($this->site->edgeMeta()['database'] ?? [], ['readonly' => $on])]);
        $this->site->save();
        $on ? $this->toastSuccess(__('Read-only login on. Copy the password now; it is not shown again.')) : $this->toastSuccess(__('Read-only login off.'));

        return $password;
    }

    public function loadDatabaseExports(): void
    {
        $this->authorize('view', $this->site);
        $record = $this->dplyDatabaseRecord();
        if ($record === null) {
            return;
        }
        try {
            $this->databaseExports = array_reverse(ValkeyGatewayClient::fromConfig(EdgeDplyDatabase::regionOf($record))->databaseExports((string) $record['remote_id']));
        } catch (\Throwable $e) {
            $this->databaseExports = null;
            $this->databaseInsightsError = $this->databaseAgentMessage($e);
        }
    }

    public function exportDatabase(): void
    {
        $this->startDatabaseTransfer('export', '');
    }

    /** Load a file into this database: one it exported, or one uploaded to its imports. */
    public function importDatabase(string $from, string $file): void
    {
        $record = $this->dplyDatabaseRecord();
        if ($record === null || ! in_array($from, ['exports', 'imports'], true) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,119}$/', $file) !== 1) {
            $this->toastError(__('Pick a file to load.'));

            return;
        }
        // Built from this database's own id: never a key from the browser.
        $this->startDatabaseTransfer('import', 'tenants/'.$record['remote_id'].'/'.$from.'/'.$file);
    }

    /** A curl command that uploads a dump to this database's imports, valid an hour. */
    public function prepareDatabaseUpload(): void
    {
        $this->authorize('update', $this->site);
        $record = $this->dplyDatabaseRecord();
        $file = trim($this->databaseImportFile);
        if ($record === null || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,119}$/', $file) !== 1) {
            $this->toastError(__('Name the file: letters, digits, dot, dash and underscore.'));

            return;
        }
        try {
            $link = ValkeyGatewayClient::fromConfig(EdgeDplyDatabase::regionOf($record))->databaseUploadLink((string) $record['remote_id'], $file);
            $this->databaseUploadCommand = 'curl -fT '.escapeshellarg($file).' '.escapeshellarg($link['url']);
        } catch (\Throwable $e) {
            $this->toastError($this->databaseAgentMessage($e));
        }
    }

    private function startDatabaseTransfer(string $kind, string $key): void
    {
        $this->authorize('update', $this->site);
        $database = $this->dplyDatabaseRecord();
        if ($database === null) {
            return;
        }
        $running = $database['transfer'] ?? null;
        // A job lasts at most an hour; past that a "running" row is a dead worker.
        if (is_array($running) && ($running['status'] ?? '') === 'running' && Carbon::parse($running['started_at'] ?? 'now')->gt(now()->subMinutes(70))) {
            $this->toastError(__('An export or import is already running.'));

            return;
        }
        $this->site->mergeEdgeMeta(['database' => array_merge($database, ['transfer' => ['status' => 'running', 'kind' => $kind, 'file' => basename($key), 'started_at' => now()->toIso8601String()]])]);
        $this->site->save();
        TransferEdgeDplyDatabaseJob::dispatch((string) $this->site->id, $kind, $key);
    }

    /** @param array<string, mixed> $body */
    private function databaseAgent(string $name, array $body = []): ?array
    {
        $this->authorize('update', $this->site);
        $record = $this->dplyDatabaseRecord();
        if ($record === null) {
            return null;
        }
        try {
            return ValkeyGatewayClient::fromConfig(EdgeDplyDatabase::regionOf($record))->action((string) $record['remote_id'], $name, $body);
        } catch (\Throwable $e) {
            $this->toastError($this->databaseAgentMessage($e));

            return null;
        }
    }

    /**
     * A gateway or database agent from before insights answers 404: say it
     * arrives with the update instead of showing "404 page not found".
     */
    private function databaseAgentMessage(\Throwable $e): string
    {
        $message = trim($e->getMessage());
        if (preg_match('/\b404\b|page not found|not available for this engine|unknown action/i', $message) === 1) {
            return __('This arrives with the next database update. A database moves onto it after its next sleep.');
        }
        if (preg_match('/timed out|timeout/i', $message) === 1) {
            return __('The database is still waking up (the first start on a machine can take up to a minute). Try again in a few seconds.');
        }

        return $message;
    }

    /**
     * The app's database password, read from its env only when asked for, so
     * it is not in the page until someone who can edit the app clicks Show.
     */
    public function databasePassword(): string
    {
        $this->authorize('update', $this->site);

        return $this->readDatabasePassword();
    }

    /** Server-side use only (stats, backlog): never returned to the browser without update. */
    private function readDatabasePassword(): string
    {
        $record = $this->dplyDatabaseRecord();
        if ($record === null) {
            return '';
        }
        $env = fn (string $key): string => (string) ($this->site->edgeEnvVars()->where('scope', 'production')->where('key', $key)->first()?->value ?? '');
        if (($record['engine'] ?? '') === 'mongodb') {
            return rawurldecode((string) (parse_url($env('MONGODB_URI'), PHP_URL_PASS) ?? ''));
        }

        return $env('DB_PASSWORD');
    }

    /**
     * This month's awake time and storage, and awake hours for each of the
     * last 14 days (for the chart).
     *
     * @return array{awake_seconds: int, storage_gb_hours: float, days: list<array{date: string, hours: float}>}
     */
    private function databaseUsage(): array
    {
        $rows = EdgePostgresUsage::query()->where('site_id', $this->site->id)
            ->where('date', '>=', now()->subDays(40)->toDateString())
            ->get(['date', 'compute_unit_seconds', 'storage_byte_hours'])
            ->keyBy(fn ($row) => $row->date->toDateString());
        $month = $rows->filter(fn ($row) => $row->date->isSameMonth(now()));
        // Usage is in compute units (1 CU = 4 GB awake for a second): divide by
        // this database's size to get wall-clock awake time.
        $cu = (float) (EdgeAppDatabase::POSTGRES_SIZES[(string) ($this->dplyDatabaseRecord()['size'] ?? '0.25')]['cu'] ?? 0.25);
        $days = [];
        for ($i = 13; $i >= 0; $i--) {
            $date = now()->subDays($i)->toDateString();
            $days[] = ['date' => $date, 'hours' => round(((int) ($rows[$date]->compute_unit_seconds ?? 0)) / $cu / 3600, 1)];
        }

        return [
            'awake_seconds' => (int) round($month->sum('compute_unit_seconds') / $cu),
            'storage_gb_hours' => round($month->sum('storage_byte_hours') / 1024 ** 3, 1),
            'days' => $days,
        ];
    }

    public function loadValkeyStatus(): void
    {
        $this->authorize('view', $this->site);
        $connection = collect(EdgeContainerConnections::for($this->site))->firstWhere('host', $this->valkeyHost);
        if (! is_array($connection) || ! EdgeValkey::isTarget($connection['target'])) {
            return;
        }
        try {
            $this->valkeyStatus = ValkeyGatewayClient::fromConfig(EdgeValkey::region($connection['target']))->get(EdgeValkey::tenantId($connection['target']));
            $this->valkeyStatsError = null;
        } catch (\Throwable $e) {
            $this->valkeyStatsError = $e->getMessage();
        }
    }

    public function loadValkeyStats(): void
    {
        $this->authorize('view', $this->site);
        $password = $this->readValkeyPassword($this->valkeyHost);
        $connection = collect(EdgeContainerConnections::for($this->site))->firstWhere('host', $this->valkeyHost);
        if (! is_array($connection) || $password === '') {
            $this->valkeyStatsError = __('No password on this app yet. Deploy once so REDIS_URL is set.');

            return;
        }
        try {
            $this->valkeyStats = EdgeValkey::stats($connection['target'], $password);
            $this->valkeyStatsError = null;
            try {
                $this->valkeySlowlog = ValkeyGatewayClient::fromConfig(EdgeValkey::region($connection['target']))->slowlog(EdgeValkey::tenantId($connection['target']))['entries'];
            } catch (\Throwable) {
                $this->valkeySlowlog = null; // an older gateway: stats still show
            }
        } catch (\Throwable $e) {
            $this->valkeyStatsError = $e->getMessage();
        }
        $this->loadValkeyStatus();
    }

    /**
     * Test tab, "From the app": the app times its own Redis connection
     * (dply/laravel's redis-probe command), so the numbers are what the app
     * gets from where Cloudflare runs it. Laravel apps with dply/laravel only.
     */
    public function testValkeyFromApp(): void
    {
        $this->authorize('update', $this->site);
        $url = $this->site->edgeLiveUrl();
        $fail = fn (string $error) => $this->valkeyAppTest = ['ok' => false, 'error' => $error, 'steps' => []];
        if (! is_string($url) || $url === '') {
            $fail(__('This app has no live URL yet. Deploy it first.'));

            return;
        }
        try {
            $response = Http::timeout(60)
                ->withHeaders(['x-dply-queue-token' => EdgeContainerDeployer::queueToken($this->site)])
                ->post(rtrim($url, '/').'/_dply/command', ['command' => 'redis-probe']);
        } catch (\Throwable $e) {
            $fail($e->getMessage());

            return;
        }
        $body = $response->json();
        if (! is_array($body) || ! array_key_exists('steps', $body)) {
            $fail($response->status() === 422 || $response->status() === 404
                ? __('The app does not answer this test yet. It needs dply/laravel from the next deploy (Laravel apps only).')
                : __('The app answered HTTP :status.', ['status' => $response->status()]));

            return;
        }
        $this->valkeyAppTest = $body;
    }

    /** Test tab: connect like the app does and time a few commands. */
    public function testValkey(): void
    {
        $this->authorize('view', $this->site);
        $password = $this->readValkeyPassword($this->valkeyHost);
        $connection = collect(EdgeContainerConnections::for($this->site))->firstWhere('host', $this->valkeyHost);
        if (! is_array($connection) || $password === '') {
            $this->valkeyTest = ['ok' => false, 'error' => __('No password on this app yet. Deploy once so REDIS_URL is set.'), 'steps' => [], 'ping_median_ms' => null, 'ping_max_ms' => null];

            return;
        }
        $this->valkeyTest = EdgeValkey::probe($connection['target'], $password);
    }

    public string $kvDemoKey = 'hello';

    public string $kvDemoValue = '';

    public string $kvDemoPreview = '';

    /** @var list<string> */
    public array $kvDemoLog = [];

    public string $kvName = '';

    /** @var list<string> */
    public array $kvKeys = [];

    public int $kvReads = 0;

    public int $kvWrites = 0;

    public int $kvDeletes = 0;

    public int $kvLists = 0;

    public int $kvStorageBytes = 0;

    public int $kvMonthCents = 0;

    public string $objectHost = '';

    public string $objectKey = 'hello.txt';

    public string $objectBody = '';

    public string $objectPreview = '';

    /** @var list<string> */
    public array $objectLog = [];

    /** @var list<array{key: string, size: int}> */
    public array $objectList = [];

    /**
     * The organization's own resources, from catalog(). Locked: attach saves
     * whichever id is picked from this list, so the browser must not add one.
     *
     * @var list<array{id: string, label: string}>
     */
    #[Locked]
    public array $connectionOptions = [];

    public function mount(Server $server, Site $site): void
    {
        $this->mountEdgeWorkspaceSection($server, $site);
        // Saves on this same instance; a refresh() here only re-ran every
        // loaded relation (server, preview domains) for nothing.
        EdgeContainerConnections::prefixBareHosts($site);
        if (($site->edgeMeta()['runtime_mode'] ?? '') === 'container') {
            $settings = EdgeContainerSettings::for($site);
            $this->sleepAfter = $settings['sleep_after'];
            $this->jurisdiction = $settings['jurisdiction'];
            $this->regions = $settings['regions'];
            $this->scheduler = $settings['scheduler'];
            $this->stickySessions = $settings['sticky_sessions'];
            $this->dedicatedJobs = $settings['dedicated_jobs'];
            $this->workers = EdgeQueueWorkers::for($this->site);
            $this->migrateOnBoot = $settings['migrate_on_boot'];
            $this->rolloutMode = $settings['rollout_mode'];
            $this->rolloutSteps = implode(', ', $settings['rollout_step_percentage']);
            $this->rolloutGraceSeconds = $settings['rollout_active_grace_period'];
            $raw = is_array($site->edgeMeta()['container'] ?? null) ? $site->edgeMeta()['container'] : [];
            $this->customVcpu = (int) ($raw['custom_vcpu'] ?? 1);
            $this->customMemoryGib = (int) ($raw['custom_memory_gib'] ?? 3);
            $this->customDiskGb = (int) ($raw['custom_disk_gb'] ?? 6);
        }
        $this->hydrateDrafts();
    }

    public function updated(string $name): void
    {
        if ($name === 'draftInstanceType') {
            $allowed = $this->draftInstanceType === 'custom' || array_key_exists($this->draftInstanceType, EdgeContainerSettings::INSTANCE_TYPES);
            if (! $allowed) {
                $this->draftInstanceType = $this->persistedState()['instance_type'];
            }
        }

        if ($name === 'jurisdiction' || str_starts_with($name, 'regions')) {
            $this->regions = EdgeContainerSettings::normalizeRegions($this->regions, $this->jurisdiction);
        }

        // Auto-save persists these straight away, so hold them to saveRuntime's rules first.
        if (array_key_exists($name, $this->runtimeRules())) {
            $this->validateOnly($name, $this->runtimeRules());
        }

        if (in_array($name, ['draftInstanceType', 'sleepAfter', 'jurisdiction', 'scheduler', 'stickySessions', 'dedicatedJobs', 'migrateOnBoot', 'customVcpu', 'customMemoryGib', 'customDiskGb', 'rolloutMode', 'rolloutSteps', 'rolloutGraceSeconds'], true) || str_starts_with($name, 'regions') || str_starts_with($name, 'workers.')) {
            $this->refreshPending();
        }
    }

    public function openPanel(string $panel): void
    {
        if ($panel !== '' && ! in_array($panel, ['sleep', 'cache', 'databases', 'connection', 'delete-connection', 'browser', 'estimate', 'failed-jobs', 'worker-logs'], true)) {
            return;
        }

        $this->panel = $panel;
    }

    public function selectSize(string $type): void
    {
        $this->authorize('update', $this->site);
        if (($this->site->edgeMeta()['runtime_mode'] ?? '') !== 'container') {
            return;
        }
        if ($type !== 'custom' && ! array_key_exists($type, EdgeContainerSettings::INSTANCE_TYPES)) {
            return;
        }

        $this->draftInstanceType = $type;
        $this->refreshPending();
    }

    public function saveCustom(): void
    {
        $this->authorize('update', $this->site);
        $error = EdgeContainerSettings::customError($this->customVcpu, $this->customMemoryGib, $this->customDiskGb);
        if ($error !== null) {
            $this->addError('custom', $error);

            return;
        }

        $this->draftInstanceType = 'custom';
        $this->refreshPending();
    }

    public function selectInstances(int $count): void
    {
        $this->authorize('update', $this->site);
        if (($this->site->edgeMeta()['runtime_mode'] ?? '') !== 'container') {
            return;
        }
        if (! in_array($count, self::INSTANCE_COUNTS, true)) {
            return;
        }

        $this->draftMaxInstances = $count;
        $this->refreshPending();
    }

    /** @return array<string, list<string>> */
    private function runtimeRules(): array
    {
        return [
            'sleepAfter' => ['required', 'in:'.implode(',', EdgeContainerSettings::SLEEP_AFTER)],
            'jurisdiction' => ['in:'.implode(',', EdgeContainerSettings::JURISDICTIONS)],
            'rolloutMode' => ['required', 'in:'.implode(',', EdgeContainerSettings::ROLLOUT_MODES)],
            'rolloutGraceSeconds' => ['integer', 'between:0,'.EdgeContainerSettings::ROLLOUT_GRACE_MAX],
        ];
    }

    public function saveRuntime(): void
    {
        $this->authorize('update', $this->site);
        if (($this->site->edgeMeta()['runtime_mode'] ?? '') !== 'container') {
            return;
        }

        $this->validate($this->runtimeRules());
        $stepsError = EdgeContainerSettings::rolloutStepsError($this->rolloutSteps);
        if ($stepsError !== null) {
            $this->addError('rolloutSteps', $stepsError);

            return;
        }

        $this->panel = '';
        $this->refreshPending();
    }

    public function selectCache(string $mode): void
    {
        $this->authorize('update', $this->site);
        if (! in_array($mode, ['off', 'assets', 'standard', 'everything'], true)) {
            return;
        }

        $this->draftCacheMode = $mode;
        $this->refreshPending();
    }

    public function toggleEdgeCache(bool $enabled): void
    {
        $current = is_array($this->site->edgeMeta()['cache'] ?? null) ? $this->site->edgeMeta()['cache'] : [];
        if (! $enabled) {
            $this->selectCache('off');

            return;
        }

        $restore = (string) ($current['restore_mode'] ?? 'assets');
        $this->selectCache(in_array($restore, ['assets', 'standard', 'everything'], true) ? $restore : 'assets');
    }

    public function enableBrowser(): void
    {
        $this->authorize('update', $this->site);
        if ($this->allowedKinds() === []) {
            return;
        }
        if (! EdgeContainerConnections::paidFeatures($this->site->organization)) {
            $this->toastError(EdgeContainerConnections::paidOnlyReason());

            return;
        }
        $this->site->mergeEdgeMeta(['browser' => true, 'connections' => $this->connectionsWithoutBrowser()]);
        $this->site->save();
        $this->toastSuccess(__('Browser turned on. Deploy the app before it can open pages.'));
    }

    public function runBrowserDemo(string $kind): void
    {
        $this->authorize('update', $this->site);
        $this->browserDemoKind = '';
        $this->browserDemoPreview = '';
        $this->browserDemoLog = [];
        $this->resetErrorBag('browserDemo');
        if (! in_array($kind, ['content', 'screenshot', 'pdf'], true)) {
            return;
        }

        $path = $kind === 'content' ? '/content' : ($kind === 'pdf' ? '/pdf' : '/screenshot');
        $result = $kind === 'content' ? 'the page' : ($kind === 'pdf' ? 'a PDF' : 'a PNG');
        $host = EdgeContainerConnections::browserHost($this->site);

        try {
            $url = PublicOutboundUrl::parse($this->browserDemoUrl)->url;
        } catch (UnsafeOutboundUrlException $e) {
            $this->browserDemoLog = [
                __('Checked the page address.'),
                $e->getMessage(),
            ];
            $this->addError('browserDemo', $e->getMessage());

            return;
        }

        $this->browserDemoLog = [
            __('This page asked for :result of :url.', ['result' => $result, 'url' => $url]),
            __('This demo calls the platform browser. It does not call the app.'),
            __('The app connects by posting {"url":":url"} to http://:host:path.', ['url' => $url, 'host' => $host, 'path' => $path]),
            __('Only this app can use that address, and only after a deploy. The worker opens the page and returns :result to the app.', ['result' => $result]),
        ];

        try {
            $rendered = EdgeCloudflareClient::fromConfig()->renderBrowser($kind, $url);
        } catch (\Throwable $e) {
            $message = $e->getMessage();
            if (str_contains(strtolower($message), 'authentication')) {
                $message = __('The platform account cannot open pages yet.');
            }
            $this->browserDemoLog[] = __('Stopped: :message', ['message' => $message]);
            $this->addError('browserDemo', $message);

            return;
        }

        $body = $rendered['body'];
        if (strlen($body) > 1_500_000) {
            $message = __('That result is too large to show here.');
            $this->browserDemoLog[] = $message;
            $this->addError('browserDemo', $message);

            return;
        }

        $this->browserDemoLog[] = __('The platform browser returned :result. Shown below.', ['result' => $result]);
        $this->browserDemoKind = $kind;
        $this->browserDemoPreview = $kind === 'content'
            ? mb_substr($body, 0, 1500)
            : base64_encode($body);
    }

    public function runServiceDemo(): void
    {
        $this->authorize('update', $this->site);
        $this->serviceDemoStatus = '';
        $this->serviceDemoPreview = '';
        $this->serviceDemoLog = [];
        $this->resetErrorBag('serviceDemo');

        $connection = collect(EdgeContainerConnections::for($this->site))
            ->firstWhere('host', $this->explainConnectionHost);
        $peer = is_array($connection)
            ? collect(EdgeContainerConnections::peerApps($this->site))->firstWhere('id', $connection['target'])
            : null;
        $path = '/'.ltrim(trim($this->serviceDemoPath), '/');
        if (! is_array($connection) || $connection['kind'] !== 'service' || preg_match('/[\s?#]/', $path) === 1) {
            $this->serviceDemoStatus = 'invalid';
            $this->serviceDemoLog = [__('Name a path on the other app, such as /.')];

            return;
        }

        $host = $connection['host'];
        $this->serviceDemoLog = [
            __('This page asked for GET :path.', ['path' => $path]),
            __('This demo calls the other app from here. It does not call this app.'),
            __('This app connects with GET http://:host:path.', ['host' => $host, 'path' => $path]),
            __('Only this app can use that address, and only after a deploy. The worker sends the same path to the other app and returns its response.'),
        ];

        if (! is_array($peer) || $peer['origin'] === '') {
            $this->serviceDemoStatus = 'missing';
            $this->serviceDemoLog[] = __('That app has no address yet.');

            return;
        }

        try {
            $url = PublicOutboundUrl::parse($peer['origin'].$path)->url;
        } catch (UnsafeOutboundUrlException $e) {
            $this->serviceDemoStatus = 'invalid';
            $this->serviceDemoLog[] = $e->getMessage();

            return;
        }

        try {
            $response = Http::timeout(15)->withoutRedirecting()->get($url);
        } catch (\Throwable $e) {
            $this->serviceDemoStatus = 'failed';
            $this->serviceDemoLog[] = __('Stopped: :message', ['message' => __('The other app did not answer.')]);

            return;
        }

        $this->serviceDemoStatus = (string) $response->status();
        $this->serviceDemoLog[] = __(':name answered :status.', ['name' => $peer['label'], 'status' => $response->status()]);
        $type = strtolower((string) $response->header('content-type'));
        if (str_contains($type, 'text') || str_contains($type, 'json')) {
            $this->serviceDemoPreview = mb_substr($response->body(), 0, 1500);
        }
    }

    public function runKvDemo(string $action): void
    {
        $this->authorize('update', $this->site);
        $this->kvDemoPreview = '';
        $this->kvDemoLog = [];
        $this->kvDemoMeta = null;
        $this->resetErrorBag('kvDemo');

        $connection = collect(EdgeContainerConnections::for($this->site))->firstWhere('host', $this->kvHost);
        if (is_array($connection) && $connection['asleep']) {
            $this->kvDemoLog = [__('This store is asleep. Wake it before reading or writing.')];

            return;
        }
        $key = trim($this->kvDemoKey);
        if (! is_array($connection) || $connection['kind'] !== 'key_value' || $connection['target'] === '' || preg_match('/^[A-Za-z0-9_.:\/-]{1,128}$/', $key) !== 1) {
            $this->kvDemoLog = [__('Name a key using letters, numbers, and . _ : / -')];

            return;
        }

        $host = $connection['host'];
        $namespace = $connection['target'];
        $client = EdgeCloudflareClient::fromConfig();
        $this->kvDemoLog = [
            __('This demo writes the store from here. It does not call this app.'),
            __('This app uses http://:host/:key after the next deploy.', ['host' => $host, 'key' => $key]),
        ];

        try {
            if ($action === 'write') {
                if (strlen($this->kvDemoValue) > 8192) {
                    $this->kvDemoLog[] = __('Keep the demo value under 8 KB.');

                    return;
                }
                $ttl = trim($this->kvDemoTtl);
                if ($ttl !== '' && (! ctype_digit($ttl) || (int) $ttl < 60)) {
                    $this->kvDemoLog[] = __('Expire after at least 60 seconds, or leave it empty to keep the key.');

                    return;
                }
                $client->putKvValue($namespace, $key, $this->kvDemoValue, $ttl === '' ? null : (int) $ttl);
                $this->kvDemoLog[] = $ttl === '' ? __('Saved :key.', ['key' => $key]) : __('Saved :key. It expires in :seconds seconds.', ['key' => $key, 'seconds' => number_format((int) $ttl)]);
                $this->kvDemoPreview = $this->kvDemoValue;
            } elseif ($action === 'read') {
                $value = $client->getKvValue($namespace, $key);
                $this->kvDemoLog[] = $value === null ? __('No value for :key.', ['key' => $key]) : __('Read :key.', ['key' => $key]);
                $this->kvDemoPreview = $value ?? '';
                $this->kvDemoMeta = $value === null ? null : $this->kvKeyDetails($client, $namespace, $key);
            } elseif ($action === 'delete') {
                $client->deleteKvValue($namespace, $key);
                $this->kvDemoLog[] = __('Deleted :key.', ['key' => $key]);
            } else {
                $this->kvDemoLog[] = __('Choose read, write, or delete.');
            }
        } catch (\Throwable $e) {
            $this->kvDemoLog[] = __('Stopped: :message', ['message' => __('The store did not answer.')]);
            $this->addError('kvDemo', __('The store did not answer.'));
        }
    }

    public function refreshKv(): void
    {
        $this->authorize('update', $this->site);
        $connection = $this->kvConnection();
        if ($connection === null) {
            $this->kvKeys = [];
            $this->kvCursor = null;
            $this->kvReads = $this->kvWrites = $this->kvDeletes = $this->kvLists = $this->kvStorageBytes = $this->kvMonthCents = 0;

            return;
        }

        $this->kvName = EdgeContainerConnections::resourceLabel($connection['host']);
        $this->loadKvUsage($connection['target']);

        $this->kvCursor = null;
        try {
            $page = EdgeCloudflareClient::fromConfig()->listKvKeysPage($connection['target'], null, trim($this->kvPrefix));
            $this->kvKeys = array_column($page['keys'], 'name');
            $this->kvCursor = $page['cursor'];
        } catch (\Throwable) {
            $this->kvKeys = [];
            $this->addError('kvSettings', __('The store did not answer.'));
        }
    }

    public function openKv(string $host): void
    {
        $this->authorize('update', $this->site);
        $this->kvHost = $host;
        $this->kvPrefix = '';
        $this->kvDemoPreview = '';
        $this->kvDemoLog = [];
        $this->kvDemoMeta = null;
        $this->resetErrorBag('kvSettings');
        $this->refreshKv();
        $this->dispatch('open-modal', 'resources-kv');
    }

    public function pickKvKey(string $key): void
    {
        $this->authorize('update', $this->site);
        $this->kvDemoKey = $key;
    }

    public function saveKvSettings(): void
    {
        $this->authorize('update', $this->site);
        $connection = $this->kvConnection();
        if ($connection === null) {
            return;
        }

        $identity = EdgeContainerConnections::identity($this->kvName, $this->site);
        if ($identity === null) {
            $this->addError('kvSettings', __('Name the store. Letters and numbers only, starting with a letter.'));

            return;
        }

        if ($identity['host'] !== $connection['host']) {
            foreach (EdgeContainerConnections::for($this->site) as $row) {
                if ($row['host'] === $identity['host'] || $row['name'] === $identity['name']) {
                    $this->addError('kvSettings', __('That name is already used on this app.'));

                    return;
                }
            }
        }

        try {
            EdgeCloudflareClient::fromConfig()->renameKvNamespace($connection['target'], $identity['resource']);
        } catch (\Throwable) {
            $this->addError('kvSettings', __('The store did not answer.'));

            return;
        }

        $rows = EdgeContainerConnections::for($this->site);
        foreach ($rows as $index => $row) {
            if ($row['host'] !== $connection['host'] || $row['kind'] !== 'key_value') {
                continue;
            }
            $rows[$index]['name'] = $identity['name'];
            $rows[$index]['host'] = $identity['host'];
        }
        $this->site->mergeEdgeMeta(['connections' => $rows]);
        $this->site->save();
        $this->kvHost = $identity['host'];
        $this->kvName = $identity['resource'];
        $this->toastSuccess(__('Saved. The app uses http://:host/ after the next deploy.', ['host' => $identity['host']]));
    }

    /**
     * @return array{kind: string, name: string, host: string, target: string, asleep: bool, plan: string, read_regions: int}|null
     */
    private function kvConnection(): ?array
    {
        $connection = collect(EdgeContainerConnections::for($this->site))->firstWhere('host', $this->kvHost);
        if (! is_array($connection) || $connection['kind'] !== 'key_value' || $connection['target'] === '') {
            return null;
        }

        return $connection;
    }

    private function loadKvUsage(string $namespaceId): void
    {
        $rows = EdgeKvUsage::query()
            ->where('namespace_id', $namespaceId)
            ->whereBetween('date', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])
            ->get(['reads', 'writes', 'deletes', 'lists', 'storage_bytes']);

        $this->kvReads = (int) $rows->sum('reads');
        $this->kvWrites = (int) $rows->sum('writes');
        $this->kvDeletes = (int) $rows->sum('deletes');
        $this->kvLists = (int) $rows->sum('lists');
        $this->kvStorageBytes = (int) $rows->max('storage_bytes');
        $this->kvMonthCents = app(EdgeKvCost::class)->cents($this->kvReads, $this->kvWrites, $this->kvDeletes, $this->kvLists, $this->kvStorageBytes);
    }

    public function pickObject(string $key): void
    {
        $this->authorize('update', $this->site);
        $this->objectKey = $key;
    }

    public function openObject(string $host): void
    {
        $this->authorize('update', $this->site);
        $this->objectHost = $host;
        $this->objectPrefix = '';
        $this->objectPreview = '';
        $this->objectLog = [];
        $this->resetErrorBag('object');
        $this->refreshObjectList();
        $connection = $this->objectConnection();
        $this->objectUsage = $connection === null ? null : $this->objectBucketUsage($connection['target']);
    }

    public function refreshObjectList(): void
    {
        $this->authorize('update', $this->site);
        $connection = $this->objectConnection();
        $this->objectCursor = null;
        if ($connection === null) {
            $this->objectList = [];

            return;
        }

        try {
            $page = EdgeCloudflareClient::fromConfig()->listR2ObjectsPage($connection['target'], null, trim($this->objectPrefix));
            $this->objectList = $page['objects'];
            $this->objectCursor = $page['cursor'];
        } catch (\Throwable) {
            $this->objectList = [];
            $this->addError('object', __('The bucket did not answer.'));
        }
    }

    public function runObject(string $action): void
    {
        $this->authorize('update', $this->site);
        $this->objectPreview = '';
        $this->objectLog = [];
        $this->resetErrorBag('object');

        $connection = $this->objectConnection();
        $key = trim($this->objectKey);
        if ($connection === null || preg_match('#^[A-Za-z0-9_.:/-]{1,256}$#', $key) !== 1 || str_contains($key, '..') || str_starts_with($key, '/')) {
            $this->objectLog = [__('Name an object using letters, numbers, and . _ : / -')];

            return;
        }

        $client = EdgeCloudflareClient::fromConfig();
        $bucket = $connection['target'];
        $this->objectLog = [
            __('This writes the bucket from here. It does not call this app.'),
            __('This app uses http://:host/:key after the next deploy.', ['host' => $connection['host'], 'key' => $key]),
        ];

        try {
            if ($action === 'write') {
                if (strlen($this->objectBody) > 65536) {
                    $this->objectLog[] = __('Keep this upload under 64 KB. Larger files go through the app.');

                    return;
                }
                $client->putR2Object($bucket, $key, $this->objectBody);
                $this->objectLog[] = __('Saved :key.', ['key' => $key]);
                $this->objectPreview = $this->objectBody;
            } elseif ($action === 'read') {
                $value = $client->getR2Object($bucket, $key);
                $this->objectLog[] = $value === null ? __('No object named :key.', ['key' => $key]) : __('Read :key.', ['key' => $key]);
                $this->objectPreview = $value === null ? '' : mb_substr($value, 0, 1500);
            } elseif ($action === 'delete') {
                $client->deleteR2Object($bucket, $key);
                $this->objectLog[] = __('Deleted :key.', ['key' => $key]);
            } else {
                $this->objectLog[] = __('Choose read, write, or delete.');
            }
            $this->refreshObjectList();
        } catch (\Throwable) {
            $this->objectLog[] = __('Stopped: :message', ['message' => __('The bucket did not answer.')]);
            $this->addError('object', __('The bucket did not answer.'));
        }
    }

    /**
     * @return array{kind: string, name: string, host: string, target: string, asleep: bool}|null
     */
    private function objectConnection(): ?array
    {
        $connection = collect(EdgeContainerConnections::for($this->site))->firstWhere('host', $this->objectHost);
        if (! is_array($connection) || $connection['kind'] !== 'object_storage' || $connection['target'] === '') {
            return null;
        }

        return $connection;
    }

    public function askRemoveBrowser(): void
    {
        $this->authorize('update', $this->site);
        $this->confirmRemoveBrowser = true;
    }

    public function removeBrowser(): void
    {
        $this->authorize('update', $this->site);
        $this->site->mergeEdgeMeta(['browser' => false, 'connections' => $this->connectionsWithoutBrowser()]);
        $this->site->save();
        $this->confirmRemoveBrowser = false;
        $this->dispatch('close-modal', 'resources-remove-browser');
        $this->toastSuccess(__('Browser removed. Deploy the app to stop opening pages.'));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function connectionsWithoutBrowser(): array
    {
        return array_values(array_filter(
            $this->site->edgeMeta()['connections'] ?? [],
            fn ($row) => ! is_array($row) || ($row['kind'] ?? '') !== 'browser',
        ));
    }

    public function openConnectionBuilder(): void
    {
        $this->authorize('update', $this->site);
        $this->reset('connectionKind', 'connectionLabel', 'connectionPick', 'connectionOptions');
        $this->connectionMode = 'create';
        $this->resetErrorBag('connection');
        $this->panel = 'connection';
    }

    /**
     * Kinds this app's runtime can use. A static site has no code to read
     * them; a Worker site gets what Workers for Platforms can bind.
     *
     * @return list<string>
     */
    /**
     * Paid plan on the workspace. DPLY_EDGE_SKIP_CARD_CHECK lets a local
     * install start paid resources without a card, for testing; it does
     * nothing outside APP_ENV=local.
     */
    private function cardOnFile(): bool
    {
        return EdgeContainerConnections::cardOnFile($this->site->organization);
    }

    private function allowedKinds(): array
    {
        return match ((string) ($this->site->edgeMeta()['runtime_mode'] ?? 'static')) {
            'container' => array_keys(EdgeContainerConnections::KINDS),
            'ssr', 'hybrid' => EdgeContainerConnections::WORKER_KINDS,
            default => [],
        };
    }

    /**
     * Names the last build's wrangler.toml declares. The repo wins at deploy.
     *
     * @return list<string>
     */
    private function repoBindingNames(): array
    {
        $deployment = EdgeDeployment::query()->where('site_id', $this->site->id)->whereNotNull('repo_config')->latest('id')->first();

        return array_column(array_filter(
            EdgeEffectiveBindings::for($this->site, $deployment),
            static fn (array $b): bool => $b['source'] === 'repo',
        ), 'name');
    }

    /**
     * Queue name => the other app that runs its jobs, for queues this app only sends to.
     *
     * @param  list<array{kind: string, target: string}>  $connections
     * @return array<string, string>
     */
    private function queueOwners(array $connections): array
    {
        $owners = [];
        foreach ($connections as $connection) {
            if ($connection['kind'] !== 'queue' || $this->site->organization === null) {
                continue;
            }
            $owner = EdgeQueueConsumers::owner($this->site->organization, $connection['target']);
            if ($owner !== null && ! $owner->is($this->site)) {
                $owners[$connection['target']] = (string) $owner->name;
            }
        }

        return $owners;
    }

    /**
     * Workers on the database queue keep a sleeping database awake. Queue on
     * dply Valkey instead: point the workers at Redis and open its setup.
     */
    public function useValkeyForWorkers(): void
    {
        $this->authorize('update', $this->site);
        $this->workers['connection'] = 'redis';
        $this->panel = 'connection';
        $this->chooseConnectionKind('redis');
        $this->refreshPending();
    }

    public function chooseConnectionKind(string $kind): void
    {
        if (! isset(EdgeContainerConnections::KINDS[$kind]) || ! in_array($kind, $this->allowedKinds(), true) || in_array($kind, EdgeContainerConnections::HIDDEN_FROM_BUILDER, true)) {
            return;
        }
        $this->connectionKind = $kind;
        $this->connectionMode = in_array($kind, EdgeContainerConnections::CREATABLE, true) || in_array($kind, ['redis', 'realtime'], true) ? 'create' : 'attach';
        $this->reset('connectionLabel', 'connectionPick', 'connectionOptions');
        $this->resetErrorBag('connection');
        if (in_array($kind, EdgeContainerConnections::ENABLE, true)) {
            $refused = EdgeContainerConnections::attachError($this->site, $kind, '');
            if ($refused !== null) {
                $this->connectionKind = '';
                $this->toastError($refused);

                return;
            }
            $this->storeConnection(strtoupper($kind), $kind.'.internal', '');

            return;
        }
        if ($kind === 'redis') {
            $this->valkeyClass = EdgeValkey::DEFAULT_CLASS;
            $this->valkeySleep = EdgeValkey::DEFAULT_SLEEP;
        }
        if ($kind === 'service') {
            $this->connectionOptions = array_map(static fn (array $peer): array => ['id' => $peer['id'], 'label' => $peer['label']], EdgeContainerConnections::peerApps($this->site));
        } elseif ($this->connectionMode === 'attach' && in_array($kind, EdgeContainerConnections::CREATABLE, true)) {
            $this->connectionOptions = EdgeContainerConnections::catalog($kind, $this->site->organization);
        }
    }

    public function setConnectionMode(string $mode): void
    {
        if (! in_array($mode, ['create', 'attach'], true) || (! in_array($this->connectionKind, EdgeContainerConnections::CREATABLE, true) && $this->connectionKind !== 'redis')) {
            return;
        }
        if ($this->connectionKind === 'redis') {
            $this->connectionMode = $mode;

            return;
        }
        $this->connectionMode = $mode;
        $this->connectionOptions = $mode === 'attach' ? EdgeContainerConnections::catalog($this->connectionKind, $this->site->organization) : [];
    }

    public function saveConnection(): void
    {
        $this->authorize('update', $this->site);
        $kind = $this->connectionKind;
        if (! isset(EdgeContainerConnections::KINDS[$kind]) || in_array($kind, EdgeContainerConnections::ENABLE, true) || ! in_array($kind, $this->allowedKinds(), true) || in_array($kind, EdgeContainerConnections::HIDDEN_FROM_BUILDER, true)) {
            return;
        }
        if ($kind === 'realtime') {
            $this->saveRealtimeConnection();

            return;
        }

        if ($this->connectionMode === 'attach' && in_array($kind, EdgeContainerConnections::CREATABLE, true)) {
            $match = collect($this->connectionOptions)->firstWhere('id', $this->connectionPick);
            if (! is_array($match)) {
                $this->addError('connection', 'Pick a resource to attach.');

                return;
            }
            // connectionOptions is client state: check the pick is still ours.
            $refused = EdgeContainerConnections::attachError($this->site, $kind, (string) $match['id']);
            if ($refused !== null) {
                $this->addError('connection', $refused);

                return;
            }
            $identity = EdgeContainerConnections::identity((string) $match['label'], $this->site);
            if ($identity === null) {
                $this->addError('connection', 'That resource needs a name that can become a host.');

                return;
            }
            $this->storeConnection($identity['name'], $identity['host'], (string) $match['id']);

            return;
        }

        $identity = EdgeContainerConnections::identity($this->connectionLabel, $this->site);
        if ($identity === null) {
            $this->addError('connection', 'Name the resource. Letters and numbers only, starting with a letter.');

            return;
        }

        $target = $identity['resource'];
        if (in_array($kind, EdgeContainerConnections::CREATABLE, true)) {
            // provision() applies the card rule, plan limits, and paid-only kinds.
            try {
                $target = EdgeContainerConnections::provision($kind, $identity['resource'], $this->site->organization, [
                    'location_hint' => $kind === 'object_storage' && isset(self::R2_LOCATION_HINTS[$this->objectLocationHint]) ? $this->objectLocationHint : null,
                ] + match ($kind) {
                    'vectors' => $this->vectorsProvisionOptions(),
                    'database_pool' => $this->poolProvisionOptions(),
                    default => [],
                });
            } catch (\Throwable $e) {
                $this->addError('connection', $e->getMessage());

                return;
            }
            if ($target === '') {
                $this->addError('connection', 'The resource was created but no id came back.');

                return;
            }
        } elseif ($kind === 'redis') {
            foreach (EdgeContainerConnections::for($this->site) as $connection) {
                if ($connection['kind'] === 'redis') {
                    $this->addError('connection', 'This app already has Redis. Detach it before connecting another address.');

                    return;
                }
            }
            if ($this->connectionMode === 'create') {
                if (! $this->cardOnFile()) {
                    $this->addError('connection', __('Add a card before starting dply Valkey. Usage is billed to that card.'));

                    return;
                }
                try {
                    // dply's own Valkey (T-021).
                    $this->redisPlan = isset(EdgeValkey::CLASSES[$this->valkeyClass]) ? $this->valkeyClass : EdgeValkey::DEFAULT_CLASS;
                    $valkey = EdgeValkey::provision($this->site, $identity['resource'], $this->redisPlan, $this->valkeySleep);
                    $started = ['id' => $valkey['target'], 'url' => $valkey['url']];
                } catch (\Throwable $e) {
                    $this->addError('connection', $e->getMessage());

                    return;
                }
                $this->writeRedisEnv($started['url']);
                $this->storeConnection($identity['name'], $identity['host'], $started['id'], $this->redisPlan);
                if ($this->getErrorBag()->has('connection')) {
                    $this->forgetRedisUrl();
                    EdgeContainerConnections::destroy('redis', $started['id'], $this->site->organization);
                } elseif (EdgeValkey::isTarget($started['id'])) {
                    $this->site->mergeEdgeMeta(['valkey_sleep' => [$started['id'] => EdgeValkey::sleepAfter($this->redisPlan, $this->valkeySleep)] + (array) ($this->site->edgeMeta()['valkey_sleep'] ?? [])]);
                    $this->site->save();
                }

                return;
            }
            $url = trim($this->connectionPick);
            if (strlen($url) > 2048 || preg_match('#^rediss?://\S+$#', $url) !== 1 || (parse_url($url)['host'] ?? '') === '') {
                $this->addError('connection', 'Paste a redis:// or rediss:// address.');

                return;
            }
            $this->writeRedisEnv($url);
            $this->storeConnection($identity['name'], $identity['host'], '');
            if ($this->getErrorBag()->has('connection')) {
                $this->forgetRedisUrl();
            }

            return;
        } elseif ($kind === 'durable_object') {
            $this->storeConnection($identity['name'], $identity['host'], '');

            return;
        } elseif ($kind === 'service') {
            $match = collect(EdgeContainerConnections::peerApps($this->site))->firstWhere('id', $this->connectionPick);
            if (! is_array($match)) {
                $this->addError('connection', 'Pick an app.');

                return;
            }
            $target = (string) $match['id'];
        } else {
            $target = trim($this->connectionPick);
            if ($target === '') {
                $this->addError('connection', 'Enter the existing '.EdgeContainerConnections::targetLabel($kind).'.');

                return;
            }
        }

        $this->storeConnection($identity['name'], $identity['host'], $target);
    }

    private function writeRedisEnv(string $url): void
    {
        $parts = parse_url($url) ?: [];
        $this->storeEnv('REDIS_URL', $url);
        $this->storeEnv('REDIS_USERNAME', rawurldecode((string) ($parts['user'] ?? '')) ?: 'default');
        $this->storeEnv('REDIS_PASSWORD', rawurldecode((string) ($parts['pass'] ?? '')));
    }

    private function storeEnv(string $key, string $value): void
    {
        $row = $this->site->edgeEnvVars()
            ->where('scope', EdgeSiteEnvVar::SCOPE_PRODUCTION)
            ->where('key', $key)
            ->first();
        if ($row === null) {
            (new EdgeSiteEnvVar([
                'site_id' => $this->site->id,
                'key' => $key,
                'value' => $value,
                'scope' => EdgeSiteEnvVar::SCOPE_PRODUCTION,
                'created_by_user_id' => auth()->id(),
            ]))->save();

            return;
        }
        $row->value = $value;
        $row->save();
    }

    private function forgetRedisUrl(): void
    {
        $this->site->edgeEnvVars()
            ->where('scope', EdgeSiteEnvVar::SCOPE_PRODUCTION)
            ->whereIn('key', ['REDIS_URL', 'REDIS_USERNAME', 'REDIS_PASSWORD'])
            ->delete();
    }

    private function storeConnection(string $name, string $host, string $target, string $plan = ''): void
    {
        $row = EdgeContainerConnections::normalize([
            'kind' => $this->connectionKind,
            'name' => $name,
            'host' => $host,
            'target' => $target,
            'plan' => $plan,
        ]);
        if ($row === null) {
            $this->addError('connection', 'That resource could not be connected.');

            return;
        }
        $existing = EdgeContainerConnections::for($this->site);
        foreach ($existing as $connection) {
            if ($connection['name'] === $row['name'] || $connection['host'] === $row['host']) {
                $this->addError('connection', 'That resource is already connected.');

                return;
            }
        }
        if (in_array($row['name'], $this->repoBindingNames(), true)) {
            $this->addError('connection', __('wrangler.toml already declares :name. The repo file wins, so pick another name.', ['name' => $row['name']]));

            return;
        }
        $existing[] = $row;
        $this->site->mergeEdgeMeta(['connections' => $existing]);
        $this->site->save();
        $this->panel = '';
        $this->reset('connectionKind', 'connectionLabel', 'connectionPick', 'connectionOptions');
        $this->dispatch('close-modal', 'resources-connection');
        $this->toastSuccess(__('Connected. It applies on the next deploy.'));
    }

    public function sleepConnection(string $host, bool $asleep): void
    {
        $this->authorize('update', $this->site);
        $rows = EdgeContainerConnections::for($this->site);
        $found = false;
        foreach ($rows as $index => $connection) {
            if ($connection['host'] !== $host) {
                continue;
            }
            $rows[$index]['asleep'] = $asleep;
            $found = true;
        }
        if (! $found) {
            return;
        }
        $kind = collect($rows)->firstWhere('host', $host)['kind'] ?? '';
        $valkey = collect($rows)->firstWhere('host', $host);
        if ($kind === 'redis' && EdgeValkey::isTarget((string) $valkey['target'])) {
            // The gateway, not the deploy, stops the billing: set it first.
            try {
                EdgeValkey::setAsleep($valkey['target'], $this->productionRedisUrl(), $valkey['plan'] !== '' ? $valkey['plan'] : EdgeValkey::DEFAULT_CLASS, (int) ($this->site->edgeMeta()['valkey_sleep'][$valkey['target']] ?? EdgeValkey::DEFAULT_SLEEP), $asleep);
            } catch (\Throwable $e) {
                $this->toastError(__('Could not reach the Valkey gateway: :error', ['error' => $e->getMessage()]));

                return;
            }
        }
        if ($kind === 'realtime') {
            // Enforced by the relay, not the deploy: disable it in KV (and
            // close open sockets) before the card says asleep. The env stays.
            $target = (string) (collect($rows)->firstWhere('host', $host)['target'] ?? '');
            $app = EdgeRealtimeApp::query()->whereKey($target)->where('organization_id', $this->site->organization_id)->first();
            try {
                if ($app !== null) {
                    app(EdgeRealtimeApps::class)->setAsleep($app, $asleep);
                }
            } catch (\Throwable $e) {
                $this->toastError(__('Could not reach the Realtime relay: :error', ['error' => $e->getMessage()]));

                return;
            }
        }
        $this->site->mergeEdgeMeta(['connections' => $rows]);
        $this->site->save();
        if ($kind === 'realtime') {
            $this->toastSuccess($asleep
                ? __('Asleep. Open connections were closed, and new ones and publishes are refused until you wake it.')
                : __('Awake. Connections and publishes work again now; no deploy needed.'));

            return;
        }
        if ($kind === 'redis') {
            $this->toastSuccess($asleep
                ? __('Asleep. The app stops receiving the Redis address on the next deploy. The store keeps its keys and stops billing a minute after the app lets go of it.')
                : __('Awake. The app gets the Redis address on the next deploy.'));

            return;
        }
        if ($kind === 'key_value') {
            $this->toastSuccess($asleep
                ? __('Asleep. The app loses this address on the next deploy, so it cannot read or write. Its stored data is kept and still billed; delete the store to stop that.')
                : __('Awake. The app gets the address on the next deploy.'));

            return;
        }
        $this->toastSuccess($asleep ? __('Asleep until you wake it. Applies on the next deploy.') : __('Awake on the next deploy.'));
    }

    public function askDeleteConnection(string $host): void
    {
        $this->authorize('update', $this->site);
        $this->deleteConnectionHost = $host;
        $this->panel = 'delete-connection';
    }

    public function deleteConnection(): void
    {
        $this->authorize('update', $this->site);
        $host = $this->deleteConnectionHost;
        $rows = EdgeContainerConnections::for($this->site);
        $target = null;
        $kept = [];
        foreach ($rows as $connection) {
            if ($connection['host'] === $host) {
                $target = $connection;

                continue;
            }
            $kept[] = $connection;
        }
        if ($target === null) {
            return;
        }
        // The live deploy still binds it: detach now, delete once the next
        // deploy has dropped the binding (EdgeContainerConnections::deletePending).
        $deferred = EdgeContainerConnections::deleteWaitsForDeploy($this->site, $target['kind'])
            && EdgeContainerConnections::owns($target['kind'], $target['target'], $this->site->organization);
        $deleted = false;
        if (! $deferred) {
            try {
                $deleted = EdgeContainerConnections::destroy($target['kind'], $target['target'], $this->site->organization);
            } catch (\Throwable $e) {
                // A preview can still bind a queue with no live deploy; Cloudflare refuses that too.
                if ($target['kind'] !== 'queue' || ! str_contains($e->getMessage(), 'still referenced by a binding')) {
                    $this->addError('connectionDelete', $e->getMessage());

                    return;
                }
                $deferred = true;
            }
        }
        if ($target['kind'] === 'redis') {
            $this->forgetRedisUrl();
        }
        $this->site->mergeEdgeMeta(['connections' => $kept]);
        if ($deferred) {
            EdgeContainerConnections::deleteAfterDeploy($this->site, $target['kind'], $target['target']);
            $this->site->mergeEdgeMeta(['settings_saved_at' => now()->toIso8601String()]);
        }
        $this->site->save();
        if ($deleted) {
            // The resource is gone: unbind it from the organization's other
            // apps too, or their next deploy would bind something missing.
            $this->detachEverywhere($target['kind'], $target['target']);
        }
        $this->deleteConnectionHost = '';
        $this->panel = '';
        if ($host === $this->kvHost) {
            $this->kvHost = '';
            $this->dispatch('close-modal', 'resources-kv');
        }
        if ($host === $this->objectHost) {
            $this->objectHost = '';
            $this->dispatch('close-modal', 'resources-object');
        }
        $this->dispatch('close-modal', 'resources-delete-connection');
        $this->toastSuccess(match (true) {
            $deferred && EdgeContainerConnections::boundElsewhere($this->site, $target['kind'], $target['target']) => __('Detached. Redeploy this app, and detach or delete it on the other app that still uses it too: it is kept, and billed, until no app does, then deleted after the next deploy.'),
            $deferred => __('Detached. The live app still uses it, so it is deleted after the next deploy — redeploy to finish.'),
            $deleted => __('Deleted.'),
            default => __('Detached. There was nothing this organization created to delete, so it was left in place.'),
        });
    }

    /**
     * The connection whose sheet is open, for kinds that use the generic
     * opener (SQL, queue, State, AI, external Redis, vectors, pool).
     */
    public string $resourceHost = '';

    /** Open a resource's sheet: resources-{kind}, keyed by its host. */
    public function openResource(string $host): void
    {
        $this->authorize('view', $this->site);
        $connection = collect(EdgeContainerConnections::for($this->site))->firstWhere('host', $host);
        if (! is_array($connection)) {
            return;
        }
        $this->resourceHost = $host;
        $kind = $connection['kind'] === 'redis' ? 'redis-external' : $connection['kind'];
        $this->dispatch('resource-opened', kind: $kind, host: $host);
        $this->dispatch('open-modal', 'resources-'.str_replace('_', '-', $kind));
    }

    /** @return array{kind: string, name: string, host: string, target: string, asleep: bool, plan: string, read_regions: int}|null */
    protected function openResourceConnection(): ?array
    {
        $connection = collect(EdgeContainerConnections::for($this->site))->firstWhere('host', $this->resourceHost);

        return is_array($connection) ? $connection : null;
    }

    private function detachEverywhere(string $kind, string $target): void
    {
        Site::query()
            ->where('organization_id', $this->site->organization_id)
            ->whereKeyNot($this->site->id)
            ->get()
            ->each(function (Site $other) use ($kind, $target): void {
                foreach (EdgeContainerConnections::for($other) as $connection) {
                    if ($connection['kind'] === $kind && $connection['target'] === $target) {
                        EdgeContainerConnections::detach($other, $connection['name']);
                    }
                }
            });
    }

    public function removeConnection(string $host): void
    {
        $this->authorize('update', $this->site);
        $rows = EdgeContainerConnections::for($this->site);
        // Detach only unlinks. A dply Valkey cannot be attached again, so it
        // has no Detach (it would keep running and billing): Delete it instead.
        foreach ($rows as $connection) {
            // Same for Realtime: it has no attach, so Detach would orphan a live app.
            if ($connection['host'] === $host && $connection['kind'] === 'realtime') {
                return;
            }
            if ($connection['host'] !== $host || $connection['kind'] !== 'redis') {
                continue;
            }
            if (EdgeValkey::isTarget($connection['target'])) {
                return;
            }
            $this->forgetRedisUrl();
        }
        $kept = array_values(array_filter(
            $rows,
            static fn (array $connection): bool => $connection['host'] !== $host,
        ));
        $this->site->mergeEdgeMeta(['connections' => $kept]);
        $this->site->save();
    }

    public function addDatabase(): void
    {
        $this->authorize('update', $this->site);
        $this->databaseVisible = true;
        $this->panel = '';
    }

    /**
     * Restore dply Postgres to a moment within backup retention (7 days) from
     * its wal-g backups. The current data is kept aside by the database until
     * the next restore.
     */
    public function restorePostgres(): void
    {
        $this->authorize('update', $this->site);
        $database = is_array($this->site->edgeMeta()['database'] ?? null) ? $this->site->edgeMeta()['database'] : [];
        if (! EdgeAppDatabase::isDply($database) || (string) ($database['remote_id'] ?? '') === '') {
            $this->postgresRestoreResult = __('Only a dply database can be restored here.');

            return;
        }
        try {
            $at = Carbon::parse($this->postgresRestoreAt, 'UTC');
        } catch (\Throwable) {
            $this->postgresRestoreResult = __('Pick a date and time.');

            return;
        }
        if ($at->isFuture() || $at->lt(now()->subDays(7))) {
            $this->postgresRestoreResult = __('Pick a time in the last 7 days.');

            return;
        }
        if (($database['restore']['status'] ?? '') === 'running') {
            $this->postgresRestoreResult = __('A restore is already running.');

            return;
        }
        // Minutes of work (fetch a backup, replay or load it): a queued job, not this request.
        $target = $at->utc()->format('Y-m-d\TH:i:s\Z');
        $this->site->mergeEdgeMeta(['database' => array_merge($database, ['restore' => ['status' => 'running', 'target' => $target]])]);
        $this->site->save();
        RestoreEdgeDplyPostgresJob::dispatch((string) $this->site->id, $target);
        $this->postgresRestoreResult = null;
    }

    public function runDatabaseCommand(string $action): void
    {
        $this->authorize('update', $this->site);

        if ($action === 'rollback') {
            $this->pendingDatabaseCommand = 'rollback';

            return;
        }

        $this->pendingDatabaseCommand = '';
        $this->executeDatabaseCommand($action);
    }

    public function confirmDatabaseCommand(): void
    {
        $this->authorize('update', $this->site);
        $action = $this->pendingDatabaseCommand;
        $this->pendingDatabaseCommand = '';
        if ($action !== '') {
            $this->executeDatabaseCommand($action);
        }
    }

    private function executeDatabaseCommand(string $action): void
    {
        $laravel = $this->site->isLaravelFrameworkDetected();
        $rails = $this->site->isRailsFrameworkDetected();
        $allowed = $laravel
            ? ['migrate', 'status', 'seed', 'rollback']
            : ($rails ? ['migrate', 'status', 'seed', 'rollback', 'prepare'] : []);
        if (! in_array($action, $allowed, true)) {
            $this->databaseCommandOutput = __('This app does not have database commands.');

            return;
        }

        $url = $this->site->edgeLiveUrl();
        if (! is_string($url) || $url === '') {
            $this->databaseCommandOutput = __('This app has no live URL yet. Deploy it first.');

            return;
        }

        try {
            $response = Http::timeout(120)
                ->withHeaders(['x-dply-queue-token' => EdgeContainerDeployer::queueToken($this->site)])
                ->post(rtrim($url, '/').'/_dply/command', ['command' => $action]);
        } catch (\Throwable $e) {
            $this->databaseCommandOutput = $e->getMessage();

            return;
        }

        $body = $response->json();
        $output = is_array($body) ? trim((string) ($body['output'] ?? $body['error'] ?? $body['task'] ?? '')) : '';
        $this->databaseCommandOutput = $output !== '' ? $output : $response->body();
    }

    public function selectDatabase(string $engine): void
    {
        $this->authorize('update', $this->site);
        if (! in_array($engine, EdgeAppDatabase::ENGINES, true)) {
            return;
        }
        if (in_array($engine, EdgeAppDatabase::DPLY_ENGINES, true) && (! $this->cardOnFile() || ! EdgeDplyDatabase::enabled())) {
            return;
        }

        $this->draftDatabase = $engine;
        $this->databaseVisible = $engine !== 'none';
        $this->refreshPending();
    }

    public function selectPostgresPlan(string $plan): void
    {
        $this->authorize('update', $this->site);
        if (! $this->cardOnFile()) {
            return;
        }
        if (! array_key_exists($plan, EdgeAppDatabase::POSTGRES_PLANS)) {
            return;
        }

        $this->draftPostgresPlan = $plan;
        $this->refreshPending();
    }

    public function selectPostgresSize(string $size): void
    {
        $this->authorize('update', $this->site);
        if (! $this->cardOnFile()) {
            return;
        }
        if (! array_key_exists($size, EdgeAppDatabase::POSTGRES_SIZES)) {
            return;
        }
        if (! in_array($size, EdgeDplyDatabase::OFFERED_SIZES, true)) {
            return;
        }

        $this->draftPostgresSize = $size;
        $this->refreshPending();
    }

    public function selectPostgresDisk(int $gb): void
    {
        $this->authorize('update', $this->site);
        if (! array_key_exists($gb, EdgeDplyDatabase::DISKS)) {
            return;
        }
        $this->draftPostgresDisk = $gb;
        $this->refreshPending();
    }

    public function selectPostgresSuspend(int $seconds): void
    {
        $this->authorize('update', $this->site);
        if (! $this->cardOnFile()) {
            return;
        }
        if (! array_key_exists($seconds, EdgeAppDatabase::POSTGRES_SLEEPS)) {
            return;
        }

        $this->draftPostgresSuspend = $seconds;
        $this->draftPostgresPlan = $seconds === -1 ? 'awake' : 'sleep';
        $this->refreshPending();
    }

    public function discardPending(): void
    {
        $this->site->refresh();
        $this->mountEdgeWorkspaceSection($this->server, $this->site);
        $meta = $this->site->edgeMeta();
        if (($meta['runtime_mode'] ?? '') === 'container') {
            $settings = EdgeContainerSettings::for($this->site);
            $this->sleepAfter = $settings['sleep_after'];
            $this->jurisdiction = $settings['jurisdiction'];
            $this->regions = $settings['regions'];
            $this->scheduler = $settings['scheduler'];
            $this->stickySessions = $settings['sticky_sessions'];
            $this->dedicatedJobs = $settings['dedicated_jobs'];
            $this->workers = EdgeQueueWorkers::for($this->site);
            $this->migrateOnBoot = $settings['migrate_on_boot'];
            $this->rolloutMode = $settings['rollout_mode'];
            $this->rolloutSteps = implode(', ', $settings['rollout_step_percentage']);
            $this->rolloutGraceSeconds = $settings['rollout_active_grace_period'];
            $raw = is_array($meta['container'] ?? null) ? $meta['container'] : [];
            $this->customVcpu = (int) ($raw['custom_vcpu'] ?? 1);
            $this->customMemoryGib = (int) ($raw['custom_memory_gib'] ?? 3);
            $this->customDiskGb = (int) ($raw['custom_disk_gb'] ?? 6);
        }
        $this->hydrateDrafts();
        $this->resetErrorBag();
    }

    public function saveSettings(): void
    {
        $this->persistPending(false);
    }

    public function redeploySettings(): void
    {
        if (! $this->persistPending(true)) {
            return;
        }

        $this->redeployEdge();
    }

    /**
     * This month's estimate in cents, keyed by connection host.
     *
     * @param  list<array{kind: string, host: string, target: string, asleep: bool, plan: string, read_regions: int}>  $connections
     * @return array<string, int>
     */
    /** Once per request: render() and the cost estimate both need it. */
    private ?int $valkeyAwakeSecondsMemo = null;

    /** Awake seconds for this app's Valkey this month (collected hourly). */
    private function valkeyAwakeSeconds(): int
    {
        return $this->valkeyAwakeSecondsMemo ??= (int) EdgeRedisUsage::query()->where('site_id', $this->site->id)
            ->whereBetween('date', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])
            ->sum('awake_seconds');
    }

    private function connectionCostEstimates(array $connections): array
    {
        $estimates = $this->sharedCostEstimates($connections);
        // A resource trait can give its own figure: {kind}CostCents($connection)
        // (e.g. sqlCostCents for one D1 instead of the organization's total).
        foreach ($connections as $connection) {
            $method = Str::camel($connection['kind']).'CostCents';
            if (method_exists($this, $method) && ($cents = $this->{$method}($connection)) !== null) {
                $estimates[$connection['host']] = $cents;
            }
        }

        return $estimates;
    }

    /** @return array<string, int|float> */
    private function sharedCostEstimates(array $connections): array
    {
        $from = now()->startOfMonth()->toDateString();
        $to = now()->endOfMonth()->toDateString();
        $estimates = [];
        $namespaces = [];
        $hasSql = false;
        $hasQueue = false;

        foreach ($connections as $connection) {
            if ($connection['kind'] === 'key_value' && $connection['target'] !== '') {
                $namespaces[] = $connection['target'];
            }
            $hasSql = $hasSql || $connection['kind'] === 'sql';
            $hasQueue = $hasQueue || $connection['kind'] === 'queue';
        }

        $kvRows = $namespaces === []
            ? collect()
            : EdgeKvUsage::query()
                ->whereIn('namespace_id', $namespaces)
                ->whereBetween('date', [$from, $to])
                ->get(['namespace_id', 'reads', 'writes', 'deletes', 'lists', 'storage_bytes'])
                ->groupBy('namespace_id');
        $kvCost = app(EdgeKvCost::class);
        foreach ($connections as $connection) {
            if ($connection['kind'] !== 'key_value') {
                continue;
            }
            $rows = $kvRows->get($connection['target'], collect());
            $estimates[$connection['host']] = $kvCost->cents(
                (int) $rows->sum('reads'),
                (int) $rows->sum('writes'),
                (int) $rows->sum('deletes'),
                (int) $rows->sum('lists'),
                (int) ($rows->max('storage_bytes') ?? 0),
            );
        }

        $valkeySeconds = $this->valkeyAwakeSeconds();
        foreach ($connections as $connection) {
            if ($connection['kind'] === 'redis' && EdgeValkey::isTarget($connection['target']) && $this->site->organization !== null) {
                // Exact (fractional) cents for display: the bill rounds the
                // month's total to a cent, but a few minutes is $0.0017, not $0.01.
                $class = EdgeValkey::spec((string) $connection['plan']);
                $estimates[$connection['host']] = min($class['cap_cents'], $valkeySeconds * $class['per_second'] * 100);
            }
        }

        if ($hasSql || $hasQueue) {
            $data = EdgeDataUsage::query()
                ->where('organization_id', $this->site->organization_id)
                ->whereBetween('date', [$from, $to])
                ->selectRaw('COALESCE(SUM(d1_rows_read), 0) as reads, COALESCE(SUM(d1_rows_written), 0) as writes, COALESCE(MAX(d1_storage_bytes), 0) as storage, COALESCE(SUM(queue_operations), 0) as operations')
                ->first();
            $dataCost = app(EdgeDataUsageCost::class);
            $sqlCents = $dataCost->cents((int) $data->reads, (int) $data->writes, (int) $data->storage, 0);
            $queueCents = $dataCost->cents(0, 0, 0, (int) $data->operations);
            foreach ($connections as $connection) {
                if ($connection['kind'] === 'sql') {
                    $estimates[$connection['host']] = $sqlCents;
                }
                if ($connection['kind'] === 'queue') {
                    $estimates[$connection['host']] = $queueCents;
                }
            }
        }

        return $estimates;
    }

    public function render(): View
    {
        return view('livewire.sites.edge.workspace.resources', $this->viewData());
    }

    /**
     * An action inside a sheet re-renders only that sheet's island
     * (resources.blade.php). The map shows what the sheets change
     * (connections, sizes, the redeploy banner), so it re-renders with each.
     */
    public function renderIsland($name, $content = null, $mode = 'morph', $with = [], $mount = false)
    {
        // Livewire renders the island after each call and skips it after the
        // first, so a second call in the same request (two $wire calls in one
        // handler) would be missing from it. Render it again instead.
        $this->renderedIslandFragments = array_values(array_filter(
            $this->renderedIslandFragments,
            fn (string $fragment): bool => ! str_contains($fragment, "|name={$name}|") && ! str_contains($fragment, '|name=map|'),
        ));
        $this->viewData = null;
        parent::renderIsland($name, $content, $mode, $with, $mount);

        if ($name !== 'map') {
            parent::renderIsland('map');
        }
    }

    /** @var array<string, mixed>|null */
    private ?array $viewData = null;

    /**
     * An island render skips render(), so each island reads this too
     * (`with: $this->viewData()`). Memoized: a full render renders every
     * island. Not with(): a public method is an action the browser can call.
     *
     * @return array<string, mixed>
     */
    protected function viewData(): array
    {
        return $this->viewData ??= $this->buildViewData(app(EdgeContainerComputeCost::class));
    }

    /** @return array<string, mixed> */
    private function buildViewData(EdgeContainerComputeCost $cost): array
    {
        $meta = $this->site->edgeMeta();
        $container = is_array($meta['container'] ?? null) ? $meta['container'] : [];
        $runtime = (string) ($meta['runtime_mode'] ?? 'static');
        $allowedKinds = $this->allowedKinds();
        $hasCode = $allowedKinds !== [];
        $plan = (string) ($container['plan'] ?? '');
        if ($plan === '' && $runtime === 'container') {
            $plan = EdgeContainerPlans::DEFAULT;
        }
        $settings = $runtime === 'container'
            ? EdgeContainerSettings::for($this->site)
            : null;
        $customQuote = null;
        $quote = null;
        if ($runtime === 'container') {
            $instances = max(1, $this->draftMaxInstances);
            if ($this->draftInstanceType === 'custom' && EdgeContainerSettings::customError($this->customVcpu, $this->customMemoryGib, $this->customDiskGb) === null) {
                $quote = $this->runningQuote($cost, $this->customVcpu, $this->customMemoryGib, $this->customDiskGb, $instances);
                $customQuote = [
                    'vcpu' => $this->customVcpu.' vCPU',
                    'memory' => $this->customMemoryGib.' GiB',
                    'disk' => $this->customDiskGb.' GB',
                    'price' => $quote['month'],
                ];
            } elseif (isset(EdgeContainerSettings::INSTANCE_TYPES[$this->draftInstanceType])) {
                [$vcpu, $memory, $disk] = EdgeContainerSettings::INSTANCE_TYPES[$this->draftInstanceType];
                $quote = $this->runningQuote($cost, (float) $vcpu, (float) $memory, (float) $disk, $instances);
            }
        }
        if (is_array($settings)) {
            $settings['instance_type'] = $this->draftInstanceType;
            $settings['max_instances'] = $this->draftMaxInstances;
            $settings['sleep_after'] = $this->sleepAfter;
        }
        $cache = is_array($meta['cache'] ?? null) ? $meta['cache'] : [];
        $cacheMode = $this->draftCacheMode;
        $hostname = (string) (parse_url((string) ($this->site->edgeLiveUrl() ?? ''), PHP_URL_HOST) ?: ($meta['routing']['hostname'] ?? ''));
        $storedDatabase = is_array($meta['database'] ?? null) ? $meta['database'] : [];
        $databaseEngine = $this->draftDatabase;
        $databaseCost = app(EdgeAppDatabaseCost::class);
        $postgres = $databaseCost->presentation();
        $postgresSizes = [];
        foreach (EdgeDplyDatabase::sizes() as $key => $size) {
            $size['second'] = $databaseCost->perSecond($size['cu']);
            $size['hour'] = $databaseCost->hourly($size['cu']);
            $size['day'] = $databaseCost->daily($size['cu']);
            $size['month'] = $databaseCost->monthly($size['cu']);
            $postgresSizes[$key] = $size;
        }
        $postgresSuspend = EdgeAppDatabase::postgresSuspend($this->draftPostgresSuspend, $this->draftPostgresPlan);
        $postgresPlan = $postgresSuspend === -1 ? 'awake' : 'sleep';
        $postgresSize = EdgeDplyDatabase::size($this->draftPostgresSize);
        $awakeHours = max(0, min(24, $this->awakeHours));
        foreach ($postgresSizes as $key => $size) {
            $hours = $postgresSuspend === -1 ? 24 : $awakeHours;
            $postgresSizes[$key]['day'] = number_format((float) $size['hour'] * $hours, 2);
            $postgresSizes[$key]['month'] = number_format((float) $size['hour'] * ($postgresSuspend === -1 ? 720 : $awakeHours * 30), 2);
        }

        return array_merge(
            EdgeSiteViewData::context($this->site, 'resources'),
            [
                'server' => $this->server,
                'site' => $this->site,
                'runtimeMode' => $runtime,
                'isContainer' => $runtime === 'container',
                'plan' => $plan,
                'sizes' => $this->sizes($cost, (int) ($settings['max_instances'] ?? 1)),
                'customQuote' => $customQuote,
                'quote' => $quote,
                'instanceCounts' => self::instanceCounts($settings['max_instances'] ?? 1),
                'settings' => $settings,
                'cacheMode' => $cacheMode,
                'cacheModes' => [
                    'off' => __('Off'),
                    'assets' => __('Assets'),
                    'standard' => __('Standard'),
                    'everything' => __('Everything'),
                ],
                'hostname' => $hostname,
                'connections' => $connections = EdgeContainerConnections::for($this->site),
                'connectionEstimates' => $this->connectionCostEstimates($connections),
                'servicePeers' => collect(EdgeContainerConnections::peerApps($this->site))->keyBy('id')->all(),
                'browserOn' => $hasCode && EdgeContainerConnections::browserEnabled($this->site),
                'browserDeployed' => $hasCode && is_string($this->site->edgeMeta()['active_deployment_id'] ?? null) && $this->site->edgeMeta()['active_deployment_id'] !== '',
                'browserHost' => EdgeContainerConnections::browserHost($this->site),
                'showBrowser' => $hasCode,
                'connectionKinds' => EdgeContainerConnections::KINDS,
                'cardOnFile' => $this->cardOnFile(),
                'paidFeatures' => EdgeContainerConnections::paidFeatures($this->site->organization),
                'valkeyAwakeSeconds' => $this->valkeyAwakeSeconds(),
                'allowedKinds' => $allowedKinds,
                'hasCode' => $hasCode,
                'isWorker' => in_array($runtime, ['ssr', 'hybrid'], true),
                'overriddenByRepo' => $hasCode ? $this->repoBindingNames() : [],
                'queueOwners' => $this->queueOwners($connections),
                'databaseEngine' => $databaseEngine,
                'databaseName' => (string) ($storedDatabase['name'] ?? 'production'),
                'databaseHost' => $databaseEngine === (string) ($storedDatabase['engine'] ?? '') ? (string) ($storedDatabase['host'] ?? '') : '',
                'postgresHour' => $postgres['hour'],
                'postgresGigabyte' => $postgres['gigabyte'],
                'postgresPlans' => EdgeAppDatabase::POSTGRES_PLANS,
                'postgresSizes' => $postgresSizes,
                'postgresPlan' => $postgresPlan,
                'postgresSize' => $postgresSize,
                'postgresSuspend' => $postgresSuspend,
                'postgresSleeps' => EdgeAppDatabase::POSTGRES_SLEEPS,
                'postgresAwakeHours' => $awakeHours,
                'dplyDatabases' => EdgeDplyDatabase::enabled(),
                'postgresDisks' => EdgeDplyDatabase::DISKS,
                'postgresDisk' => EdgeDplyDatabase::disk($this->draftPostgresDisk),
                'dplyDatabase' => $this->dplyDatabaseRecord(),
                'workersUnavailable' => EdgeQueueWorkers::unavailableReason($this->site),
                'workersConnection' => EdgeQueueWorkers::connection($this->site, (string) (EdgeQueueWorkers::normalize($this->workers)['connection'])),
                'workersMonthlyCents' => EdgeQueueWorkers::monthlyCents($this->site, EdgeQueueWorkers::draftInstances($this->workers)['min']),
                'workersMaxMonthlyCents' => EdgeQueueWorkers::monthlyCents($this->site, EdgeQueueWorkers::draftInstances($this->workers)['max']),
                'workerScaling' => array_values(array_map(fn (array $g): array => [
                    'label' => $g['key'] !== '' ? $g['key'] : __('main'),
                    'max' => $g['max_instances'],
                    'history' => ScaleEdgeQueueWorkersCommand::history($this->site, $g['key']),
                    'scaler' => Cache::get(ScaleEdgeQueueWorkersCommand::stateKey($this->site, $g['key'])),
                ], array_filter(EdgeQueueWorkers::groups($this->site), static fn (array $g): bool => $g['autoscale']))),
                'databaseUsage' => $this->dplyDatabaseRecord() !== null ? $this->databaseUsage() : null,
                'map' => EdgeServiceMap::for($this->site),
                'savedDatabase' => $this->persistedState()['database'],
                'needsRedeploy' => $this->needsRedeploy(),
            ],
        );
    }

    protected function currentEdgeSection(): ?string
    {
        return 'general';
    }

    /** Settings saved since the last deploy started, so the live app does not have them yet. */
    private function needsRedeploy(): bool
    {
        $saved = $this->site->edgeMeta()['settings_saved_at'] ?? null;
        if (! is_string($saved) || $saved === '') {
            return false;
        }
        $last = $this->site->edgeDeployments()->max('created_at');

        return $last === null || Carbon::parse($saved)->gt(Carbon::parse($last));
    }

    private function hydrateDrafts(): void
    {
        $state = $this->persistedState();
        $this->draftInstanceType = $state['instance_type'];
        $this->draftMaxInstances = $state['max_instances'];
        $this->draftCacheMode = $state['cache'];
        $this->draftDatabase = $state['database'];
        $this->databaseVisible = $state['database'] !== 'none';
        $this->draftPostgresPlan = $state['postgres_plan'];
        $this->draftPostgresSize = $state['postgres_size'];
        $this->draftPostgresSuspend = $state['postgres_suspend'];
        $this->draftPostgresDisk = $state['postgres_disk'];
        $this->draftPostgresPlan = $state['postgres_suspend'] === -1 ? 'awake' : 'sleep';
        $this->pending = false;
    }

    /**
     * Settings save as they change. The exception is switching the database
     * engine: that starts (and bills) a new database or drops the old one, so
     * it waits for the confirm button in the database sheet (saveSettings).
     */
    private function refreshPending(): void
    {
        $saved = $this->persistedState();
        $this->pending = $this->draftState() != $saved;
        if ($this->pending && $this->draftDatabase === $saved['database']) {
            $this->persistPending(true);
        }
    }

    /**
     * @return array{instance_type: string, max_instances: int, sleep_after: string, jurisdiction: string, scheduler: bool, migrate_on_boot: bool, custom_vcpu: int, custom_memory_gib: int, custom_disk_gb: int, cache: string, database: string}
     */
    private function persistedState(): array
    {
        $meta = $this->site->edgeMeta();
        $runtime = (string) ($meta['runtime_mode'] ?? 'static');
        $container = is_array($meta['container'] ?? null) ? $meta['container'] : [];
        $settings = $runtime === 'container' ? EdgeContainerSettings::for($this->site) : null;
        $cache = is_array($meta['cache'] ?? null) ? $meta['cache'] : [];
        $cacheMode = (string) ($cache['mode'] ?? 'off');
        $database = is_array($meta['database'] ?? null) ? $meta['database'] : [];
        $engine = (string) ($database['engine'] ?? '');

        return [
            'instance_type' => $settings['instance_type'] ?? 'basic',
            'max_instances' => (int) ($settings['max_instances'] ?? 1),
            'sleep_after' => $settings['sleep_after'] ?? '10m',
            'jurisdiction' => $settings['jurisdiction'] ?? '',
            'regions' => $settings['regions'] ?? [],
            'scheduler' => (bool) ($settings['scheduler'] ?? false),
            'sticky_sessions' => (bool) ($settings['sticky_sessions'] ?? true),
            'dedicated_jobs' => (bool) ($settings['dedicated_jobs'] ?? false),
            'workers' => EdgeQueueWorkers::for($this->site),
            'migrate_on_boot' => (bool) ($settings['migrate_on_boot'] ?? false),
            'custom_vcpu' => (int) ($container['custom_vcpu'] ?? 1),
            'custom_memory_gib' => (int) ($container['custom_memory_gib'] ?? 3),
            'custom_disk_gb' => (int) ($container['custom_disk_gb'] ?? 6),
            'rollout_mode' => $settings['rollout_mode'] ?? 'gradual',
            'rollout_steps' => implode(', ', $settings['rollout_step_percentage'] ?? []),
            'rollout_grace' => (int) ($settings['rollout_active_grace_period'] ?? 0),
            'cache' => in_array($cacheMode, ['off', 'assets', 'standard', 'everything'], true) ? $cacheMode : 'off',
            'database' => in_array($engine, EdgeAppDatabase::ENGINES, true) ? $engine : ($runtime === 'container' ? 'sql' : 'none'),
            'postgres_plan' => EdgeAppDatabase::postgresPlan((string) ($database['plan'] ?? '')),
            'postgres_size' => EdgeAppDatabase::postgresSize(in_array($engine, EdgeAppDatabase::DPLY_ENGINES, true) ? (string) ($database['size'] ?? '') : ''),
            'postgres_suspend' => EdgeAppDatabase::postgresSuspend((int) ($database['suspend'] ?? 0), (string) ($database['plan'] ?? '')),
            'postgres_disk' => EdgeDplyDatabase::disk((int) ($database['disk_gb'] ?? 0)),
        ];
    }

    /**
     * @return array{instance_type: string, max_instances: int, sleep_after: string, jurisdiction: string, scheduler: bool, migrate_on_boot: bool, custom_vcpu: int, custom_memory_gib: int, custom_disk_gb: int, cache: string, database: string}
     */
    private function draftState(): array
    {
        $saved = $this->persistedState();
        $custom = $this->draftInstanceType === 'custom' || $saved['instance_type'] === 'custom';

        return [
            'instance_type' => $this->draftInstanceType,
            'max_instances' => $this->draftMaxInstances,
            'sleep_after' => $this->sleepAfter,
            'jurisdiction' => $this->jurisdiction,
            'regions' => EdgeContainerSettings::normalizeRegions($this->regions, $this->jurisdiction),
            'scheduler' => $this->scheduler,
            'sticky_sessions' => $this->stickySessions,
            'dedicated_jobs' => $this->dedicatedJobs,
            'workers' => EdgeQueueWorkers::normalize($this->workers),
            'migrate_on_boot' => $this->migrateOnBoot,
            'custom_vcpu' => $custom ? $this->customVcpu : $saved['custom_vcpu'],
            'custom_memory_gib' => $custom ? $this->customMemoryGib : $saved['custom_memory_gib'],
            'custom_disk_gb' => $custom ? $this->customDiskGb : $saved['custom_disk_gb'],
            'rollout_mode' => $this->rolloutMode,
            'rollout_steps' => $this->rolloutSteps,
            'rollout_grace' => $this->rolloutGraceSeconds,
            'cache' => $this->draftCacheMode,
            'database' => $this->draftDatabase,
            'postgres_plan' => EdgeAppDatabase::postgresPlan($this->draftPostgresPlan),
            'postgres_size' => EdgeAppDatabase::postgresSize($this->draftPostgresSize),
            'postgres_suspend' => EdgeAppDatabase::postgresSuspend($this->draftPostgresSuspend, $this->draftPostgresPlan),
            'postgres_disk' => EdgeDplyDatabase::disk($this->draftPostgresDisk),
        ];
    }

    private function persistPending(bool $quiet): bool
    {
        $this->authorize('update', $this->site);
        $stepsError = EdgeContainerSettings::rolloutStepsError($this->rolloutSteps);
        if ($stepsError !== null) {
            $this->addError('rolloutSteps', $stepsError);

            return false;
        }
        if ($this->draftInstanceType === 'custom') {
            $error = EdgeContainerSettings::customError($this->customVcpu, $this->customMemoryGib, $this->customDiskGb);
            if ($error !== null) {
                $this->addError('custom', $error);

                return false;
            }
        }

        // Only the cache applies live (host map); everything else waits for a deploy.
        $draft = $this->draftState();
        $before = $this->persistedState();
        unset($draft['cache'], $before['cache']);
        if ($draft != $before) {
            $this->site->mergeEdgeMeta(['settings_saved_at' => now()->toIso8601String()]);
        }

        $meta = $this->site->edgeMeta();
        $runtime = (string) ($meta['runtime_mode'] ?? 'static');
        if ($runtime === 'container') {
            $current = is_array($meta['container'] ?? null) ? $meta['container'] : [];
            $current['instance_type'] = $this->draftInstanceType;
            $current['max_instances'] = $this->draftMaxInstances;
            $current['sleep_after'] = $this->sleepAfter;
            $current['jurisdiction'] = $this->jurisdiction;
            $current['regions'] = EdgeContainerSettings::normalizeRegions($this->regions, $this->jurisdiction);
            $current['scheduler'] = $this->scheduler;
            $current['sticky_sessions'] = $this->stickySessions;
            $current['dedicated_jobs'] = $this->dedicatedJobs;
            $current['workers'] = EdgeQueueWorkers::normalize($this->workers);
            $current['migrate_on_boot'] = $this->migrateOnBoot;
            $current['rollout_mode'] = $this->rolloutMode;
            $current['rollout_step_percentage'] = EdgeContainerSettings::parseRolloutSteps($this->rolloutSteps);
            $current['rollout_active_grace_period'] = max(0, min(EdgeContainerSettings::ROLLOUT_GRACE_MAX, $this->rolloutGraceSeconds));
            if ($this->draftInstanceType === 'custom') {
                $current['custom_vcpu'] = $this->customVcpu;
                $current['custom_memory_gib'] = $this->customMemoryGib;
                $current['custom_disk_gb'] = $this->customDiskGb;
            }
            $current['plan'] = '';
            foreach (EdgeContainerPlans::PLANS as $key => $plan) {
                if ($plan['instance_type'] === $this->draftInstanceType) {
                    $current['plan'] = $key;
                    break;
                }
            }
            $this->site->mergeEdgeMeta(['container' => $current]);
        }

        $cacheChanged = $this->draftCacheMode !== ($this->persistedState()['cache'] ?? 'off');
        if ($cacheChanged) {
            $current = is_array($this->site->edgeMeta()['cache'] ?? null) ? $this->site->edgeMeta()['cache'] : [];
            $restore = (string) ($current['restore_mode'] ?? '');
            $mode = $this->draftCacheMode;
            if ($mode !== 'off') {
                $restore = $mode;
            } elseif (! in_array($restore, ['assets', 'standard', 'everything'], true)) {
                $previous = (string) ($current['mode'] ?? 'assets');
                $restore = in_array($previous, ['assets', 'standard', 'everything'], true) ? $previous : 'assets';
            }
            $this->site->mergeEdgeMeta([
                'cache' => [
                    'mode' => $mode,
                    'restore_mode' => $restore,
                    'edge_ttl_seconds' => (int) ($current['edge_ttl_seconds'] ?? 86400),
                    'browser_ttl_seconds' => (int) ($current['browser_ttl_seconds'] ?? 86400),
                    'query_string' => ($current['query_string'] ?? 'ignore') === 'include' ? 'include' : 'ignore',
                ],
            ]);
        }

        $databaseError = EdgeAppDatabase::sync(
            $this->site,
            (string) ($this->persistedState()['database'] ?? 'sql'),
            $this->draftDatabase,
            $this->draftPostgresPlan,
            $this->draftPostgresSize,
            $this->draftPostgresSuspend,
            $this->draftPostgresDisk,
        );
        if ($databaseError !== null) {
            $this->addError('database', $databaseError);

            return false;
        }
        $this->site->save();
        if ($cacheChanged) {
            $this->republishEdgeHostMap();
        }
        $this->pending = false;
        if (! $quiet) {
            $database = is_array($this->site->edgeMeta()['database'] ?? null) ? $this->site->edgeMeta()['database'] : [];
            $message = ($database['status'] ?? '') === 'provisioning'
                ? __('MySQL is starting. Redeploy after the address is ready.')
                : __('Saved. Redeploy to apply these settings.');
            $this->toastSuccess($message);
        }

        return true;
    }

    /**
     * @return list<array{key: string, label: string, vcpu: string, memory: string, disk: string, price: string}>
     */
    /**
     * @return array{second: string, minute: string, hour: string, day: string, month: string, awakeMonth: string, saved: string}
     */
    private function runningQuote(EdgeContainerComputeCost $cost, float $vcpu, float $memoryGib, float $diskGb, int $instances): array
    {
        $perMinute = $cost->perMinuteMillicents($vcpu, $memoryGib, $diskGb) / 100_000 * $instances;
        $month = $perMinute * 60 * 730;
        $awake = max(0, min(24, $this->awakeHours));
        $used = $month * ($awake / 24);

        return [
            'second' => self::money($perMinute / 60),
            'minute' => self::money($perMinute),
            'hour' => self::money($perMinute * 60),
            'day' => self::money($perMinute * 60 * 24),
            'month' => self::money($month),
            'awakeMonth' => self::money($used),
            'saved' => self::money(max(0, $month - $used)),
        ];
    }

    private static function money(float $dollars): string
    {
        $places = match (true) {
            $dollars >= 1 => 2,
            $dollars >= 0.01 => 4,
            default => 6,
        };

        return '$'.number_format($dollars, $places);
    }

    private function sizes(EdgeContainerComputeCost $cost, int $instances): array
    {
        $instances = max(1, $instances);
        $sizes = [];
        foreach (EdgeContainerSettings::INSTANCE_TYPES as $key => [$vcpu, $memory, $disk]) {
            $perMinute = $cost->perMinuteMillicents((float) $vcpu, (float) $memory, (float) $disk) / 100_000;
            $perMonth = $perMinute * 60 * 730 * $instances;
            $sizes[] = [
                'key' => $key,
                'label' => EdgeSizeLadder::containerLabel($key),
                'vcpu' => self::vcpuLabel((float) $vcpu),
                'second' => UsagePrice::dollars($perMinute * 100_000 / 60),
                'memory' => $memory < 1 ? ((int) round($memory * 1024)).' MB' : $memory.' GiB',
                'disk' => $disk.' GB',
                'price' => $perMonth >= 10
                    ? '$'.number_format($perMonth, 0).'/mo'
                    : '$'.number_format($perMonth, 2).'/mo',
            ];
        }

        return $sizes;
    }

    /**
     * @return list<int>
     */
    private static function instanceCounts(int $current): array
    {
        $counts = self::INSTANCE_COUNTS;
        if (! in_array($current, $counts, true)) {
            $counts[] = $current;
            sort($counts);
        }

        return $counts;
    }

    private static function vcpuLabel(float $vcpu): string
    {
        return match (true) {
            abs($vcpu - (1 / 16)) < 0.001 => '1/16 vCPU',
            default => rtrim(rtrim(number_format($vcpu, 1, '.', ''), '0'), '.').' vCPU',
        };
    }
}
