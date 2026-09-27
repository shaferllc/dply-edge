<?php

declare(strict_types=1);

namespace App\Modules\Edge\Services;

use App\Models\EdgeDatabase;
use App\Models\EdgeDataUsage;
use App\Models\EdgeQueue;
use App\Modules\Providers\Cloudflare\EdgeCloudflareClient;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * One day of D1 + Queues usage, attributed to organizations through the
 * edge_databases / edge_queues rows that own each Cloudflare resource.
 */
class EdgeDataUsageCollector
{
    public function __construct(private ?EdgeCloudflareClient $client = null) {}

    /** @return array{organizations: int} */
    public function collectForDate(CarbonInterface $date, bool $dryRun = false): array
    {
        $usage = ($this->client ?? EdgeCloudflareClient::fromConfig())->dataUsageForDate($date);

        $orgByDatabase = EdgeDatabase::query()->whereIn('cloudflare_id', array_keys($usage['d1']))->pluck('organization_id', 'cloudflare_id');
        $orgByQueue = EdgeQueue::query()->whereIn('cloudflare_id', array_keys($usage['queues']))->pluck('organization_id', 'cloudflare_id');

        $perOrg = [];
        $blank = ['d1_rows_read' => 0, 'd1_rows_written' => 0, 'd1_storage_bytes' => 0, 'queue_operations' => 0, 'd1_by_database' => []];
        foreach ($usage['d1'] as $databaseId => $row) {
            $org = $orgByDatabase[$databaseId] ?? null;
            if ($org === null) {
                continue;
            }
            $perOrg[$org] ??= $blank;
            $perOrg[$org]['d1_rows_read'] += $row['rows_read'];
            $perOrg[$org]['d1_rows_written'] += $row['rows_written'];
            $perOrg[$org]['d1_storage_bytes'] += $row['storage_bytes'];
            // Kept per database too, so one database's cost can be shown alone.
            $perOrg[$org]['d1_by_database'][$databaseId] = $row;
        }
        foreach ($usage['queues'] as $queueId => $operations) {
            $org = $orgByQueue[$queueId] ?? null;
            if ($org === null) {
                continue;
            }
            $perOrg[$org] ??= $blank;
            $perOrg[$org]['queue_operations'] += $operations;
        }

        if (! $dryRun) {
            foreach ($perOrg as $organizationId => $totals) {
                EdgeDataUsage::query()->updateOrCreate(['organization_id' => $organizationId, 'date' => $date->toDateString()], $totals);
            }
            // Kept per queue too, so one queue's cost can be shown alone.
            foreach ($usage['queues'] as $queueId => $operations) {
                if (isset($orgByQueue[$queueId])) {
                    DB::table('edge_queue_usage')->upsert([[
                        'id' => (string) Str::ulid(),
                        'organization_id' => $orgByQueue[$queueId],
                        'queue_id' => (string) $queueId,
                        'date' => $date->toDateString(),
                        'operations' => $operations,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]], ['queue_id', 'date'], ['operations', 'updated_at']);
                }
            }
        }

        return ['organizations' => count($perOrg)];
    }
}
