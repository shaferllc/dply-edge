<?php

declare(strict_types=1);

namespace App\Modules\Edge\Jobs;

use App\Models\Site;
use App\Modules\Edge\Services\EdgeAppDatabase;
use App\Modules\Providers\Valkey\ValkeyGatewayClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Point-in-time restore of a dply Postgres from its wal-g backups. It can take
 * minutes (fetch a base backup, replay WAL), so it runs here, not in the
 * request. Progress is on meta.edge.database.restore for the Resources tab.
 *
 * Called from Resources::restorePostgres.
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
    public function __construct(public string $siteId, public string $targetTime)
    {
        $this->onQueue('dply');
    }

    public function handle(): void
    {
        $site = Site::query()->find($this->siteId);
        $database = is_array($site?->edgeMeta()['database'] ?? null) ? $site->edgeMeta()['database'] : [];
        if (! $site instanceof Site || ($database['engine'] ?? '') !== 'postgres' || ! EdgeAppDatabase::isDply($database)) {
            return;
        }

        try {
            ValkeyGatewayClient::fromConfig()->restore((string) $database['remote_id'], $this->targetTime);
            $restore = ['status' => 'done', 'target' => $this->targetTime, 'finished_at' => now()->toIso8601String()];
        } catch (Throwable $e) {
            $restore = ['status' => 'failed', 'target' => $this->targetTime, 'error' => $e->getMessage(), 'finished_at' => now()->toIso8601String()];
        }

        $site->refresh();
        $site->mergeEdgeMeta(['database' => array_merge($site->edgeMeta()['database'] ?? [], ['restore' => $restore])]);
        $site->save();
    }
}
