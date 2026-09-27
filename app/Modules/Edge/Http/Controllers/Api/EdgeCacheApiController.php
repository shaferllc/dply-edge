<?php

declare(strict_types=1);

namespace App\Modules\Edge\Http\Controllers\Api;

use App\Modules\Edge\Services\EdgeCachePurger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class EdgeCacheApiController extends EdgeApiController
{
    protected const WRITE_ABILITY = 'deploy';

    public function purge(Request $request, string $site): JsonResponse
    {
        $found = $this->findEdgeSite($request, $site);
        if ($found === null) {
            return $this->notFound();
        }

        try {
            $data = $request->validate([
                'tag' => ['nullable', 'string', 'max:128', 'regex:/^[A-Za-z0-9._-]+$/'],
                'paths' => ['nullable', 'array', 'max:100'],
                'paths.*' => ['string', 'max:2048'],
            ]);
        } catch (ValidationException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], 422);
        }

        $tag = isset($data['tag']) ? trim((string) $data['tag']) : '';
        $paths = isset($data['paths']) && is_array($data['paths']) ? $data['paths'] : [];

        if ($tag === '' && $paths === []) {
            return response()->json([
                'message' => 'Either `tag` or `paths` is required.',
                'errors' => ['tag' => ['Either `tag` or `paths` is required.']],
            ], 422);
        }

        $purger = app(EdgeCachePurger::class);
        $fresh = $found->fresh();

        if ($tag !== '') {
            $result = $purger->purgeByTag($fresh, $tag);
            if ($result['ok'] && $fresh->organization_id) {
                audit_log($fresh->organization, $request->user(), 'site.edge.cache.purge_tag', $fresh, null, ['tag' => $tag]);
            }
        } else {
            $result = $purger->purgeByPaths($fresh, $paths);
            if ($result['ok'] && $fresh->organization_id) {
                audit_log($fresh->organization, $request->user(), 'site.edge.cache.purge_paths', $fresh, null, ['paths' => array_values($paths)]);
            }
        }

        return response()->json([
            'data' => [
                'ok' => $result['ok'],
                'purged_keys' => $result['purged_keys'],
                'message' => $result['message'],
            ],
        ], $result['ok'] ? 200 : 422);
    }
}
