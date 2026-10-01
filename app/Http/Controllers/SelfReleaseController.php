<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Throwable;

/**
 * dply's own answer to the deploy's release step (EdgeContainerDeployer::runRelease):
 * customer Laravel apps get POST /_dply/command from the dply/laravel package,
 * which dply itself does not install, so without this a push to main deployed
 * new code against an unmigrated database. Only `release` (migrate --force) is
 * answered; the deployer sends it once, to the new version, before the switch.
 */
class SelfReleaseController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $token = (string) config('edge.self.queue_token');
        if ($token === '' || ! hash_equals($token, (string) $request->header('x-dply-queue-token'))) {
            return new JsonResponse(['error' => 'Forbidden'], 403);
        }
        if ($request->input('command') !== 'release') {
            return new JsonResponse(['error' => 'Unknown command'], 422);
        }

        set_time_limit(0);
        try {
            $code = Artisan::call('migrate', ['--force' => true]);
        } catch (Throwable $e) {
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }

        return new JsonResponse(['output' => Artisan::output()], $code === 0 ? 200 : 500);
    }
}
