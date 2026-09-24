<?php

declare(strict_types=1);

namespace App\Modules\Edge\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Modules\Edge\Support\EdgeAnalyticsEngineTraffic;
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

        $rows = app(EdgeAnalyticsEngineTraffic::class)->recent($site, $since, $limit);

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
}
