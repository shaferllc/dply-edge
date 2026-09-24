<?php

declare(strict_types=1);

namespace App\Modules\Edge\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\EdgeAccessLog;
use App\Models\Site;
use App\Modules\Edge\Support\EdgeAccessLogQuery;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * In-product "Download CSV" for the Edge live request tail. Session-authed
 * + Gate-checked so the workspace button doesn't require minting an API
 * token. Honors the same ?since/?status/?method/?path filters as the
 * public API so the downloaded file matches the on-screen tail.
 */
class EdgeLogCsvDownloadController extends Controller
{
    public function __invoke(Request $request, Site $site): StreamedResponse
    {
        Gate::authorize('view', $site);

        if (! $site->usesEdgeRuntime()) {
            abort(404, 'Not an Edge site.');
        }

        $query = EdgeAccessLogQuery::build($request, $site, chronological: true);

        $filename = sprintf(
            'dply-edge-logs-%s-%s.csv',
            preg_replace('/[^a-z0-9-]+/i', '-', (string) $site->name) ?: 'site',
            now()->format('Ymd-His'),
        );

        return new StreamedResponse(function () use ($query): void {
            $handle = fopen('php://output', 'wb');
            if ($handle === false) {
                return;
            }

            fputcsv($handle, [
                'occurred_at',
                'method',
                'path',
                'status',
                'duration_ms',
                'bytes_egress',
                'cache_status',
                'country',
                'deployment_id',
            ]);

            $query->chunkById(500, function ($chunk) use ($handle): void {
                /** @var Collection<int, EdgeAccessLog> $chunk */
                foreach ($chunk as $log) {
                    fputcsv($handle, [
                        $log->occurred_at->toIso8601String(),
                        (string) $log->method,
                        (string) $log->path,
                        (string) $log->status_code,
                        (string) (int) $log->duration_ms,
                        (string) (int) $log->bytes_egress,
                        (string) ($log->cache_status ?? ''),
                        (string) ($log->country ?? ''),
                        (string) ($log->edge_deployment_id ?? ''),
                    ]);
                }
                if (function_exists('flush')) {
                    @flush();
                }
            });

            fclose($handle);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'no-store, max-age=0',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
