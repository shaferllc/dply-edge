<?php

declare(strict_types=1);

namespace Dply\Laravel;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Throwable;

/**
 * Cron Trigger from the site Worker: runs the handler as an artisan command
 * (`schedule:run` for the scheduler, or e.g. `reports:send --daily`).
 */
class ScheduleController
{
    public function __invoke(Request $request): JsonResponse
    {
        $token = (string) config('queue.connections.dply.token');
        if ($token === '' || ! hash_equals($token, (string) $request->header('x-dply-queue-token'))) {
            return new JsonResponse(['error' => 'Forbidden'], 403);
        }

        $command = trim((string) $request->input('handler', '')) ?: 'schedule:run';
        @set_time_limit(0);

        try {
            $exit = Artisan::call($command);
        } catch (Throwable $e) {
            report($e);

            return new JsonResponse(['command' => $command, 'error' => $e->getMessage()], 500);
        }

        return new JsonResponse(['command' => $command, 'exit' => $exit, 'output' => mb_substr(Artisan::output(), -2000)], $exit === 0 ? 200 : 500);
    }
}
