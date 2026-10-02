<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Livewire\Admin\Concerns\AuthorizesPlatformAdmin;
use App\Models\AuditLog;
use App\Models\DplyDatabase;
use App\Models\EdgeDatabase;
use App\Models\EdgeQueue;
use App\Models\Organization;
use App\Models\Site;
use App\Modules\Billing\Services\EdgeSiteBillingAnalytics;
use App\Modules\Edge\Jobs\VerifyDatabaseBackupJob;
use App\Modules\Edge\Services\Containers\EdgeContainerCommands;
use App\Modules\Edge\Services\EdgeAppDatabase;
use App\Modules\Edge\Support\EdgeContainerConnections;
use App\Modules\Edge\Support\EdgeContainerInstances;
use App\Modules\Edge\Support\EdgeDplyDatabase;
use App\Modules\Edge\Support\EdgeValkey;
use App\Modules\Providers\Valkey\ValkeyGatewayClient;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Throwable;

/**
 * Every organization's apps and the resources they use, in one list, with
 * operator actions (T-036): live instances, sleep, restore check, and a
 * database query console behind a data-access session. The list itself:
 * containers, dply databases, Valkey and every other attached kind, D1
 * databases and queues. Read-only (T-035, ruling r-5w7h5d0b902aeq0n): state
 * comes from what dply already stores (meta, the hourly backup status, the
 * cached instance snapshot), so the page never wakes or calls an app.
 *
 * ponytail: builds every row on each render, with a billing read per app.
 * Paginate or cache it once there are hundreds of apps.
 */
#[Layout('layouts.admin')]
class Resources extends Component
{
    use AuthorizesPlatformAdmin;

    /** Kinds this page groups by, in display order. */
    public const KINDS = [
        'app' => 'Apps',
        'database' => 'dply databases',
        'redis' => 'Valkey',
        'd1' => 'D1 databases',
        'queue' => 'Queues',
        'other' => 'Other resources',
    ];

    #[Url]
    public string $search = '';

    #[Url]
    public string $kind = '';

    #[Url]
    public bool $troubleOnly = false;

    /** How long a data-access session lasts (ruling r-5w7h5d0b902aeq0n). */
    public const ACCESS_MINUTES = 30;

    /** The last action's outcome, shown above the list. */
    public ?string $flash = null;

    /** @var array<string, string> app id → its live instance summary */
    public array $live = [];

    /** The database the query panel is open for (its gateway id). */
    public ?string $queryFor = null;

    public string $accessReason = '';

    public string $querySql = '';

    public string $queryCollection = '';

    public string $queryFilter = '';

    /** @var array<string, mixed>|null */
    public ?array $queryResult = null;

    /** The container app the command panel is open for (its site id). */
    public ?string $commandFor = null;

    public string $opCommand = '';

    /** A target, '' for the app's default, or 'every' for every awake container. */
    public string $opTarget = '';

    public bool $opWake = false;

    /** @var array<string, string> target => run id */
    public array $opRuns = [];

    /**
     * Operator commands that only look at the machine run without a session;
     * anything else could read customer data and needs one (a reason, logged).
     */
    public const INFRA_COMMANDS = '/^(ps|df|free|uptime|nproc|top -b -n ?1)(\s+-?[a-zA-Z]+)*$/';

    public function openCommand(string $id): void
    {
        $row = $this->row('app', $id);
        abort_unless($row['engine'] === 'container', 422);
        $this->closeQuery();
        [$this->commandFor, $this->opRuns, $this->accessReason, $this->opTarget] = [$id, [], '', ''];
    }

    public function closeCommand(): void
    {
        $this->reset('commandFor', 'opCommand', 'opTarget', 'opWake', 'opRuns', 'accessReason');
    }

    public function runOpCommand(): void
    {
        $row = $this->row('app', (string) $this->commandFor);
        $command = trim($this->opCommand);
        abort_if($command === '' || mb_strlen($command) > 4000, 422);
        $access = $this->access($row['id']);
        $infra = preg_match(self::INFRA_COMMANDS, $command) === 1;
        abort_if(! $infra && $access === null, 403);
        $site = Site::query()->findOrFail($row['siteId']);
        $targets = EdgeContainerCommands::targets($site);
        $context = $access !== null ? ['reason' => $access['reason']] : [];
        $this->opRuns = [];
        if ($this->opTarget === 'every') {
            // Never wakes anything: a fleet-wide look at what is running now.
            foreach (array_keys($targets) as $target) {
                $this->opRuns[$target] = EdgeContainerCommands::start($site, $command, $target, 120, false, auth()->user(), true, $context);
            }

            return;
        }
        abort_if($this->opTarget !== '' && ! isset($targets[$this->opTarget]), 422);
        $target = $this->opTarget !== '' ? $this->opTarget : null;
        $this->opRuns[$target ?? 'default'] = EdgeContainerCommands::start($site, $command, $target, 300, $this->opWake, auth()->user(), true, $context);
    }

    public function mount(): void
    {
        $this->mountAuthorizesPlatformAdmin();
    }

    /** Asks a container app for its instances now (it answers; nothing wakes). */
    public function liveState(string $id): void
    {
        $row = $this->row('app', $id);
        $site = Site::query()->findOrFail($row['siteId']);
        Cache::forget('edge-container-instances:'.$site->id);
        $snapshot = EdgeContainerInstances::snapshot($site);
        $this->live[$id] = $snapshot['error'] ?? collect($snapshot['instances'] ?? [])
            ->map(fn (array $i): string => $i['name'].' '.$i['status'])->implode(' · ') ?: __('no instances');
    }

    /** Puts a dply database or Valkey to sleep now. */
    public function sleepResource(string $group, string $id): void
    {
        $row = $this->row($group, $id);
        try {
            if ($group === 'database') {
                ValkeyGatewayClient::fromConfig($row['region'])->sleep($row['id']);
            } elseif ($group === 'redis' && str_starts_with($row['id'], EdgeValkey::PREFIX)) {
                ValkeyGatewayClient::fromConfig(EdgeValkey::region($row['id']))->sleep(EdgeValkey::tenantId($row['id']));
            } else {
                abort(422);
            }
        } catch (Throwable $e) {
            $this->flash = __('Could not put :name to sleep: :error', ['name' => $row['name'], 'error' => Str::limit($e->getMessage(), 200)]);

            return;
        }
        $this->audit($row, 'support.resource.sleep');
        $this->flash = __(':name is asleep. It wakes on its next connection.', ['name' => $row['name']]);
    }

    /** Queues the weekly restore check for one Postgres database now. */
    public function verifyBackup(string $id): void
    {
        $row = $this->row('database', $id);
        abort_unless($row['engine'] === 'postgres', 422);
        VerifyDatabaseBackupJob::dispatch($row['id'], $row['name'], $row['databaseId'] === null ? $row['siteId'] : null, $row['databaseId']);
        $this->audit($row, 'support.database.verify');
        $this->flash = __('Restore check queued for :name. The result shows on its row in a minute or two.', ['name' => $row['name']]);
    }

    public function openQuery(string $id): void
    {
        $this->row('database', $id);
        $this->reset('commandFor', 'opCommand', 'opTarget', 'opWake', 'opRuns');
        [$this->queryFor, $this->queryResult, $this->accessReason] = [$id, null, ''];
    }

    public function closeQuery(): void
    {
        $this->reset('queryFor', 'queryResult', 'accessReason', 'querySql', 'queryCollection', 'queryFilter');
    }

    /** @return array<string, array<string, mixed>> target => run, for the open command panel */
    public function opRunsState(): array
    {
        return array_filter(array_map(EdgeContainerCommands::read(...), $this->opRuns));
    }

    /** Starts a data-access session: a reason, 30 minutes, logged and shown to the customer. */
    public function startAccess(): void
    {
        $row = $this->queryFor !== null ? $this->row('database', $this->queryFor) : $this->row('app', (string) $this->commandFor);
        $this->resetErrorBag('accessReason');
        $reason = trim($this->accessReason);
        if (mb_strlen($reason) < 5) {
            $this->addError('accessReason', __('Say why you need to see this customer\'s data.'));

            return;
        }
        Cache::put($this->accessKey($row['id']), ['reason' => $reason, 'until' => now()->addMinutes(self::ACCESS_MINUTES)->toIso8601String()], now()->addMinutes(self::ACCESS_MINUTES));
        $this->audit($row, 'support.access.start', ['reason' => $reason, 'minutes' => self::ACCESS_MINUTES]);
    }

    /** One read-only statement (a find for MongoDB), at most 200 rows, inside an open session. */
    public function runQuery(): void
    {
        $row = $this->row('database', (string) $this->queryFor);
        $access = $this->access($row['id']);
        abort_if($access === null, 403);
        $body = $row['engine'] === 'mongodb'
            ? ['collection' => trim($this->queryCollection), 'filter' => trim($this->queryFilter) ?: '{}']
            : ['sql' => $this->querySql];
        $this->audit($row, 'support.database.query', ['reason' => $access['reason']] + $body);
        try {
            $this->queryResult = ValkeyGatewayClient::fromConfig($row['region'])->action($row['id'], 'query', $body);
        } catch (Throwable $e) {
            $this->queryResult = ['error' => Str::limit($e->getMessage(), 500)];
        }
    }

    /** @return array{reason: string, until: string}|null */
    public function access(string $remoteId): ?array
    {
        $access = Cache::get($this->accessKey($remoteId));

        return is_array($access) ? $access : null;
    }

    private function accessKey(string $remoteId): string
    {
        return 'admin-data-access:'.auth()->id().':'.$remoteId;
    }

    /**
     * The row for an action, re-read on the server: actions never act on
     * anything the list doesn't show.
     *
     * @return array<string, mixed>
     */
    private function row(string $group, string $id): array
    {
        $this->authorizePlatformAdmin();
        foreach ($this->rows() as $row) {
            if ($row['group'] === $group && $row['id'] === $id) {
                return $row;
            }
        }
        abort(404);
    }

    /**
     * In the customer's organization log, which they see under Activity,
     * and in the platform audit log.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $values
     */
    private function audit(array $row, string $action, array $values = []): void
    {
        $organization = Organization::query()->find($row['orgId']);
        if ($organization === null) {
            return;
        }
        $site = $row['siteId'] !== null ? Site::query()->find($row['siteId']) : null;
        AuditLog::log($organization, auth()->user(), $action, $site, null, ['resource' => $row['name'], 'id' => $row['id']] + $values);
    }

    public function render(): View
    {
        $this->authorizePlatformAdmin();
        $rows = $this->rows();
        $needle = Str::lower(trim($this->search));
        $shown = array_values(array_filter($rows, fn (array $row): bool => ($this->kind === '' || $row['group'] === $this->kind)
            && (! $this->troubleOnly || $row['problem'] !== null)
            && ($needle === '' || str_contains(Str::lower($row['name'].' '.$row['org'].' '.implode(' ', $row['apps']).' '.$row['id']), $needle))));

        $groups = [];
        foreach (self::KINDS as $key => $label) {
            $inGroup = array_values(array_filter($shown, fn (array $row): bool => $row['group'] === $key));
            if ($inGroup !== []) {
                $groups[] = ['key' => $key, 'label' => $label, 'rows' => $inGroup];
            }
        }

        return view('livewire.admin.resources', [
            'groups' => $groups,
            'total' => count($rows),
            'trouble' => count(array_filter($rows, fn (array $row): bool => $row['problem'] !== null)),
        ]);
    }

    /**
     * One row per app and per resource.
     *
     * @return list<array{group: string, kind: string, id: string, name: string, org: string, orgId: ?string, apps: list<string>, state: string, detail: string, problem: ?string, costCents: ?int, href: ?string, siteId: ?string, region: ?string, engine: ?string, databaseId: ?string}>
     */
    public function rows(): array
    {
        $orgs = Organization::query()->pluck('name', 'id');
        $rows = [];
        $primaryIds = [];

        $sites = Site::query()->whereNotNull('edge_backend')->orderBy('name')->get();
        foreach ($sites as $site) {
            $meta = $site->edgeMeta();
            $org = (string) ($orgs[$site->organization_id] ?? '—');
            $container = ($meta['runtime_mode'] ?? '') === 'container';
            $rows[] = $this->appRow($site, $org, $container);

            $database = $meta['database'] ?? null;
            if (is_array($database) && EdgeAppDatabase::isDply($database) && (string) ($database['remote_id'] ?? '') !== '') {
                $primaryIds[(string) $database['remote_id']] = true;
                $rows[] = $this->databaseRow((string) $database['remote_id'], (string) ($database['engine'] ?? 'postgres'), $site->name, $org, (string) $site->organization_id, [$site->name], $database, (string) ($database['size'] ?? ''), (int) ($database['disk_gb'] ?? 0), (int) ($database['suspend'] ?? 0), EdgeDplyDatabase::regionOf($database), (string) $site->id, null);
            }
            foreach (EdgeContainerConnections::for($site) as $connection) {
                $rows[] = [
                    'group' => $connection['kind'] === 'redis' ? 'redis' : 'other',
                    'kind' => EdgeContainerConnections::KINDS[$connection['kind']]['label'] ?? $connection['kind'],
                    'id' => $connection['target'] !== '' ? $connection['target'] : $connection['host'],
                    'name' => $connection['name'],
                    'org' => $org,
                    'orgId' => (string) $site->organization_id,
                    'apps' => [$site->name],
                    'state' => $connection['asleep'] ? 'asleep' : 'on',
                    'detail' => $connection['plan'] !== '' ? $connection['plan'] : '',
                    'problem' => null,
                    'costCents' => null,
                    'href' => null,
                    'siteId' => (string) $site->id,
                    'region' => null,
                    'engine' => null,
                    'databaseId' => null,
                ];
            }
        }

        // Databases with their own row: an app's extra ones and detached ones.
        foreach (DplyDatabase::query()->with('sites:id,name')->get() as $database) {
            if (isset($primaryIds[$database->remote_id])) {
                continue;
            }
            $state = (array) ($database->state ?? []);
            $rows[] = $this->databaseRow($database->remote_id, (string) $database->engine, (string) $database->name, (string) ($orgs[$database->organization_id] ?? '—'), (string) $database->organization_id, $database->sites->pluck('name')->all(), $state, (string) ($database->size ?? ''), (int) $database->disk_gb, (int) $database->suspend, (string) $database->region, $database->sites->first()?->id, (string) $database->id);
        }

        foreach (EdgeDatabase::query()->get() as $d1) {
            $rows[] = $this->plainRow('d1', 'D1', (string) $d1->cloudflare_id, (string) $d1->name, (string) ($orgs[$d1->organization_id] ?? '—'), (string) $d1->organization_id, (string) ($d1->location_hint ?? ''));
        }
        foreach (EdgeQueue::query()->get() as $queue) {
            $rows[] = $this->plainRow('queue', 'Queue', (string) $queue->cloudflare_id, (string) $queue->name, (string) ($orgs[$queue->organization_id] ?? '—'), (string) $queue->organization_id, '');
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    private function appRow(Site $site, string $org, bool $container): array
    {
        $meta = $site->edgeMeta();
        $problem = match (true) {
            $site->status === Site::STATUS_EDGE_FAILED => __('Last deploy failed'),
            (bool) ($meta['traffic_gate'] ?? false) => __('Paused: no plan or spending limit reached'),
            ($meta['check_copy_handoff'] ?? null) !== null => __('Visitors are on a check copy'),
            default => null,
        };
        // Only what a recent visit to the app's Resources tab cached: never asks the app.
        $instances = $container ? Cache::get('edge-container-instances:'.$site->id) : null;
        $running = is_array($instances) && is_array($instances['instances'] ?? null) ? (int) ($instances['running'] ?? 0) : null;
        $billing = app(EdgeSiteBillingAnalytics::class)->forSite($site);

        return [
            'group' => 'app',
            'kind' => $container ? 'Container' : 'Static',
            'id' => (string) $site->id,
            'name' => (string) $site->name,
            'org' => $org,
            'orgId' => (string) $site->organization_id,
            'apps' => [],
            'state' => (string) $site->status,
            'detail' => trim($site->edgeHostname().($running !== null ? ' · '.trans_choice(':count instance running|:count instances running', $running) : '')),
            'problem' => $problem,
            'costCents' => is_array($billing) ? (int) ($billing['total_cents'] ?? 0) : null,
            'href' => $site->server_id !== null ? route('sites.show', ['site' => $site->id]) : null,
            'siteId' => (string) $site->id,
            'region' => null,
            'engine' => $container ? 'container' : null,
            'databaseId' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $record  where its backup status lives (site meta or the row's state)
     * @param  list<string>  $apps
     * @return array<string, mixed>
     */
    private function databaseRow(string $remoteId, string $engine, string $name, string $org, string $orgId, array $apps, array $record, string $size, int $diskGb, int $suspend, string $region, ?string $siteId, ?string $databaseId): array
    {
        $backup = (array) ($record['backup'] ?? []);
        $verify = (array) ($backup['verify'] ?? []);
        $problem = EdgeDplyDatabase::backupProblem($backup);
        if ($problem === null && $verify !== [] && ! ($verify['ok'] ?? false)) {
            $problem = __('Restore check failed: :error', ['error' => Str::limit((string) ($verify['error'] ?? ''), 120)]);
        }
        $lastBackup = (string) ($backup['last_ok_at'] ?? '');
        $detail = collect([
            $size !== '' ? $size : null,
            $diskGb > 0 ? $diskGb.' GB disk' : null,
            $suspend === -1 ? __('stays on') : __('sleeps'),
            $lastBackup !== '' ? __('backed up :ago', ['ago' => Carbon::parse($lastBackup)->diffForHumans()]) : __('no backup yet'),
            ($verify['ok'] ?? false) ? __('restore checked :ago', ['ago' => Carbon::parse((string) $verify['at'])->diffForHumans()]) : null,
        ])->filter()->implode(' · ');

        return [
            'group' => 'database',
            'kind' => ['postgres' => 'Postgres', 'mysql' => 'MySQL', 'mongodb' => 'MongoDB'][$engine] ?? $engine,
            'id' => $remoteId,
            'name' => $name,
            'org' => $org,
            'orgId' => $orgId,
            'apps' => $apps,
            'state' => '',
            'detail' => $detail,
            'problem' => $problem,
            'costCents' => null,
            'href' => null,
            'siteId' => $siteId,
            'region' => $region,
            'engine' => $engine,
            'databaseId' => $databaseId,
        ];
    }

    /** @return array<string, mixed> */
    private function plainRow(string $group, string $kind, string $id, string $name, string $org, string $orgId, string $detail): array
    {
        return ['group' => $group, 'kind' => $kind, 'id' => $id, 'name' => $name, 'org' => $org, 'orgId' => $orgId, 'apps' => [], 'state' => '', 'detail' => $detail, 'problem' => null, 'costCents' => null, 'href' => null, 'siteId' => null, 'region' => null, 'engine' => null, 'databaseId' => null];
    }
}
