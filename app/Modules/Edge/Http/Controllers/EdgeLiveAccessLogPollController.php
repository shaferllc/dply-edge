<?php

declare(strict_types=1);

namespace App\Modules\Edge\Http\Controllers;

use App\Events\Edge\EdgeAccessLogReceived;
use App\Http\Controllers\Controller;
use App\Models\EdgeAccessLog;
use App\Models\Server;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

/**
 * Session-authed poll for the Edge workspace live request tail.
 *
 * Echo/Reverb is the primary push path; this endpoint seeds the table and
 * catches rows when broadcast is down or Logpush wrote to the DB without
 * firing {@see EdgeAccessLogReceived}.
 */
class EdgeLiveAccessLogPollController extends Controller
{
    public function __invoke(Request $request, Server $server, Site $site): JsonResponse
    {
        Gate::authorize('view', $site);

        if ((string) $site->server_id !== (string) $server->id) {
            abort(404);
        }

        if (! $site->usesEdgeRuntime()) {
            abort(404, 'Not an Edge site.');
        }

        $sinceRaw = trim((string) $request->query('since', ''));
        $since = $sinceRaw !== ''
            ? Carbon::parse($sinceRaw)
            : now()->subMinutes(15);
        $limit = min(200, max(1, (int) $request->query('limit', 50)));

        $rows = EdgeAccessLog::query()
            ->where('site_id', $site->id)
            ->where('occurred_at', '>', $since)
            ->orderByDesc('occurred_at')
            ->limit($limit)
            ->get();

        return response()->json([
            'data' => $rows->map(fn (EdgeAccessLog $row) => [
                'occurred_at' => $row->occurred_at?->toIso8601String(),
                'deployment_id' => $row->edge_deployment_id,
                'hostname' => $row->hostname,
                'method' => $row->method,
                'path' => $row->path,
                'status' => $row->status_code,
                'duration_ms' => $row->duration_ms,
                'bytes_egress' => $row->bytes_egress,
                'cache_status' => $row->cache_status,
                'country' => $row->country,
                'referrer' => $row->referrer,
                'user_agent' => $row->user_agent,
            ])->values(),
            'meta' => [
                'since' => $since->toIso8601String(),
                'count' => $rows->count(),
                'tail_cursor' => $rows->first()?->occurred_at?->toIso8601String()
                    ?? $since->toIso8601String(),
            ],
        ]);
    }
}
