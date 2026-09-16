<?php

declare(strict_types=1);

namespace App\Modules\Edge\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Edge\Services\EdgeLogpushRecordImporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Receives Cloudflare Logpush http_requests batches for Edge zones.
 */
class EdgeLogpushIngestController extends Controller
{
    public function __invoke(Request $request, EdgeLogpushRecordImporter $importer): JsonResponse|Response
    {
        if (! filter_var((string) config('edge.logpush.enabled', false), FILTER_VALIDATE_BOOLEAN)) {
            return response()->json(['message' => 'Logpush ingest disabled.'], 404);
        }

        $secret = trim((string) config('edge.logpush.secret', ''));
        $auth = (string) $request->header('Authorization', '');
        $expected = $secret !== '' ? 'Bearer '.$secret : '';

        if ($secret === '' || $auth === '' || ! hash_equals($expected, $auth)) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $payload = $request->getContent();
        if ($request->header('Content-Encoding') === 'gzip') {
            $decoded = gzdecode($payload);
            if ($decoded === false) {
                return response()->json(['message' => 'Invalid gzip payload.'], 400);
            }
            $payload = $decoded;
        }

        $records = json_decode($payload, true);
        if (! is_array($records)) {
            return response()->json(['message' => 'Expected JSON array of log records.'], 400);
        }

        $result = $importer->import($records);

        return response()->json([
            'message' => 'Processed.',
            'imported' => $result['imported'],
            'skipped' => $result['skipped'],
        ], 202);
    }
}
