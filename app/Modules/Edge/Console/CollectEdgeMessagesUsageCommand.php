<?php

declare(strict_types=1);

namespace App\Modules\Edge\Console;

use App\Modules\Edge\Services\Messages\EdgeMessages;
use Illuminate\Console\Command;

/** Scheduled hourly from DplySchedule. Calls EdgeMessages::collectUsage. */
class CollectEdgeMessagesUsageCommand extends Command
{
    protected $signature = 'dply:edge:collect-messages-usage';

    protected $description = 'Collect published dply Messages per organization for billing.';

    public function handle(EdgeMessages $messages): int
    {
        if (! EdgeMessages::configured()) {
            $this->info('Messages is not configured (DPLY_MESSAGES_URL / DPLY_MESSAGES_OPERATOR_TOKEN).');

            return self::SUCCESS;
        }
        $this->info(sprintf('Collected Messages usage for %d organization(s).', $messages->collectUsage()));

        return self::SUCCESS;
    }
}
