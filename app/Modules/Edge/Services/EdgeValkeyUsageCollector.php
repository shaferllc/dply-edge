<?php

declare(strict_types=1);

namespace App\Modules\Edge\Services;

use App\Models\EdgeRedisUsage;
use App\Models\Site;
use App\Modules\Edge\Support\EdgeContainerConnections;
use App\Modules\Edge\Support\EdgeValkey;
use App\Modules\Providers\Valkey\ValkeyGatewayClient;
use Illuminate\Support\Facades\DB;

/**
 * Per-second billing for dply Valkey (T-021). The gateway reports a running
 * total of awake seconds per database. Each run adds what changed since the
 * last run to today's edge_redis_usage row. The last total seen is kept on
 * the site (meta.edge.valkey_counter) so a missed run is caught up, never lost.
 */
class EdgeValkeyUsageCollector
{
    /**
     * @return array{sites: int, seconds: int}
     */
    public function collect(bool $dryRun = false): array
    {
        if (! ValkeyGatewayClient::configured()) {
            return ['sites' => 0, 'seconds' => 0];
        }
        $totals = ValkeyGatewayClient::fromConfig()->usage();
        $date = now()->utc()->toDateString();
        $sites = 0;
        $seconds = 0;

        Site::query()->whereNotNull('edge_backend')->whereNotNull('organization_id')->each(function (Site $site) use ($totals, $date, $dryRun, &$sites, &$seconds): void {
            $counters = (array) ($site->edgeMeta()['valkey_counter'] ?? []);
            $added = 0;
            foreach (EdgeContainerConnections::for($site) as $connection) {
                if ($connection['kind'] !== 'redis' || ! EdgeValkey::isTarget($connection['target'])) {
                    continue;
                }
                $total = $totals[EdgeValkey::tenantId($connection['target'])] ?? null;
                if ($total === null) {
                    continue;
                }
                $last = (int) ($counters[$connection['target']] ?? 0);
                // A total below the last one means the tenant was recreated.
                $added += $total >= $last ? $total - $last : $total;
                $counters[$connection['target']] = $total;
            }
            if ($added === 0 || $dryRun) {
                $sites += $added > 0 ? 1 : 0;
                $seconds += $added;

                return;
            }

            DB::transaction(function () use ($site, $date, $added, $counters): void {
                $row = EdgeRedisUsage::query()->firstOrCreate(
                    ['site_id' => $site->id, 'date' => $date],
                    ['organization_id' => $site->organization_id],
                );
                $row->increment('awake_seconds', $added);
                $site->mergeEdgeMeta(['valkey_counter' => $counters]);
                $site->save();
            });
            $sites++;
            $seconds += $added;
        });

        return ['sites' => $sites, 'seconds' => $seconds];
    }
}
