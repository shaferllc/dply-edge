<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Livewire\Admin\Concerns\AuthorizesPlatformAdmin;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Site;
use App\Modules\Edge\Jobs\VerifyDatabaseBackupJob;
use App\Modules\Edge\Services\Containers\EdgeContainerAgent;
use App\Modules\Edge\Services\Containers\EdgeContainerCommands;
use App\Modules\Edge\Support\EdgeContainerInstances;
use App\Modules\Edge\Support\EdgeResourceIndex;
use App\Modules\Edge\Support\EdgeValkey;
use App\Modules\Providers\Valkey\ValkeyGatewayClient;
use Illuminate\Contracts\View\View;
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
    public const KINDS = EdgeResourceIndex::KINDS;

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
        $this->reset('commandFor', 'opCommand', 'opTarget', 'opWake', 'opRuns', 'accessReason', 'inspect');
    }

    /** @var array{kind: string, results: array<string, array<string, mixed>>}|null processes or env, per target */
    public ?array $inspect = null;

    /**
     * What is running in the app's containers, with memory (T-040). Never
     * wakes one. One click: it shows process names and sizes, not data.
     */
    public function showProcesses(): void
    {
        $this->inspectContainers('processes', 'support.container.processes');
    }

    /**
     * The env each container booted with. Values stay masked unless a
     * data-access session is open; then they show and the reveal is logged.
     */
    public function showEnv(): void
    {
        $this->inspectContainers('env', 'support.container.env');
    }

    private function inspectContainers(string $kind, string $action): void
    {
        $row = $this->row('app', (string) $this->commandFor);
        $site = Site::query()->findOrFail($row['siteId']);
        $targets = EdgeContainerCommands::targets($site);
        $chosen = $this->opTarget === 'every' ? array_keys($targets) : [$this->opTarget !== '' ? $this->opTarget : (string) array_key_first($targets)];
        abort_if(array_diff($chosen, array_keys($targets)) !== [], 422);
        $access = $this->access($row['id']);
        $results = [];
        foreach ($chosen as $target) {
            try {
                $data = EdgeContainerAgent::get($site, $kind, $target);
            } catch (Throwable $e) {
                $data = ['error' => Str::limit($e->getMessage(), 300)];
            }
            if ($kind === 'env' && isset($data['env']) && $access === null) {
                $data['env'] = array_map(fn ($value): string => '•••• ('.mb_strlen((string) $value).')', (array) $data['env']);
            }
            $results[$target] = $data;
        }
        $this->audit($row, $action, ['targets' => $chosen] + ($kind === 'env' ? ['revealed' => $access !== null] : []) + ($access !== null ? ['reason' => $access['reason']] : []));
        $this->inspect = ['kind' => $kind, 'results' => $results];
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

    /** @return list<array<string, mixed>> */
    public function rows(): array
    {
        return EdgeResourceIndex::rows();
    }
}
