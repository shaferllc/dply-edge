<?php

declare(strict_types=1);

namespace App\Modules\Edge\Console;

use App\Modules\Edge\Services\EdgePlatformUsageCollector;
use Illuminate\Console\Command;

/**
 * Scheduled from DplySchedule. Calls EdgePlatformUsageCollector. Fails when
 * any Cloudflare dataset fails, so a broken query is not silent lost revenue.
 */
class CollectEdgePlatformUsageCommand extends Command
{
    protected $signature = 'dply:edge:collect-platform-usage
                            {--date= : UTC date (Y-m-d); defaults to yesterday}
                            {--today : Collect today (UTC) so far}
                            {--dry-run : Report without writing}';

    protected $description = 'Collect Workers CPU, Durable Object and customer object-storage usage for billing.';

    public function handle(EdgePlatformUsageCollector $collector): int
    {
        $date = match (true) {
            (bool) $this->option('today') => now()->utc()->startOfDay(),
            is_string($this->option('date')) && $this->option('date') !== '' => now()->utc()->parse((string) $this->option('date'))->startOfDay(),
            default => now()->utc()->subDay()->startOfDay(),
        };

        $result = $collector->collectForDate($date, (bool) $this->option('dry-run'));
        $this->info(sprintf('%s platform usage for %s — %d script(s)/bucket(s).', $this->option('dry-run') ? '[dry-run]' : 'Collected', $date->toDateString(), $result['resources']));
        if ($result['failed'] !== []) {
            $this->error('Failed datasets: '.implode(', ', $result['failed']).' (see the log).');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
