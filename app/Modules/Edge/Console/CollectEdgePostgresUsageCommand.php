<?php

declare(strict_types=1);

namespace App\Modules\Edge\Console;

use App\Modules\Edge\Services\EdgePostgresUsageCollector;
use Illuminate\Console\Command;

/**
 * Scheduled from DplySchedule. Calls EdgePostgresUsageCollector.
 * User request: "ok lets move ahead with imp,emeting neon and postgres first".
 */
class CollectEdgePostgresUsageCommand extends Command
{
    protected $signature = 'dply:edge:collect-postgres-usage
                            {--date= : UTC date (Y-m-d); defaults to yesterday}
                            {--today : Collect today (UTC) so far}
                            {--dry-run : Report without writing}';

    protected $description = 'Collect Postgres compute and storage usage for billing.';

    public function handle(EdgePostgresUsageCollector $collector): int
    {
        $date = match (true) {
            (bool) $this->option('today') => now()->utc()->startOfDay(),
            is_string($this->option('date')) && $this->option('date') !== '' => now()->parse((string) $this->option('date'))->utc()->startOfDay(),
            default => now()->utc()->subDay()->startOfDay(),
        };

        $result = $collector->collectForDate($date, (bool) $this->option('dry-run'));
        $this->info(sprintf('%s Postgres usage for %s — %d app(s).', $this->option('dry-run') ? '[dry-run]' : 'Collected', $date->toDateString(), $result['sites']));

        return self::SUCCESS;
    }
}
