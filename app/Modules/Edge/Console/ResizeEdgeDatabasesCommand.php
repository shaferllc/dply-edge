<?php

declare(strict_types=1);

namespace App\Modules\Edge\Console;

use App\Models\Site;
use App\Modules\Edge\Support\EdgeDatabaseResize;
use Illuminate\Console\Command;

/**
 * Runs the database resizes someone approved for "tonight" in the database
 * sheet, once their time comes (EdgeDatabaseResize::schedule). Each sends
 * edge.database.resized or resize_failed.
 *
 *   php artisan dply:edge:resize-databases
 */
class ResizeEdgeDatabasesCommand extends Command
{
    protected $signature = 'dply:edge:resize-databases';

    protected $description = 'Run approved, scheduled dply database resizes that are due.';

    public function handle(): int
    {
        $sites = Site::query()->whereNotNull('meta->edge->database->resize_scheduled')->get();
        foreach ($sites as $site) {
            $scheduled = $site->edgeMeta()['database']['resize_scheduled'] ?? null;
            if (! is_array($scheduled) || (int) ($scheduled['at'] ?? PHP_INT_MAX) > now()->getTimestamp()) {
                continue;
            }
            $error = EdgeDatabaseResize::apply($site, (string) $scheduled['size']);
            $this->line($site->name.': '.($error ?? 'resized to '.$scheduled['size']));
        }

        return self::SUCCESS;
    }
}
