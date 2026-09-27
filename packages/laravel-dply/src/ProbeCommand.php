<?php

declare(strict_types=1);

namespace Dply\Laravel;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Where this container runs and how far its data is: median round trips to
 * the app's database and, when the queue is on Redis, to Redis. Queue workers
 * run it once at start (the line is read back from the logs), since a worker
 * has no HTTP endpoint to ask.
 *
 *   php artisan dply:probe --prefix="[dply-worker worker-0]"
 */
class ProbeCommand extends Command
{
    protected $signature = 'dply:probe {--prefix= : Text to start the line with}';

    protected $description = 'Report this container\'s location and its round trips to the database and Redis.';

    public function handle(): int
    {
        $out = [
            'location' => strtolower((string) (getenv('CLOUDFLARE_LOCATION') ?: '')),
            'region' => (string) (getenv('CLOUDFLARE_REGION') ?: ''),
        ];

        try {
            if (config('database.default') !== 'sqlite') {
                $db = DB::connection();
                $out['db_ms'] = $this->median(fn () => $db->select('select 1'));
            }
        } catch (Throwable) {
            // No database here, or not reachable: leave it out.
        }

        try {
            $queue = (array) config('queue.connections.'.config('queue.default'), []);
            if (($queue['driver'] ?? '') === 'redis') {
                $redis = Redis::connection($queue['connection'] ?? 'default');
                $out['redis_ms'] = $this->median(fn () => $redis->command('ping'));
            }
        } catch (Throwable) {
            // Same for Redis.
        }

        $this->line(trim((string) $this->option('prefix').' probe '.json_encode($out)));

        return self::SUCCESS;
    }

    /** Median of five timed calls after one warm-up (which may connect). */
    private function median(callable $call): float
    {
        $call();
        $times = [];
        for ($i = 0; $i < 5; $i++) {
            $start = hrtime(true);
            $call();
            $times[] = round((hrtime(true) - $start) / 1e6, 1);
        }
        sort($times);

        return $times[2];
    }
}
