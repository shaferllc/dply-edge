<?php

declare(strict_types=1);

namespace App\Modules\Edge\Support;

use App\Models\Site;
use App\Modules\Providers\Cloudflare\EdgeCloudflareClient;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Same-day visitor rows from the platform Analytics Engine dataset.
 * The edge worker writes one point per request. This reads that dataset.
 */
final class EdgeAnalyticsEngineTraffic
{
    public function __construct(
        private readonly ?EdgeCloudflareClient $client = null,
    ) {}

    /**
     * @return array{
     *     available: bool,
     *     requests: int,
     *     bytes_egress: int,
     *     status: array{2xx: int, 3xx: int, 4xx: int, 5xx: int},
     *     paths: list<array{path: string, requests: int}>,
     *     recent: list<array<string, mixed>>
     * }
     */
    public static function empty(): array
    {
        return [
            'available' => false,
            'requests' => 0,
            'bytes_egress' => 0,
            'status' => ['2xx' => 0, '3xx' => 0, '4xx' => 0, '5xx' => 0],
            'paths' => [],
            'recent' => [],
        ];
    }

    /**
     * @return array{
     *     available: bool,
     *     requests: int,
     *     bytes_egress: int,
     *     status: array{2xx: int, 3xx: int, 4xx: int, 5xx: int},
     *     paths: list<array{path: string, requests: int}>,
     *     recent: list<array<string, mixed>>
     * }
     */
    public function overview(Site $site): array
    {
        $dataset = $this->dataset();
        $index = $this->index($site);
        if ($dataset === null || $index === null) {
            return self::empty();
        }

        $start = now()->utc()->startOfDay()->format('Y-m-d H:i:s');
        $totals = $this->rows(sprintf(
            "SELECT count() AS requests, sum(double3) AS bytes_egress, sumIf(1, double1 >= 200 AND double1 < 300) AS status_2xx, sumIf(1, double1 >= 300 AND double1 < 400) AS status_3xx, sumIf(1, double1 >= 400 AND double1 < 500) AS status_4xx, sumIf(1, double1 >= 500) AS status_5xx FROM %s WHERE index1 = '%s' AND timestamp >= toDateTime('%s')",
            $dataset,
            $index,
            $start,
        ));
        if ($totals === null) {
            return self::empty();
        }

        $row = $totals[0] ?? [];
        $paths = $this->rows(sprintf(
            "SELECT blob4 AS path, count() AS requests FROM %s WHERE index1 = '%s' AND timestamp >= toDateTime('%s') GROUP BY path ORDER BY requests DESC LIMIT 8",
            $dataset,
            $index,
            $start,
        )) ?? [];

        return [
            'available' => true,
            'requests' => (int) ($row['requests'] ?? 0),
            'bytes_egress' => (int) ($row['bytes_egress'] ?? 0),
            'status' => [
                '2xx' => (int) ($row['status_2xx'] ?? 0),
                '3xx' => (int) ($row['status_3xx'] ?? 0),
                '4xx' => (int) ($row['status_4xx'] ?? 0),
                '5xx' => (int) ($row['status_5xx'] ?? 0),
            ],
            'paths' => $this->paths($paths),
            'recent' => $this->recent($site, now()->utc()->startOfDay(), 50),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recent(Site $site, Carbon $since, int $limit): array
    {
        $dataset = $this->dataset();
        $index = $this->index($site);
        if ($dataset === null || $index === null) {
            return [];
        }

        $limit = min(200, max(1, $limit));
        $rows = $this->rows(sprintf(
            "SELECT timestamp, blob2 AS hostname, blob3 AS method, blob4 AS path, double1 AS status, double2 AS duration_ms, double3 AS bytes_egress, blob5 AS cache_status FROM %s WHERE index1 = '%s' AND timestamp >= toDateTime('%s') ORDER BY timestamp DESC LIMIT %d",
            $dataset,
            $index,
            $since->utc()->format('Y-m-d H:i:s'),
            $limit,
        ));
        if ($rows === null) {
            return [];
        }

        return array_values(array_map(static function (array $row): array {
            $at = $row['timestamp'] ?? null;
            $path = trim((string) ($row['path'] ?? ''));

            return [
                'occurred_at' => is_string($at) && $at !== '' ? Carbon::parse($at, 'UTC')->toIso8601String() : null,
                'deployment_id' => null,
                'hostname' => (string) ($row['hostname'] ?? ''),
                'method' => (string) ($row['method'] ?? ''),
                'path' => $path !== '' ? $path : '/',
                'status' => (int) ($row['status'] ?? 0),
                'duration_ms' => (int) ($row['duration_ms'] ?? 0),
                'bytes_egress' => (int) ($row['bytes_egress'] ?? 0),
                'cache_status' => (string) ($row['cache_status'] ?? ''),
                'country' => null,
                'referrer' => null,
                'user_agent' => null,
            ];
        }, $rows));
    }

    private function dataset(): ?string
    {
        $dataset = trim((string) config('edge.cloudflare.analytics_dataset', ''));
        if (! preg_match('/^[A-Za-z0-9_]+$/', $dataset) || ! $this->client()->canQueryAnalyticsEngine()) {
            return null;
        }

        return $dataset;
    }

    private function index(Site $site): ?string
    {
        $index = preg_replace('/[^A-Za-z0-9]/', '', (string) $site->id) ?? '';

        return $index !== '' ? $index : null;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{path: string, requests: int}>
     */
    private function paths(array $rows): array
    {
        $paths = [];
        foreach ($rows as $row) {
            $path = trim((string) ($row['path'] ?? ''));
            $requests = (int) ($row['requests'] ?? 0);
            if ($path === '' || $requests < 1) {
                continue;
            }
            $paths[] = ['path' => $path, 'requests' => $requests];
        }

        return $paths;
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    private function rows(string $sql): ?array
    {
        try {
            $rows = $this->client()->queryAnalyticsEngineSql($sql);
        } catch (Throwable) {
            return null;
        }

        return array_values(array_filter($rows, is_array(...)));
    }

    private function client(): EdgeCloudflareClient
    {
        return $this->client ?? EdgeCloudflareClient::fromConfig();
    }
}
