<?php

declare(strict_types=1);

namespace App\Modules\Edge\Console;

use App\Modules\Providers\Cloudflare\EdgeCloudflareClient;
use Illuminate\Console\Command;

/**
 * Puts dply database backups under an R2 bucket lock: for LOCK_DAYS after it
 * is written, a backup object can be neither deleted nor overwritten, by a
 * bug, a bad delete or a leaked key. One rule per engine's tenant prefix
 * (tenants/pg-, tenants/my-, tenants/mg-). Valkey snapshots (tenants/01…)
 * and site files (edge/) are rewritten all the time and stay unlocked.
 * Shorter than the shortest retention (7 days), so pruning old backups still
 * works. Other rules on the bucket are kept: the API replaces the whole set.
 *
 *   php artisan dply:databases:lock-backups [--off] [--dry-run]
 */
class LockDatabaseBackupsCommand extends Command
{
    public const LOCK_DAYS = 5;

    /** Gateway tenant id prefixes (EdgeDplyDatabase::ENGINES). */
    public const PREFIXES = ['pg' => 'tenants/pg-', 'my' => 'tenants/my-', 'mg' => 'tenants/mg-'];

    protected $signature = 'dply:databases:lock-backups
        {--off : Remove the dply database backup lock rules}
        {--dry-run : Show the rules without writing them}';

    protected $description = 'Lock dply database backups in R2 against deletion and overwrite for '.self::LOCK_DAYS.' days.';

    public function handle(): int
    {
        $bucket = trim((string) config('edge.r2.bucket'));
        if ($bucket === '') {
            $this->error('edge.r2.bucket is not set.');

            return self::FAILURE;
        }
        $client = EdgeCloudflareClient::fromConfig();
        $ours = array_map(static fn (string $engine): string => 'dply-db-backups-'.$engine, array_keys(self::PREFIXES));
        $rules = array_values(array_filter($client->r2BucketLockRules($bucket), static fn (array $rule): bool => ! in_array($rule['id'] ?? '', $ours, true)));
        if (! $this->option('off')) {
            foreach (self::PREFIXES as $engine => $prefix) {
                $rules[] = ['id' => 'dply-db-backups-'.$engine, 'enabled' => true, 'prefix' => $prefix, 'condition' => ['type' => 'Age', 'maxAgeSeconds' => self::LOCK_DAYS * 86400]];
            }
        }
        foreach ($rules as $rule) {
            $this->line(sprintf('%s  %s  %s', $rule['id'] ?? '?', $rule['prefix'] ?? '(all)', json_encode($rule['condition'] ?? null)));
        }
        if (! $this->option('dry-run')) {
            $client->putR2BucketLockRules($bucket, $rules);
            $this->info($this->option('off') ? 'Database backup lock removed.' : 'Database backups locked for '.self::LOCK_DAYS.' days after they are written.');
        }

        return self::SUCCESS;
    }
}
