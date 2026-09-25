<?php

declare(strict_types=1);

namespace App\Modules\Edge\Console;

use App\Modules\Edge\Services\EdgeValkeyUsageCollector;
use Illuminate\Console\Command;

class CollectEdgeValkeyUsageCommand extends Command
{
    protected $signature = 'dply:edge:collect-valkey-usage {--dry-run : Report without writing}';

    protected $description = 'Add dply Valkey awake seconds since the last run to today\'s usage.';

    public function handle(EdgeValkeyUsageCollector $collector): int
    {
        $result = $collector->collect((bool) $this->option('dry-run'));
        $this->info(sprintf('%s %d awake second(s) across %d app(s).', $this->option('dry-run') ? '[dry-run]' : 'Collected', $result['seconds'], $result['sites']));

        return self::SUCCESS;
    }
}
