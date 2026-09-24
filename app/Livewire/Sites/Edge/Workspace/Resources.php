<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Edge\Workspace;

use App\Livewire\Concerns\Edge\ManagesEdgeRedeploy;
use App\Livewire\Concerns\Edge\MountsEdgeWorkspaceSection;
use App\Livewire\Concerns\Edge\PublishesEdgeHostMap;
use App\Models\EdgeSiteEnvVar;
use App\Models\Server;
use App\Models\Site;
use App\Modules\Billing\Services\EdgeContainerComputeCost;
use App\Modules\Edge\Services\EdgeRedisUsageCollector;
use App\Modules\Edge\Support\EdgeContainerConnections;
use App\Modules\Edge\Support\EdgeContainerPlans;
use App\Modules\Edge\Support\EdgeContainerSettings;
use App\Modules\Providers\Cloudflare\EdgeCloudflareClient;
use App\Modules\Providers\Upstash\UpstashRedisClient;
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

    public bool $dedicatedJobs = true;

    public bool $migrateOnBoot = false;

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

    public string $connectionKind = '';

    public string $connectionMode = 'create';

    public string $connectionLabel = '';

    public string $connectionPick = '';

    public string $redisRegion = 'us-east-1';

    public string $redisHost = '';

    public string $redisUser = '';

    public string $redisPassword = '';

    public bool $redisShowPassword = false;

    public bool $redisResetArmed = false;

    public string $redisName = '';

    public bool $redisManaged = false;

    public bool $redisEviction = false;

    public bool $redisTls = true;

    public bool $redisAutoUpgrade = false;

    public bool $redisDailyBackup = false;

    public int $redisBudget = 0;

    public string $redisState = '';

    public string $redisRegionLabel = '';

    /** @var array<string, string> */
    public array $redisStats = [];

    public string $deleteConnectionHost = '';

    public string $explainConnectionHost = '';

    public string $serviceDemoPath = '/';

    public string $serviceDemoStatus = '';

    public string $serviceDemoPreview = '';

    /** @var list<string> */
    public array $serviceDemoLog = [];

    public string $kvHost = '';

    public string $stateHost = '';

    public string $kvDemoKey = 'hello';

    public string $kvDemoValue = '';

    public string $kvDemoPreview = '';

    /** @var list<string> */
    public array $kvDemoLog = [];

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

        if (in_array($name, ['draftInstanceType', 'sleepAfter', 'jurisdiction', 'scheduler', 'stickySessions', 'dedicatedJobs', 'migrateOnBoot', 'customVcpu', 'customMemoryGib', 'customDiskGb', 'rolloutMode', 'rolloutSteps', 'rolloutGraceSeconds'], true) || str_starts_with($name, 'regions')) {
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
        if ((string) ($this->site->edgeMeta()['runtime_mode'] ?? '') !== 'container') {
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

    public function chooseConnectionKind(string $kind): void
    {
        if (! isset(EdgeContainerConnections::KINDS[$kind])) {
            return;
        }
        $this->connectionKind = $kind;
        $this->connectionMode = in_array($kind, EdgeContainerConnections::CREATABLE, true) || $kind === 'redis' ? 'create' : 'attach';
        $this->reset('connectionLabel', 'connectionPick', 'connectionOptions');
        $this->resetErrorBag('connection');
        if (in_array($kind, EdgeContainerConnections::ENABLE, true)) {
            $this->storeConnection(strtoupper($kind), $kind.'.internal', '');

            return;
        }
        if ($kind === 'redis') {
            $configured = (string) config('edge.upstash.region', 'us-east-1');
            $this->redisRegion = isset(EdgeContainerConnections::redisRegions()[$configured]) ? $configured : 'us-east-1';
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
        if (! isset(EdgeContainerConnections::KINDS[$kind]) || in_array($kind, EdgeContainerConnections::ENABLE, true)) {
            return;
        }

        if ($this->connectionMode === 'attach' && in_array($kind, EdgeContainerConnections::CREATABLE, true)) {
            $match = collect($this->connectionOptions)->firstWhere('id', $this->connectionPick);
            if (! is_array($match)) {
                $this->addError('connection', 'Pick a resource to attach.');

                return;
            }
            $identity = EdgeContainerConnections::identity((string) $match['label']);
            if ($identity === null) {
                $this->addError('connection', 'That resource needs a name that can become a host.');

                return;
            }
            $this->storeConnection($identity['name'], $identity['host'], (string) $match['id']);

            return;
        }

        $identity = EdgeContainerConnections::identity($this->connectionLabel);
        if ($identity === null) {
            $this->addError('connection', 'Name the resource. Letters and numbers only, starting with a letter.');

            return;
        }

        $target = $identity['resource'];
        if (in_array($kind, EdgeContainerConnections::CREATABLE, true)) {
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
        } elseif ($kind === 'redis') {
            foreach (EdgeContainerConnections::for($this->site) as $connection) {
                if ($connection['kind'] === 'redis') {
                    $this->addError('connection', 'This app already has Redis. Detach it before connecting another address.');

                    return;
                }
            }
            if ($this->connectionMode === 'create') {
                try {
                    $started = EdgeContainerConnections::provisionRedis($this->site, $identity['resource'], $this->redisRegion);
                } catch (\Throwable $e) {
                    $this->addError('connection', $e->getMessage());

                    return;
                }
                $this->writeRedisEnv($started['url']);
                $this->storeConnection($identity['name'], $identity['host'], $started['id']);
                if ($this->getErrorBag()->has('connection')) {
                    $this->forgetRedisUrl();
                    EdgeContainerConnections::destroy('redis', $started['id']);
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

    public function openRedis(string $host): void
    {
        $this->authorize('update', $this->site);
        $this->resetErrorBag('redisSettings');
        $this->redisResetArmed = false;
        $this->redisShowPassword = false;
        $this->redisStats = [];
        $connection = collect(EdgeContainerConnections::for($this->site))->first(fn (array $row): bool => $row['host'] === $host && $row['kind'] === 'redis');
        if (! is_array($connection)) {
            return;
        }
        $this->redisHost = $host;
        $this->redisManaged = EdgeRedisUsageCollector::isProvisionedId((string) $connection['target']);
        $url = (string) ($this->site->edgeEnvVars()->where('scope', EdgeSiteEnvVar::SCOPE_PRODUCTION)->where('key', 'REDIS_URL')->first()?->value ?? '');
        $creds = $this->splitRedisUrl($url);
        $this->redisUser = $creds['user'];
        $this->redisPassword = $creds['password'];
        $this->redisName = '';
        $this->redisState = '';
        $this->redisRegionLabel = '';
        if (! $this->redisManaged) {
            return;
        }
        try {
            $client = UpstashRedisClient::fromConfig();
            $database = $client->database((string) $connection['target']);
            $this->applyRedisDatabase($database, $url);
            $this->redisStats = $this->redisStatLines($client->stats((string) $connection['target']));
        } catch (\Throwable) {
            $this->addError('redisSettings', __('Redis did not answer.'));
        }
    }

    public function closeRedis(): void
    {
        $this->redisHost = '';
        $this->redisPassword = '';
        $this->redisShowPassword = false;
        $this->redisResetArmed = false;
        $this->redisStats = [];
    }

    public function saveRedisSettings(): void
    {
        $this->authorize('update', $this->site);
        $id = $this->managedRedisId();
        if ($id === null) {
            return;
        }
        $name = trim($this->redisName);
        if ($name !== '' && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $name) !== 1) {
            $this->addError('redisSettings', __('Use letters, numbers, dashes, or underscores.'));

            return;
        }
        if ($this->redisBudget < 0 || $this->redisBudget > 10000) {
            $this->addError('redisSettings', __('Monthly budget must be between 0 and 10000.'));

            return;
        }
        try {
            $client = UpstashRedisClient::fromConfig();
            $current = $client->database($id);
            if (! $this->redisTls && (bool) ($current['tls'] ?? false)) {
                $this->redisTls = true;
                $this->addError('redisSettings', __('TLS stays on once it is enabled.'));

                return;
            }
            if ($name !== '' && $name !== (string) ($current['database_name'] ?? '')) {
                $client->rename($id, $name);
            }
            if ((bool) ($current['eviction'] ?? false) !== $this->redisEviction) {
                $client->setEviction($id, $this->redisEviction);
            }
            if ((bool) ($current['auto_upgrade'] ?? false) !== $this->redisAutoUpgrade) {
                $client->setAutoUpgrade($id, $this->redisAutoUpgrade);
            }
            if ((bool) ($current['daily_backup_enabled'] ?? false) !== $this->redisDailyBackup) {
                $client->setDailyBackup($id, $this->redisDailyBackup);
            }
            if ((int) ($current['budget'] ?? 0) !== $this->redisBudget) {
                $client->updateBudget($id, $this->redisBudget);
            }
            if ($this->redisTls && ! (bool) ($current['tls'] ?? false)) {
                $client->enableTls($id);
            }
            $this->toastSuccess(__('Saved.'));
            $this->openRedis($this->redisHost);
        } catch (\Throwable) {
            $this->addError('redisSettings', __('Redis did not answer.'));
        }
    }

    public function resetRedisPassword(): void
    {
        $this->authorize('update', $this->site);
        if (! $this->redisResetArmed) {
            $this->redisResetArmed = true;

            return;
        }
        $id = $this->managedRedisId();
        if ($id === null) {
            return;
        }
        try {
            $database = UpstashRedisClient::fromConfig()->resetPassword($id);
            $password = (string) ($database['password'] ?? '');
            if ($password === '') {
                $this->addError('redisSettings', __('Redis did not answer.'));

                return;
            }
            $url = (string) ($this->site->edgeEnvVars()->where('scope', EdgeSiteEnvVar::SCOPE_PRODUCTION)->where('key', 'REDIS_URL')->first()?->value ?? '');
            $parts = parse_url($url) ?: [];
            $user = rawurlencode($this->redisUser !== '' ? $this->redisUser : 'default');
            $host = (string) ($parts['host'] ?? '');
            $port = (int) ($parts['port'] ?? ($database['port'] ?? 6379));
            $scheme = ((bool) ($database['tls'] ?? true)) ? 'rediss' : 'redis';
            if ($host !== '') {
                $this->writeRedisEnv(sprintf('%s://%s:%s@%s:%d', $scheme, $user, rawurlencode($password), $host, $port > 0 ? $port : 6379));
            }
            $this->redisPassword = $password;
            $this->redisShowPassword = true;
            $this->redisResetArmed = false;
            $this->toastSuccess(__('Password reset. The app gets it on the next deploy.'));
        } catch (\Throwable) {
            $this->addError('redisSettings', __('Redis did not answer.'));
        }
    }

    private function writeRedisEnv(string $url): void
    {
        $creds = $this->splitRedisUrl($url);
        $this->storeEnv('REDIS_URL', $url);
        $this->storeEnv('REDIS_USERNAME', $creds['user']);
        $this->storeEnv('REDIS_PASSWORD', $creds['password']);
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

    private function managedRedisId(): ?string
    {
        if (! $this->redisManaged || $this->redisHost === '') {
            return null;
        }
        $connection = collect(EdgeContainerConnections::for($this->site))->first(fn (array $row): bool => $row['host'] === $this->redisHost && $row['kind'] === 'redis');
        $id = is_array($connection) ? (string) $connection['target'] : '';

        return EdgeRedisUsageCollector::isProvisionedId($id) ? $id : null;
    }

    /**
     * @param  array<string, mixed>  $database
     */
    private function applyRedisDatabase(array $database, string $url): void
    {
        $password = (string) ($database['password'] ?? '');
        if ($password !== '') {
            $this->redisPassword = $password;
        }
        $user = (string) ($database['user_name'] ?? '');
        if ($user !== '') {
            $this->redisUser = $user;
        } elseif ($this->redisUser === '') {
            $this->redisUser = 'default';
        }
        $this->redisName = (string) ($database['database_name'] ?? '');
        $this->redisEviction = (bool) ($database['eviction'] ?? false);
        $this->redisTls = (bool) ($database['tls'] ?? str_starts_with($url, 'rediss://'));
        $this->redisAutoUpgrade = (bool) ($database['auto_upgrade'] ?? false);
        $this->redisDailyBackup = (bool) ($database['daily_backup_enabled'] ?? false);
        $this->redisBudget = max(0, (int) ($database['budget'] ?? 0));
        $this->redisState = (string) ($database['state'] ?? '');
        $region = (string) ($database['primary_region'] ?? '');
        $this->redisRegionLabel = UpstashRedisClient::REGIONS[$region] ?? $region;
        if ($password !== '' && $url !== '') {
            $parts = parse_url($url) ?: [];
            $host = (string) ($parts['host'] ?? '');
            if ($host !== '' && rawurldecode((string) ($parts['pass'] ?? '')) !== $password) {
                $scheme = $this->redisTls ? 'rediss' : 'redis';
                $port = (int) ($parts['port'] ?? ($database['port'] ?? 6379));
                $this->writeRedisEnv(sprintf('%s://%s:%s@%s:%d', $scheme, rawurlencode($this->redisUser), rawurlencode($password), $host, $port > 0 ? $port : 6379));
            }
        }
    }

    /**
     * @return array{user: string, password: string}
     */
    private function splitRedisUrl(string $url): array
    {
        $parts = parse_url($url) ?: [];
        $user = rawurldecode((string) ($parts['user'] ?? ''));

        return [
            'user' => $user !== '' ? $user : 'default',
            'password' => rawurldecode((string) ($parts['pass'] ?? '')),
        ];
    }

    /**
     * @param  array<string, mixed>  $stats
     * @return array<string, string>
     */
    private function redisStatLines(array $stats): array
    {
        $latest = static function (mixed $series): string {
            if (! is_array($series) || $series === []) {
                return '0';
            }
            $last = $series[array_key_last($series)];

            return is_array($last) ? (string) ($last['y'] ?? 0) : '0';
        };
        $bytes = static function (int $value): string {
            $units = ['B', 'KB', 'MB', 'GB'];
            $size = (float) max(0, $value);
            $unit = 0;
            while ($size >= 1024 && $unit < count($units) - 1) {
                $size /= 1024;
                $unit++;
            }

            return ($unit === 0 ? (string) (int) $size : number_format($size, 1)).' '.$units[$unit];
        };

        return [
            __('Commands today') => (string) (int) ($stats['daily_net_commands'] ?? 0),
            __('Reads today') => (string) (int) ($stats['daily_read_requests'] ?? 0),
            __('Writes today') => (string) (int) ($stats['daily_write_requests'] ?? 0),
            __('Commands this month') => (string) (int) ($stats['total_monthly_requests'] ?? 0),
            __('Storage') => $bytes((int) ($stats['current_storage'] ?? 0)),
            __('Bandwidth today') => $bytes((int) ($stats['dailybandwidth'] ?? 0)),
            __('Keys') => $latest($stats['keyspace'] ?? null),
            __('Connections') => $latest($stats['connection_count'] ?? null),
        ];
    }

    private function storeConnection(string $name, string $host, string $target): void
    {
        $row = EdgeContainerConnections::normalize([
            'kind' => $this->connectionKind,
            'name' => $name,
            'host' => $host,
            'target' => $target,
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

    public function selectDatabase(string $engine): void
    {
        $this->authorize('update', $this->site);
        if (! in_array($engine, ['sql', 'none'], true)) {
            return;
        }

        $this->draftDatabase = $engine;
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

    public function render(EdgeContainerComputeCost $cost): View
    {
        $meta = $this->site->edgeMeta();
        $container = is_array($meta['container'] ?? null) ? $meta['container'] : [];
        $runtime = (string) ($meta['runtime_mode'] ?? 'static');
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
                'connections' => EdgeContainerConnections::for($this->site),
                'servicePeers' => collect(EdgeContainerConnections::peerApps($this->site))->keyBy('id')->all(),
                'browserOn' => $runtime === 'container' && EdgeContainerConnections::browserEnabled($this->site),
                'browserDeployed' => $runtime === 'container' && is_string($this->site->edgeMeta()['active_deployment_id'] ?? null) && $this->site->edgeMeta()['active_deployment_id'] !== '',
                'browserHost' => EdgeContainerConnections::browserHost($this->site),
                'showBrowser' => $runtime === 'container',
                'connectionKinds' => EdgeContainerConnections::KINDS,
                'databaseEngine' => $databaseEngine,
                'databaseName' => (string) ($storedDatabase['name'] ?? 'production'),
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
            'dedicated_jobs' => (bool) ($settings['dedicated_jobs'] ?? true),
            'migrate_on_boot' => (bool) ($settings['migrate_on_boot'] ?? false),
            'custom_vcpu' => (int) ($container['custom_vcpu'] ?? 1),
            'custom_memory_gib' => (int) ($container['custom_memory_gib'] ?? 3),
            'custom_disk_gb' => (int) ($container['custom_disk_gb'] ?? 6),
            'rollout_mode' => $settings['rollout_mode'] ?? 'gradual',
            'rollout_steps' => implode(', ', $settings['rollout_step_percentage'] ?? []),
            'rollout_grace' => (int) ($settings['rollout_active_grace_period'] ?? 0),
            'cache' => in_array($cacheMode, ['off', 'assets', 'standard', 'everything'], true) ? $cacheMode : 'off',
            'database' => in_array($engine, ['sql', 'none'], true) ? $engine : ($runtime === 'container' ? 'sql' : 'none'),
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

        $this->site->mergeEdgeMeta([
            'database' => [
                'engine' => $this->draftDatabase,
                'name' => 'production',
            ],
        ]);
        $this->site->save();
        if ($cacheChanged) {
            $this->republishEdgeHostMap();
        }
        $this->pending = false;
        if (! $quiet) {
            $this->toastSuccess(__('Saved. Redeploy to apply these settings.'));
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
