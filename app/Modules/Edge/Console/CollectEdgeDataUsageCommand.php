<?php

declare(strict_types=1);

namespace App\Modules\Edge\Console;

use App\Modules\Edge\Services\EdgeDataUsageCollector;
use Illuminate\Console\Command;

/**
 * Collect D1 and Queues usage per organization.
 *
 *   php artisan dply:edge:collect-data-usage            # yesterday
 *   php artisan dply:edge:collect-data-usage --today
 */
class CollectEdgeDataUsageCommand extends Command
{
    protected $signature = 'dply:edge:collect-data-usage
                            {--date= : UTC date (Y-m-d); defaults to yesterday}
                            {--today : Collect today (UTC) so far}
                            {--dry-run : Report without writing}';

    protected $description = 'Collect Cloudflare D1 and Queues usage for billing.';

    public function handle(EdgeDataUsageCollector $collector): int
    {
        $date = match (true) {
            (bool) $this->option('today') => now()->startOfDay(),
            is_string($this->option('date')) && $this->option('date') !== '' => now()->parse((string) $this->option('date'))->startOfDay(),
            default => now()->subDay()->startOfDay(),
        };

        $result = $collector->collectForDate($date, (bool) $this->option('dry-run'));
        $this->info(sprintf('%s D1/Queues usage for %s — %d organization(s).', $this->option('dry-run') ? '[dry-run]' : 'Collected', $date->toDateString(), $result['organizations']));

        return self::SUCCESS;
    }
}
