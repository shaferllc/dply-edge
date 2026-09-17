<?php

declare(strict_types=1);

namespace App\Modules\Edge\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Server;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Per-site audit-log export. Mirrors the on-screen panel
 * (livewire/sites/partials/edge/audit-log.blade.php) but streams the
 * full history without the 100-row cap. CSV by default; JSON when
 * `?format=json` is passed.
 */
class EdgeAuditLogExportController extends Controller
{
    public function __invoke(Request $request, Server $server, Site $site): StreamedResponse|JsonResponse
    {
        Gate::authorize('view', $site);

        if ((string) $site->server_id !== (string) $server->id) {
            abort(404);
        }

        if (! $site->usesEdgeRuntime()) {
            abort(404, 'Not an Edge site.');
        }

        abort_unless($site->organization?->tierAllowances()['audit_log'] ?? false, 403, 'Audit log export is on the Team plan.');

        $format = strtolower((string) $request->query('format', 'csv'));
        if (! in_array($format, ['csv', 'json'], true)) {
            $format = 'csv';
        }

        $query = AuditLog::query()
            ->with('user:id,name,email')
            ->where(function ($q) use ($site): void {
                $q->where(function ($q2) use ($site): void {
                    $q2->where('subject_type', Site::class)
                        ->where('subject_id', $site->id);
                })->orWhere(function ($q2) use ($site): void {
                    $q2->where('organization_id', $site->organization_id)
                        ->where('subject_id', $site->id)
                        ->where('action', 'like', 'site.edge.%');
                });
            })
            ->orderBy('created_at');

        $stem = preg_replace('/[^a-z0-9-]+/i', '-', (string) $site->name) ?: 'site';
        $filename = sprintf('dply-edge-audit-%s-%s.%s', $stem, now()->format('Ymd-His'), $format);

        if ($format === 'json') {
            $rows = $query->get()->map(fn (AuditLog $e) => [
                'occurred_at' => $e->created_at->toIso8601String(),
                'actor' => $e->user ? ['id' => $e->user->id, 'name' => $e->user->name, 'email' => $e->user->email] : null,
                'action' => $e->action,
                'subject_type' => $e->subject_type,
                'subject_id' => $e->subject_id,
                'old_values' => $e->old_values,
                'new_values' => $e->new_values,
                'ip_address' => $e->ip_address,
            ]);

            return response()->json($rows, 200, [
                'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            ]);
        }

        return new StreamedResponse(function () use ($query): void {
            $handle = fopen('php://output', 'wb');
            if ($handle === false) {
                return;
            }
            fputcsv($handle, [
                'occurred_at', 'actor_name', 'actor_email', 'action',
                'subject_type', 'subject_id', 'old_values_json', 'new_values_json', 'ip_address',
            ]);
            $query->chunkById(500, function ($chunk) use ($handle): void {
                foreach ($chunk as $e) {
                    fputcsv($handle, [
                        $e->created_at->toIso8601String(),
                        (string) ($e->user->name ?? ''),
                        (string) ($e->user->email ?? ''),
                        (string) $e->action,
                        (string) ($e->subject_type ?? ''),
                        (string) ($e->subject_id ?? ''),
                        json_encode($e->old_values, JSON_UNESCAPED_SLASHES),
                        json_encode($e->new_values, JSON_UNESCAPED_SLASHES),
                        (string) ($e->ip_address ?? ''),
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
        ]);
    }
}
