<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Edge\Workspace\Concerns\Resources;

use App\Livewire\Sites\Edge\Workspace\Resources;
use App\Models\EdgeDataUsage;
use App\Modules\Billing\Services\EdgeDataUsageCost;
use App\Modules\Edge\Support\EdgeContainerConnections;
use App\Modules\Providers\Cloudflare\EdgeCloudflareClient;
use Illuminate\Support\Facades\Cache;

/**
 * Resources sheet: SQL database (Cloudflare D1). Mixed into
 * {@see Resources}; the open connection
 * is $this->openResourceConnection() (set by openResource()).
 *
 * Every call re-reads the connection from the site and checks this
 * organization owns the database: the host and table names come from the
 * browser, and dply's Cloudflare account holds every organization's D1.
 */
trait ManagesSqlResource
{
    /** Rows kept per result: they live in the Livewire snapshot. */
    private const SQL_ROW_CAP = 200;

    /** The host sqlLoad() last loaded, so opening another database starts clean. */
    public string $sqlLoadedHost = '';

    /** @var array<string, mixed>|null D1 details: file_size, num_tables, running_in_region… */
    public ?array $sqlInfo = null;

    /** @var list<string>|null */
    public ?array $sqlTables = null;

    public ?string $sqlError = null;

    public string $sqlTable = '';

    /** @var list<array<string, mixed>>|null */
    public ?array $sqlTableRows = null;

    public string $sqlQuery = "SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name;";

    /** @var list<array<string, mixed>>|null */
    public ?array $sqlResults = null;

    /** @var array<string, mixed>|null */
    public ?array $sqlResultMeta = null;

    public ?string $sqlQueryError = null;

    public string $sqlName = '';

    /** @var array<string, array{reads: int, writes: int, storage: int}>|null */
    private ?array $sqlUsageMemo = null;

    /** Details and tables, on opening the sheet (never during render). */
    public function sqlLoad(): void
    {
        $this->authorize('view', $this->site);
        if ($this->sqlLoadedHost !== $this->resourceHost) {
            $this->reset('sqlInfo', 'sqlTables', 'sqlError', 'sqlTable', 'sqlTableRows', 'sqlQuery', 'sqlResults', 'sqlResultMeta', 'sqlQueryError');
            $this->sqlLoadedHost = $this->resourceHost;
            $this->sqlName = strtolower((string) ($this->openResourceConnection()['name'] ?? ''));
        }
        $connection = $this->sqlOwnedConnection();
        if ($connection === null) {
            return;
        }
        try {
            $client = EdgeCloudflareClient::fromConfig();
            $this->sqlInfo = Cache::remember('d1-info:'.$connection['target'], 60, fn () => $client->getD1Database($connection['target']));
            $rows = $client->queryD1($connection['target'], "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite\\_%' ESCAPE '\\' AND name NOT LIKE '\\_cf\\_%' ESCAPE '\\' ORDER BY name");
            $this->sqlTables = array_values(array_map(static fn (array $row): string => (string) ($row['name'] ?? ''), (array) ($rows[0]['results'] ?? [])));
            $this->sqlError = null;
        } catch (\Throwable $e) {
            $this->sqlError = __('The database did not answer: :error', ['error' => $e->getMessage()]);
        }
    }

    /** First 50 rows of one table. */
    public function sqlOpenTable(string $table): void
    {
        $this->authorize('view', $this->site);
        $connection = $this->sqlOwnedConnection();
        if ($connection === null || $table === '' || strlen($table) > 128) {
            return;
        }
        // Quoted as an identifier: the name comes from the browser.
        $sql = 'SELECT * FROM "'.str_replace('"', '""', $table).'" LIMIT 50';
        try {
            $result = EdgeCloudflareClient::fromConfig()->queryD1($connection['target'], $sql);
            $this->sqlTable = $table;
            $this->sqlTableRows = array_values((array) ($result[0]['results'] ?? []));
            $this->sqlError = null;
        } catch (\Throwable $e) {
            $this->sqlTableRows = null;
            $this->sqlError = __('Could not read :table: :error', ['table' => $table, 'error' => $e->getMessage()]);
        }
    }

    /** Run the console's SQL against this app's database. Shows the last statement's rows. */
    public function sqlRun(): void
    {
        $this->authorize('update', $this->site);
        $this->validate(['sqlQuery' => ['required', 'string', 'max:100000']]);
        $connection = $this->sqlOwnedConnection();
        if ($connection === null) {
            return;
        }
        try {
            $statements = EdgeCloudflareClient::fromConfig()->queryD1($connection['target'], $this->sqlQuery);
            $last = $statements === [] ? [] : $statements[array_key_last($statements)];
            $this->sqlResults = array_slice(array_values((array) ($last['results'] ?? [])), 0, self::SQL_ROW_CAP);
            $this->sqlResultMeta = (array) ($last['meta'] ?? []) + ['statements' => count($statements)];
            $this->sqlQueryError = null;
            Cache::forget('d1-info:'.$connection['target']);
        } catch (\Throwable $e) {
            $this->sqlResults = null;
            $this->sqlResultMeta = null;
            $this->sqlQueryError = $e->getMessage();
        }
    }

    /** Rename the binding (env.NAME and host). The D1 database keeps its name. */
    public function sqlRename(): void
    {
        $this->authorize('update', $this->site);
        $connection = $this->openResourceConnection();
        if ($connection === null || $connection['kind'] !== 'sql') {
            return;
        }
        $identity = EdgeContainerConnections::identity($this->sqlName, $this->site);
        if ($identity === null) {
            $this->addError('sqlName', __('Letters and numbers only, starting with a letter.'));

            return;
        }
        $rows = EdgeContainerConnections::for($this->site);
        foreach ($rows as $row) {
            if ($row['host'] !== $connection['host'] && ($row['host'] === $identity['host'] || $row['name'] === $identity['name'])) {
                $this->addError('sqlName', __('That name is already used on this app.'));

                return;
            }
        }
        foreach ($rows as $index => $row) {
            if ($row['host'] === $connection['host']) {
                $rows[$index]['name'] = $identity['name'];
                $rows[$index]['host'] = $identity['host'];
            }
        }
        $this->site->mergeEdgeMeta(['connections' => $rows]);
        $this->site->save();
        $this->resourceHost = $identity['host'];
        $this->sqlLoadedHost = $identity['host'];
        $this->toastSuccess(__('Renamed. The app uses the new name after the next deploy.'));
    }

    /** This database's share of the month, not the organization's D1 total. */
    public function sqlCostCents(array $connection): ?int
    {
        $usage = $this->sqlMonthUsage($connection['target']);

        return app(EdgeDataUsageCost::class)->cents($usage['reads'], $usage['writes'], $usage['storage'], 0);
    }

    /**
     * Rows read / written this month and the peak size, for one D1 id.
     *
     * @return array{reads: int, writes: int, storage: int}
     */
    public function sqlMonthUsage(string $target): array
    {
        if ($this->sqlUsageMemo === null) {
            $this->sqlUsageMemo = [];
            $days = EdgeDataUsage::query()
                ->where('organization_id', $this->site->organization_id)
                ->whereBetween('date', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])
                ->pluck('d1_by_database');
            foreach ($days as $databases) {
                foreach ((array) $databases as $id => $row) {
                    $sum = $this->sqlUsageMemo[$id] ?? ['reads' => 0, 'writes' => 0, 'storage' => 0];
                    $sum['reads'] += (int) ($row['rows_read'] ?? 0);
                    $sum['writes'] += (int) ($row['rows_written'] ?? 0);
                    $sum['storage'] = max($sum['storage'], (int) ($row['storage_bytes'] ?? 0));
                    $this->sqlUsageMemo[$id] = $sum;
                }
            }
        }

        return $this->sqlUsageMemo[$target] ?? ['reads' => 0, 'writes' => 0, 'storage' => 0];
    }

    /**
     * The open connection when it is a D1 this organization created. Only
     * its own target is ever queried.
     *
     * @return array{kind: string, name: string, host: string, target: string, asleep: bool, plan: string, read_regions: int}|null
     */
    private function sqlOwnedConnection(): ?array
    {
        $connection = $this->openResourceConnection();
        if ($connection === null || $connection['kind'] !== 'sql' || $connection['target'] === '') {
            return null;
        }
        try {
            $owned = $this->site->organization !== null
                && EdgeContainerConnections::owns('sql', $connection['target'], $this->site->organization);
        } catch (\Throwable) {
            $owned = false;
        }
        if (! $owned) {
            $this->sqlError = __('This database was not created by this organization, so it cannot be browsed here.');

            return null;
        }

        return $connection;
    }
}
