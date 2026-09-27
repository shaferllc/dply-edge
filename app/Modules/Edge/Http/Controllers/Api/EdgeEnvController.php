<?php

declare(strict_types=1);

namespace App\Modules\Edge\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EdgeSiteEnvVar;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Public HTTP API for managing per-site Edge env vars.
 *
 * GET    /api/v1/edge/sites/{site}/env           list keys (never values)
 * PUT    /api/v1/edge/sites/{site}/env           bulk replace
 * PATCH  /api/v1/edge/sites/{site}/env/{key}     set single value
 * DELETE /api/v1/edge/sites/{site}/env/{key}     remove
 */
class EdgeEnvController extends Controller
{
    public function index(Request $request, Site $site): JsonResponse
    {
        if (($resp = $this->authorizeSite($request, $site)) !== null) {
            return $resp;
        }

        return $this->keysResponse($site);
    }

    public function bulkUpdate(Request $request, Site $site): JsonResponse
    {
        if (($resp = $this->authorizeSite($request, $site)) !== null) {
            return $resp;
        }

        $payload = $request->json()->all();

        $errors = [];
        $normalized = [];
        foreach ($payload as $key => $value) {
            $strKey = (string) $key;
            $reason = EdgeSiteEnvVar::rejectionReason($strKey);
            if ($reason !== null) {
                $errors[$strKey] = $reason;

                continue;
            }
            if (! is_scalar($value) && $value !== null) {
                $errors[$strKey] = 'Value must be a string or scalar.';

                continue;
            }
            $normalized[$strKey] = (string) ($value ?? '');
        }

        if ($errors !== []) {
            return response()->json(['message' => 'Invalid env payload.', 'errors' => $errors], 422);
        }

        $userId = $this->resolveUserId($request);

        DB::transaction(function () use ($site, $normalized, $userId): void {
            $existing = $site->edgeEnvVars()
                ->where('scope', EdgeSiteEnvVar::SCOPE_PRODUCTION)
                ->get()
                ->keyBy('key');

            foreach ($normalized as $key => $value) {
                $row = $existing[$key] ?? null;
                if ($row === null) {
                    $row = new EdgeSiteEnvVar([
                        'site_id' => $site->id,
                        'key' => $key,
                        'value' => $value,
                        'scope' => EdgeSiteEnvVar::SCOPE_PRODUCTION,
                        'created_by_user_id' => $userId,
                    ]);
                    $row->save();
                } else {
                    $row->value = $value;
                    $row->created_by_user_id = $userId ?? $row->created_by_user_id;
                    $row->save();
                }
            }

            $toDelete = array_diff($existing->keys()->all(), array_keys($normalized));
            if ($toDelete !== []) {
                $site->edgeEnvVars()
                    ->where('scope', EdgeSiteEnvVar::SCOPE_PRODUCTION)
                    ->whereIn('key', $toDelete)
                    ->delete();
            }
        });

        return $this->keysResponse($site);
    }

    private function keysResponse(Site $site): JsonResponse
    {
        $rows = $site->edgeEnvVars()
            ->where('scope', EdgeSiteEnvVar::SCOPE_PRODUCTION)
            ->get(['id', 'key', 'updated_at', 'created_at']);

        return response()->json([
            'data' => $rows->map(fn (EdgeSiteEnvVar $v) => [
                'key' => $v->key,
                'updated_at' => $v->updated_at->toIso8601String(),
                'created_at' => $v->created_at->toIso8601String(),
            ])->all(),
        ]);
    }

    public function upsert(Request $request, Site $site, string $key): JsonResponse
    {
        if (($resp = $this->authorizeSite($request, $site)) !== null) {
            return $resp;
        }

        $reason = EdgeSiteEnvVar::rejectionReason($key);
        if ($reason !== null) {
            return response()->json(['message' => $reason], 422);
        }

        $body = $request->json()->all();
        $value = $body['value'] ?? null;
        if (! is_scalar($value) && $value !== null) {
            return response()->json(['message' => 'Body must include a string `value`.'], 422);
        }

        $userId = $this->resolveUserId($request);

        $row = $site->edgeEnvVars()
            ->where('scope', EdgeSiteEnvVar::SCOPE_PRODUCTION)
            ->where('key', $key)
            ->first();

        if ($row === null) {
            $row = new EdgeSiteEnvVar([
                'site_id' => $site->id,
                'key' => $key,
                'value' => (string) ($value ?? ''),
                'scope' => EdgeSiteEnvVar::SCOPE_PRODUCTION,
                'created_by_user_id' => $userId,
            ]);
            $row->save();
        } else {
            $row->value = (string) ($value ?? '');
            if ($userId !== null) {
                $row->created_by_user_id = $userId;
            }
            $row->save();
        }

        return response()->json([
            'data' => [
                'key' => $row->key,
                'updated_at' => $row->updated_at->toIso8601String(),
                'created_at' => $row->created_at->toIso8601String(),
            ],
        ]);
    }

    public function destroy(Request $request, Site $site, string $key): JsonResponse
    {
        if (($resp = $this->authorizeSite($request, $site)) !== null) {
            return $resp;
        }

        $deleted = $site->edgeEnvVars()
            ->where('scope', EdgeSiteEnvVar::SCOPE_PRODUCTION)
            ->where('key', $key)
            ->delete();

        return response()->json(['deleted' => (int) $deleted]);
    }

    /**
     * Edge sites are organization-scoped (no required server). Return a 403
     * JSON response if the site doesn't belong to the bearer token's org,
     * or null if the request is allowed to proceed.
     */
    private function authorizeSite(Request $request, Site $site): ?JsonResponse
    {
        $organization = $request->attributes->get('api_organization');
        if ($organization === null) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if ((string) $site->organization_id !== (string) $organization->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if (! $site->usesEdgeRuntime()) {
            return response()->json(['message' => 'Site is not an Edge site.'], 422);
        }

        EdgeApiController::authorizeTokenUser($request, $site);

        return null;
    }

    private function resolveUserId(Request $request): ?string
    {
        $user = $request->user();
        if ($user !== null) {
            return (string) $user->getKey();
        }

        $token = $request->attributes->get('api_token');
        if (is_object($token)) {
            $tokenUserId = $token->user_id ?? null;
            if ($tokenUserId !== null) {
                return (string) $tokenUserId;
            }
        }

        return null;
    }
}
