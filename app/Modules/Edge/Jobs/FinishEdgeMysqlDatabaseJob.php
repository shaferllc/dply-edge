<?php

declare(strict_types=1);

namespace App\Modules\Edge\Jobs;

use App\Models\Site;
use App\Modules\Edge\Services\EdgeAppDatabase;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * PlanetScale MySQL is not ready in the save request. Poll until the cluster
 * is ready, then store the address on the app.
 *
 * Called from EdgeAppDatabase::startMysql.
 */
class FinishEdgeMysqlDatabaseJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 40;

    public int $timeout = 60;

    public function __construct(public string $siteId) {}

    public function handle(): void
    {
        $site = Site::query()->find($this->siteId);
        $record = is_array($site?->edgeMeta()['database'] ?? null) ? $site->edgeMeta()['database'] : [];
        if (! $site instanceof Site || ($record['engine'] ?? '') !== 'mysql' || ($record['status'] ?? '') !== 'provisioning') {
            return;
        }

        if (! EdgeAppDatabase::finishMysql($site, $record)) {
            $this->release(15);
        }
    }

    public function failed(Throwable $e): void
    {
        $site = Site::query()->find($this->siteId);
        if (! $site instanceof Site) {
            return;
        }
        $record = is_array($site->edgeMeta()['database'] ?? null) ? $site->edgeMeta()['database'] : [];
        if (($record['status'] ?? '') !== 'provisioning') {
            return;
        }
        $record['status'] = 'failed';
        $record['error'] = $e->getMessage();
        $site->mergeEdgeMeta(['database' => $record]);
        $site->save();
    }
}
