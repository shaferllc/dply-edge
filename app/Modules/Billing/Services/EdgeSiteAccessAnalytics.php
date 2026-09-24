<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Models\EdgeAccessLog;
use App\Models\EdgePerformanceHourly;
use App\Models\EdgeWebVital;
use App\Models\Site;
use App\Modules\Edge\Support\EdgeAnalyticsEngineTraffic;
use App\Modules\Providers\Cloudflare\EdgeCloudflareClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Access logs + performance rollups for Edge traffic workspace.
 */
final class EdgeSiteAccessAnalytics
{
    /**
     * @return array<string, mixed>
     */
    public function forSite(Site $site): array
    {
        $memoKey = 'edge.access.for_site.'.$site->id;
        if (app()->bound('request') && request()->attributes->has($memoKey)) {
            /** @var array<string, mixed> */
            return request()->attributes->get($memoKey);
        }

        $since = now()->subDays(7);

        $recentLogs = EdgeAccessLog::query()
            ->where('site_id', $site->id)
            ->where('occurred_at', '>=', $since)
            ->orderByDesc('occurred_at')
            ->limit(50)
            ->get(['hostname', 'method', 'path', 'status_code', 'duration_ms', 'bytes_egress', 'country', 'cache_status', 'occurred_at']);

        $hourly = EdgePerformanceHourly::query()
            ->where('site_id', $site->id)
            ->where('hour_start', '>=', $since->copy()->startOfHour())
            ->where('source', $this->performanceSource($site, $since))
            ->orderBy('hour_start')
            ->get();

        $requests7d = (int) $hourly->sum('requests');
        $avgDuration = $requests7d > 0
            ? (int) round(((int) $hourly->sum('duration_ms_total')) / max(1, $requests7d))
            : 0;

        $p95 = $this->approximateP95Duration($site, $since);

        $vitals = EdgeWebVital::query()
            ->where('site_id', $site->id)
            ->where('occurred_at', '>=', $since)
            ->get(['lcp_ms', 'cls', 'inp_ms', 'fcp_ms', 'ttfb_ms']);

        $apiPerformance = ($recentLogs->isEmpty() && $hourly->isEmpty())
            ? $this->performanceFromAnalyticsEngine($site)
            : null;
        $apiVitals = $vitals->isEmpty()
            ? $this->vitalsFromAnalyticsEngine($site)
            : null;

        $payload = [
            'has_worker_logs' => $recentLogs->isNotEmpty() || $hourly->isNotEmpty() || $apiPerformance !== null,
            'has_web_vitals' => $vitals->isNotEmpty() || $apiVitals !== null,
            'recent_logs' => $recentLogs->map(fn (EdgeAccessLog $log): array => [
                'hostname' => $log->hostname,
                'method' => $log->method,
                'path' => $log->path,
                'status_code' => $log->status_code,
                'duration_ms' => $log->duration_ms,
                'bytes_egress' => $log->bytes_egress,
                'country' => $log->country,
                'cache_status' => $log->cache_status,
                'occurred_at' => $log->occurred_at?->toIso8601String(),
            ])->all(),
            'performance' => $apiPerformance ?? [
                'requests_7d' => $requests7d,
                'avg_duration_ms' => $avgDuration,
                'p95_duration_ms' => $p95,
                'cache_hit_ratio' => $this->cacheHitRatio($hourly),
            ],
            'web_vitals' => $apiVitals ?? [
                'samples_7d' => $vitals->count(),
                'lcp_p75_ms' => $this->percentile($vitals->pluck('lcp_ms')->filter()->values(), 75),
                'cls_p75' => $this->percentile($vitals->pluck('cls')->filter()->values(), 75),
                'inp_p75_ms' => $this->percentile($vitals->pluck('inp_ms')->filter()->values(), 75),
                'fcp_p75_ms' => $this->percentile($vitals->pluck('fcp_ms')->filter()->values(), 75),
                'ttfb_p75_ms' => $this->percentile($vitals->pluck('ttfb_ms')->filter()->values(), 75),
            ],
            'dataset' => $site->edge_backend === 'dply_edge'
                ? app(EdgeAnalyticsEngineTraffic::class)->overview($site)
                : EdgeAnalyticsEngineTraffic::empty(),
        ];

        if (app()->bound('request')) {
            request()->attributes->set($memoKey, $payload);
        }

        return $payload;
    }

    /**
     * @param  Collection<int, int|float>  $values
     */
    private function percentile($values, int $percentile): int|float|null
    {
        if ($values->isEmpty()) {
            return null;
        }

        $sorted = $values->sort()->values();
        $index = (int) max(0, min($sorted->count() - 1, (int) ceil($sorted->count() * ($percentile / 100)) - 1));
        $value = $sorted[$index];

        return is_float($value) ? round($value, 4) : (int) $value;
    }

    /**
     * @param  Collection<int, EdgePerformanceHourly>  $hourly
     */
    private function cacheHitRatio($hourly): ?float
    {
        $requests = (int) $hourly->sum('requests');
        $hits = (int) $hourly->sum('cache_hits');
        if ($requests === 0) {
            return null;
        }

        return round($hits / $requests, 3);
    }

    private function approximateP95Duration(Site $site, Carbon $since): ?int
    {
        $durations = EdgeAccessLog::query()
            ->where('site_id', $site->id)
            ->where('occurred_at', '>=', $since)
            ->orderByDesc('duration_ms')
            ->limit(200)
            ->pluck('duration_ms')
            ->map(fn ($value): int => (int) $value)
            ->sort()
            ->values();

        if ($durations->isEmpty()) {
            return null;
        }

        $index = (int) max(0, min($durations->count() - 1, (int) ceil($durations->count() * 0.95) - 1));

        return $durations[$index];
    }

    /**
     * @return array{requests_7d: int, avg_duration_ms: int, p95_duration_ms: int|float|null, cache_hit_ratio: float|null}|null
     */
    private function performanceFromAnalyticsEngine(Site $site): ?array
    {
        $rows = $this->analyticsEngineRows($site, (string) $site->id, 'double2 AS ms, blob5 AS cache');
        if ($rows === []) {
            return null;
        }

        $durations = collect($rows)
            ->map(fn (array $row): int => (int) round((float) ($row['ms'] ?? 0)))
            ->sort()
            ->values();
        $requests = $durations->count();
        $hits = collect($rows)->filter(
            fn (array $row): bool => str_contains(strtolower((string) ($row['cache'] ?? '')), 'hit'),
        )->count();

        return [
            'requests_7d' => $requests,
            'avg_duration_ms' => (int) round((float) ($durations->avg() ?? 0)),
            'p95_duration_ms' => $this->percentile($durations, 95),
            'cache_hit_ratio' => $requests > 0 ? round($hits / $requests, 3) : null,
        ];
    }

    /**
     * @return array{samples_7d: int, lcp_p75_ms: int|float|null, cls_p75: int|float|null, inp_p75_ms: int|float|null, fcp_p75_ms: int|float|null, ttfb_p75_ms: int|float|null}|null
     */
    private function vitalsFromAnalyticsEngine(Site $site): ?array
    {
        $rows = $this->analyticsEngineRows(
            $site,
            'v'.preg_replace('/[^A-Za-z0-9]/', '', (string) $site->id),
            'double1 AS lcp_ms, double2 AS cls, double3 AS inp_ms, double4 AS fcp_ms, double5 AS ttfb_ms',
        );
        if ($rows === []) {
            return null;
        }

        $samples = collect($rows);

        return [
            'samples_7d' => $samples->count(),
            'lcp_p75_ms' => $this->positivePercentile($samples->pluck('lcp_ms'), 75),
            'cls_p75' => $this->percentile($samples->pluck('cls')->map(fn ($value): float => (float) $value)->values(), 75),
            'inp_p75_ms' => $this->positivePercentile($samples->pluck('inp_ms'), 75),
            'fcp_p75_ms' => $this->positivePercentile($samples->pluck('fcp_ms'), 75),
            'ttfb_p75_ms' => $this->positivePercentile($samples->pluck('ttfb_ms'), 75),
        ];
    }

    /**
     * @param  Collection<int, mixed>  $values
     */
    private function positivePercentile($values, int $percentile): int|float|null
    {
        return $this->percentile(
            $values->map(fn ($value): int => (int) round((float) $value))->filter(fn (int $value): bool => $value > 0)->values(),
            $percentile,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function analyticsEngineRows(Site $site, string $index, string $columns): array
    {
        if (app()->environment('testing')) {
            return [];
        }

        $dataset = (string) config('edge.cloudflare.analytics_dataset', '');
        $index = preg_replace('/[^A-Za-z0-9]/', '', $index) ?? '';
        if ($dataset === '' || ! preg_match('/^[A-Za-z0-9_]+$/', $dataset) || ! preg_match('/^[A-Za-z0-9]+$/', $index)) {
            return [];
        }

        $sql = sprintf(
            "SELECT %s FROM %s WHERE index1 = '%s' AND timestamp > NOW() - INTERVAL '7' DAY LIMIT 2000",
            $columns,
            $dataset,
            $index,
        );

        try {
            $rows = EdgeCloudflareClient::fromConfig()->queryAnalyticsEngineSql($sql);
        } catch (Throwable) {
            return [];
        }

        return array_values(array_filter($rows, is_array(...)));
    }

    private function performanceSource(Site $site, Carbon $since): string
    {
        if (! filter_var((string) config('edge.analytics.prefer_analytics_engine', true), FILTER_VALIDATE_BOOLEAN)) {
            return 'worker';
        }

        $hasAnalyticsEngine = EdgePerformanceHourly::query()
            ->where('site_id', $site->id)
            ->where('hour_start', '>=', $since->copy()->startOfHour())
            ->where('source', 'analytics_engine')
            ->exists();

        return $hasAnalyticsEngine ? 'analytics_engine' : 'worker';
    }
}
