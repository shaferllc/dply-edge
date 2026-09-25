<?php

declare(strict_types=1);

namespace App\Modules\Edge\Support;

use App\Models\Site;
use App\Modules\Edge\Services\EdgeAppDatabase;
use App\Modules\Providers\Valkey\ValkeyGatewayClient;
use Illuminate\Support\Str;

/**
 * dply databases (ruling r-67chv2jdx2ha025q): Postgres or MongoDB, one pod per
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
    ];

    /** Sizes that fit the shared flex nodes (4 GB). Bigger ones need a pool. */
    public const OFFERED_SIZES = ['0.25', '0.5'];

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

    /** @return array<string, array{cpu: string, memory: string, cu: float}> */
    public static function sizes(): array
    {
        return array_intersect_key(EdgeAppDatabase::POSTGRES_SIZES, array_flip(self::OFFERED_SIZES));
    }

    public static function size(string $size): string
    {
        return in_array($size, self::OFFERED_SIZES, true) ? $size : self::OFFERED_SIZES[0];
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

    public static function host(string $id): string
    {
        return $id.'.'.config('edge.valkey.db_domain', 'db.dply.local');
    }

    public static function memoryMb(string $size): int
    {
        return (int) round(EdgeAppDatabase::POSTGRES_SIZES[self::size($size)]['cu'] * 4096);
    }

    /** POSTGRES_SLEEPS uses -1 for "stays on"; the gateway uses 0. */
    public static function sleepAfter(int $suspend): int
    {
        return $suspend === -1 ? 0 : max(60, $suspend);
    }

    /**
     * @return array{id: string, host: string, port: string, database: string, username: string, password: string}
     */
    public static function provision(Site $site, string $size, int $suspend, int $disk, string $engine = 'postgres'): array
    {
        $id = self::tenantId($site, $engine);
        $password = Str::random(40);
        ValkeyGatewayClient::fromConfig()->put($id, $password, self::memoryMb($size), self::sleepAfter($suspend), true, $engine, self::disk($disk));

        return ['id' => $id, 'host' => self::host($id), 'port' => self::ENGINES[$engine][1], 'database' => 'app', 'username' => 'app', 'password' => $password];
    }

    /** New size, sleep time or a bigger disk. The gateway applies memory on the next wake. */
    public static function update(string $id, string $password, string $size, int $suspend, int $disk, string $engine = 'postgres'): void
    {
        ValkeyGatewayClient::fromConfig()->put($id, $password, self::memoryMb($size), self::sleepAfter($suspend), true, $engine, self::disk($disk));
    }

    public static function destroy(string $id): void
    {
        ValkeyGatewayClient::fromConfig()->delete($id);
    }
}
