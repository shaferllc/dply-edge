<?php

declare(strict_types=1);

namespace App\Modules\Edge\Support;

use App\Modules\Providers\Valkey\ValkeyGatewayClient;
use PDO;

/**
 * Live numbers for a dply database, read the way the app itself connects: TLS
 * through the gateway with the app's own login. Connecting wakes a sleeping
 * database, so the UI only calls this when someone asks for stats.
 *
 * MongoDB is read by the database's agent through the gateway instead: PHP
 * has no MongoDB driver here.
 */
final class EdgeDplyDatabaseStats
{
    /**
     * A wake is ~300 ms, but the first one on a node (image pull, volume
     * attach) can pass 30 s, PHP's request limit. Give up well before that;
     * the database keeps starting, so asking again a moment later works.
     */
    private const CONNECT_TIMEOUT = 20;

    /**
     * @return array{engine: string, version: string, uptime_seconds: int, size_bytes: int, tables: int, rows: int, connections: int, max_connections: int, cache_hit_ratio: ?float, commits: int, rollbacks: int, largest: list<array{name: string, rows: int, bytes: int}>}
     */
    public static function read(string $engine, string $host, string $id, string $password): array
    {
        $started = microtime(true);
        try {
            return match ($engine) {
                'postgres' => self::postgres($host, $password),
                'mysql' => self::mysql($host, $id, $password),
                // No MongoDB driver in PHP here: the database's own agent reports them.
                'mongodb' => ValkeyGatewayClient::fromConfig()->databaseStats($id),
                default => throw new \RuntimeException(__('Live stats are not available for this database.')),
            };
        } catch (\PDOException $e) {
            if (microtime(true) - $started >= self::CONNECT_TIMEOUT - 1) {
                throw new \RuntimeException(__('The database is still waking up (the first start on a machine can take up to a minute). Try again in a few seconds.'), previous: $e);
            }
            throw $e;
        }
    }

    /**
     * Queue backlog for the database queue driver: jobs waiting per queue in
     * `jobs`, and the `failed_jobs` total. Missing tables read as zero.
     *
     * @param  list<string>  $queues
     * @return array{queues: array<string, int>, failed: int}
     */
    public static function queueBacklog(string $engine, string $host, string $id, string $password, array $queues): array
    {
        $pdo = match ($engine) {
            'postgres' => new PDO("pgsql:host={$host};port=5432;dbname=app;sslmode=require;connect_timeout=".self::CONNECT_TIMEOUT, 'app', $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]),
            'mysql' => new PDO("mysql:host={$host};port=3306;dbname=app", $id, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => self::CONNECT_TIMEOUT, PDO::MYSQL_ATTR_SSL_CA => true, PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false]),
            default => throw new \RuntimeException(__('The database queue needs Postgres or MySQL.')),
        };
        $count = static function (string $sql, array $bind = []) use ($pdo): int {
            try {
                $statement = $pdo->prepare($sql);
                $statement->execute($bind);

                return (int) $statement->fetchColumn();
            } catch (\PDOException) {
                return 0; // table not migrated yet
            }
        };
        $out = [];
        foreach ($queues as $queue) {
            $out[$queue] = $count('select count(*) from jobs where queue = ?', [$queue]);
        }

        return ['queues' => $out, 'failed' => $count('select count(*) from failed_jobs')];
    }

    /** @return array<string, mixed> */
    private static function postgres(string $host, string $password): array
    {
        $pdo = new PDO("pgsql:host={$host};port=5432;dbname=app;sslmode=require;connect_timeout=".self::CONNECT_TIMEOUT, 'app', $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $one = static fn (string $sql): array => (array) $pdo->query($sql)->fetch();

        $db = $one("select pg_database_size(current_database()) as size,
            extract(epoch from now() - pg_postmaster_start_time())::bigint as uptime,
            current_setting('server_version') as version,
            current_setting('max_connections')::int as max_connections");
        $activity = $one('select count(*) as n from pg_stat_activity where datname = current_database()');
        $stat = $one('select xact_commit, xact_rollback, blks_hit, blks_read from pg_stat_database where datname = current_database()');
        $tables = $one('select count(*) as n, coalesce(sum(n_live_tup), 0) as row_total from pg_stat_user_tables');
        $largest = $pdo->query('select relname as name, n_live_tup as row_total, pg_total_relation_size(relid) as bytes
            from pg_stat_user_tables order by pg_total_relation_size(relid) desc limit 5')->fetchAll();

        $hits = (int) $stat['blks_hit'];
        $reads = (int) $stat['blks_read'];

        return [
            'engine' => 'postgres',
            'version' => 'PostgreSQL '.$db['version'],
            'uptime_seconds' => (int) $db['uptime'],
            'size_bytes' => (int) $db['size'],
            'tables' => (int) $tables['n'],
            'rows' => (int) $tables['row_total'],
            'connections' => (int) $activity['n'],
            'max_connections' => (int) $db['max_connections'],
            'cache_hit_ratio' => $hits + $reads > 0 ? round($hits / ($hits + $reads) * 100, 1) : null,
            'commits' => (int) $stat['xact_commit'],
            'rollbacks' => (int) $stat['xact_rollback'],
            'largest' => array_map(static fn (array $r): array => ['name' => (string) $r['name'], 'rows' => (int) $r['row_total'], 'bytes' => (int) $r['bytes']], $largest),
        ];
    }

    /** @return array<string, mixed> */
    private static function mysql(string $host, string $id, string $password): array
    {
        // No SNI from PDO: the gateway takes the database id as the user name instead.
        $pdo = new PDO("mysql:host={$host};port=3306;dbname=app", $id, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => self::CONNECT_TIMEOUT,
            PDO::MYSQL_ATTR_SSL_CA => true,
            PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false,
        ]);
        $status = [];
        foreach ($pdo->query("show global status where Variable_name in ('Uptime','Threads_connected','Com_commit','Com_rollback','Innodb_buffer_pool_read_requests','Innodb_buffer_pool_reads')")->fetchAll() as $row) {
            $status[$row['Variable_name']] = (int) $row['Value'];
        }
        $version = (string) $pdo->query('select version()')->fetchColumn();
        $max = (int) $pdo->query('select @@max_connections')->fetchColumn();
        $size = (array) $pdo->query('select coalesce(sum(data_length + index_length), 0) as bytes, count(*) as n, coalesce(sum(table_rows), 0) as row_total
            from information_schema.tables where table_schema = database()')->fetch();
        $largest = $pdo->query('select table_name as name, table_rows as row_total, data_length + index_length as bytes
            from information_schema.tables where table_schema = database() order by bytes desc limit 5')->fetchAll();

        $requests = $status['Innodb_buffer_pool_read_requests'] ?? 0;
        $disk = $status['Innodb_buffer_pool_reads'] ?? 0;

        return [
            'engine' => 'mysql',
            'version' => 'MySQL '.$version,
            'uptime_seconds' => $status['Uptime'] ?? 0,
            'size_bytes' => (int) $size['bytes'],
            'tables' => (int) $size['n'],
            'rows' => (int) $size['row_total'],
            'connections' => $status['Threads_connected'] ?? 0,
            'max_connections' => $max,
            'cache_hit_ratio' => $requests > 0 ? round(($requests - $disk) / $requests * 100, 1) : null,
            'commits' => $status['Com_commit'] ?? 0,
            'rollbacks' => $status['Com_rollback'] ?? 0,
            'largest' => array_map(static fn (array $r): array => ['name' => (string) $r['name'], 'rows' => (int) $r['row_total'], 'bytes' => (int) $r['bytes']], $largest),
        ];
    }
}
