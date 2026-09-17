<?php

declare(strict_types=1);

namespace App\Modules\Edge\Console;

use App\Modules\Edge\Services\Containers\EdgeContainerUsageCollector;
use Illuminate\Console\Command;

/**
 * Collect container compute usage (vCPU / memory / disk seconds) per site.
 *
 *   php artisan dply:edge:collect-container-usage            # yesterday
 *   php artisan dply:edge:collect-container-usage --today
 *   php artisan dply:edge:collect-container-usage --date=2026-09-15 --dry-run
 */
class CollectEdgeContainerUsageCommand extends Command
{
    protected $signature = 'dply:edge:collect-container-usage
                            {--date= : UTC date (Y-m-d); defaults to yesterday}
                            {--today : Collect today (UTC) so far}
                            {--dry-run : Report without writing}';

    protected $description = 'Collect Cloudflare Containers compute usage for per-minute billing.';

    public function handle(EdgeContainerUsageCollector $collector): int
    {
        $date = match (true) {
            (bool) $this->option('today') => now()->startOfDay(),
            is_string($this->option('date')) && $this->option('date') !== '' => now()->parse((string) $this->option('date'))->startOfDay(),
            default => now()->subDay()->startOfDay(),
        };

        $result = $collector->collectForDate($date, (bool) $this->option('dry-run'));
        $this->info(sprintf('%s container usage for %s — %d site(s) from %d application(s).',
            $this->option('dry-run') ? '[dry-run]' : 'Collected', $date->toDateString(), $result['sites'], $result['applications']));

        return self::SUCCESS;
    }
}
