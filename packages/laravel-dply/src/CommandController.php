<?php

declare(strict_types=1);

namespace Dply\Laravel;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
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
        if ($action === 'db-probe') {
            return $this->databaseProbe();
        }
        if ($action === 'resources') {
            return $this->containerResources();
        }
        if ($action === 'queue-test') {
            return $this->queueTest((int) $request->input('count', 1), (string) $request->input('queue', ''));
        }
        if ($action === 'queue-size') {
            return $this->queueSizes((string) $request->input('connection', ''), array_values(array_filter(array_map('strval', (array) $request->input('queues', ['default'])))));
        }
        if (in_array($action, ['failed-jobs', 'retry', 'forget', 'flush-failed'], true)) {
            return $this->failedJobs($action, array_values(array_filter(array_map('strval', (array) $request->input('ids', [])))));
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
     * Queue `php artisan inspire` jobs through the app's own dispatcher, on
     * its default connection: an end-to-end check that workers pick up what
     * the app queues (and, 1000 at a time, a small load test).
     */
    private function queueTest(int $count, string $queue): JsonResponse
    {
        $count = max(1, min(1000, $count));
        try {
            for ($i = 0; $i < $count; $i++) {
                $pending = Artisan::queue('inspire');
                if ($queue !== '') {
                    $pending->onQueue($queue);
                }
            }
        } catch (Throwable $e) {
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }

        return new JsonResponse(['queued' => $count, 'connection' => (string) config('queue.default'), 'queue' => $queue !== '' ? $queue : 'default']);
    }

    /**
     * Jobs waiting per queue on a connection (the app's default when blank),
     * for dply's worker autoscaler. Works for every queue driver.
     *
     * @param  list<string>  $queues
     */
    private function queueSizes(string $connection, array $queues): JsonResponse
    {
        try {
            $queue = Queue::connection($connection !== '' ? $connection : null);
            $sizes = [];
            $oldest = null;
            $delayed = 0;
            foreach ($queues as $name) {
                // Laravel 11+ splits ready from delayed; size() counts both.
                $sizes[$name] = method_exists($queue, 'pendingSize') ? (int) $queue->pendingSize($name) : (int) $queue->size($name);
                $delayed += method_exists($queue, 'delayedSize') ? (int) $queue->delayedSize($name) : 0;
                // Laravel 11+: when the oldest ready job was queued.
                if ($sizes[$name] > 0 && method_exists($queue, 'creationTimeOfOldestPendingJob')) {
                    $created = $queue->creationTimeOfOldestPendingJob($name);
                    if (is_int($created)) {
                        $oldest = min($oldest ?? PHP_INT_MAX, $created);
                    }
                }
            }
        } catch (Throwable $e) {
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }

        return new JsonResponse(['sizes' => $sizes, 'total' => array_sum($sizes), 'oldest_age' => $oldest !== null ? max(0, time() - $oldest) : null, 'delayed' => $delayed]);
    }

    /**
     * Failed jobs from the app's own failed-job store, whatever its driver:
     * list (newest first, 50), retry or forget some (or all), or flush.
     *
     * @param  list<string>  $ids
     */
    private function failedJobs(string $action, array $ids): JsonResponse
    {
        try {
            if ($action === 'retry') {
                $exit = Artisan::call('queue:retry', ['id' => $ids === [] ? ['all'] : $ids]);
            } elseif ($action === 'forget') {
                foreach ($ids as $id) {
                    Artisan::call('queue:forget', ['id' => $id]);
                }
                $exit = 0;
            } elseif ($action === 'flush-failed') {
                $exit = Artisan::call('queue:flush');
            }
            if (isset($exit)) {
                return new JsonResponse(['exit' => $exit, 'output' => mb_substr(Artisan::output(), -4000)], $exit === 0 ? 200 : 500);
            }

            $failer = app('queue.failer');
            $all = $failer->all();
            $jobs = [];
            foreach (array_slice($all, 0, 50) as $job) {
                $job = (array) $job;
                $payload = json_decode((string) ($job['payload'] ?? ''), true);
                $exception = (string) ($job['exception'] ?? '');
                $jobs[] = [
                    'id' => (string) ($job['uuid'] ?? $job['id'] ?? ''),
                    'name' => is_array($payload) ? (string) ($payload['displayName'] ?? $payload['job'] ?? '') : '',
                    'connection' => (string) ($job['connection'] ?? ''),
                    'queue' => (string) ($job['queue'] ?? ''),
                    'failed_at' => (string) ($job['failed_at'] ?? ''),
                    'attempts' => is_array($payload) ? (int) ($payload['attempts'] ?? 0) : 0,
                    'error' => strtok($exception, "\n") ?: '',
                    'trace' => mb_substr($exception, 0, 3000),
                ];
            }
        } catch (Throwable $e) {
            report($e);

            return new JsonResponse(['error' => $e->getMessage()], 500);
        }

        return new JsonResponse(['total' => count($all), 'jobs' => $jobs]);
    }

    /**
     * This container's memory: the high-water mark since it started, what it
     * uses now, and its limit (cgroup v2, else v1). dply samples it from
     * awake apps to suggest a smaller, cheaper size.
     */
    private function containerResources(): JsonResponse
    {
        $read = static function (string ...$paths): ?int {
            foreach ($paths as $path) {
                $value = @file_get_contents($path);
                if (is_string($value) && is_numeric(trim($value))) {
                    return (int) trim($value);
                }
            }

            return null;
        };
        $mb = static fn (?int $bytes): ?float => $bytes === null ? null : round($bytes / 1048576, 1);

        $peak = $mb($read('/sys/fs/cgroup/memory.peak', '/sys/fs/cgroup/memory/memory.max_usage_in_bytes'));
        $now = $mb($read('/sys/fs/cgroup/memory.current', '/sys/fs/cgroup/memory/memory.usage_in_bytes'));
        $limit = $mb($read('/sys/fs/cgroup/memory.max', '/sys/fs/cgroup/memory/memory.limit_in_bytes'));
        $source = 'cgroup';
        // Cloudflare Containers are small VMs without cgroup memory files: the
        // whole VM is the instance, so MemTotal - MemAvailable is what it uses.
        if ($peak === null && $now === null && is_readable('/proc/meminfo')) {
            preg_match_all('/^(MemTotal|MemAvailable):\s+(\d+) kB/m', (string) file_get_contents('/proc/meminfo'), $m, PREG_SET_ORDER);
            $kb = array_column(array_map(static fn ($row) => [$row[1], (int) $row[2]], $m), 1, 0);
            if (isset($kb['MemTotal'], $kb['MemAvailable'])) {
                $now = round(($kb['MemTotal'] - $kb['MemAvailable']) / 1024, 1);
                $limit = round($kb['MemTotal'] / 1024, 1);
                $source = 'meminfo'; // no high-water mark: dply keeps the highest hourly sample
            }
        }

        return new JsonResponse([
            'memory_peak_mb' => $peak ?? $now,
            'memory_now_mb' => $now,
            'memory_limit_mb' => $limit,
            'source' => $source,
        ]);
    }

    /**
     * Time the app's own database connection from inside the container, and
     * say where the container runs: each queue job and most requests make
     * several round trips, so this is what distance costs.
     */
    private function databaseProbe(): JsonResponse
    {
        try {
            $db = DB::connection();
            $start = hrtime(true);
            $db->select('select 1');
            $first = round((hrtime(true) - $start) / 1e6, 1);
            $times = [];
            for ($i = 0; $i < 10; $i++) {
                $start = hrtime(true);
                $db->select('select 1');
                $times[] = round((hrtime(true) - $start) / 1e6, 1);
            }
            sort($times);
        } catch (Throwable $e) {
            return new JsonResponse(['ok' => false, 'error' => $e->getMessage()], 200);
        }

        return new JsonResponse([
            'ok' => true,
            'driver' => $db->getDriverName(),
            'first_ms' => $first,
            'rtt_median_ms' => $times[intdiv(count($times), 2)],
            'rtt_max_ms' => max($times),
            'region' => (string) (getenv('CLOUDFLARE_REGION') ?: ''),
            'location' => (string) (getenv('CLOUDFLARE_LOCATION') ?: ''),
            'country' => (string) (getenv('CLOUDFLARE_COUNTRY_A2') ?: ''),
        ]);
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
