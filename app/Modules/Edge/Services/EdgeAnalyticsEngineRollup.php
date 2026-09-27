<?php

declare(strict_types=1);

namespace App\Modules\Edge\Services;

use App\Models\Site;
use App\Modules\Providers\Cloudflare\EdgeCloudflareClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Pulls hourly performance rollups from Cloudflare Analytics Engine SQL.
 */
final class EdgeAnalyticsEngineRollup
{
    private const SOURCE = 'analytics_engine';

    private readonly EdgeCloudflareClient $client;

    // The client needs the account id and token from config, so the
    // container cannot build it: take one in tests, else use config.
    public function __construct(
        private readonly EdgePerformanceHourlyRollup $rollup,
        ?EdgeCloudflareClient $client = null,
    ) {
        $this->client = $client ?? EdgeCloudflareClient::fromConfig();
    }

    /**
     * @return array{hours: int, rows: int}
     */
    public function rollupRecentHours(int $hours = 2): array
    {
        $dataset = trim((string) config('edge.cloudflare.analytics_dataset', ''));
        if ($dataset === '' || ! $this->client->canQueryAnalyticsEngine()) {
            return ['hours' => 0, 'rows' => 0];
        }

        $hours = max(1, min($hours, 48));
        $sites = $this->billableEdgeSites()->keyBy('id');
        if ($sites->isEmpty()) {
            return ['hours' => 0, 'rows' => 0];
        }

        $sql = sprintf(
            <<<'SQL'
            SELECT
              index1 AS site_id,
              toStartOfInterval(timestamp, INTERVAL '1' HOUR) AS hour_start,
              count() AS requests,
              sum(double3) AS bytes_egress,
              sum(double2) AS duration_ms_total,
              sumIf(1, double1 >= 200 AND double1 < 300) AS status_2xx,
              sumIf(1, double1 >= 400 AND double1 < 500) AS status_4xx,
              sumIf(1, double1 >= 500) AS status_5xx,
              sumIf(1, position('hit' IN lower(blob5)) > 0) AS cache_hits
            FROM %s
            WHERE timestamp >= NOW() - INTERVAL '%d' HOUR
            GROUP BY site_id, hour_start
            ORDER BY hour_start DESC
            SQL,
            $this->quoteIdentifier($dataset),
            $hours,
        );

        $rows = $this->client->queryAnalyticsEngineSql($sql);
        $written = 0;

        foreach ($rows as $row) {
            $siteId = (string) ($row['site_id'] ?? '');
            $site = $sites->get($siteId);
            if (! $site instanceof Site) {
                continue;
            }

            $hourStart = Carbon::parse((string) ($row['hour_start'] ?? now()->startOfHour()->toIso8601String()))->startOfHour();

            $this->rollup->upsertHour($site, $hourStart, [
                'requests' => (int) ($row['requests'] ?? 0),
                'bytes_egress' => (int) ($row['bytes_egress'] ?? 0),
                'duration_ms_total' => (int) ($row['duration_ms_total'] ?? 0),
                'status_2xx' => (int) ($row['status_2xx'] ?? 0),
                'status_4xx' => (int) ($row['status_4xx'] ?? 0),
                'status_5xx' => (int) ($row['status_5xx'] ?? 0),
                'cache_hits' => (int) ($row['cache_hits'] ?? 0),
            ], self::SOURCE);

            $written++;
        }

        Log::info('edge.analytics_engine.rollup', [
            'hours' => $hours,
            'rows' => $written,
        ]);

        return ['hours' => $hours, 'rows' => $written];
    }

    /**
     * @return Collection<int, Site>
     */
    private function billableEdgeSites(): Collection
    {
        return Site::query()
            ->where('status', Site::STATUS_EDGE_ACTIVE)
            ->whereNotNull('edge_backend')
            ->where('edge_backend', '!=', '')
            ->get()
            ->reject(fn (Site $site): bool => $site->isEdgePreview())
            ->values();
    }

    // Analytics Engine SQL has no backtick quoting; dataset names are plain
    // identifiers, so accept only those.
    private function quoteIdentifier(string $identifier): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier) !== 1) {
            throw new \InvalidArgumentException("Not an Analytics Engine dataset name: {$identifier}");
        }

        return $identifier;
    }
}
