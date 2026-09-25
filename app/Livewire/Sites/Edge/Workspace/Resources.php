<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Edge\Workspace;

use App\Livewire\Concerns\Edge\ManagesEdgeRedeploy;
use App\Livewire\Concerns\Edge\MountsEdgeWorkspaceSection;
use App\Livewire\Concerns\Edge\PublishesEdgeHostMap;
use App\Models\EdgeDataUsage;
use App\Models\EdgeDeliveryUsage;
use App\Models\EdgeDeployment;
use App\Models\EdgeKvUsage;
use App\Models\EdgeRedisUsage;
use App\Models\EdgeSiteEnvVar;
use App\Models\EdgeUsageSnapshot;
use App\Models\Server;
use App\Models\Site;
use App\Modules\Billing\Services\EdgeAppDatabaseCost;
use App\Modules\Billing\Services\EdgeContainerComputeCost;
use App\Modules\Billing\Services\EdgeDataUsageCost;
use App\Modules\Billing\Services\EdgeDeliveryCost;
use App\Modules\Billing\Services\EdgeKvCost;
use App\Modules\Billing\Services\EdgeRedisCost;
use App\Modules\Edge\Services\Containers\EdgeContainerDeployer;
use App\Modules\Edge\Services\EdgeAppDatabase;
use App\Modules\Edge\Services\EdgeQueueConsumers;
use App\Modules\Edge\Support\EdgeContainerConnections;
use App\Modules\Edge\Support\EdgeContainerPlans;
use App\Modules\Edge\Support\EdgeContainerSettings;
use App\Modules\Edge\Support\EdgeEffectiveBindings;
use App\Modules\Edge\Support\EdgeValkey;
use App\Modules\Providers\Cloudflare\EdgeCloudflareClient;
use App\Modules\Providers\Neon\NeonClient;
use App\Support\Http\PublicOutboundUrl;
use App\Support\Http\UnsafeOutboundUrlException;
use App\Support\Sites\EdgeSiteViewData;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Http;
use Livewire\Component;

/**
 * Post-create resource map. A new container app starts on Flex.
 * Size and attached resources are chosen here.
 */
class Resources extends Component
{
    use ManagesEdgeRedeploy;
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

    public string $draftMysqlSize = 'PS_10';

    public string $draftPostgresPlan = 'sleep';

    public string $draftPostgresSize = '0.25';

    public string $draftPostgresRegion = '';

    public int $draftPostgresSuspend = 300;

    public int $draftPostgresHistory = 86400;

    public string $connectionKind = '';

    public string $connectionMode = 'create';

    public string $queueStyle = '';

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
    public function saveValkey(string $host, string $class, int $sleep): void
    {
        $this->authorize('update', $this->site);
        $rows = EdgeContainerConnections::for($this->site);
        foreach ($rows as $index => $connection) {
            if ($connection['host'] !== $host || ! EdgeValkey::isTarget($connection['target']) || ! isset(EdgeValkey::CLASSES[$class])) {
                continue;
            }
            $url = (string) ($this->site->edgeEnvVars()->where('scope', 'production')->where('key', 'REDIS_URL')->first()?->value ?? '');
            try {
                EdgeValkey::update($connection['target'], $url, $class, $sleep);
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

    public string $deleteConnectionHost = '';

    public string $explainConnectionHost = '';

    public string $serviceDemoPath = '/';

    public string $serviceDemoStatus = '';

    public string $serviceDemoPreview = '';

    /** @var list<string> */
    public array $serviceDemoLog = [];

    public string $kvHost = '';

    public string $imagesHost = '';

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

    /** @var list<array{id: string, label: string}> */
    public array $connectionOptions = [];

    public function mount(Server $server, Site $site): void
    {
        $this->mountEdgeWorkspaceSection($server, $site);
        EdgeContainerConnections::prefixBareHosts($site);
        $this->site->refresh();
        if (($site->edgeMeta()['runtime_mode'] ?? '') === 'container') {
            $settings = EdgeContainerSettings::for($site);
            $this->sleepAfter = $settings['sleep_after'];
            $this->jurisdiction = $settings['jurisdiction'];
            $this->regions = $settings['regions'];
            $this->scheduler = $settings['scheduler'];
            $this->stickySessions = $settings['sticky_sessions'];
            $this->dedicatedJobs = $settings['dedicated_jobs'];
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

        if (in_array($name, ['draftInstanceType', 'sleepAfter', 'jurisdiction', 'scheduler', 'stickySessions', 'dedicatedJobs', 'migrateOnBoot', 'customVcpu', 'customMemoryGib', 'customDiskGb', 'rolloutMode', 'rolloutSteps', 'rolloutGraceSeconds', 'draftMysqlSize'], true) || str_starts_with($name, 'regions')) {
            $this->refreshPending();
        }
    }

    public function openPanel(string $panel): void
    {
        if ($panel !== '' && ! in_array($panel, ['sleep', 'cache', 'databases', 'connection', 'delete-connection', 'browser', 'estimate'], true)) {
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

    public function saveRuntime(): void
    {
        $this->authorize('update', $this->site);
        if (($this->site->edgeMeta()['runtime_mode'] ?? '') !== 'container') {
            return;
        }

        $this->validate([
            'sleepAfter' => ['required', 'in:'.implode(',', EdgeContainerSettings::SLEEP_AFTER)],
            'jurisdiction' => ['in:'.implode(',', EdgeContainerSettings::JURISDICTIONS)],
            'rolloutMode' => ['required', 'in:'.implode(',', EdgeContainerSettings::ROLLOUT_MODES)],
            'rolloutGraceSeconds' => ['integer', 'between:0,'.EdgeContainerSettings::ROLLOUT_GRACE_MAX],
        ]);
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
                $client->putKvValue($namespace, $key, $this->kvDemoValue);
                $this->kvDemoLog[] = __('Saved :key.', ['key' => $key]);
                $this->kvDemoPreview = $this->kvDemoValue;
            } elseif ($action === 'read') {
                $value = $client->getKvValue($namespace, $key);
                $this->kvDemoLog[] = $value === null ? __('No value for :key.', ['key' => $key]) : __('Read :key.', ['key' => $key]);
                $this->kvDemoPreview = $value ?? '';
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
            $this->kvReads = $this->kvWrites = $this->kvDeletes = $this->kvLists = $this->kvStorageBytes = $this->kvMonthCents = 0;

            return;
        }

        $this->kvName = EdgeContainerConnections::resourceLabel($connection['host']);
        $this->loadKvUsage($connection['target']);

        try {
            $this->kvKeys = EdgeCloudflareClient::fromConfig()->listKvKeys($connection['target']);
        } catch (\Throwable) {
            $this->kvKeys = [];
            $this->addError('kvSettings', __('The store did not answer.'));
        }
    }

    public function openKv(string $host): void
    {
        $this->authorize('update', $this->site);
        $this->kvHost = $host;
        $this->kvDemoPreview = '';
        $this->kvDemoLog = [];
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
        $this->objectPreview = '';
        $this->objectLog = [];
        $this->resetErrorBag('object');
        $this->refreshObjectList();
    }

    public function refreshObjectList(): void
    {
        $this->authorize('update', $this->site);
        $connection = $this->objectConnection();
        if ($connection === null) {
            $this->objectList = [];

            return;
        }

        try {
            $this->objectList = EdgeCloudflareClient::fromConfig()->listR2Objects($connection['target']);
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
            $this->objectList = $client->listR2Objects($bucket);
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
        if (app()->isLocal() && config('edge.skip_card_check')) {
            return true;
        }

        return (bool) $this->site->organization?->onAnyPaidPlan();
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

    public function chooseConnectionKind(string $kind): void
    {
        if (! isset(EdgeContainerConnections::KINDS[$kind]) || ! in_array($kind, $this->allowedKinds(), true)) {
            return;
        }
        $this->connectionKind = $kind;
        $this->queueStyle = '';
        $this->connectionMode = in_array($kind, EdgeContainerConnections::CREATABLE, true) || $kind === 'redis' || $kind === 'http_delivery' ? 'create' : 'attach';
        $this->reset('connectionLabel', 'connectionPick', 'connectionOptions');
        $this->resetErrorBag('connection');
        if (in_array($kind, EdgeContainerConnections::ENABLE, true)) {
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
            $this->connectionOptions = EdgeContainerConnections::catalog($kind);
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
        $this->connectionOptions = $mode === 'attach' ? EdgeContainerConnections::catalog($this->connectionKind) : [];
    }

    public function saveConnection(): void
    {
        $this->authorize('update', $this->site);
        $kind = $this->connectionKind;
        if (! isset(EdgeContainerConnections::KINDS[$kind]) || in_array($kind, EdgeContainerConnections::ENABLE, true) || ! in_array($kind, $this->allowedKinds(), true)) {
            return;
        }

        if ($this->connectionMode === 'attach' && in_array($kind, EdgeContainerConnections::CREATABLE, true)) {
            $match = collect($this->connectionOptions)->firstWhere('id', $this->connectionPick);
            if (! is_array($match)) {
                $this->addError('connection', 'Pick a resource to attach.');

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
            if ($kind === 'key_value' && ! $this->cardOnFile()) {
                $this->addError('connection', __('Add a card before starting a key-value store. Reads, writes, and storage are billed to that card.'));

                return;
            }
            try {
                $target = EdgeContainerConnections::provision($kind, $identity['resource']);
            } catch (\Throwable $e) {
                $this->addError('connection', $e->getMessage());

                return;
            }
            if ($target === '') {
                $this->addError('connection', 'The resource was created but no id came back.');

                return;
            }
        } elseif ($kind === 'http_delivery') {
            if (! $this->cardOnFile()) {
                $this->addError('connection', __('Add a card before starting HTTP delivery. Messages are billed to that card.'));

                return;
            }
            try {
                $target = EdgeContainerConnections::provisionHttpDelivery();
            } catch (\Throwable $e) {
                $this->addError('connection', $e->getMessage());

                return;
            }
            $this->storeConnection($identity['name'], $identity['host'], $target);

            return;
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
                    EdgeContainerConnections::destroy('redis', $started['id']);
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
        $this->site->mergeEdgeMeta(['connections' => $rows]);
        $this->site->save();
        $kind = collect($rows)->firstWhere('host', $host)['kind'] ?? '';
        if ($kind === 'redis') {
            $this->toastSuccess($asleep
                ? __('Asleep. The app stops receiving the Redis address on the next deploy. The database stays.')
                : __('Awake. The app gets the Redis address on the next deploy.'));

            return;
        }
        if ($kind === 'key_value') {
            $this->toastSuccess($asleep
                ? __('Asleep. The app loses this address on the next deploy, so it cannot read or write. This store is not billed until you wake it.')
                : __('Awake. The app gets the address on the next deploy, and this month’s usage is billed again.'));

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
        try {
            EdgeContainerConnections::destroy($target['kind'], $target['target']);
        } catch (\Throwable $e) {
            $this->addError('connectionDelete', $e->getMessage());

            return;
        }
        if ($target['kind'] === 'redis') {
            $this->forgetRedisUrl();
        }
        $this->site->mergeEdgeMeta(['connections' => $kept]);
        $this->site->save();
        $this->deleteConnectionHost = '';
        $this->panel = '';
        $this->dispatch('close-modal', 'resources-delete-connection');
        $this->toastSuccess(__('Deleted.'));
    }

    public function removeConnection(string $host): void
    {
        $this->authorize('update', $this->site);
        $rows = EdgeContainerConnections::for($this->site);
        foreach ($rows as $connection) {
            if ($connection['host'] === $host && $connection['kind'] === 'redis') {
                EdgeContainerConnections::destroy('redis', $connection['target']);
                $this->forgetRedisUrl();
            }
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
        if (! in_array($engine, EdgeAppDatabase::ENGINES, true) || $engine === 'mysql') {
            return;
        }
        if ($engine === 'postgres' && ! $this->cardOnFile()) {
            return;
        }

        $this->draftDatabase = $engine;
        $this->databaseVisible = $engine !== 'none';
        $this->refreshPending();
        if ($engine !== 'none') {
            $this->dispatch('database-tab', 'settings');
            $this->dispatch('open-modal', 'resources-app-database');
        }
    }

    public function selectMysqlSize(string $size): void
    {
        $this->authorize('update', $this->site);
        if (! array_key_exists($size, EdgeAppDatabase::MYSQL_SIZES)) {
            return;
        }

        $this->draftMysqlSize = $size;
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

        $this->draftPostgresSize = $size;
        $this->refreshPending();
    }

    public function selectPostgresRegion(string $region): void
    {
        $this->authorize('update', $this->site);
        if (! $this->cardOnFile()) {
            return;
        }
        if (! array_key_exists($region, NeonClient::REGIONS)) {
            return;
        }
        $database = is_array($this->site->edgeMeta()['database'] ?? null) ? $this->site->edgeMeta()['database'] : [];
        if ($this->draftDatabase === 'postgres' && (string) ($database['engine'] ?? '') === 'postgres' && (string) ($database['remote_id'] ?? '') !== '') {
            return;
        }

        $this->draftPostgresRegion = $region;
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

    public function selectPostgresHistory(int $seconds): void
    {
        $this->authorize('update', $this->site);
        if (! $this->cardOnFile()) {
            return;
        }
        if (! array_key_exists($seconds, EdgeAppDatabase::POSTGRES_HISTORY)) {
            return;
        }

        $this->draftPostgresHistory = $seconds;
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
    private function connectionCostEstimates(array $connections): array
    {
        $from = now()->startOfMonth()->toDateString();
        $to = now()->endOfMonth()->toDateString();
        $estimates = [];
        $namespaces = [];
        $hasDelivery = false;
        $hasSql = false;
        $hasQueue = false;
        $hasObjects = false;

        foreach ($connections as $connection) {
            if ($connection['kind'] === 'key_value' && $connection['target'] !== '') {
                $namespaces[] = $connection['target'];
            }
            $hasDelivery = $hasDelivery || $connection['kind'] === 'http_delivery';
            $hasSql = $hasSql || $connection['kind'] === 'sql';
            $hasQueue = $hasQueue || $connection['kind'] === 'queue';
            $hasObjects = $hasObjects || $connection['kind'] === 'object_storage';
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
            if ($connection['asleep']) {
                $estimates[$connection['host']] = 0;

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

        $valkeySeconds = (int) EdgeRedisUsage::query()->where('site_id', $this->site->id)->whereBetween('date', [$from, $to])->sum('awake_seconds');
        foreach ($connections as $connection) {
            if ($connection['kind'] === 'redis' && EdgeValkey::isTarget($connection['target']) && $this->site->organization !== null) {
                $estimates[$connection['host']] = app(EdgeRedisCost::class)->valkeyCents($this->site->organization, [$this->site->id => $valkeySeconds]);
            }
        }

        if ($hasDelivery) {
            $delivery = EdgeDeliveryUsage::query()
                ->where('site_id', $this->site->id)
                ->whereBetween('date', [$from, $to])
                ->selectRaw('COALESCE(SUM(messages), 0) as messages, COALESCE(SUM(bandwidth_bytes), 0) as bandwidth')
                ->first();
            $deliveryCents = app(EdgeDeliveryCost::class)->cents((int) $delivery->messages, (int) $delivery->bandwidth);
            foreach ($connections as $connection) {
                if ($connection['kind'] === 'http_delivery') {
                    $estimates[$connection['host']] = $deliveryCents;
                }
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

        if ($hasObjects) {
            $objects = $this->objectStorageEstimateCents($from, $to);
            foreach ($connections as $connection) {
                if ($connection['kind'] === 'object_storage') {
                    $estimates[$connection['host']] = $objects;
                }
            }
        }

        return $estimates;
    }

    private function objectStorageEstimateCents(string $from, string $to): int
    {
        $row = EdgeUsageSnapshot::query()
            ->where('site_id', $this->site->id)
            ->whereDate('period_start', '>=', $from)
            ->whereDate('period_start', '<=', $to)
            ->selectRaw('COALESCE(MAX(r2_storage_bytes), 0) as storage, COALESCE(SUM(r2_class_a_ops), 0) as class_a, COALESCE(SUM(r2_class_b_ops), 0) as class_b')
            ->first();
        $rate = static fn (string $key): int => max(0, (int) config('dply.edge.usage_billing.'.$key, 0));
        $storage = max(0, (int) $row->storage - $rate('included_r2_storage_gb_per_site') * 1024 ** 3);
        $classA = max(0, (int) $row->class_a - $rate('included_r2_class_a_ops_per_site'));
        $classB = max(0, (int) $row->class_b - $rate('included_r2_class_b_ops_per_site'));
        $cents = (int) ceil($storage / 1024 ** 3 * $rate('r2_storage_cents_per_gb_month'))
            + (int) ceil($classA / 1_000_000 * $rate('r2_class_a_cents_per_million'))
            + (int) ceil($classB / 1_000_000 * $rate('r2_class_b_cents_per_million'));

        return (int) ceil($cents * (100 + $rate('markup_percent')) / 100);
    }

    public function render(EdgeContainerComputeCost $cost): View
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
        $postgresStored = $databaseCost->stored($this->site);
        $postgresSizes = [];
        foreach (EdgeAppDatabase::POSTGRES_SIZES as $key => $size) {
            $size['hour'] = $databaseCost->hourly($size['cu']);
            $size['day'] = $databaseCost->daily($size['cu']);
            $size['month'] = $databaseCost->monthly($size['cu']);
            $postgresSizes[$key] = $size;
        }
        $postgresSuspend = EdgeAppDatabase::postgresSuspend($this->draftPostgresSuspend, $this->draftPostgresPlan);
        $postgresPlan = $postgresSuspend === -1 ? 'awake' : 'sleep';
        $postgresSize = EdgeAppDatabase::postgresSize($this->draftPostgresSize);
        $postgresRegion = EdgeAppDatabase::postgresRegion($this->draftPostgresRegion);
        $postgresHistory = EdgeAppDatabase::postgresHistory($this->draftPostgresHistory);
        $awakeHours = max(0, min(24, $this->awakeHours));
        foreach ($postgresSizes as $key => $size) {
            $hours = $postgresSuspend === -1 ? 24 : $awakeHours;
            $postgresSizes[$key]['day'] = number_format((float) $size['hour'] * $hours, 2);
            $postgresSizes[$key]['month'] = number_format((float) $size['hour'] * ($postgresSuspend === -1 ? 720 : $awakeHours * 30), 2);
        }

        return view('livewire.sites.edge.workspace.resources', array_merge(
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
                'allowedKinds' => $allowedKinds,
                'hasCode' => $hasCode,
                'isWorker' => in_array($runtime, ['ssr', 'hybrid'], true),
                'overriddenByRepo' => $hasCode ? $this->repoBindingNames() : [],
                'queueOwners' => $this->queueOwners($connections),
                'databaseEngine' => $databaseEngine,
                'databaseName' => (string) ($storedDatabase['name'] ?? 'production'),
                'databaseHost' => $databaseEngine === (string) ($storedDatabase['engine'] ?? '') ? (string) ($storedDatabase['host'] ?? '') : '',
                'databaseStatus' => $databaseEngine === (string) ($storedDatabase['engine'] ?? '') ? (string) ($storedDatabase['status'] ?? '') : '',
                'mysqlMonthly' => number_format(EdgeAppDatabase::mysqlCents($this->draftMysqlSize) / 100, 2),
                'mysqlSizes' => EdgeAppDatabase::MYSQL_SIZES,
                'mysqlSize' => EdgeAppDatabase::mysqlSize($this->draftMysqlSize),
                'postgresHour' => $postgres['hour'],
                'postgresGigabyte' => $postgres['gigabyte'],
                'postgresHistoryRate' => $postgres['history'],
                'postgresStored' => $postgresStored,
                'postgresPlans' => EdgeAppDatabase::POSTGRES_PLANS,
                'postgresSizes' => $postgresSizes,
                'postgresPlan' => $postgresPlan,
                'postgresSize' => $postgresSize,
                'postgresRegion' => $postgresRegion,
                'postgresSuspend' => $postgresSuspend,
                'postgresSleeps' => EdgeAppDatabase::POSTGRES_SLEEPS,
                'postgresHistory' => $postgresHistory,
                'postgresHistories' => EdgeAppDatabase::POSTGRES_HISTORY,
                'postgresAwakeHours' => $awakeHours,
                'postgresRegions' => NeonClient::REGIONS,
                'postgresRegionLocked' => $databaseEngine === 'postgres'
                    && (string) ($storedDatabase['engine'] ?? '') === 'postgres'
                    && (string) ($storedDatabase['remote_id'] ?? '') !== '',
                'deployments' => $this->site->edgeDeployments()->orderByDesc('created_at')->limit(5)->get(),
            ],
        ));
    }

    protected function currentEdgeSection(): ?string
    {
        return 'resources';
    }

    private function hydrateDrafts(): void
    {
        $state = $this->persistedState();
        $this->draftInstanceType = $state['instance_type'];
        $this->draftMaxInstances = $state['max_instances'];
        $this->draftCacheMode = $state['cache'];
        $this->draftDatabase = $state['database'];
        $this->databaseVisible = $state['database'] !== 'none';
        $this->draftMysqlSize = $state['mysql_size'];
        $this->draftPostgresPlan = $state['postgres_plan'];
        $this->draftPostgresSize = $state['postgres_size'];
        $this->draftPostgresRegion = $state['postgres_region'];
        $this->draftPostgresSuspend = $state['postgres_suspend'];
        $this->draftPostgresHistory = $state['postgres_history'];
        $this->draftPostgresPlan = $state['postgres_suspend'] === -1 ? 'awake' : 'sleep';
        $this->pending = false;
    }

    private function refreshPending(): void
    {
        $this->pending = $this->draftState() != $this->persistedState();
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
            'migrate_on_boot' => (bool) ($settings['migrate_on_boot'] ?? false),
            'custom_vcpu' => (int) ($container['custom_vcpu'] ?? 1),
            'custom_memory_gib' => (int) ($container['custom_memory_gib'] ?? 3),
            'custom_disk_gb' => (int) ($container['custom_disk_gb'] ?? 6),
            'rollout_mode' => $settings['rollout_mode'] ?? 'gradual',
            'rollout_steps' => implode(', ', $settings['rollout_step_percentage'] ?? []),
            'rollout_grace' => (int) ($settings['rollout_active_grace_period'] ?? 0),
            'cache' => in_array($cacheMode, ['off', 'assets', 'standard', 'everything'], true) ? $cacheMode : 'off',
            'database' => in_array($engine, EdgeAppDatabase::ENGINES, true) ? $engine : ($runtime === 'container' ? 'sql' : 'none'),
            'mysql_size' => EdgeAppDatabase::mysqlSize((string) ($database['size'] ?? '')),
            'postgres_plan' => EdgeAppDatabase::postgresPlan((string) ($database['plan'] ?? '')),
            'postgres_size' => EdgeAppDatabase::postgresSize($engine === 'postgres' ? (string) ($database['size'] ?? '') : ''),
            'postgres_region' => EdgeAppDatabase::postgresRegion((string) ($database['region'] ?? '')),
            'postgres_suspend' => EdgeAppDatabase::postgresSuspend((int) ($database['suspend'] ?? 0), (string) ($database['plan'] ?? '')),
            'postgres_history' => EdgeAppDatabase::postgresHistory((int) ($database['history'] ?? 0)),
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
            'migrate_on_boot' => $this->migrateOnBoot,
            'custom_vcpu' => $custom ? $this->customVcpu : $saved['custom_vcpu'],
            'custom_memory_gib' => $custom ? $this->customMemoryGib : $saved['custom_memory_gib'],
            'custom_disk_gb' => $custom ? $this->customDiskGb : $saved['custom_disk_gb'],
            'rollout_mode' => $this->rolloutMode,
            'rollout_steps' => $this->rolloutSteps,
            'rollout_grace' => $this->rolloutGraceSeconds,
            'cache' => $this->draftCacheMode,
            'database' => $this->draftDatabase,
            'mysql_size' => EdgeAppDatabase::mysqlSize($this->draftMysqlSize),
            'postgres_plan' => EdgeAppDatabase::postgresPlan($this->draftPostgresPlan),
            'postgres_size' => EdgeAppDatabase::postgresSize($this->draftPostgresSize),
            'postgres_region' => EdgeAppDatabase::postgresRegion($this->draftPostgresRegion),
            'postgres_suspend' => EdgeAppDatabase::postgresSuspend($this->draftPostgresSuspend, $this->draftPostgresPlan),
            'postgres_history' => EdgeAppDatabase::postgresHistory($this->draftPostgresHistory),
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
            $this->draftMysqlSize,
            $this->draftPostgresPlan,
            $this->draftPostgresSize,
            $this->draftPostgresRegion,
            $this->draftPostgresSuspend,
            $this->draftPostgresHistory,
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
        $labels = [
            'lite' => 'Lite',
            'basic' => 'Flex',
            'standard-1' => 'Small',
            'standard-2' => 'Medium',
            'standard-3' => 'Large',
            'standard-4' => 'XL',
        ];
        $instances = max(1, $instances);
        $sizes = [];
        foreach (EdgeContainerSettings::INSTANCE_TYPES as $key => [$vcpu, $memory, $disk]) {
            $perMinute = $cost->perMinuteMillicents((float) $vcpu, (float) $memory, (float) $disk) / 100_000;
            $perMonth = $perMinute * 60 * 730 * $instances;
            $sizes[] = [
                'key' => $key,
                'label' => $labels[$key] ?? $key,
                'vcpu' => self::vcpuLabel((float) $vcpu),
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
            abs($vcpu - 0.25) < 0.001 => '1/4 vCPU',
            abs($vcpu - 0.5) < 0.001 => '1/2 vCPU',
            default => rtrim(rtrim(number_format($vcpu, 1, '.', ''), '0'), '.').' vCPU',
        };
    }
}
