<?php

declare(strict_types=1);

namespace App\Modules\Edge\Services;

use App\Models\EdgeSiteEnvVar;
use App\Models\Site;
use App\Modules\Edge\Jobs\FinishEdgeMysqlDatabaseJob;
use App\Modules\Edge\Support\EdgeDplyDatabase;
use App\Modules\Providers\Neon\NeonClient;
use App\Modules\Providers\PlanetScale\PlanetScaleClient;
use RuntimeException;

/**
 * The app database on the Resources card. New Postgres is dply Postgres
 * (EdgeDplyDatabase, record provider "dply") when the gateway is configured;
 * older records without a provider are Neon projects and stay on Neon until
 * moved. MySQL is a PlanetScale cluster that stays on.
 * SQLite stays a file in the container.
 *
 * Called from Resources::persistPending and FinishEdgeMysqlDatabaseJob.
 * Credentials are encrypted site env vars. The remote id lives on edge meta
 * database.remote_id. No schema change.
 */
final class EdgeAppDatabase
{
    /** @var list<string> */
    public const ENGINES = ['sql', 'none', 'postgres', 'mysql', 'mongodb'];

    /**
     * Customer-facing MySQL sizes. Keys are the cluster names the API expects.
     *
     * @var array<string, array{cpu: string, memory: string, cents: int}>
     */
    public const MYSQL_SIZES = [
        'PS_10' => ['cpu' => '1/8 vCPU', 'memory' => '1 GiB', 'cents' => 3900],
        'PS_20' => ['cpu' => '1/4 vCPU', 'memory' => '2 GiB', 'cents' => 5900],
        'PS_40' => ['cpu' => '1/2 vCPU', 'memory' => '4 GiB', 'cents' => 9900],
        'PS_80' => ['cpu' => '1 vCPU', 'memory' => '8 GiB', 'cents' => 17900],
    ];

    /**
     * Postgres plans. Sleep stops compute after 5 idle minutes. Awake stays on.
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
     * How far back a change can be restored.
     *
     * @var array<int, string>
     */
    public const POSTGRES_HISTORY = [
        86400 => '1 day',
        604800 => '7 days',
    ];

    /**
     * Postgres compute sizes. One unit is about 4 GB of memory.
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
     * Move the app from one engine to another. Returns an error the page can show.
     */
    public static function sync(Site $site, string $from, string $to, string $mysqlSize = '', string $postgresPlan = '', string $postgresSize = '', string $postgresRegion = '', int $postgresSuspend = 0, int $postgresHistory = 0, int $postgresDisk = 0): ?string
    {
        if (! in_array($to, self::ENGINES, true)) {
            return 'Pick a database.';
        }

        $mysqlSize = self::mysqlSize($mysqlSize);
        $current = self::record($site);
        $sameRemote = $from === $to && (string) ($current['remote_id'] ?? '') !== '';
        if ($from === $to && ($to === 'sql' || $to === 'none' || ($sameRemote && ($current['status'] ?? '') !== 'failed'))) {
            if ($to === 'mysql' && $sameRemote && self::mysqlSize((string) ($current['size'] ?? '')) !== $mysqlSize) {
                try {
                    self::requireCard($site);
                    PlanetScaleClient::fromConfig()->resize((string) $current['remote_id'], $mysqlSize);
                    self::remember($site, array_merge($current, ['size' => $mysqlSize]));
                } catch (RuntimeException $e) {
                    return $e->getMessage();
                }
            }
            if ($to === 'mongodb' && $sameRemote) {
                $error = self::applyDplyPostgres($site, $current, $postgresPlan, $postgresSize, $postgresSuspend, $postgresDisk);
                if ($error !== null) {
                    return $error;
                }
            }
            if ($to === 'postgres' && $sameRemote) {
                $error = self::isDply($current)
                    ? self::applyDplyPostgres($site, $current, $postgresPlan, $postgresSize, $postgresSuspend, $postgresDisk)
                    : self::applyPostgresPlan($site, $current, $postgresPlan, $postgresSize, $postgresRegion, $postgresSuspend, $postgresHistory);
                if ($error !== null) {
                    return $error;
                }
            }

            return null;
        }

        try {
            if ($to === 'postgres' || $to === 'mysql' || $to === 'mongodb') {
                self::requireCard($site);
                if ($to === 'mongodb' && ! EdgeDplyDatabase::enabled()) {
                    throw new RuntimeException('MongoDB cannot be started from here yet.');
                }
                if ($to === 'postgres' && ! EdgeDplyDatabase::enabled() && ! NeonClient::configured()) {
                    throw new RuntimeException('Postgres cannot be started from here yet.');
                }
                if ($to === 'mysql' && ! PlanetScaleClient::configured()) {
                    throw new RuntimeException('MySQL cannot be started from here yet.');
                }
            }
            self::release($site, $from, $current);
            if ($to === 'mongodb') {
                self::startDplyPostgres($site, $postgresPlan, $postgresSize, $postgresSuspend, $postgresDisk, 'mongodb');
            } elseif ($to === 'postgres' && EdgeDplyDatabase::enabled()) {
                self::startDplyPostgres($site, $postgresPlan, $postgresSize, $postgresSuspend, $postgresDisk);
            } elseif ($to === 'postgres') {
                self::startPostgres($site, $postgresPlan, $postgresSize, $postgresRegion, $postgresSuspend, $postgresHistory);
            } elseif ($to === 'mysql') {
                self::startMysql($site, $mysqlSize);
            } else {
                self::remember($site, [
                    'engine' => $to,
                    'name' => 'production',
                ]);
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
        $user = rawurlencode($credentials['username']);
        $password = rawurlencode($credentials['password']);
        $auth = $user.':'.$password;
        $database = rawurlencode($credentials['database']);
        if ($engine === 'mongodb') {
            $url = 'mongodb://'.$auth.'@'.$credentials['host'].':'.$credentials['port'].'/'.$database.'?tls=true&authSource='.$database;
            $pairs = [
                'MONGODB_URI' => $url,
                'MONGO_URL' => $url,
                'MONGODB_DATABASE' => $credentials['database'],
            ];
        } elseif ($engine === 'postgres') {
            $url = 'postgresql://'.$auth.'@'.$credentials['host'].':'.$credentials['port'].'/'.$database.'?sslmode=require';
            $pairs = [
                'DB_CONNECTION' => 'pgsql',
                'DB_HOST' => $credentials['host'],
                'DB_PORT' => $credentials['port'],
                'DB_DATABASE' => $credentials['database'],
                'DB_USERNAME' => $credentials['username'],
                'DB_PASSWORD' => $credentials['password'],
                'DB_SSLMODE' => 'require',
                'DATABASE_URL' => $url,
            ];
        } else {
            $url = 'mysql://'.$auth.'@'.$credentials['host'].':'.$credentials['port'].'/'.$database.'?ssl-mode=REQUIRED';
            $pairs = [
                'DB_CONNECTION' => 'mysql',
                'DB_HOST' => $credentials['host'],
                'DB_PORT' => $credentials['port'],
                'DB_DATABASE' => $credentials['database'],
                'DB_USERNAME' => $credentials['username'],
                'DB_PASSWORD' => $credentials['password'],
                'MYSQL_ATTR_SSL_CA' => '/etc/ssl/certs/ca-certificates.crt',
                'DATABASE_URL' => $url,
            ];
        }

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

    /**
     * @param  array<string, mixed>  $record
     */
    public static function finishMysql(Site $site, array $record): bool
    {
        $client = PlanetScaleClient::fromConfig();
        $remote = $client->database((string) $record['remote_id']);
        if (! $remote['ready']) {
            return false;
        }

        $credentials = $client->createPassword($remote['name'], $remote['branch']);
        self::storeCredentials($site, 'mysql', $credentials);
        self::remember($site, [
            'engine' => 'mysql',
            'name' => 'production',
            'status' => 'ready',
            'remote_id' => $remote['name'],
            'host' => $credentials['host'],
            'size' => self::mysqlSize((string) ($record['size'] ?? '')),
        ]);
        $site->save();

        return true;
    }

    /**
     * @param  array<string, mixed>  $current
     */
    private static function release(Site $site, string $from, array $current): void
    {
        $remoteId = (string) ($current['remote_id'] ?? '');
        if ($remoteId === '') {
            return;
        }
        if (($from === 'postgres' || $from === 'mongodb') && self::isDply($current)) {
            EdgeDplyDatabase::destroy($remoteId);
        } elseif ($from === 'postgres') {
            NeonClient::fromConfig()->delete($remoteId);
        } elseif ($from === 'mysql') {
            PlanetScaleClient::fromConfig()->delete($remoteId);
        }
        self::forgetCredentials($site);
    }

    /** @param  array<string, mixed>  $record */
    public static function isDply(array $record): bool
    {
        return ($record['provider'] ?? '') === 'dply';
    }

    private static function startDplyPostgres(Site $site, string $plan, string $size, int $suspend, int $disk, string $engine = 'postgres'): void
    {
        self::requireCard($site);
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
     * New size, sleep time or disk for a dply Postgres. The password is read
     * back from DB_PASSWORD; memory applies on the next wake, disk grows now.
     *
     * @param  array<string, mixed>  $current
     */
    private static function applyDplyPostgres(Site $site, array $current, string $plan, string $size, int $suspend, int $disk): ?string
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

    private static function startPostgres(Site $site, string $plan, string $size, string $region, int $suspend, int $history): void
    {
        self::requireCard($site);
        $suspend = self::postgresSuspend($suspend, $plan);
        $plan = $suspend === -1 ? 'awake' : 'sleep';
        $size = self::postgresSize($size);
        $region = self::postgresRegion($region);
        $history = self::postgresHistory($history);
        $limits = self::postgresLimits($plan, $size, $suspend);
        $created = NeonClient::fromConfig()->create(self::resourceName($site, 'pg'), $limits['min'], $limits['max'], $limits['suspend'], $region, $history);
        self::storeCredentials($site, 'postgres', $created);
        self::remember($site, [
            'engine' => 'postgres',
            'name' => 'production',
            'status' => 'ready',
            'remote_id' => $created['id'],
            'endpoint_id' => $created['endpoint_id'],
            'host' => $created['host'],
            'plan' => $plan,
            'size' => $size,
            'region' => $region,
            'suspend' => $suspend,
            'history' => $history,
        ]);
    }

    /**
     * @param  array<string, mixed>  $current
     */
    private static function applyPostgresPlan(Site $site, array $current, string $plan, string $size, string $region, int $suspend, int $history): ?string
    {
        $suspend = self::postgresSuspend($suspend, $plan);
        $plan = $suspend === -1 ? 'awake' : 'sleep';
        $size = self::postgresSize($size);
        $region = self::postgresRegion($region);
        $history = self::postgresHistory($history);
        $storedRegion = self::postgresRegion((string) ($current['region'] ?? ''));
        if ((string) ($current['remote_id'] ?? '') !== '' && $region !== $storedRegion) {
            return 'The database stays where it was created. Remove it and add it again to use another location.';
        }
        $limits = self::postgresLimits($plan, $size, $suspend);
        $storedSuspend = self::postgresSuspend((int) ($current['suspend'] ?? 0), (string) ($current['plan'] ?? ''));
        $storedHistory = self::postgresHistory((int) ($current['history'] ?? 0));
        $storedPlan = $storedSuspend === -1 ? 'awake' : 'sleep';
        $storedSize = self::postgresSize((string) ($current['size'] ?? ''));
        $stored = self::postgresLimits($storedPlan, $storedSize, $storedSuspend);
        $same = $limits === $stored && $storedSize === $size && $storedHistory === $history;
        if ($same) {
            if (($current['plan'] ?? '') !== $plan || (string) ($current['size'] ?? '') !== $size || (string) ($current['region'] ?? '') === '' || ! isset($current['suspend']) || ! isset($current['history'])) {
                self::remember($site, array_merge($current, [
                    'plan' => $plan,
                    'size' => $size,
                    'region' => $storedRegion,
                    'suspend' => $suspend,
                    'history' => $history,
                ]));
            }

            return null;
        }

        try {
            self::requireCard($site);
            $client = NeonClient::fromConfig();
            if ($limits !== $stored || $storedSize !== $size) {
                $client->configure(
                    (string) $current['remote_id'],
                    $limits['min'],
                    $limits['max'],
                    $limits['suspend'],
                    (string) ($current['endpoint_id'] ?? ''),
                );
            }
            if ($storedHistory !== $history) {
                $client->retain((string) $current['remote_id'], $history);
            }
            self::remember($site, array_merge($current, [
                'plan' => $plan,
                'size' => $size,
                'region' => $storedRegion,
                'suspend' => $suspend,
                'history' => $history,
            ]));
        } catch (RuntimeException $e) {
            return $e->getMessage();
        }

        return null;
    }

    public static function postgresPlan(string $plan): string
    {
        return array_key_exists($plan, self::POSTGRES_PLANS) ? $plan : 'sleep';
    }

    public static function postgresSize(string $size): string
    {
        return array_key_exists($size, self::POSTGRES_SIZES) ? $size : '0.25';
    }

    public static function postgresRegion(string $region): string
    {
        if (array_key_exists($region, NeonClient::REGIONS)) {
            return $region;
        }
        $configured = (string) config('edge.neon.region', 'aws-us-east-1');

        return array_key_exists($configured, NeonClient::REGIONS) ? $configured : 'aws-us-east-1';
    }

    public static function postgresSuspend(int $seconds, string $plan = 'sleep'): int
    {
        if (array_key_exists($seconds, self::POSTGRES_SLEEPS)) {
            return $seconds;
        }

        return self::postgresPlan($plan) === 'awake' ? -1 : 300;
    }

    public static function postgresHistory(int $seconds): int
    {
        return array_key_exists($seconds, self::POSTGRES_HISTORY) ? $seconds : 86400;
    }

    /**
     * @return array{min: float, max: float, suspend: int}
     */
    public static function postgresLimits(string $plan, string $size, int $suspend = 0): array
    {
        $cu = self::POSTGRES_SIZES[self::postgresSize($size)]['cu'];
        $suspend = self::postgresSuspend($suspend, $plan);
        if ($suspend === -1) {
            return ['min' => $cu, 'max' => $cu, 'suspend' => -1];
        }

        return ['min' => 0.25, 'max' => $cu, 'suspend' => $suspend];
    }

    public static function mysqlSize(string $size): string
    {
        $normalized = str_starts_with($size, 'PS-') ? 'PS_'.substr($size, 3) : $size;

        return array_key_exists($normalized, self::MYSQL_SIZES) ? $normalized : 'PS_10';
    }

    public static function mysqlCents(string $size): int
    {
        return self::MYSQL_SIZES[self::mysqlSize($size)]['cents'];
    }

    private static function startMysql(Site $site, string $size): void
    {
        self::requireCard($site);
        $size = self::mysqlSize($size);
        $created = PlanetScaleClient::fromConfig()->create(self::resourceName($site, 'mysql'), $size);
        if ($created['ready']) {
            $credentials = PlanetScaleClient::fromConfig()->createPassword($created['name'], $created['branch']);
            self::storeCredentials($site, 'mysql', $credentials);
            self::remember($site, [
                'engine' => 'mysql',
                'name' => 'production',
                'status' => 'ready',
                'remote_id' => $created['name'],
                'host' => $credentials['host'],
                'size' => $size,
            ]);

            return;
        }

        self::remember($site, [
            'engine' => 'mysql',
            'name' => 'production',
            'status' => 'provisioning',
            'remote_id' => $created['name'],
            'host' => '',
            'size' => $size,
        ]);
        FinishEdgeMysqlDatabaseJob::dispatch((string) $site->id);
    }

    private static function requireCard(Site $site): void
    {
        // Same local-only escape hatch as Resources::cardOnFile.
        if (app()->isLocal() && config('edge.skip_card_check')) {
            return;
        }
        if (! $site->organization?->onAnyPaidPlan()) {
            throw new RuntimeException('Add a card before starting a database. It is billed to that card.');
        }
    }

    private static function resourceName(Site $site, string $suffix): string
    {
        $slug = strtolower((string) ($site->slug !== '' ? $site->slug : $site->name));
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', $slug), '-');
        $name = trim($slug.'-'.substr((string) $site->id, -8).'-'.$suffix, '-');

        return substr($name !== '' ? $name : 'app-'.$suffix, 0, 40);
    }

    /**
     * @return array<string, mixed>
     */
    private static function record(Site $site): array
    {
        $database = $site->edgeMeta()['database'] ?? null;

        return is_array($database) ? $database : [];
    }

    /**
     * @param  array<string, mixed>  $database
     */
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
