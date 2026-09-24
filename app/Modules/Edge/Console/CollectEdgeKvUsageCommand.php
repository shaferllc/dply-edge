<?php

declare(strict_types=1);

namespace App\Modules\Edge\Console;

use App\Modules\Edge\Services\EdgeKvUsageCollector;
use Illuminate\Console\Command;

/**
 * Scheduled from DplySchedule. Calls EdgeKvUsageCollector.
 * User request: "continue buuiikding out key value".
 */
class CollectEdgeKvUsageCommand extends Command
{
    protected $signature = 'dply:edge:collect-kv-usage
                            {--date= : UTC date (Y-m-d); defaults to yesterday}
                            {--today : Collect today (UTC) so far}
                            {--dry-run : Report without writing}';

    protected $description = 'Collect key-value read, write, and storage usage for billing.';

    public function handle(EdgeKvUsageCollector $collector): int
    {
        $date = match (true) {
            (bool) $this->option('today') => now()->startOfDay(),
            is_string($this->option('date')) && $this->option('date') !== '' => now()->parse((string) $this->option('date'))->startOfDay(),
            default => now()->subDay()->startOfDay(),
        };

        $result = $collector->collectForDate($date, (bool) $this->option('dry-run'));
        $this->info(sprintf('%s key-value usage for %s — %d app(s).', $this->option('dry-run') ? '[dry-run]' : 'Collected', $date->toDateString(), $result['sites']));

        return self::SUCCESS;
    }
}
