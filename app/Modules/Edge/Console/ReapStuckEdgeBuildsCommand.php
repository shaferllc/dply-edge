<?php

declare(strict_types=1);

namespace App\Modules\Edge\Console;

use App\Modules\Edge\Actions\CancelStuckEdgeDeployment;
use Illuminate\Console\Command;

class ReapStuckEdgeBuildsCommand extends Command
{
    protected $signature = 'dply:edge:reap-stuck-builds';

    protected $description = 'Fail Edge builds whose worker died, and free their build slot.';

    public function handle(CancelStuckEdgeDeployment $cancel): int
    {
        $this->info(sprintf('Reaped %d stuck build(s).', $cancel->reapStuck()));

        return self::SUCCESS;
    }
}
