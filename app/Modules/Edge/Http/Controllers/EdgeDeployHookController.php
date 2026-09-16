<?php

declare(strict_types=1);

namespace App\Modules\Edge\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\EdgeDeployHook;
use App\Modules\Edge\Actions\RedeployEdgeSite;
use App\Support\ProductLine\ProductLineKillSwitches;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public endpoint for P10b deploy hooks. Accepts POST (recommended)
 * and GET (some CMSes prefer it). On a valid token, fires a
 * production redeploy via {@see RedeployEdgeSite} and records the
 * trigger time on the hook row so operators can audit.
 *
 * No auth middleware — the token IN the URL IS the credential.
 * Rate-limited by IP to slow brute-force attempts.
 */
class EdgeDeployHookController extends Controller
{
    public function __invoke(Request $request, string $token): JsonResponse
    {
        $hook = EdgeDeployHook::resolvePlaintext($token);
        if ($hook === null) {
            return response()->json(['message' => 'Invalid hook token.'], 404);
        }

        $site = $hook->site;
        if ($site === null || ! $site->usesEdgeRuntime() || $site->isEdgePreview()) {
            return response()->json(['message' => 'Deploy hook target is not an active Edge site.'], 422);
        }

        if (ProductLineKillSwitches::blocksEdgeDelivery()) {
            return response()->json(['message' => 'Edge delivery is temporarily disabled.'], 503);
        }

        try {
            $deployment = app(RedeployEdgeSite::class)->handle($site);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Deploy queue failed.',
                'error' => $e->getMessage(),
            ], 500);
        }

        $hook->forceFill([
            'last_used_at' => now(),
            'last_triggered_deployment_id' => $deployment->id,
        ])->save();

        return response()->json([
            'message' => 'Deploy queued.',
            'deployment_id' => $deployment->id,
            'site_id' => (string) $site->id,
        ], 202);
    }
}
