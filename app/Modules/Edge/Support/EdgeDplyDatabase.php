<?php

declare(strict_types=1);

namespace App\Modules\Edge\Support;

use App\Models\Site;
use App\Modules\Edge\Services\EdgeAppDatabase;
use App\Modules\Providers\Valkey\ValkeyGatewayClient;
use App\Modules\Providers\Valkey\ValkeyRegions;
use Illuminate\Support\Str;

/**
 * dply databases (ruling r-67chv2jdx2ha025q): Postgres, MySQL or MongoDB, one pod per
 * app on the dply Kubernetes cluster, data on a DigitalOcean volume, parked
 * when idle. The gateway (packages/valkey-gateway) creates and wakes it; apps
 * connect to {id}.db.dply.io (5432 or 27017) with TLS as user "app" to
 * database "app".
 *
 * Sizes reuse EdgeAppDatabase::POSTGRES_SIZES and their compute-unit pricing
 * (1 CU = 4 GB), so billing and the size list stay one table.
 */
final class EdgeDplyDatabase
{
    /** Engine => [tenant id prefix, port]. */
    public const ENGINES = [
        'postgres' => ['pg', '5432'],
        'mongodb' => ['mg', '27017'],
        'mysql' => ['my', '3306'],
    ];

    /** Sizes that fit the db pool's 4 GB nodes. Always sold. */
    public const OFFERED_SIZES = ['0.25', '0.5'];

    /**
     * Sizes that run on the db-large / db-xl pools (deploy/valkey/terraform),
     * sold once dply.databases.large_sizes_enabled is on.
     */
    public const LARGE_SIZES = ['1', '2', '4'];

    /**
     * Volume sizes. A volume only grows, so a smaller pick is refused.
     *
     * @var array<int, string>
     */
    public const DISKS = [1 => '1 GB', 5 => '5 GB', 10 => '10 GB', 25 => '25 GB'];

    public const DEFAULT_DISK = 1;

    public static function enabled(): bool
    {
        return ValkeyGatewayClient::configured();
    }

    /**
     * Large sizes never sleep: each is priced to pay for the whole node it
     * brings up, which a sleeping database would hold without billing
     * (ruling r-gd2vgb7jd1b4vqtf). The gateway enforces it too.
     */
    public static function alwaysOn(string $size): bool
    {
        return in_array($size, self::LARGE_SIZES, true);
    }

    /** @return list<string> Sizes a customer can pick now. */
    public static function offeredSizes(): array
    {
        return config('dply.databases.large_sizes_enabled')
            ? [...self::OFFERED_SIZES, ...self::LARGE_SIZES]
            : self::OFFERED_SIZES;
    }

    /** @return array<string, array{cpu: string, memory: string, cu: float}> */
    public static function sizes(): array
    {
        return array_intersect_key(EdgeAppDatabase::POSTGRES_SIZES, array_flip(self::offeredSizes()));
    }

    /**
     * An offered size, or the database's current one: a database keeps its
     * size if the large sizes are switched off after it was made.
     */
    public static function size(string $size, string $current = ''): string
    {
        return in_array($size, self::offeredSizes(), true) || ($size === $current && array_key_exists($size, EdgeAppDatabase::POSTGRES_SIZES))
            ? $size
            : self::OFFERED_SIZES[0];
    }

    public static function disk(int $gb): int
    {
        return array_key_exists($gb, self::DISKS) ? $gb : self::DEFAULT_DISK;
    }

    /** 3-40 characters of a-z, 0-9 and "-" (the gateway's tenant id rule). */
    public static function tenantId(Site $site, string $engine = 'postgres'): string
    {
        return self::ENGINES[$engine][0].'-'.strtolower((string) $site->id);
    }

    public static function host(string $id, ?string $region = null): string
    {
        return $id.'.'.ValkeyRegions::get($region)['db_domain'];
    }

    public static function memoryMb(string $size): int
    {
        // $size was checked by size() when picked; a stored size is kept as is.
        return (int) round((EdgeAppDatabase::POSTGRES_SIZES[$size] ?? EdgeAppDatabase::POSTGRES_SIZES[self::OFFERED_SIZES[0]])['cu'] * 4096);
    }

    /** POSTGRES_SLEEPS uses -1 for "stays on"; the gateway uses 0. */
    public static function sleepAfter(int $suspend): int
    {
        return $suspend === -1 ? 0 : max(60, $suspend);
    }

    /**
     * @return array{id: string, host: string, port: string, database: string, username: string, password: string, region: string}
     */
    public static function provision(Site $site, string $size, int $suspend, int $disk, string $engine = 'postgres', ?string $region = null, ?string $id = null): array
    {
        // An app's first database keeps the old per-site id; more get their own (DplyDatabases).
        $id ??= self::tenantId($site, $engine);
        $password = Str::random(40);
        $region = ValkeyRegions::get($region ?? DataRegion::forSite($site))['key'];
        ValkeyGatewayClient::fromConfig($region)->put($id, $password, self::memoryMb($size), self::sleepAfter($suspend), true, $engine, self::disk($disk), self::backupDays($site));

        return ['id' => $id, 'host' => self::host($id, $region), 'port' => self::ENGINES[$engine][1], 'database' => 'app', 'username' => 'app', 'password' => $password, 'region' => $region];
    }

    /** New size, sleep time or a bigger disk. The gateway applies memory on the next wake. */
    public static function update(string $id, string $password, string $size, int $suspend, int $disk, string $engine = 'postgres', ?string $region = null, ?int $backupDays = null): void
    {
        ValkeyGatewayClient::fromConfig($region)->put($id, $password, self::memoryMb($size), self::sleepAfter($suspend), true, $engine, self::disk($disk), $backupDays);
    }

    /**
     * Days of backups the site's plan keeps (subscription tiers'
     * backup_retention_days: 7 / 14 / 30, ruling r-78fm1ejqqy4c17en). A
     * database pod reads it when it is created, so a plan change reaches a
     * running database the next time its pod is recreated.
     */
    public static function backupDays(?Site $site): int
    {
        return max(1, (int) ($site?->organization?->tierAllowances()['backup_retention_days'] ?? 7));
    }

    public static function destroy(string $id, ?string $region = null): void
    {
        ValkeyGatewayClient::fromConfig($region)->delete($id);
    }

    /** A dply database record's region (records from before regions are in the default one). */
    public static function regionOf(array $record): string
    {
        return ValkeyRegions::get((string) ($record['region'] ?? ''))['key'];
    }

    /**
     * What is wrong with a database's backups, from the agent's status
     * (meta.edge.database.backup), or null when they are fine. The daily full
     * backup (last_*) and the continuous change log (log_*) fail separately.
     *
     * @param  array<string, mixed>  $status
     */
    public static function backupProblem(array $status): ?string
    {
        $failing = fn (string $kind): bool => (string) ($status[$kind.'_error_at'] ?? '') !== ''
            && (string) $status[$kind.'_error_at'] > (string) ($status[$kind === 'last' ? 'last_ok_at' : 'log_ok_at'] ?? '');

        return match (true) {
            $failing('last') => __('The last full backup failed: :error', ['error' => $status['last_error'] ?? '']),
            $failing('log') => __('Saving recent changes is failing: :error', ['error' => $status['log_error'] ?? '']),
            default => null,
        };
    }
}
