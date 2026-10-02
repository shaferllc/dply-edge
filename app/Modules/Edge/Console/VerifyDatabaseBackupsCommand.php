<?php

declare(strict_types=1);

namespace App\Modules\Edge\Console;

use App\Models\DplyDatabase;
use App\Models\Site;
use App\Modules\Edge\Jobs\VerifyDatabaseBackupJob;
use App\Modules\Edge\Services\EdgeAppDatabase;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;

/**
 * Weekly proof that dply Postgres backups restore (VerifyDatabaseBackupJob).
 * One at a time (a chain on the builder queue), so a builder's disk holds one
 * restored copy. Databases on volumes over MAX_DISK_GB are skipped and say so:
 * the restore would not fit beside the build cache.
 *
 *   php artisan dply:databases:verify-backups [--remote=pg-…] [--dry-run]
 */
class VerifyDatabaseBackupsCommand extends Command
{
    public const MAX_DISK_GB = 20;

    protected $signature = 'dply:databases:verify-backups
        {--remote= : Only the database with this gateway tenant id (pg-…)}
        {--dry-run : List what would be checked}';

    protected $description = 'Restore each dply Postgres database from its backups in a throwaway container and record the result.';

    public function handle(): int
    {
        $jobs = [];
        $only = (string) $this->option('remote');
        $add = function (string $remote, int $diskGb, string $label, ?string $siteId, ?string $databaseId) use (&$jobs, $only): void {
            if ($remote === '' || ($only !== '' && $remote !== $only)) {
                return;
            }
            if ($diskGb > self::MAX_DISK_GB) {
                $this->line("{$label} ({$remote}): skipped, {$diskGb} GB volume is over ".self::MAX_DISK_GB.' GB');

                return;
            }
            $this->line("{$label} ({$remote})");
            $jobs[] = new VerifyDatabaseBackupJob($remote, $label, $siteId, $databaseId);
        };

        // An app's primary dply database lives on the site's meta.
        Site::query()->whereNotNull('edge_backend')->where('meta->edge->database->engine', 'postgres')->each(function (Site $site) use ($add): void {
            $database = (array) ($site->edgeMeta()['database'] ?? []);
            if (EdgeAppDatabase::isDply($database)) {
                $add((string) ($database['remote_id'] ?? ''), (int) ($database['disk_gb'] ?? 0), $site->name, (string) $site->id, null);
            }
        });
        // The rest (another app's extra, or detached) on their own rows.
        DplyDatabase::query()->where('engine', 'postgres')
            ->whereDoesntHave('sites', fn ($q) => $q->where('dply_database_site.primary', true))
            ->each(fn (DplyDatabase $database) => $add($database->remote_id, (int) $database->disk_gb, $database->name, null, (string) $database->id));

        if (! $this->option('dry-run') && $jobs !== []) {
            Bus::chain($jobs)->onQueue(\App\Support\DplyRuntime::BUILDER_QUEUE)->dispatch();
        }
        $this->info(sprintf('%d database(s) %s.', count($jobs), $this->option('dry-run') ? 'would be checked' : 'queued for a restore check'));

        return self::SUCCESS;
    }
}
