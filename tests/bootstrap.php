<?php

require __DIR__.'/../vendor/autoload.php';

use Illuminate\Database\Connection;
use Tests\Support\TestingPostgresConnection;

if ((getenv('APP_ENV') ?: $_ENV['APP_ENV'] ?? null) === 'testing') {
    Connection::resolverFor('pgsql', function ($connection, $database, $prefix, $config) {
        return new TestingPostgresConnection($connection, $database, $prefix, $config);
    });
}

/*
| Point the dply Queue data plane at this worker's parallel database.
|
| Laravel's parallel testing only rewrites the *default* connection's database
| name (Illuminate\Testing\Concerns\TestDatabases::switchToDatabase). The
| `dply_queue` connection defaults to DB_DATABASE (config/database.php), so
| every worker kept pointing at the shared `dply_edge_testing` while its primary
| connection moved to `dply_edge_testing_test_<token>`. Each worker's migrate:fresh
| then re-ran the queue migrations against that one shared database and the
| second worker to arrive died on:
|
|   SQLSTATE[42P07]: relation "dply_queue_jobs" already exists
|
| which failed ~2400 tests under `--parallel` while a sequential run stayed
| green. Overriding the env var (rather than config) keeps the production
| DPLY_QUEUE_DB_* override path intact — it only fires when paratest has
| handed this process a token, and only when that variable is not already set
| explicitly.
*/
$parallelToken = getenv('TEST_TOKEN') ?: ($_ENV['TEST_TOKEN'] ?? null);

if ($parallelToken && ! (getenv('DPLY_QUEUE_DB_DATABASE') ?: $_ENV['DPLY_QUEUE_DB_DATABASE'] ?? null)) {
    $primaryDatabase = (string) (getenv('DB_DATABASE') ?: $_ENV['DB_DATABASE'] ?? 'dply_edge_testing');
    $queueDatabase = $primaryDatabase.'_test_'.$parallelToken;

    putenv('DPLY_QUEUE_DB_DATABASE='.$queueDatabase);
    $_ENV['DPLY_QUEUE_DB_DATABASE'] = $queueDatabase;
    $_SERVER['DPLY_QUEUE_DB_DATABASE'] = $queueDatabase;
}

$argv = $_SERVER['argv'] ?? [];
$isParatestWorker = in_array('--status-file', $argv, true);

if (! $isParatestWorker) {
    $runsParallel = in_array('--parallel', $argv, true);

    $requestsCoverageReport = false;

    foreach ($argv as $argument) {
        if (str_starts_with($argument, '--coverage') || $argument === '--min') {
            $requestsCoverageReport = true;

            break;
        }
    }

    if ($runsParallel && $requestsCoverageReport) {
        // Paratest merges worker coverage in the parent process; serializing the
        // merged object exceeds the default 1G worker limit from phpunit.xml.
        ini_set('memory_limit', '2G');
    }
}
