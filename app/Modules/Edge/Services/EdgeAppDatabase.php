<?php

declare(strict_types=1);

namespace App\Modules\Edge\Services;

use App\Models\EdgeSiteEnvVar;
use App\Models\Site;
use App\Modules\Edge\Support\EdgeDplyDatabase;
use RuntimeException;

/**
 * The app database on the Resources card. Postgres, MySQL and MongoDB are
 * dply databases (EdgeDplyDatabase: one pod per app on the dply cluster,
 * ruling r-67chv2jdx2ha025q); SQLite stays a file in the container. Neon and
 * PlanetScale were removed on 2026-09-25 (no app used them).
 *
 * Called from Resources::persistPending. Credentials are encrypted site env
 * vars; the database id lives on edge meta database.remote_id.
 */
final class EdgeAppDatabase
{
    /** @var list<string> */
    public const ENGINES = ['sql', 'none', 'postgres', 'mysql', 'mongodb'];

    /** Engines that run as a dply database. */
    public const DPLY_ENGINES = ['postgres', 'mysql', 'mongodb'];

    /**
     * Sleep stops compute after the idle time. Awake stays on.
     *
     * @var array<string, string>
     */
    public const POSTGRES_PLANS = [
        'sleep' => 'Sleeps',
        'awake' => 'Stays on',
    ];

    /**
     * Idle time before compute stops. -1 stays on.
     *
     * @var array<int, string>
     */
    public const POSTGRES_SLEEPS = [
        60 => '1 minute',
        300 => '5 minutes',
        900 => '15 minutes',
        -1 => 'Stays on',
    ];

    /**
     * Compute sizes and their compute units (1 CU = 4 GB), which the usage
     * rates are priced in. EdgeDplyDatabase::OFFERED_SIZES is what fits today.
     *
     * @var array<string, array{cpu: string, memory: string, cu: float}>
     */
    public const POSTGRES_SIZES = [
        '0.25' => ['cpu' => '1/4 vCPU', 'memory' => '1 GB', 'cu' => 0.25],
        '0.5' => ['cpu' => '1/2 vCPU', 'memory' => '2 GB', 'cu' => 0.5],
        '1' => ['cpu' => '1 vCPU', 'memory' => '4 GB', 'cu' => 1.0],
        '2' => ['cpu' => '2 vCPU', 'memory' => '8 GB', 'cu' => 2.0],
        '4' => ['cpu' => '4 vCPU', 'memory' => '16 GB', 'cu' => 4.0],
    ];

    /** @var list<string> */
    public const MANAGED_KEYS = [
        'DB_CONNECTION',
        'DB_HOST',
        'DB_PORT',
        'DB_DATABASE',
        'DB_USERNAME',
        'DB_PASSWORD',
        'DB_SSLMODE',
        'MYSQL_ATTR_SSL_CA',
        'DATABASE_URL',
        'MONGODB_URI',
        'MONGO_URL',
        'MONGODB_DATABASE',
    ];

    /**
     * Move the app from one engine to another, or change the size, sleep time
     * or disk of the one it has. Returns an error the page can show.
     */
    public static function sync(Site $site, string $from, string $to, string $plan = '', string $size = '', int $suspend = 0, int $disk = 0): ?string
    {
        if (! in_array($to, self::ENGINES, true)) {
            return 'Pick a database.';
        }

        $current = self::record($site);
        $sameRemote = $from === $to && (string) ($current['remote_id'] ?? '') !== '';
        if ($from === $to && ($to === 'sql' || $to === 'none' || ($sameRemote && ($current['status'] ?? '') !== 'failed'))) {
            return $sameRemote && in_array($to, self::DPLY_ENGINES, true)
                ? self::applyDply($site, $current, $plan, $size, $suspend, $disk)
                : null;
        }

        try {
            if (in_array($to, self::DPLY_ENGINES, true)) {
                self::requireCard($site);
                if (! EdgeDplyDatabase::enabled()) {
                    throw new RuntimeException('Databases cannot be started from here yet.');
                }
            }
            self::release($site, $current);
            if (in_array($to, self::DPLY_ENGINES, true)) {
                self::startDply($site, $to, $plan, $size, $suspend, $disk);
            } else {
                self::remember($site, ['engine' => $to, 'name' => 'production']);
            }
        } catch (RuntimeException $e) {
            return $e->getMessage();
        }

        return null;
    }

    /**
     * @param  array{host: string, port: string, database: string, username: string, password: string}  $credentials
     */
    public static function storeCredentials(Site $site, string $engine, array $credentials): void
    {
        $auth = rawurlencode($credentials['username']).':'.rawurlencode($credentials['password']);
        $database = rawurlencode($credentials['database']);
        $address = $credentials['host'].':'.$credentials['port'];
        $pairs = match ($engine) {
            'mongodb' => [
                'MONGODB_URI' => $url = 'mongodb://'.$auth.'@'.$address.'/'.$database.'?tls=true&authSource='.$database,
                'MONGO_URL' => $url,
                'MONGODB_DATABASE' => $credentials['database'],
            ],
            'postgres' => [
                'DB_CONNECTION' => 'pgsql',
                'DB_HOST' => $credentials['host'],
                'DB_PORT' => $credentials['port'],
                'DB_DATABASE' => $credentials['database'],
                'DB_USERNAME' => $credentials['username'],
                'DB_PASSWORD' => $credentials['password'],
                'DB_SSLMODE' => 'require',
                'DATABASE_URL' => 'postgresql://'.$auth.'@'.$address.'/'.$database.'?sslmode=require',
            ],
            default => [
                'DB_CONNECTION' => 'mysql',
                'DB_HOST' => $credentials['host'],
                'DB_PORT' => $credentials['port'],
                'DB_DATABASE' => $credentials['database'],
                'DB_USERNAME' => $credentials['username'],
                'DB_PASSWORD' => $credentials['password'],
                'MYSQL_ATTR_SSL_CA' => '/etc/ssl/certs/ca-certificates.crt',
                'DATABASE_URL' => 'mysql://'.$auth.'@'.$address.'/'.$database.'?ssl-mode=REQUIRED',
            ],
        };

        foreach ($pairs as $key => $value) {
            self::writeEnv($site, $key, $value);
        }
    }

    public static function forgetCredentials(Site $site): void
    {
        $site->edgeEnvVars()
            ->where('scope', EdgeSiteEnvVar::SCOPE_PRODUCTION)
            ->whereIn('key', self::MANAGED_KEYS)
            ->delete();
    }

    /** @param  array<string, mixed>  $record */
    public static function isDply(array $record): bool
    {
        return ($record['provider'] ?? '') === 'dply';
    }

    public static function postgresPlan(string $plan): string
    {
        return array_key_exists($plan, self::POSTGRES_PLANS) ? $plan : 'sleep';
    }

    public static function postgresSize(string $size): string
    {
        return array_key_exists($size, self::POSTGRES_SIZES) ? $size : '0.25';
    }

    public static function postgresSuspend(int $seconds, string $plan = 'sleep'): int
    {
        if (array_key_exists($seconds, self::POSTGRES_SLEEPS)) {
            return $seconds;
        }

        return self::postgresPlan($plan) === 'awake' ? -1 : 300;
    }

    /** @param  array<string, mixed>  $current */
    private static function release(Site $site, array $current): void
    {
        $remoteId = (string) ($current['remote_id'] ?? '');
        if ($remoteId === '') {
            return;
        }
        if (self::isDply($current)) {
            EdgeDplyDatabase::destroy($remoteId);
        }
        self::forgetCredentials($site);
    }

    private static function startDply(Site $site, string $engine, string $plan, string $size, int $suspend, int $disk): void
    {
        $suspend = self::postgresSuspend($suspend, $plan);
        $size = EdgeDplyDatabase::size($size);
        $disk = EdgeDplyDatabase::disk($disk);
        $created = EdgeDplyDatabase::provision($site, $size, $suspend, $disk, $engine);
        self::storeCredentials($site, $engine, $created);
        self::remember($site, [
            'engine' => $engine,
            'provider' => 'dply',
            'name' => 'production',
            'status' => 'ready',
            'remote_id' => $created['id'],
            'host' => $created['host'],
            'plan' => $suspend === -1 ? 'awake' : 'sleep',
            'size' => $size,
            'suspend' => $suspend,
            'disk_gb' => $disk,
            // Storage is billed per hour from here (EdgeValkeyUsageCollector).
            'storage_at' => now()->timestamp,
        ]);
    }

    /**
     * New size, sleep time or disk. The password is read back from the app's
     * env; memory applies on the next wake, a bigger disk grows now.
     *
     * @param  array<string, mixed>  $current
     */
    private static function applyDply(Site $site, array $current, string $plan, string $size, int $suspend, int $disk): ?string
    {
        $suspend = self::postgresSuspend($suspend, $plan);
        $size = EdgeDplyDatabase::size($size);
        $disk = EdgeDplyDatabase::disk($disk);
        $storedDisk = EdgeDplyDatabase::disk((int) ($current['disk_gb'] ?? 0));
        if ($disk < $storedDisk) {
            return sprintf('A database disk only grows. Pick %d GB or more.', $storedDisk);
        }
        if ($size === (string) ($current['size'] ?? '') && $suspend === (int) ($current['suspend'] ?? 0) && $disk === $storedDisk) {
            return null;
        }
        try {
            self::requireCard($site);
            $engine = (string) ($current['engine'] ?? 'postgres');
            $env = fn (string $key): string => (string) ($site->edgeEnvVars()->where('scope', EdgeSiteEnvVar::SCOPE_PRODUCTION)->where('key', $key)->first()?->value ?? '');
            $password = $engine === 'mongodb'
                ? rawurldecode((string) (parse_url($env('MONGODB_URI'), PHP_URL_PASS) ?? ''))
                : $env('DB_PASSWORD');
            EdgeDplyDatabase::update((string) $current['remote_id'], $password, $size, $suspend, $disk, $engine);
            self::remember($site, array_merge($current, [
                'plan' => $suspend === -1 ? 'awake' : 'sleep',
                'size' => $size,
                'suspend' => $suspend,
                'disk_gb' => $disk,
            ]));
        } catch (\Throwable $e) {
            return $e->getMessage();
        }

        return null;
    }

    private static function requireCard(Site $site): void
    {
        // Local-only escape hatch, the same as Resources::cardOnFile.
        if (app()->isLocal() && config('edge.skip_card_check')) {
            return;
        }
        if (! $site->organization?->onAnyPaidPlan()) {
            throw new RuntimeException('Add a card before starting a database. It is billed to that card.');
        }
    }

    /** @return array<string, mixed> */
    private static function record(Site $site): array
    {
        $database = $site->edgeMeta()['database'] ?? null;

        return is_array($database) ? $database : [];
    }

    /** @param  array<string, mixed>  $database */
    private static function remember(Site $site, array $database): void
    {
        $site->mergeEdgeMeta(['database' => $database]);
    }

    private static function writeEnv(Site $site, string $key, string $value): void
    {
        $row = $site->edgeEnvVars()
            ->where('scope', EdgeSiteEnvVar::SCOPE_PRODUCTION)
            ->where('key', $key)
            ->first();
        if ($row === null) {
            (new EdgeSiteEnvVar([
                'site_id' => $site->id,
                'key' => $key,
                'value' => $value,
                'scope' => EdgeSiteEnvVar::SCOPE_PRODUCTION,
            ]))->save();

            return;
        }
        $row->value = $value;
        $row->save();
    }
}
