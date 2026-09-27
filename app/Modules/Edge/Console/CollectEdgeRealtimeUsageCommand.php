<?php

declare(strict_types=1);

namespace App\Modules\Edge\Console;

use App\Modules\Edge\Services\Realtime\EdgeRealtimeUsageCollector;
use Illuminate\Console\Command;

/** Scheduled hourly from DplySchedule. Calls EdgeRealtimeUsageCollector. */
class CollectEdgeRealtimeUsageCommand extends Command
{
    protected $signature = 'dply:edge:collect-realtime-usage {--dry-run : Report without writing}';

    protected $description = 'Collect Realtime connection time, messages, and peak connections for billing.';

    public function handle(EdgeRealtimeUsageCollector $collector): int
    {
        $result = $collector->collect((bool) $this->option('dry-run'));
        $this->info(sprintf(
            '%s Realtime usage — %d app(s), %d connection-second(s), %d message(s).',
            $this->option('dry-run') ? '[dry-run]' : 'Collected',
            $result['apps'], $result['connection_seconds'], $result['messages'],
        ));

        return self::SUCCESS;
    }
}
