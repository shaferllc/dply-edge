<?php

declare(strict_types=1);

namespace Dply\Laravel;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Throwable;

/**
 * Allowlisted database commands from the workspace. The Worker checks the
 * token first; this checks it again.
 */
class CommandController
{
    /** @var array<string, array{0: string, 1: array<string, bool>}> */
    private const COMMANDS = [
        'migrate' => ['migrate', ['--force' => true, '--isolated' => true]],
        'status' => ['migrate:status', []],
        'seed' => ['db:seed', ['--force' => true]],
        'rollback' => ['migrate:rollback', ['--force' => true]],
    ];

    public function __invoke(Request $request): JsonResponse
    {
        $token = (string) config('queue.connections.dply.token');
        if ($token === '' || ! hash_equals($token, (string) $request->header('x-dply-queue-token'))) {
            return new JsonResponse(['error' => 'Forbidden'], 403);
        }

        $action = (string) $request->input('command', '');
        $command = self::COMMANDS[$action] ?? null;
        if ($command === null) {
            return new JsonResponse(['error' => 'Unknown command.'], 422);
        }

        @set_time_limit(0);

        try {
            $exit = Artisan::call($command[0], $command[1]);
        } catch (Throwable $e) {
            report($e);

            return new JsonResponse(['command' => $command[0], 'error' => $e->getMessage()], 500);
        }

        return new JsonResponse([
            'command' => $command[0],
            'exit' => $exit,
            'output' => mb_substr(Artisan::output(), -4000),
        ], $exit === 0 ? 200 : 500);
    }
}
