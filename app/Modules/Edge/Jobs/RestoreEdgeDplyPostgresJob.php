<?php

declare(strict_types=1);

namespace App\Modules\Edge\Jobs;

use App\Models\DplyDatabase;
use App\Models\Site;
use App\Modules\Edge\Services\DplyDatabases;
use App\Modules\Edge\Services\EdgeAppDatabase;
use App\Modules\Edge\Support\EdgeDplyDatabase;
use App\Modules\Providers\Valkey\ValkeyGatewayClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Point-in-time restore of a dply database: Postgres from wal-g, MongoDB and
 * MySQL from a daily dump plus the oplog / binlog (dbagent). It can take
 * minutes, so it runs here, not in the request. Progress is on meta.edge.database.restore for the Resources tab.
 *
 * Started by DplyDatabaseActions (the Resources panel and the API).
 */
class RestoreEdgeDplyPostgresJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1500;

    // The "dply" queue's Horizon workers allow 3600 s (config/horizon.php);
    // "default" stops a job at 900 s, shorter than a large restore.
    public function __construct(public string $siteId, public string $targetTime, public ?string $databaseId = null)
    {
        $this->onQueue('dply');
    }

    public function handle(): void
    {
        // $databaseId: any of the organization's databases (DplyDatabases). Without it
        // (jobs queued before it existed) the app's primary, from its mirror.
        $row = $this->databaseId !== null ? DplyDatabase::query()->find($this->databaseId) : null;
        $site = $row === null ? Site::query()->find($this->siteId) : null;
        $database = $row !== null ? DplyDatabases::record($row) : (is_array($site?->edgeMeta()['database'] ?? null) ? $site->edgeMeta()['database'] : []);
        if (($row === null && ! $site instanceof Site) || ! EdgeAppDatabase::isDply($database)) {
            return;
        }

        try {
            ValkeyGatewayClient::fromConfig(EdgeDplyDatabase::regionOf($database))->restore((string) $database['remote_id'], $this->targetTime);
            $restore = ['status' => 'done', 'target' => $this->targetTime, 'finished_at' => now()->toIso8601String()];
        } catch (Throwable $e) {
            $restore = ['status' => 'failed', 'target' => $this->targetTime, 'error' => $e->getMessage(), 'finished_at' => now()->toIso8601String()];
        }

        if ($row !== null) {
            DplyDatabases::remember($row, ['restore' => $restore]);

            return;
        }
        $site->refresh();
        $site->mergeEdgeMeta(['database' => array_merge($site->edgeMeta()['database'] ?? [], ['restore' => $restore])]);
        $site->save();
    }
}
