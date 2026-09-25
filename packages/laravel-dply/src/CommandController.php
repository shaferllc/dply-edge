<?php

declare(strict_types=1);

namespace Dply\Laravel;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Redis;
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
        if ($action === 'redis-probe') {
            return $this->redisProbe();
        }
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

    /**
     * Time the app's own Redis connection from inside the container: the
     * workspace's Test tab measures from the dply server, this measures what
     * the app actually gets. The first command includes connecting (TLS +
     * AUTH) unless a persistent connection is already open in this worker.
     */
    private function redisProbe(): JsonResponse
    {
        $steps = [];
        $time = static function (string $step, callable $run) use (&$steps): mixed {
            $start = hrtime(true);
            $result = $run();
            $steps[] = ['step' => $step, 'ms' => round((hrtime(true) - $start) / 1e6, 1), 'result' => is_scalar($result) ? (string) $result : 'OK'];

            return $result;
        };

        try {
            $redis = Redis::connection();
            $key = 'dply:probe:'.bin2hex(random_bytes(4));
            $value = bin2hex(random_bytes(8));
            $time('First command (connects if needed)', fn () => $redis->command('ping'));
            $time('SET (expires in 60s)', fn () => $redis->command('set', [$key, $value, 'EX', 60]));
            $read = $time('GET', fn () => $redis->command('get', [$key]));
            $time('DEL', fn () => $redis->command('del', [$key]));
            $pings = [];
            for ($i = 0; $i < 10; $i++) {
                $start = hrtime(true);
                $redis->command('ping');
                $pings[] = round((hrtime(true) - $start) / 1e6, 1);
            }
            sort($pings);
        } catch (Throwable $e) {
            return new JsonResponse(['ok' => false, 'error' => $e->getMessage(), 'steps' => $steps], 200);
        }

        return new JsonResponse([
            'ok' => $read === $value,
            'error' => $read === $value ? null : 'GET returned a different value.',
            'steps' => $steps,
            'ping_median_ms' => $pings[intdiv(count($pings), 2)],
            'ping_max_ms' => max($pings),
            'client' => (string) config('database.redis.client'),
            'persistent' => (bool) config('database.redis.options.persistent', false),
            'region' => (string) (getenv('CLOUDFLARE_REGION') ?: getenv('CLOUDFLARE_LOCATION') ?: ''),
        ]);
    }
}
