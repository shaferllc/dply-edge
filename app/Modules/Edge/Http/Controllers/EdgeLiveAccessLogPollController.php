<?php

declare(strict_types=1);

namespace App\Modules\Edge\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Modules\Providers\Cloudflare\EdgeCloudflareClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

/**
 * Session-authed poll for the Edge workspace live request tail.
 *
 * Rows come from the Workers Analytics Engine dataset the edge worker writes
 * on each request. The tail does not depend on the worker posting back to
 * this app.
 */
class EdgeLiveAccessLogPollController extends Controller
{
    public function __invoke(Request $request, Site $site): JsonResponse
    {
        Gate::authorize('view', $site);

        if (! $site->usesEdgeRuntime()) {
            abort(404, 'Not an Edge site.');
        }

        $sinceRaw = trim((string) $request->query('since', ''));
        $since = $sinceRaw !== ''
            ? Carbon::parse($sinceRaw)->utc()
            : now()->subHour();
        $limit = min(200, max(1, (int) $request->query('limit', 50)));

        $rows = $this->requestsFromAnalytics($site, $since, $limit);

        return response()->json([
            'data' => $rows,
            'meta' => [
                'since' => $since->toIso8601String(),
                'count' => count($rows),
                'source' => 'analytics_engine',
                'tail_cursor' => $rows[0]['occurred_at'] ?? $since->toIso8601String(),
            ],
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function requestsFromAnalytics(Site $site, Carbon $since, int $limit): array
    {
        $client = EdgeCloudflareClient::fromConfig();
        $dataset = trim((string) config('edge.cloudflare.analytics_dataset', ''));
        $siteId = preg_replace('/[^A-Za-z0-9]/', '', (string) $site->id) ?? '';
        if (! $client->canQueryAnalyticsEngine() || ! preg_match('/^[A-Za-z0-9_]+$/', $dataset) || $siteId === '') {
            return [];
        }

        $sql = sprintf(
            "SELECT timestamp, blob2 AS hostname, blob3 AS method, blob4 AS path, double1 AS status, double2 AS duration_ms, double3 AS bytes_egress, blob5 AS cache_status FROM %s WHERE index1 = '%s' AND timestamp >= toDateTime('%s') ORDER BY timestamp DESC LIMIT %d",
            $dataset,
            $siteId,
            $since->utc()->format('Y-m-d H:i:s'),
            $limit,
        );

        try {
            $rows = $client->queryAnalyticsEngineSql($sql);
        } catch (\Throwable) {
            return [];
        }

        return array_values(array_map(static function (array $row): array {
            $at = $row['timestamp'] ?? null;

            return [
                'occurred_at' => is_string($at) && $at !== '' ? Carbon::parse($at, 'UTC')->toIso8601String() : null,
                'deployment_id' => null,
                'hostname' => (string) ($row['hostname'] ?? ''),
                'method' => (string) ($row['method'] ?? ''),
                'path' => (string) ($row['path'] ?? '/'),
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
}
