<?php

declare(strict_types=1);

namespace App\Modules\Edge\Jobs;

use App\Models\Site;
use App\Modules\Edge\Services\EdgeAppDatabase;
use App\Modules\Edge\Support\EdgeDplyDatabase;
use App\Modules\Providers\Valkey\ValkeyGatewayClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Export a dply database to its bucket, or import a file into it (dbagent
 * transfer.go). Either can run for up to an hour, so it is a job; progress is
 * on meta.edge.database.transfer for the Resources tab.
 *
 * Once only: a retried import would load the same data twice. The key is
 * built by the caller from this database's own id, never from the browser.
 *
 * Called from Resources::exportDatabase / importDatabase.
 */
class TransferEdgeDplyDatabaseJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 3600;

    /** @param 'export'|'import' $kind */
    public function __construct(public string $siteId, public string $kind, public string $key = '')
    {
        $this->onQueue('dply');
    }

    public function handle(): void
    {
        $site = Site::query()->find($this->siteId);
        $database = is_array($site?->edgeMeta()['database'] ?? null) ? $site->edgeMeta()['database'] : [];
        if (! $site instanceof Site || ! EdgeAppDatabase::isDply($database)) {
            return;
        }

        try {
            $out = ValkeyGatewayClient::fromConfig(EdgeDplyDatabase::regionOf($database))
                ->action((string) $database['remote_id'], $this->kind, $this->kind === 'import' ? ['key' => $this->key] : []);
            $transfer = ['status' => 'done', 'bytes' => (int) ($out['bytes'] ?? 0)];
        } catch (Throwable $e) {
            $transfer = ['status' => 'failed', 'error' => $e->getMessage()];
        }

        $site->refresh();
        $site->mergeEdgeMeta(['database' => array_merge($site->edgeMeta()['database'] ?? [], [
            'transfer' => $transfer + ['kind' => $this->kind, 'file' => basename($this->key), 'finished_at' => now()->toIso8601String()],
        ])]);
        $site->save();
    }
}
