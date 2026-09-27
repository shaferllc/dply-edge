<?php

declare(strict_types=1);

namespace App\Modules\Edge\Http\Controllers;

use App\Models\Site;
use App\Modules\Edge\Support\EdgeMeter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Reports and checks from the AI / browser / vector search proxy (EdgeMeter::JS).
 * The body is HMAC-signed with the app's key (EdgeMeter::key). A leaked key
 * can only add usage to its own app or read its org's verdict. The answer is
 * the verdict: which services the proxy must refuse, and why.
 */
final class EdgeMeterIngestController
{
    private const MAX_BODY_BYTES = 32 * 1024;

    public function __invoke(Request $request, Site $site): JsonResponse
    {
        if (($site->edge_backend ?? '') !== 'dply_edge') {
            return response()->json(['ok' => false, 'error' => 'not_edge'], 404);
        }
        $raw = $request->getContent();
        if (strlen($raw) > self::MAX_BODY_BYTES) {
            return response()->json(['ok' => false, 'error' => 'too_large'], 413);
        }
        $signature = (string) $request->header('X-Dply-Meter-Signature', '');
        if ($signature === '' || ! hash_equals(hash_hmac('sha256', $raw, EdgeMeter::key($site)), $signature)) {
            return response()->json(['ok' => false, 'error' => 'invalid_signature'], 401);
        }
        $payload = json_decode($raw, true);
        $at = is_array($payload) && is_string($payload['at'] ?? null) ? strtotime($payload['at']) : false;
        // Signed bodies are replayable; bound the window.
        if ($at === false || abs(now()->getTimestamp() - $at) > 600) {
            return response()->json(['ok' => false, 'error' => 'stale'], 422);
        }

        $usage = is_array($payload['usage'] ?? null) ? $payload['usage'] : [];
        $neurons = 0.0;
        foreach (array_slice(is_array($usage['ai'] ?? null) ? $usage['ai'] : [], 0, 100) as $call) {
            if (is_array($call)) {
                $neurons += EdgeMeter::neurons(
                    mb_substr((string) ($call['model'] ?? ''), 0, 120),
                    min(10_000_000, max(0, (int) ($call['in'] ?? 0))),
                    min(10_000_000, max(0, (int) ($call['out'] ?? 0))),
                );
            }
        }
        EdgeMeter::record($site, [
            'ai_neurons' => $neurons,
            // One report is at most a day of browser time or 100k dimensions.
            'browser_ms' => min(86_400_000, max(0, (int) ($usage['browser_ms'] ?? 0))),
            'vector_query_dims' => min(100_000, max(0, (int) ($usage['vector_dims'] ?? 0))),
        ]);

        return response()->json(EdgeMeter::verdict($site->organization));
    }
}
