<?php

use App\Support\Redis\RedisConnectionTls;
use Illuminate\Support\Str;
use Pdo\Mysql;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Database Connection Name
    |--------------------------------------------------------------------------
    |
    | Here you may specify which of the database connections below you wish
    | to use as your default connection for database operations. This is
    | the connection which will be utilized unless another connection
    | is explicitly specified when you execute a query / statement.
    |
    */

    'default' => env('DB_CONNECTION', 'pgsql'),

    /*
    |--------------------------------------------------------------------------
    | Database Connections
    |--------------------------------------------------------------------------
    |
    | Below are all of the database connections defined for your application.
    | An example configuration is provided for each database system which
    | is supported by Laravel. You're free to add / remove connections.
    |
    */

    'connections' => [

        'mysql' => [
            'driver' => 'mysql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                (PHP_VERSION_ID >= 80500 ? Mysql::ATTR_SSL_CA : PDO::MYSQL_ATTR_SSL_CA) => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        'mariadb' => [
            'driver' => 'mariadb',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                (PHP_VERSION_ID >= 80500 ? Mysql::ATTR_SSL_CA : PDO::MYSQL_ATTR_SSL_CA) => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        'pgsql' => [
            'driver' => 'pgsql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => env('DB_SSLMODE', 'prefer'),
            'timezone' => env('DB_TIMEZONE', 'UTC'),
        ],

        'sqlsrv' => [
            'driver' => 'sqlsrv',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', 'localhost'),
            'port' => env('DB_PORT', '1433'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            // 'encrypt' => env('DB_ENCRYPT', 'yes'),
            // 'trust_server_certificate' => env('DB_TRUST_SERVER_CERTIFICATE', 'false'),
        ],

        /*
        | dply Queue data plane — customer job rows only.
        |
        | Deliberately NOT the primary connection, for three reasons
        | (docs/adr/dply-queue.md, decision 8):
        |
        |   1. Job payloads are arbitrary customer data, frequently PII. They
        |      do not belong in the control-plane database.
        |   2. The jobs table needs autovacuum tuned far more aggressively
        |      than anything else here; on a shared cluster that competes with
        |      every other table.
        |   3. A runaway tenant backlog must not be able to degrade dply.
        |
        | Same split as dply Logs (metadata in Postgres, volume in ClickHouse).
        | Defaults to the primary database in development so nothing extra has
        | to be running locally; production points DPLY_QUEUE_DB_* at its own
        | instance.
        |
        | Namespaces, credentials, and usage rollups stay on `pgsql`.
        */
        'dply_queue' => [
            'driver' => 'pgsql',
            'url' => env('DPLY_QUEUE_DB_URL'),
            'host' => env('DPLY_QUEUE_DB_HOST', env('DB_HOST', '127.0.0.1')),
            'port' => env('DPLY_QUEUE_DB_PORT', env('DB_PORT', '5432')),
            'database' => env('DPLY_QUEUE_DB_DATABASE', env('DB_DATABASE', 'laravel')),
            'username' => env('DPLY_QUEUE_DB_USERNAME', env('DB_USERNAME', 'root')),
            'password' => env('DPLY_QUEUE_DB_PASSWORD', env('DB_PASSWORD', '')),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => env('DPLY_QUEUE_DB_SSLMODE', env('DB_SSLMODE', 'prefer')),
            'timezone' => env('DB_TIMEZONE', 'UTC'),
        ],

        /*
        | dply Cache's item store. Same fall-through shape as `dply_queue`
        | above, and the same warning applies twice over: a cache is HIGHER
        | churn than a queue, so leaving these unset puts that write volume on
        | the Postgres serving the dashboard. `CacheStoreIsolation` surfaces
        | the condition rather than failing closed — sharing is a legitimate
        | way to run a small install.
        |
        | The items table itself is UNLOGGED, so even in the shared
        | configuration it generates no WAL. See docs/adr/dply-cache.md,
        | decision 5.
        */
        'dply_cache' => [
            'driver' => 'pgsql',
            'url' => env('DPLY_CACHE_DB_URL'),
            'host' => env('DPLY_CACHE_DB_HOST', env('DB_HOST', '127.0.0.1')),
            'port' => env('DPLY_CACHE_DB_PORT', env('DB_PORT', '5432')),
            'database' => env('DPLY_CACHE_DB_DATABASE', env('DB_DATABASE', 'laravel')),
            'username' => env('DPLY_CACHE_DB_USERNAME', env('DB_USERNAME', 'root')),
            'password' => env('DPLY_CACHE_DB_PASSWORD', env('DB_PASSWORD', '')),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => env('DPLY_CACHE_DB_SSLMODE', env('DB_SSLMODE', 'prefer')),
            'timezone' => env('DB_TIMEZONE', 'UTC'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Migration Repository Table
    |--------------------------------------------------------------------------
    |
    | This table keeps track of all the migrations that have already run for
    | your application. Using this information, we can determine which of
    | the migrations on disk haven't actually been run on the database.
    |
    */

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Redis Databases
    |--------------------------------------------------------------------------
    |
    | Redis is an open source, fast, and advanced key-value store that also
    | provides a richer body of commands than a typical key-value system
    | such as Memcached. You may define your connection settings here.
    |
    */

    'redis' => [

        'client' => env('REDIS_CLIENT', 'phpredis'),

        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix' => env('REDIS_PREFIX', Str::slug((string) env('APP_NAME', 'laravel')).'-database-'),
            'persistent' => env('REDIS_PERSISTENT', false),
        ],

        /*
        | Tight default timeouts: when REDIS_HOST points at a remote box (or the
        | local Redis is down), the page must fail fast with a RedisException
        | rather than wedging PHP-FPM until the proxy returns 502. 2s connect /
        | 2s read is generous for a healthy Redis on the same LAN and short
        | enough that a dead host produces a proper Laravel error response.
        | Override via env if you have a slow link; do not raise beyond ~5s.
        |
        | Resilience: max_retries defaults to 2 so a *transient* drop ("read
        | error on connection" from a brief network blip or an idle connection
        | reset on a remote box) is reconnected-and-retried with decorrelated
        | jitter backoff before it ever surfaces as a RedisException. A local
        | Redis that's healthy never retries, so this is free there. A sustained
        | outage still fails fast (retries exhaust within a few seconds) and hits
        | the friendly redis-unreachable handler in bootstrap/app.php.
        |
        | TLS: DigitalOcean managed Redis/Valkey on :25061 / *.db.ondigitalocean.com
        | is TLS-only. RedisConnectionTls infers scheme=tls (and redis:// → rediss://)
        | so a stale .env missing REDIS_SCHEME still handshakes. Local 127.0.0.1 stays tcp.
        | A rediss:// REDIS_URL (dply Valkey: rediss://default:<pw>@<id>.cache.dply.io:6380)
        | is TLS too; `context` sends the host as SNI (the gateway routes on it)
        | and verifies the certificate. AUTH comes from the URL's user:password.
        */
        'default' => [
            'url' => RedisConnectionTls::url(env('REDIS_URL'), env('REDIS_HOST'), env('REDIS_PORT')),
            'scheme' => RedisConnectionTls::scheme(env('REDIS_SCHEME'), env('REDIS_HOST'), env('REDIS_PORT'), env('REDIS_URL')),
            'context' => RedisConnectionTls::context(env('REDIS_SCHEME'), env('REDIS_HOST'), env('REDIS_PORT'), env('REDIS_URL')),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
            'timeout' => env('REDIS_TIMEOUT', 2.0),
            'read_timeout' => env('REDIS_READ_TIMEOUT', 2.0),
            'max_retries' => env('REDIS_MAX_RETRIES', 2),
            'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
            'backoff_base' => env('REDIS_BACKOFF_BASE', 100),
            'backoff_cap' => env('REDIS_BACKOFF_CAP', 1000),
        ],

        'cache' => [
            'url' => RedisConnectionTls::url(env('REDIS_URL'), env('REDIS_HOST'), env('REDIS_PORT')),
            'scheme' => RedisConnectionTls::scheme(env('REDIS_SCHEME'), env('REDIS_HOST'), env('REDIS_PORT'), env('REDIS_URL')),
            'context' => RedisConnectionTls::context(env('REDIS_SCHEME'), env('REDIS_HOST'), env('REDIS_PORT'), env('REDIS_URL')),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_CACHE_DB', '1'),
            'timeout' => env('REDIS_TIMEOUT', 2.0),
            'read_timeout' => env('REDIS_READ_TIMEOUT', 2.0),
            'max_retries' => env('REDIS_MAX_RETRIES', 2),
            'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
            'backoff_base' => env('REDIS_BACKOFF_BASE', 100),
            'backoff_cap' => env('REDIS_BACKOFF_CAP', 1000),
        ],

        /*
        | Long-running worker connection. The `default`/`cache` connections fail
        | fast (2s read) so a web request never wedges PHP-FPM. A queue:work /
        | Horizon worker is the opposite: it holds ONE connection for hours and
        | uses a blocking pop (BLPOP via `block_for`). With a 2s read_timeout
        | that BLPOP — or an idle connection reset by a remote box — surfaces as
        | "read error on connection to <host>:6379" and kills the worker.
        |
        | So this connection disables the read timeout (-1): an established
        | connection never trips on a slow/blocking read. Fail-fast on a *dead*
        | host is still preserved by the 2s connect `timeout` (every reconnect
        | bounds itself), and max_retries + backoff recover transient drops.
        |
        | Same database as `default` (REDIS_DB) so pointing the queue at this
        | connection never orphans in-flight jobs. read_timeout MUST stay >=
        | the queue `block_for` (config/queue.php); -1 satisfies any block_for.
        */
        'queue' => [
            'url' => RedisConnectionTls::url(env('REDIS_URL'), env('REDIS_HOST'), env('REDIS_PORT')),
            'scheme' => RedisConnectionTls::scheme(env('REDIS_SCHEME'), env('REDIS_HOST'), env('REDIS_PORT'), env('REDIS_URL')),
            'context' => RedisConnectionTls::context(env('REDIS_SCHEME'), env('REDIS_HOST'), env('REDIS_PORT'), env('REDIS_URL')),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_QUEUE_DB', env('REDIS_DB', '0')),
            'timeout' => env('REDIS_TIMEOUT', 2.0),
            'read_timeout' => env('REDIS_QUEUE_READ_TIMEOUT', -1),
            'max_retries' => env('REDIS_MAX_RETRIES', 2),
            'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
            'backoff_base' => env('REDIS_BACKOFF_BASE', 100),
            'backoff_cap' => env('REDIS_BACKOFF_CAP', 1000),
        ],

    ],

];
