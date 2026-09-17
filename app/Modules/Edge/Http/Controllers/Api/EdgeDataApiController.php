<?php

declare(strict_types=1);

namespace App\Modules\Edge\Http\Controllers\Api;

use App\Models\EdgeDatabase;
use App\Models\EdgeQueue;
use App\Modules\Providers\Cloudflare\EdgeCloudflareClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * D1 databases and Queues for API tokens / the CLI: list, run SQL, send a
 * message. Creating and deleting stays in the dashboard.
 */
class EdgeDataApiController extends EdgeApiController
{
    public function databases(Request $request): JsonResponse
    {
        return response()->json(['data' => EdgeDatabase::query()
            ->where('organization_id', $this->organization($request)->id)
            ->orderBy('name')
            ->get()
            ->map(fn (EdgeDatabase $db) => ['id' => $db->id, 'name' => $db->name, 'cloudflare_id' => $db->cloudflare_id, 'created_at' => $db->created_at?->toIso8601String()])]);
    }

    public function query(Request $request, string $database): JsonResponse
    {
        $db = EdgeDatabase::query()
            ->where('organization_id', $this->organization($request)->id)
            ->where(fn ($q) => $q->where('id', $database)->orWhere('name', $database))
            ->first();
        if ($db === null) {
            return $this->notFound('Database not found.');
        }

        $data = $request->validate(['sql' => ['required', 'string', 'max:100000'], 'params' => ['array']]);

        try {
            return response()->json(['data' => EdgeCloudflareClient::fromConfig()->queryD1($db->cloudflare_id, $data['sql'], array_values($data['params'] ?? []))]);
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function queues(Request $request): JsonResponse
    {
        return response()->json(['data' => EdgeQueue::query()
            ->where('organization_id', $this->organization($request)->id)
            ->orderBy('name')
            ->get()
            ->map(fn (EdgeQueue $queue) => ['id' => $queue->id, 'name' => $queue->name, 'cloudflare_name' => $queue->cloudflare_name])]);
    }

    public function send(Request $request, string $queue): JsonResponse
    {
        $model = EdgeQueue::query()
            ->where('organization_id', $this->organization($request)->id)
            ->where(fn ($q) => $q->where('id', $queue)->orWhere('name', $queue))
            ->first();
        if ($model === null) {
            return $this->notFound('Queue not found.');
        }

        $data = $request->validate(['body' => ['required']]);

        try {
            EdgeCloudflareClient::fromConfig()->sendQueueMessage($model->cloudflare_id, $data['body']);
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => ['queued' => true]], 202);
    }
}
