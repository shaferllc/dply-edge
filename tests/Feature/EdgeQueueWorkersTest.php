<?php

declare(strict_types=1);

namespace Tests\Feature\EdgeQueueWorkersTest;

use App\Enums\SiteType;
use App\Livewire\Sites\Edge\Workspace\Resources;
use App\Models\NotificationEvent;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Modules\Edge\Console\ScaleEdgeQueueWorkersCommand;
use App\Modules\Edge\Services\Containers\EdgeContainerDeployer;
use App\Modules\Edge\Services\Containers\EdgeContainerDockerfile;
use App\Modules\Edge\Support\EdgeContainerSettings;
use App\Modules\Edge\Support\EdgeQueueWorkers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/** A Laravel container app with a Postgres database (so workers can use the database queue). */
function laravelApp(array $edge = [], ?Organization $org = null): Site
{
    $org ??= Organization::factory()->create();

    return Site::factory()->create([
        'organization_id' => $org->id,
        'server_id' => Server::factory()->create(['organization_id' => $org->id])->id,
        'edge_backend' => 'dply_edge',
        'meta' => ['edge' => array_replace_recursive([
            'runtime_mode' => 'container',
            'build' => ['framework' => 'laravel'],
            'database' => ['engine' => 'postgres', 'provider' => 'dply'],
        ], $edge)],
    ]);
}

test('settings are clamped and queue names cleaned', function () {
    expect(EdgeQueueWorkers::normalize(['enabled' => true, 'instances' => 99, 'processes' => 0, 'queues' => ' high , de fault;x ,', 'connection' => 'sqs', 'timeout' => 0]))
        ->toMatchArray(['enabled' => true, 'instances' => 5, 'processes' => 1, 'queues' => 'high,defaultx', 'connection' => 'auto', 'timeout' => 1]);
});

test('automatic connection prefers redis, falls back to the database, and needs one of them', function () {
    $app = laravelApp();
    expect(EdgeQueueWorkers::connection($app))->toBe('database')
        ->and(EdgeQueueWorkers::unavailableReason($app))->toBeNull();

    $app->mergeEdgeMeta(['connections' => [['kind' => 'redis', 'name' => 'REDIS', 'host' => 'redis.internal', 'target' => 'dply-valkey:x']]]);
    expect(EdgeQueueWorkers::connection($app))->toBe('redis');

    $sqlite = laravelApp(['database' => ['engine' => 'sql']]);
    expect(EdgeQueueWorkers::connection($sqlite))->toBeNull()
        ->and(EdgeQueueWorkers::unavailableReason($sqlite))->toContain('SQLite');

    $static = laravelApp(['runtime_mode' => 'static']);
    expect(EdgeQueueWorkers::unavailableReason($static))->toContain('container app');
});

test('enabled workers add named worker instances to the container app and boot in worker mode', function () {
    $app = laravelApp(['container' => ['max_instances' => 2, 'workers' => ['enabled' => true, 'instances' => 2, 'processes' => 3, 'queues' => 'high,default']]]);
    $dir = sys_get_temp_dir().'/dply-workers-test-'.bin2hex(random_bytes(4));

    (new EdgeContainerDeployer)->scaffold($dir, $app, '/x/Dockerfile', 8080, []);
    $config = json_decode(File::get($dir.'/wrangler.jsonc'), true);
    $worker = File::get($dir.'/src/index.js');

    expect(EdgeContainerSettings::for($app)['worker_instances'])->toBe(2)
        ->and(EdgeContainerDeployer::keepsInstancesAwake(EdgeContainerSettings::for($app)))->toBeTrue()
        // two web instances + two workers
        ->and($config['containers'][0]['max_instances'])->toBeGreaterThanOrEqual(4)
        ->and($worker)->toContain('const WORKERS = 2')
        ->and($worker)->toContain('"DPLY_ROLE":"worker"')
        ->and($worker)->toContain('"DPLY_WORKER_CONNECTION":"database"')
        ->and($worker)->toContain('"DPLY_WORKER_QUEUES":"high,default"')
        ->and($worker)->toContain('"DPLY_WORKER_PROCESSES":"3"')
        ->and($worker)->toContain('getContainer(env.APP, name).startWorker(name)')
        ->and($worker)->toContain('if (isWorker(ctx.id.name)) Object.assign(this.envVars, WORKER_ENV, { DPLY_WORKER_NAME: ctx.id.name })')
        ->and($worker)->toContain("url.pathname === '/_dply/workers'")
        ->and($worker)->toContain("url.pathname === '/_dply/workers/start'")
        ->and($worker)->not->toContain('__');

    // The generated Worker must parse.
    $check = Process::run(['node', '--check', $dir.'/src/index.js']);
    expect($check->successful())->toBeTrue($check->errorOutput());
    File::deleteDirectory($dir);
});

test('without workers nothing changes in the container app', function () {
    $app = laravelApp();
    $dir = sys_get_temp_dir().'/dply-workers-test-'.bin2hex(random_bytes(4));

    (new EdgeContainerDeployer)->scaffold($dir, $app, '/x/Dockerfile', 8080, []);

    expect(File::get($dir.'/src/index.js'))->toContain('const WORKERS = 0')->toContain('const WORKER_ENV = {}')
        ->and(EdgeContainerSettings::for($app)['worker_instances'])->toBe(0);
    File::deleteDirectory($dir);
});

test('a laravel image starts the worker supervisor instead of the web server in worker mode', function () {
    $dir = sys_get_temp_dir().'/dply-workers-test-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($dir);
    File::put($dir.'/composer.json', '{"require":{"php":"^8.3"}}');
    File::put($dir.'/artisan', '');

    $dockerfile = File::get(EdgeContainerDockerfile::prepare($dir)['path']);

    expect($dockerfile)->toContain('if [ \"$DPLY_ROLE\" = \"worker\" ]; then')
        ->and($dockerfile)->toContain('php artisan queue:work \"$DPLY_WORKER_CONNECTION\"')
        ->and($dockerfile)->toContain('--max-time=\"${DPLY_WORKER_MAX_TIME:-3600}\"')
        // Worker mode must come before migrations and the web server start.
        ->and(strpos($dockerfile, 'DPLY_ROLE'))->toBeLessThan(strpos($dockerfile, 'DPLY_MIGRATE_ON_BOOT\" = \"1\"'));
    File::deleteDirectory($dir);
});

test('queue workers are added, configured and saved with the redeploy', function () {
    Queue::fake();
    Process::fake();
    // SQLite + dply Valkey: workers use Redis, and saving needs no card on file.
    $app = laravelApp(['database' => ['engine' => 'sql', 'provider' => null], 'connections' => [['kind' => 'redis', 'name' => 'REDIS', 'host' => 'redis.internal', 'target' => 'dply-valkey:x']]]);
    $user = User::factory()->create();
    $app->organization->users()->attach($user->id, ['role' => 'owner']);
    $app->forceFill(['user_id' => $user->id, 'type' => SiteType::Static, 'status' => Site::STATUS_EDGE_ACTIVE])->save();
    $app->server->forceFill(['user_id' => $user->id, 'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE]])->save();

    Livewire::actingAs($user)->test(Resources::class, ['server' => $app->server, 'site' => $app])
        ->assertSee('Queue workers')
        ->call('addWorkers')
        ->assertSet('workers.enabled', true)
        ->assertSet('pending', true)
        ->set('workers.instances', 2)
        ->set('workers.queues', 'emails,default')
        ->assertSee('Check workers')
        ->assertSee('queue:work redis --queue=emails,default')
        ->call('redeploySettings')
        ->assertHasNoErrors();

    expect($app->fresh()->edgeMeta()['container']['workers'])->toMatchArray(['enabled' => true, 'instances' => 2, 'queues' => 'emails,default'])
        ->and(EdgeContainerSettings::for($app->fresh())['worker_instances'])->toBe(2);

    // Removing saved workers is a change to save.
    Livewire::actingAs($user)->test(Resources::class, ['server' => $app->server, 'site' => $app->fresh()])
        ->assertSet('workers.enabled', true)
        ->call('removeWorkers')
        ->assertSet('workers.enabled', false)
        ->assertSet('pending', true);

    // Undoing an unsaved add leaves nothing pending.
    $other = laravelApp([], $app->organization);
    $other->forceFill(['user_id' => $user->id, 'type' => SiteType::Static, 'status' => Site::STATUS_EDGE_ACTIVE])->save();
    $other->server->forceFill(['user_id' => $user->id, 'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE]])->save();
    Livewire::actingAs($user)->test(Resources::class, ['server' => $other->server, 'site' => $other])
        ->call('addWorkers')->set('workers.instances', 3)
        ->call('removeWorkers')
        ->assertSet('pending', false);
});

test('the card shows each deployed worker and starts stopped ones', function () {
    $app = laravelApp([
        'live_url' => 'https://shop.on-dply.live',
        'database' => ['engine' => 'sql', 'provider' => null],
        'connections' => [['kind' => 'redis', 'name' => 'REDIS', 'host' => 'redis.internal', 'target' => 'dply-valkey:x']],
        'container' => ['workers' => ['enabled' => true, 'instances' => 2]],
    ]);
    $user = User::factory()->create();
    $app->organization->users()->attach($user->id, ['role' => 'owner']);
    $app->forceFill(['user_id' => $user->id, 'type' => SiteType::Static, 'status' => Site::STATUS_EDGE_ACTIVE])->save();
    $app->server->forceFill(['user_id' => $user->id, 'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE]])->save();
    Http::fake([
        'shop.on-dply.live/_dply/workers' => Http::response([
            ['name' => 'worker-0', 'status' => 'healthy', 'lastChange' => now()->subMinutes(5)->getTimestampMs()],
            ['name' => 'worker-1', 'status' => 'stopped_with_code', 'exitCode' => 137, 'lastChange' => now()->getTimestampMs()],
        ]),
        'shop.on-dply.live/_dply/workers/start' => Http::response([['name' => 'worker-0', 'ok' => true], ['name' => 'worker-1', 'ok' => false, 'error' => 'Maximum number of running container instances exceeded']]),
        '*' => Http::response([], 500),
    ]);

    Livewire::actingAs($user)->test(Resources::class, ['server' => $app->server, 'site' => $app])
        ->call('loadWorkersBacklog')
        ->assertSet('workersStatus.0.status', 'healthy')
        ->assertSee('worker-1')
        ->assertSee('Exited (code 137)')
        ->assertSee('Start stopped workers')
        ->call('startWorkers')
        ->assertSee('worker-1: Maximum number of running container instances exceeded');

    $token = EdgeContainerDeployer::queueToken($app);
    Http::assertSent(fn (Request $r): bool => $r->url() === 'https://shop.on-dply.live/_dply/workers' && $r->header('x-dply-queue-token') === [$token]);
    Http::assertSent(fn (Request $r): bool => $r->method() === 'POST' && $r->url() === 'https://shop.on-dply.live/_dply/workers/start');
});

test('failed jobs are listed, retried and deleted through the live app', function () {
    $app = laravelApp(['live_url' => 'https://shop.on-dply.live', 'container' => ['workers' => ['enabled' => true]]]);
    $user = User::factory()->create();
    $app->organization->users()->attach($user->id, ['role' => 'owner']);
    $app->forceFill(['user_id' => $user->id, 'type' => SiteType::Static, 'status' => Site::STATUS_EDGE_ACTIVE])->save();
    $app->server->forceFill(['user_id' => $user->id, 'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE]])->save();
    Http::fake(function (Request $r) {
        return match ($r['command'] ?? null) {
            'failed-jobs' => Http::response(['total' => 1, 'jobs' => [[
                'id' => 'uuid-1', 'name' => 'App\\Jobs\\SendInvoice', 'connection' => 'database', 'queue' => 'default',
                'failed_at' => now()->subMinute()->toDateTimeString(), 'attempts' => 3,
                'error' => 'RuntimeException: SMTP refused', 'trace' => "RuntimeException: SMTP refused\n#0 app/Jobs/SendInvoice.php(12)",
            ]]]),
            'retry', 'forget', 'flush-failed' => Http::response(['exit' => 0, 'output' => '']),
            default => Http::response(['error' => 'Unknown command.'], 422),
        };
    });

    Livewire::actingAs($user)->test(Resources::class, ['server' => $app->server, 'site' => $app])
        ->call('openFailedJobs')
        ->assertSet('panel', 'failed-jobs')
        ->assertSee('App\\Jobs\\SendInvoice')
        ->assertSee('RuntimeException: SMTP refused')
        ->assertSee('3 attempts')
        ->call('retryFailedJobs', 'uuid-1')
        ->assertSet('failedJobsNotice', 'The job is back on its queue.')
        ->call('forgetFailedJob', 'uuid-1')
        ->call('flushFailedJobs')
        ->assertSet('confirmFlushFailed', true)
        ->call('flushFailedJobs')
        ->assertSet('confirmFlushFailed', false)
        ->assertSet('failedJobsNotice', 'Deleted every failed job.');

    Http::assertSent(fn (Request $r): bool => $r->url() === 'https://shop.on-dply.live/_dply/command' && $r['command'] === 'retry' && $r['ids'] === ['uuid-1']);
    Http::assertSent(fn (Request $r): bool => $r['command'] === 'forget' && $r['ids'] === ['uuid-1']);
    Http::assertSentCount(7); // open list, retry+list, forget+list, flush+list
});

test('autoscaling runs enough workers for the backlog, between the always-on count and the maximum', function () {
    $s = EdgeQueueWorkers::normalize(['instances' => 2, 'max_instances' => 5, 'processes' => 2, 'scale_per' => 10, 'autoscale' => true]);

    expect(EdgeQueueWorkers::targetInstances($s, 0))->toBe(2)
        ->and(EdgeQueueWorkers::targetInstances($s, 55))->toBe(3)   // 55 / (2 × 10) → 3
        ->and(EdgeQueueWorkers::targetInstances($s, 10_000))->toBe(5)
        ->and(EdgeQueueWorkers::normalize(['instances' => 3, 'max_instances' => 1])['max_instances'])->toBe(3);

    $app = laravelApp(['container' => ['workers' => ['enabled' => true, 'instances' => 1, 'max_instances' => 4, 'autoscale' => true]]]);
    $dir = sys_get_temp_dir().'/dply-workers-test-'.bin2hex(random_bytes(4));
    (new EdgeContainerDeployer)->scaffold($dir, $app, '/x/Dockerfile', 8080, []);
    $worker = File::get($dir.'/src/index.js');

    expect(EdgeContainerSettings::for($app)['worker_instances'])->toBe(4)
        ->and($worker)->toContain('const WORKERS = 4;')
        ->and($worker)->toContain('const WORKERS_MIN = 1;')
        ->and($worker)->toContain('const WORKERS_AUTOSCALE = true;')
        ->and($worker)->toContain("url.pathname === '/_dply/workers/scale'");
    $check = Process::run(['node', '--check', $dir.'/src/index.js']);
    expect($check->successful())->toBeTrue($check->errorOutput());
    File::deleteDirectory($dir);
});

test('the scaler scales up at once and down only after the queue stays quiet', function () {
    $app = laravelApp([
        'live_url' => 'https://shop.on-dply.live',
        'container' => ['workers' => ['enabled' => true, 'instances' => 1, 'max_instances' => 3, 'processes' => 1, 'scale_per' => 10, 'autoscale' => true]],
    ]);
    laravelApp(['live_url' => 'https://fixed.on-dply.live', 'container' => ['workers' => ['enabled' => true, 'instances' => 2]]]);
    $backlog = 45;
    Http::fake(function (Request $r) use (&$backlog) {
        if (str_ends_with($r->url(), '/_dply/command')) {
            return Http::response(['sizes' => ['default' => $backlog], 'total' => $backlog]);
        }

        return Http::response(array_map(fn ($i) => ['name' => 'worker-'.$i, 'wanted' => $i < $r['count'], 'ok' => true], range(0, 2)));
    });
    $scaledTo = fn (): array => Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/_dply/workers/scale'))->map(fn ($pair) => $pair[0]['count'])->values()->all();

    $this->artisan('dply:edge:scale-queue-workers')->assertSuccessful();
    expect($scaledTo())->toBe([3]); // 45 waiting → capped at 3

    $backlog = 0;
    $this->travel(2)->minutes();
    $this->artisan('dply:edge:scale-queue-workers')->assertSuccessful();
    expect(last($scaledTo()))->toBe(3); // quiet, but not for long enough

    $this->travel(4)->minutes();
    $this->artisan('dply:edge:scale-queue-workers')->assertSuccessful();
    expect(last($scaledTo()))->toBe(1);

    // Only the autoscaling app was asked.
    Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), 'fixed.on-dply.live'));

    // Each run is kept for the workspace chart.
    $history = ScaleEdgeQueueWorkersCommand::history($app);
    expect(array_column($history, 'count'))->toBe([3, 3, 1])
        ->and(array_column($history, 'backlog'))->toBe([45, 0, 0]);

    $user = User::factory()->create();
    $app->organization->users()->attach($user->id, ['role' => 'owner']);
    $app->forceFill(['user_id' => $user->id, 'type' => SiteType::Static, 'status' => Site::STATUS_EDGE_ACTIVE])->save();
    $app->server->forceFill(['user_id' => $user->id, 'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE]])->save();
    Livewire::actingAs($user)->test(Resources::class, ['server' => $app->server, 'site' => $app->fresh()])
        ->assertSee('workers, up to 3')
        ->assertSee('waiting, peak 45');
});

test('worker logs pick worker output out of the app logs', function () {
    config(['edge.cloudflare.account_id' => 'acct', 'edge.cloudflare.api_token' => 'tok']);
    $app = laravelApp(['container' => ['workers' => ['enabled' => true]]]);
    $user = User::factory()->create();
    $app->organization->users()->attach($user->id, ['role' => 'owner']);
    $app->forceFill(['user_id' => $user->id, 'type' => SiteType::Static, 'status' => Site::STATUS_EDGE_ACTIVE])->save();
    $app->server->forceFill(['user_id' => $user->id, 'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE]])->save();
    $event = fn (string $message, string $level = 'log') => ['timestamp' => 1_790_000_000_000, '$metadata' => ['message' => $message, 'level' => $level]];
    $script = EdgeContainerDeployer::scriptName($app);
    // Container stdout is logged under the container application's id, not the script.
    Http::fake(function (Request $r) use ($event, $script) {
        if (str_ends_with($r->url(), '/containers/applications')) {
            return Http::response(['success' => true, 'result' => [['id' => 'app-uuid', 'name' => $script.'-app'], ['id' => 'other', 'name' => 'someone-else']]]);
        }
        $service = $r['parameters']['filters'][0]['value'] ?? '';

        return Http::response(['success' => true, 'result' => ['events' => ['events' => $service === 'app-uuid' ? [
            $event('[dply-worker worker-0] starting 1 x queue:work database --queue=default'),
            $event('  2026-09-26 03:00:00 App\\Jobs\\SendInvoice ........ RUNNING'),
            $event('  2026-09-26 03:00:01 App\\Jobs\\SendInvoice ... 812.40ms FAIL'),
        ] : ($service === $script ? [$event('GET /dashboard 200')] : [])]]]);
    });

    Livewire::actingAs($user)->test(Resources::class, ['server' => $app->server, 'site' => $app])
        ->call('openWorkerLogs')
        ->assertSet('workerLogsError', null)
        ->assertCount('workerLogs', 3)
        ->assertSee('[dply-worker worker-0] starting')
        ->assertSee('812.40ms FAIL')
        ->assertDontSee('GET /dashboard 200');
});

test('workers on a sleeping database can switch to dply Valkey', function () {
    $app = laravelApp(['database' => ['suspend' => 300], 'container' => ['workers' => ['enabled' => true]]]);
    $user = User::factory()->create();
    $app->organization->users()->attach($user->id, ['role' => 'owner']);
    $app->forceFill(['user_id' => $user->id, 'type' => SiteType::Static, 'status' => Site::STATUS_EDGE_ACTIVE])->save();
    $app->server->forceFill(['user_id' => $user->id, 'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE]])->save();

    Livewire::actingAs($user)->test(Resources::class, ['server' => $app->server, 'site' => $app])
        ->assertSee('Queue on dply Valkey instead')
        ->call('useValkeyForWorkers')
        ->assertSet('workers.connection', 'redis')
        ->assertSet('panel', 'connection')
        ->assertSet('connectionKind', 'redis');

    // Saved as redis without Valkey: workers do not deploy with no connection.
    $app->mergeEdgeMeta(['container' => array_merge($app->edgeMeta()['container'], ['workers' => ['enabled' => true, 'connection' => 'redis']])]);
    expect(EdgeQueueWorkers::runningInstances($app))->toBe(0)
        ->and(EdgeQueueWorkers::unavailableReason($app))->toContain('redis');
});

test('pausing stops the workers until resumed, and the autoscaler leaves a paused app alone', function () {
    $app = laravelApp([
        'live_url' => 'https://shop.on-dply.live',
        'container' => ['workers' => ['enabled' => true, 'instances' => 1, 'max_instances' => 3, 'autoscale' => true]],
    ]);
    $user = User::factory()->create();
    $app->organization->users()->attach($user->id, ['role' => 'owner']);
    $app->forceFill(['user_id' => $user->id, 'type' => SiteType::Static, 'status' => Site::STATUS_EDGE_ACTIVE])->save();
    $app->server->forceFill(['user_id' => $user->id, 'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE]])->save();
    Http::fake([
        'shop.on-dply.live/_dply/workers/pause' => Http::response([['name' => 'worker-0', 'ok' => true]]),
        'shop.on-dply.live/_dply/workers' => Http::response([['name' => 'worker-0', 'status' => 'stopped', 'wanted' => false, 'paused' => true]]),
        '*' => Http::response(['total' => 0]),
    ]);

    Livewire::actingAs($user)->test(Resources::class, ['server' => $app->server, 'site' => $app])
        ->call('pauseWorkers', true)
        ->assertSet('workers.paused', true)
        ->assertSet('pending', false) // saved at once, nothing left to redeploy
        ->assertSee('Paused. Jobs wait on the queue')
        ->assertSee('Resume');

    expect(EdgeQueueWorkers::for($app->fresh())['paused'])->toBeTrue();
    Http::assertSent(fn (Request $r): bool => str_ends_with($r->url(), '/_dply/workers/pause') && $r['paused'] === true);

    $this->artisan('dply:edge:scale-queue-workers')->assertSuccessful();
    Http::assertNotSent(fn (Request $r): bool => str_ends_with($r->url(), '/_dply/workers/scale'));

    Livewire::actingAs($user)->test(Resources::class, ['server' => $app->server, 'site' => $app->fresh()])
        ->call('pauseWorkers', false)
        ->assertSet('workers.paused', false);
    expect(EdgeQueueWorkers::for($app->fresh())['paused'])->toBeFalse();
});

test('failing jobs and crash-looping workers raise one alert each per half hour', function () {
    config(['edge.cloudflare.account_id' => 'acct', 'edge.cloudflare.api_token' => 'tok']);
    $app = laravelApp(['container' => ['workers' => ['enabled' => true]]]);
    $script = EdgeContainerDeployer::scriptName($app);
    $event = fn (string $message) => ['timestamp' => now()->getTimestampMs(), '$metadata' => ['message' => $message, 'level' => 'log']];
    Http::fake(function (Request $r) use ($event, $script) {
        if (str_ends_with($r->url(), '/containers/applications')) {
            return Http::response(['success' => true, 'result' => [['id' => 'app-uuid', 'name' => $script]]]);
        }

        return Http::response(['success' => true, 'result' => ['events' => ['events' => ($r['parameters']['filters'][0]['value'] ?? '') === 'app-uuid' ? [
            $event('  2026-09-26 03:00:01 App\\Jobs\\SendInvoice ... 812.40ms FAIL'),
            $event('[dply-worker worker-0] queue:work exited (1) within 10s, retrying in 5s'),
            $event('[dply-worker worker-0] queue:work exited (1) within 10s, retrying in 5s'),
            $event('[dply-worker worker-0] queue:work exited (1) within 10s, retrying in 5s'),
        ] : []]]]);
    });

    $this->artisan('dply:edge:check-queue-workers')->assertSuccessful();
    $this->artisan('dply:edge:check-queue-workers')->assertSuccessful();

    $events = NotificationEvent::query()->where('subject_id', (string) $app->id)->pluck('event_key')->sort()->values()->all();
    expect($events)->toBe(['edge.workers.crashing', 'edge.workers.failed_jobs']);
    expect(NotificationEvent::query()->where('event_key', 'edge.workers.failed_jobs')->first()->body)->toContain('SendInvoice');
});
