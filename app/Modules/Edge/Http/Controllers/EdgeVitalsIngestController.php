<?php

declare(strict_types=1);

namespace App\Modules\Edge\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\EdgeWebVital;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Receives Core Web Vitals beacons forwarded from the Edge worker RUM script.
 */
class EdgeVitalsIngestController extends Controller
{
    private const MAX_PATH = 2048;

    public function __invoke(Request $request, Site $site): JsonResponse
    {
        if (! $site->usesEdgeRuntime()) {
            return response()->json(['message' => 'Not an Edge site.'], 404);
        }

        $key = trim((string) config('edge.log_ingest.key', ''));
        $signature = (string) $request->header('X-Dply-Signature', '');
        $expected = $key !== ''
            ? hash_hmac('sha256', $site->id.'.'.$request->getContent(), $key)
            : '';

        if ($key === '' || $signature === '' || ! hash_equals($expected, $signature)) {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $cls = $request->input('cls');
        $clsValue = is_numeric($cls) ? round((float) $cls, 4) : null;

        EdgeWebVital::query()->create([
            'organization_id' => $site->organization_id,
            'site_id' => $site->id,
            'edge_deployment_id' => Str::limit((string) $request->input('deployment_id', ''), 26, '') ?: null,
            'hostname' => strtolower(Str::limit((string) $request->input('hostname', ''), 255, '')),
            'path' => Str::limit('/'.ltrim((string) $request->input('path', '/'), '/'), self::MAX_PATH, ''),
            'lcp_ms' => $this->nullablePositiveInt($request->input('lcp_ms')),
            'cls' => $clsValue,
            'inp_ms' => $this->nullablePositiveInt($request->input('inp_ms')),
            'fcp_ms' => $this->nullablePositiveInt($request->input('fcp_ms')),
            'ttfb_ms' => $this->nullablePositiveInt($request->input('ttfb_ms')),
            'country' => Str::limit((string) $request->input('country', ''), 8, '') ?: null,
            'source' => 'browser',
            'occurred_at' => Carbon::parse((string) $request->input('occurred_at', now()->toIso8601String())),
        ]);

        return response()->json(['message' => 'Recorded.'], 202);
    }

    private function nullablePositiveInt(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $int = (int) $value;

        return $int > 0 ? $int : null;
    }
}
