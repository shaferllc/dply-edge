<?php

declare(strict_types=1);

namespace App\Modules\Edge\Console;

use App\Modules\Edge\Services\EdgeRedisUsageCollector;
use Illuminate\Console\Command;

/**
 * Scheduled from DplySchedule. Calls EdgeRedisUsageCollector.
 * User request: "ok so how can we implement upstash and bill for it".
 */
class CollectEdgeRedisUsageCommand extends Command
{
    protected $signature = 'dply:edge:collect-redis-usage
                            {--date= : UTC date (Y-m-d); defaults to yesterday}
                            {--today : Collect today (UTC) so far}
                            {--dry-run : Report without writing}';

    protected $description = 'Collect Redis command, storage, and bandwidth usage for billing.';

    public function handle(EdgeRedisUsageCollector $collector): int
    {
        $date = match (true) {
            (bool) $this->option('today') => now()->startOfDay(),
            is_string($this->option('date')) && $this->option('date') !== '' => now()->parse((string) $this->option('date'))->startOfDay(),
            default => now()->subDay()->startOfDay(),
        };

        $result = $collector->collectForDate($date, (bool) $this->option('dry-run'));
        $this->info(sprintf('%s Redis usage for %s — %d app(s).', $this->option('dry-run') ? '[dry-run]' : 'Collected', $date->toDateString(), $result['sites']));

        return self::SUCCESS;
    }
}
