<?php

declare(strict_types=1);

use App\Console\Scheduling\DplySchedule;
use App\Jobs\DetectRepositoryRuntimeJob;
use App\Models\EdgeDeployment;
use App\Modules\Edge\Actions\CancelStuckEdgeDeployment;
use App\Modules\Edge\Jobs\BuildEdgeSiteJob;
use App\Modules\Edge\Jobs\PublishEdgeDeploymentJob;
use App\Providers\AppServiceProvider;
use App\Support\DplyRuntime;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Connectors\PostgresConnector;
use Illuminate\Foundation\Console\QueuedCommand;
use Illuminate\Queue\CallQueuedClosure;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Redis\Connectors\PhpRedisConnector;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\ConfigurationUrlParser;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Monolog\Formatter\JsonFormatter;

/**
 * Load a config file with exactly these env vars (null = unset), then put
 * the environment back.
 *
 * @param  array<string, ?string>  $env
 */
function configUnderEnv(string $file, array $env): array
{
    $saved = [];
    foreach ($env as $key => $value) {
        $saved[$key] = [$_SERVER[$key] ?? null, $_ENV[$key] ?? null, getenv($key)];
        if ($value === null) {
            unset($_SERVER[$key], $_ENV[$key]);
            putenv($key);
        } else {
            $_SERVER[$key] = $_ENV[$key] = $value;
            putenv("{$key}={$value}");
        }
    }

    try {
        return require base_path("config/{$file}.php");
    } finally {
        foreach ($saved as $key => [$server, $envValue, $put]) {
            if ($server === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $server;
            }
            if ($envValue === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $envValue;
            }
            $put === false ? putenv($key) : putenv("{$key}={$put}");
        }
    }
}

// --- runtime roles + queue routing -------------------------------------

test('container and builder split the queues with nothing shared and nothing orphaned', function () {
    $container = DplyRuntime::queuesFor('container');
    $builder = DplyRuntime::queuesFor('builder');

    expect(array_intersect($container, $builder))->toBe([])
        ->and($builder)->toEqualCanonicalizing(['dply-provision', 'dply-builder'])
        ->and(DplyRuntime::queuesFor('all'))->toEqualCanonicalizing([...$container, ...$builder])
        ->and(DplyRuntime::queuesFor('web'))->toBe([]);

    // Every queue the app dispatches to is drained by exactly one of the two.
    $dispatched = [
        (string) config('queue.connections.redis.queue'),
        (string) config('dply.queues.interactive'),
        (string) config('dply.queues.background'),
        (string) config('dply.notification_queue', 'default'),
        (string) config('edge.build.queue', 'dply-provision'),
        (new DetectRepositoryRuntimeJob('k', 'https://github.com/a/b', 'main'))->queue,
    ];
    foreach (array_keys((array) config('site_uptime.probe_workers', [])) as $worker) {
        $dispatched[] = 'probes:'.$worker;
    }
    foreach (array_unique($dispatched) as $queue) {
        expect(in_array($queue, $container, true) xor in_array($queue, $builder, true))
            ->toBeTrue("queue [{$queue}] must be drained by exactly one of container/builder");
    }
});

test('every job that shells out lands on a builder queue', function () {
    expect((new DetectRepositoryRuntimeJob('k', 'https://github.com/a/b', 'main'))->queue)->toBe(DplyRuntime::BUILDER_QUEUE);

    foreach ([BuildEdgeSiteJob::class, PublishEdgeDeploymentJob::class] as $job) {
        $source = (string) file_get_contents((new ReflectionClass($job))->getFileName());
        expect($source)->toContain("config('edge.build.queue', 'dply-provision')");
    }
    expect(config('edge.build.queue', 'dply-provision'))->toBeIn(DplyRuntime::BUILDER_QUEUES);
});

test('container and builder modes', function () {
    config(['dply_runtime.mode' => 'container', 'queue.default' => 'redis', 'cache.default' => 'redis']);
    expect(DplyRuntime::isContainer())->toBeTrue()
        ->and(DplyRuntime::runsBuilds())->toBeFalse()
        ->and(DplyRuntime::runsScheduler())->toBeTrue()
        ->and(DplyRuntime::expectsHorizon())->toBeFalse()
        ->and(DplyRuntime::isSplitDeployment())->toBeTrue()
        ->and(DplyRuntime::skipsHostWork('test'))->toBeTrue();

    config(['dply_runtime.mode' => 'builder']);
    expect(DplyRuntime::runsBuilds())->toBeTrue()
        ->and(DplyRuntime::runsScheduler())->toBeFalse()
        ->and(DplyRuntime::expectsHorizon())->toBeTrue()
        ->and(DplyRuntime::skipsHostWork('test'))->toBeFalse();
});

test('container flags shared-state misconfiguration', function () {
    config([
        'dply_runtime.mode' => 'container',
        'queue.default' => 'redis',
        'cache.default' => 'redis',
        'session.driver' => 'file',
        'filesystems.disks.platform.driver' => 'local',
        'logging.default' => 'single',
    ]);

    expect(DplyRuntime::configurationIssues())->toHaveCount(3);
});

test('horizon supervises only the queues its runtime drains', function () {
    $queuesOf = fn (string $mode): array => collect(configUnderEnv('horizon', ['DPLY_RUNTIME' => $mode])['environments']['production'])
        ->flatMap(fn (array $supervisor) => $supervisor['queue'])->values()->all();

    expect($queuesOf('builder'))->toEqualCanonicalizing(['dply-provision', 'dply-builder'])
        ->and(array_keys(configUnderEnv('horizon', ['DPLY_RUNTIME' => 'builder'])['environments']['production']))->toBe(['supervisor-build', 'supervisor-fast'])
        ->and($queuesOf('container'))->not->toContain('dply-provision')
        ->and($queuesOf('container'))->not->toContain('dply-builder')
        ->and($queuesOf('all'))->toEqualCanonicalizing(DplyRuntime::queuesFor('all'));
});

test('a container scheduler queues docker/node/age work for the builder', function () {
    config(['dply_runtime.mode' => 'container']);
    $schedule = new Schedule;
    DplySchedule::register($schedule);

    $byName = collect($schedule->events())->keyBy(fn ($event) => $event->description);
    foreach (['edge-warm-build-images', 'secrets-escrow-env', 'secrets-escrow-db-dump'] as $name) {
        expect($byName[$name] ?? null)->toBeInstanceOf(CallbackEvent::class);
    }

    Queue::fake();
    $byName['edge-warm-build-images']->run(app());
    Queue::assertPushedOn(DplyRuntime::BUILDER_QUEUE, QueuedCommand::class, fn (QueuedCommand $job) => $job->displayName() === 'dply:edge:warm-build-images');

    config(['dply_runtime.mode' => 'all']);
    $local = new Schedule;
    DplySchedule::register($local);
    $warm = collect($local->events())->first(fn ($event) => $event->description === 'edge-warm-build-images');
    expect($warm)->not->toBeInstanceOf(CallbackEvent::class)
        ->and($warm->command)->toContain('dply:edge:warm-build-images');
});

test('cancelling a build from a container kills it on the builder', function () {
    Process::fake();
    Bus::fake();
    $deployment = new EdgeDeployment;
    $deployment->id = '01jtestdeployment';
    $kill = (new ReflectionMethod(CancelStuckEdgeDeployment::class, 'killBuildContainer'));

    config(['dply_runtime.mode' => 'container']);
    $kill->invoke(app(CancelStuckEdgeDeployment::class), $deployment);
    Process::assertNothingRan();
    Bus::assertDispatched(CallQueuedClosure::class, fn (CallQueuedClosure $job) => $job->queue === DplyRuntime::BUILDER_QUEUE);

    // It survives the trip through Redis and runs the kill on the builder.
    $queued = unserialize(serialize(Bus::dispatched(CallQueuedClosure::class)->first()));
    $queued->handle(app());
    Process::assertRan(fn ($process) => $process->command === ['docker', 'kill', 'dply-edge-build-01jtestdeployment']);
    Process::fake();

    config(['dply_runtime.mode' => 'builder']);
    $kill->invoke(app(CancelStuckEdgeDeployment::class), $deployment);
    Process::assertRan(fn ($process) => $process->command === ['docker', 'kill', 'dply-edge-build-01jtestdeployment']);
});

test('ensure-build-docker is a logged no-op in a container', function () {
    Process::fake();
    config(['dply_runtime.mode' => 'container']);

    $this->artisan('dply:edge:ensure-build-docker')->expectsOutputToContain('Skipped')->assertSuccessful();
    Process::assertNothingRan();
});

// --- storage -----------------------------------------------------------

$storageEnv = [
    'DPLY_RUNTIME' => null, 'PLATFORM_DISK_BUCKET' => null, 'PLATFORM_DISK_ROOT' => null, 'PLATFORM_DISK_ENDPOINT' => null,
    'DPLY_EDGE_R2_BUCKET' => 'edge-artifacts', 'DPLY_EDGE_R2_ENDPOINT' => null, 'DPLY_EDGE_CF_ACCOUNT_ID' => 'acct123',
    'FILESYSTEM_DISK' => null, 'SITE_ASSETS_PATH' => null,
];

test('outside a container the platform disk stays local', function () use ($storageEnv) {
    $config = configUnderEnv('filesystems', $storageEnv);

    expect($config['disks']['platform']['driver'])->toBe('local')
        ->and($config['disks']['site_assets']['driver'])->toBe('local')
        ->and($config['default'])->toBe('local');
});

test('in a container the platform disk is R2 and uploads and logos use it', function () use ($storageEnv) {
    $config = configUnderEnv('filesystems', ['DPLY_RUNTIME' => 'container'] + $storageEnv);

    expect($config['disks']['platform'])->toMatchArray([
        'driver' => 's3',
        'bucket' => 'edge-artifacts',
        'root' => '_platform',
        'endpoint' => 'https://acct123.r2.cloudflarestorage.com',
    ])
        ->and($config['disks']['site_assets'])->toMatchArray(['driver' => 's3', 'root' => '_platform/site-assets'])
        ->and($config['disks']['site_assets']['url'])->toEndWith('/site-assets')
        ->and($config['default'])->toBe('platform');
});

test('a dedicated platform bucket wins and has no prefix', function () use ($storageEnv) {
    $config = configUnderEnv('filesystems', ['PLATFORM_DISK_BUCKET' => 'dply-platform'] + $storageEnv);

    expect($config['disks']['platform'])->toMatchArray(['driver' => 's3', 'bucket' => 'dply-platform', 'root' => ''])
        ->and($config['disks']['site_assets']['root'])->toBe('site-assets');
});

test('logs go to stderr as JSON in a container', function () {
    $config = configUnderEnv('logging', ['DPLY_RUNTIME' => 'container', 'LOG_CHANNEL' => null, 'LOG_STDERR_FORMATTER' => null]);

    expect($config['default'])->toBe('stderr')
        ->and($config['channels']['stderr']['formatter'])->toBe(JsonFormatter::class);
});

// --- Valkey + Postgres URLs --------------------------------------------

test('a dply Valkey rediss URL connects with TLS, SNI and AUTH', function () {
    $env = [
        'REDIS_URL' => 'rediss://default:s3cr%40t@vk01jabc.cache.dply.io:6380',
        'REDIS_HOST' => 'vk01jabc.cache.dply.io', 'REDIS_PORT' => '6380',
        'REDIS_USERNAME' => null, 'REDIS_PASSWORD' => null, 'REDIS_SCHEME' => null,
    ];
    $redis = configUnderEnv('database', $env)['redis'];

    $manager = new RedisManager(app(), 'phpredis', $redis);
    $parse = new ReflectionMethod(RedisManager::class, 'parseConnectionConfiguration');

    foreach (['default', 'cache', 'queue'] as $name) {
        $parsed = $parse->invoke($manager, $redis[$name]);

        expect($parsed['scheme'])->toBe('tls')
            ->and($parsed['host'])->toBe('vk01jabc.cache.dply.io')
            ->and((int) $parsed['port'])->toBe(6380)
            ->and($parsed['username'])->toBe('default')
            ->and($parsed['password'])->toBe('s3cr@t')
            ->and($parsed['context']['stream'])->toMatchArray(['peer_name' => 'vk01jabc.cache.dply.io', 'verify_peer' => true]);

        $host = (new ReflectionMethod(PhpRedisConnector::class, 'formatHost'))->invoke(new PhpRedisConnector, $parsed);
        expect($host)->toBe('tls://vk01jabc.cache.dply.io');
    }
});

test('a local redis stays plaintext with no TLS context', function () {
    $redis = configUnderEnv('database', ['REDIS_URL' => null, 'REDIS_HOST' => '127.0.0.1', 'REDIS_PORT' => '6379', 'REDIS_SCHEME' => null])['redis'];

    expect($redis['default']['scheme'])->toBe('tcp')
        ->and($redis['default']['context'])->toBeNull();
});

test('a dply database URL connects with sslmode=require to the SNI host', function () {
    $pgsql = configUnderEnv('database', [
        'DB_URL' => 'postgresql://app:p%40ss@pg01jabc.db.dply.io:5432/app?sslmode=require',
        'DB_SSLMODE' => null,
    ])['connections']['pgsql'];

    $parsed = (new ConfigurationUrlParser)->parseConfiguration($pgsql);
    $dsn = (new ReflectionMethod(PostgresConnector::class, 'getDsn'))->invoke(new PostgresConnector, $parsed);

    expect($parsed)->toMatchArray(['driver' => 'pgsql', 'host' => 'pg01jabc.db.dply.io', 'username' => 'app', 'password' => 'p@ss', 'database' => 'app'])
        ->and($dsn)->toContain('host=pg01jabc.db.dply.io')
        ->and($dsn)->toContain('sslmode=require');
});

test('the injected DB_* variables (EdgeAppDatabase) give the same TLS connection', function () {
    $pgsql = configUnderEnv('database', [
        'DB_URL' => null, 'DB_HOST' => 'pg01jabc.db.dply.io', 'DB_PORT' => '5432', 'DB_SSLMODE' => 'require',
    ])['connections']['pgsql'];

    $dsn = (new ReflectionMethod(PostgresConnector::class, 'getDsn'))->invoke(new PostgresConnector, $pgsql);

    expect($dsn)->toContain('host=pg01jabc.db.dply.io')->and($dsn)->toContain('sslmode=require');
});

test('a container worker listing a build queue idles instead of taking builds', function () {
    config(['dply_runtime.mode' => 'container']);
    (new ReflectionMethod(AppServiceProvider::class, 'keepContainerWorkersOffBuildQueues'))
        ->invoke(new AppServiceProvider(app()));
    $options = new WorkerOptions;

    expect(event(new Looping('redis', 'dply,default', $options), halt: true))->toBeNull()
        ->and(event(new Looping('redis', 'default,dply-provision', $options), halt: true))->toBeFalse();
});
