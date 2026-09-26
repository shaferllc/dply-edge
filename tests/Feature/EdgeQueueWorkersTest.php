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
use App\Modules\Billing\Models\Subscription;
use App\Modules\Edge\Console\ScaleEdgeQueueWorkersCommand;
use App\Modules\Edge\Services\Containers\EdgeContainerDeployer;
use App\Modules\Edge\Services\Containers\EdgeContainerDockerfile;
use App\Modules\Edge\Support\EdgeContainerSettings;
use App\Modules\Edge\Support\EdgeQueueWorkers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/** A Laravel container app with a Postgres database (so workers can use the database queue). On Team unless an org is given. */
function laravelApp(array $edge = [], ?Organization $org = null): Site
{
    $org ??= teamOrg();

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

/** An organization on Team: 10 worker instances, autoscaling, four groups. */
function teamOrg(): Organization
{
    config(['subscription.standard.stripe.tier_team' => 'price_tier_team']);
    $org = Organization::factory()->create();
    Subscription::factory()->withPrice('price_tier_team')->active()->create(['organization_id' => $org->id]);

    return $org;
}

/** An organization on Pro. */
function proOrg(): Organization
{
    config(['subscription.standard.stripe.tier_pro' => 'price_tier_pro']);
    $org = Organization::factory()->create();
    Subscription::factory()->withPrice('price_tier_pro')->active()->create(['organization_id' => $org->id]);

    return $org;
}

test('settings are clamped and queue names cleaned', function () {
    expect(EdgeQueueWorkers::normalize(['enabled' => true, 'instances' => 99, 'processes' => 0, 'queues' => ' high , de fault;x ,', 'connection' => 'sqs', 'timeout' => 0]))
        ->toMatchArray(['enabled' => true, 'instances' => 10, 'processes' => 1, 'queues' => 'high,defaultx', 'connection' => 'auto', 'timeout' => 1]);
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
        ->and($worker)->toContain('const WORKER_GROUPS = [{"key":"","prefix":"worker-","max":2,"min":2,"autoscale":false')
        ->and($worker)->toContain('"DPLY_ROLE":"worker"')
        ->and($worker)->toContain('"DPLY_WORKER_CONNECTION":"database"')
        ->and($worker)->toContain('"DPLY_WORKER_QUEUES":"high,default"')
        ->and($worker)->toContain('"DPLY_WORKER_PROCESSES":"3"')
        ->and($worker)->toContain('getContainer(env.APP, name).startWorker(name)')
        ->and($worker)->toContain('if (worker) Object.assign(this.envVars, worker.group.env, { DPLY_WORKER_NAME: ctx.id.name }')
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

    expect(File::get($dir.'/src/index.js'))->toContain('const WORKER_GROUPS = [];')
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
        ->assertSet('workers.processes', 3) // basic, 1 GiB: three per GiB
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
        ->and($worker)->toContain('const WORKER_GROUPS = [{"key":"","prefix":"worker-","max":4,"min":1,"autoscale":true')
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
    $app = laravelApp(['database' => ['suspend' => 300], 'container' => ['workers' => ['enabled' => true]]], proOrg());
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

test('the app dispatches to the connection its workers pull from', function () {
    $valkey = ['connections' => [['kind' => 'redis', 'name' => 'REDIS', 'host' => 'redis.internal', 'target' => 'valkey:x']], 'container' => ['workers' => ['enabled' => true, 'connection' => 'redis']]];
    $redis = laravelApp($valkey, proOrg());
    // Flex Valkey is on every plan. A Pro size on Free is not wired into the
    // app: no workers on it, and the app keeps its own connection.
    expect(EdgeQueueWorkers::dispatchEnv(laravelApp($valkey, Organization::factory()->create())))->toBe(['QUEUE_CONNECTION' => 'redis']);
    $proSize = array_replace_recursive($valkey, ['connections' => [['plan' => 'pro_5g']]]);
    $free = laravelApp($proSize, Organization::factory()->create());
    expect(EdgeQueueWorkers::dispatchEnv($free))->toBe([])
        ->and(EdgeQueueWorkers::runningInstances($free))->toBe(0)
        ->and(EdgeQueueWorkers::unavailableReason($free))->toContain('needs a paid plan')
        ->and(EdgeQueueWorkers::connection(laravelApp(array_replace_recursive($proSize, ['container' => ['workers' => ['connection' => 'auto']]]), Organization::factory()->create())))->toBe('database')
        ->and(EdgeQueueWorkers::dispatchEnv(laravelApp($proSize, proOrg())))->toBe(['QUEUE_CONNECTION' => 'redis']);
    $database = laravelApp(['container' => ['workers' => ['enabled' => true]]]);
    $none = laravelApp();

    expect(EdgeQueueWorkers::dispatchEnv($redis))->toBe(['QUEUE_CONNECTION' => 'redis'])
        ->and(EdgeQueueWorkers::dispatchEnv($database))->toBe(['QUEUE_CONNECTION' => 'database'])
        ->and(EdgeQueueWorkers::dispatchEnv($none))->toBe([])
        // A push queue's connection is kept: the helper only fills a gap.
        ->and(['QUEUE_CONNECTION' => 'dply'] + EdgeQueueWorkers::dispatchEnv($redis))->toBe(['QUEUE_CONNECTION' => 'dply']);
});

test('a test job goes through the app, and a dispatch mismatch is caught', function () {
    $app = laravelApp([
        'live_url' => 'https://shop.on-dply.live',
        'connections' => [['kind' => 'redis', 'name' => 'REDIS', 'host' => 'redis.internal', 'target' => 'valkey:x']],
        'container' => ['workers' => ['enabled' => true, 'connection' => 'redis', 'queues' => 'emails,default']],
    ], proOrg());
    $user = User::factory()->create();
    $app->organization->users()->attach($user->id, ['role' => 'owner']);
    $app->forceFill(['user_id' => $user->id, 'type' => SiteType::Static, 'status' => Site::STATUS_EDGE_ACTIVE])->save();
    $app->server->forceFill(['user_id' => $user->id, 'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE]])->save();
    $dispatchesTo = 'database';
    Http::fake(function (Request $r) use (&$dispatchesTo) {
        return Http::response(['queued' => 1, 'connection' => $dispatchesTo, 'queue' => $r['queue']]);
    });

    $page = Livewire::actingAs($user)->test(Resources::class, ['server' => $app->server, 'site' => $app]);
    $page->call('sendTestJob')->assertDispatched('notify', fn ($name, $params) => str_contains($params['message'] ?? '', 'the workers read redis'));

    $dispatchesTo = 'redis';
    $page->call('sendTestJob')->assertDispatched('notify', fn ($name, $params) => str_contains($params['message'] ?? '', 'Test job queued on emails'));

    Http::assertSent(fn (Request $r): bool => $r['command'] === 'queue-test' && $r['queue'] === 'emails' && $r['count'] === 1);
});

test('within one run the scaler checks every few seconds and calls the Worker only on change', function () {
    Sleep::fake(syncWithCarbon: true);
    $app = laravelApp([
        'live_url' => 'https://shop.on-dply.live',
        'container' => ['workers' => ['enabled' => true, 'instances' => 1, 'max_instances' => 4, 'processes' => 1, 'scale_per' => 10, 'autoscale' => true]],
    ]);
    $backlogs = [0, 0, 35, 35, 35, 35];
    Http::fake(function (Request $r) use (&$backlogs) {
        if (str_ends_with($r->url(), '/_dply/command')) {
            $n = array_shift($backlogs) ?? 35;

            return Http::response(['sizes' => ['default' => $n], 'total' => $n]);
        }

        return Http::response([['name' => 'worker-0', 'wanted' => true, 'ok' => true]]);
    });

    $this->artisan('dply:edge:scale-queue-workers', ['--for' => 50, '--every' => 10])->assertSuccessful();

    $scales = Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/_dply/workers/scale'))->map(fn ($pair) => $pair[0]['count'])->values()->all();
    // Six checks in the minute; the Worker hears 1 (first sync), then 4 once 35 are waiting.
    expect(Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/_dply/command'))->count())->toBe(6)
        ->and($scales)->toBe([1, 4])
        ->and(ScaleEdgeQueueWorkersCommand::history($app))->toHaveCount(1)
        ->and(ScaleEdgeQueueWorkersCommand::history($app)[0]['backlog'])->toBe(35);
});

test('the app card shows where the app runs and flags a placement far from its database', function () {
    $app = laravelApp(['placement' => ['location' => 'yyz04', 'region' => 'ENAM', 'rtt_ms' => 52.4, 'at' => now()->getTimestamp()]]);
    $user = User::factory()->create();
    $app->organization->users()->attach($user->id, ['role' => 'owner']);
    $app->forceFill(['user_id' => $user->id, 'type' => SiteType::Static, 'status' => Site::STATUS_EDGE_ACTIVE])->save();
    $app->server->forceFill(['user_id' => $user->id, 'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE]])->save();

    Livewire::actingAs($user)->test(Resources::class, ['server' => $app->server, 'site' => $app])
        ->assertSee('Running in yyz04 (ENAM), 52.4 ms to the database.')
        ->assertSee('That is far: redeploy to be placed again.');

    $app->mergeEdgeMeta(['placement' => ['location' => 'ewr05', 'region' => 'ENAM', 'rtt_ms' => 13.0, 'at' => now()->getTimestamp()]]);
    $app->save();
    Livewire::actingAs($user)->test(Resources::class, ['server' => $app->server, 'site' => $app->fresh()])
        ->assertSee('Running in ewr05 (ENAM), 13 ms to the database.')
        ->assertDontSee('That is far');
});

test('a deploy that lands far from the database is placed again', function () {
    $app = laravelApp(['live_url' => 'https://shop.on-dply.live']);
    $probes = [['location' => 'yyz04', 'rtt_median_ms' => 52.1], ['location' => 'ewr05', 'rtt_median_ms' => 13.2]];
    Http::fake(function (Request $r) use (&$probes) {
        if (str_ends_with($r->url(), '/_dply/replace')) {
            return Http::response(['ok' => true]);
        }

        return Http::response(['ok' => true, 'driver' => 'pgsql', 'region' => 'ENAM'] + array_shift($probes));
    });
    $lines = [];

    (new EdgeContainerDeployer)->recordPlacement($app, function (string $line) use (&$lines) {
        $lines[] = trim($line);
    });

    expect($lines)->toBe([
        'Running in yyz04 (ENAM), 52.1 ms to the database.',
        'That is far for a database round trip. Starting the app again to be placed closer.',
        'Now running in ewr05 (ENAM), 13.2 ms to the database.',
    ])->and($app->fresh()->edgeMeta()['placement'])->toMatchArray(['location' => 'ewr05', 'rtt_ms' => 13.2]);
    Http::assertSentCount(3);
});

test('a job that has waited too long adds a worker even when the count is low', function () {
    $s = EdgeQueueWorkers::normalize(['instances' => 1, 'max_instances' => 4, 'processes' => 4, 'scale_per' => 10, 'autoscale' => true, 'max_wait' => 30]);

    expect(EdgeQueueWorkers::targetInstances($s, 5, 10, 1))->toBe(1)    // 5 waiting, none for long
        ->and(EdgeQueueWorkers::targetInstances($s, 5, 45, 1))->toBe(2)  // the oldest waited 45 s
        ->and(EdgeQueueWorkers::targetInstances($s, 5, 45, 2))->toBe(3)  // still waiting after the last step: one more
        ->and(EdgeQueueWorkers::targetInstances($s, 5, 45, 4))->toBe(4)  // never past the maximum
        ->and(EdgeQueueWorkers::targetInstances(array_merge($s, ['max_wait' => 0]), 5, 900, 1))->toBe(1); // off

    // The scaler passes the oldest age through.
    $app = laravelApp(['live_url' => 'https://shop.on-dply.live', 'container' => ['workers' => ['enabled' => true, 'instances' => 1, 'max_instances' => 4, 'processes' => 4, 'scale_per' => 10, 'autoscale' => true, 'max_wait' => 30]]]);
    Http::fake(function (Request $r) {
        return str_ends_with($r->url(), '/_dply/command')
            ? Http::response(['sizes' => ['default' => 5], 'total' => 5, 'oldest_age' => 90])
            : Http::response([['name' => 'worker-0', 'wanted' => true, 'ok' => true]]);
    });
    $this->artisan('dply:edge:scale-queue-workers')->assertSuccessful();
    Http::assertSent(fn (Request $r): bool => str_ends_with($r->url(), '/_dply/workers/scale') && $r['count'] === 2);
});

test('worker groups get their own instances, queues and scaling', function () {
    $app = laravelApp(['container' => ['workers' => [
        'enabled' => true, 'instances' => 1, 'processes' => 2, 'queues' => 'default',
        'groups' => [
            ['key' => 'high', 'queues' => 'high', 'instances' => 1, 'max_instances' => 3, 'autoscale' => true, 'processes' => 4],
            ['queues' => 'emails,notify', 'instances' => 2],           // key from its first queue
            ['key' => 'High!', 'queues' => 'dupe'],                     // same key as the first: dropped
        ],
    ]]]);

    $groups = EdgeQueueWorkers::groups($app);
    expect(array_column($groups, 'key'))->toBe(['', 'high', 'emails'])
        ->and(array_column($groups, 'prefix'))->toBe(['worker-', 'worker-high-', 'worker-emails-'])
        ->and(array_column($groups, 'capacity'))->toBe([1, 3, 2])
        ->and(EdgeQueueWorkers::runningInstances($app))->toBe(6)
        ->and(EdgeQueueWorkers::env($app, 'high'))->toMatchArray(['DPLY_WORKER_QUEUES' => 'high', 'DPLY_WORKER_PROCESSES' => '4'])
        ->and(EdgeContainerSettings::for($app)['worker_instances'])->toBe(6);

    // The Worker's own group logic, run in Node.
    $dir = sys_get_temp_dir().'/dply-workers-test-'.bin2hex(random_bytes(4));
    (new EdgeContainerDeployer)->scaffold($dir, $app, '/x/Dockerfile', 8080, []);
    $js = File::get($dir.'/src/index.js');
    $start = strpos($js, 'const WORKER_GROUPS');
    $end = strpos($js, "\n", strpos($js, 'function allWorkerNames'));
    File::put($dir.'/groups.mjs', substr($js, $start, $end - $start)."\n".<<<'JS'
const out = {
  names: allWorkerNames(),
  high0: workerGroup('worker-high-0')?.group.key,
  main0: workerGroup('worker-0')?.group.key,
  emails1: workerGroup('worker-emails-1')?.index,
  stranger: workerGroup('worker-nope-0'),
};
console.log(JSON.stringify(out));
JS);
    $run = Process::run(['node', $dir.'/groups.mjs']);
    expect($run->successful())->toBeTrue($run->errorOutput());
    expect(json_decode($run->output(), true))->toBe([
        'names' => ['worker-0', 'worker-high-0', 'worker-high-1', 'worker-high-2', 'worker-emails-0', 'worker-emails-1'],
        'high0' => 'high',
        'main0' => '',
        'emails1' => 1,
        'stranger' => null,
    ]);
    File::deleteDirectory($dir);
});

test('each autoscaling group follows its own queues', function () {
    $app = laravelApp([
        'live_url' => 'https://shop.on-dply.live',
        'container' => ['workers' => [
            'enabled' => true, 'instances' => 1, 'max_instances' => 3, 'processes' => 1, 'scale_per' => 10, 'autoscale' => true, 'queues' => 'default',
            'groups' => [['key' => 'high', 'queues' => 'high', 'instances' => 1, 'max_instances' => 4, 'processes' => 1, 'scale_per' => 10, 'autoscale' => true]],
        ]],
    ]);
    // A flood on `high`, nothing on `default`.
    Http::fake(function (Request $r) {
        if (str_ends_with($r->url(), '/_dply/command')) {
            $n = in_array('high', (array) $r['queues'], true) ? 35 : 0;

            return Http::response(['sizes' => [], 'total' => $n]);
        }

        return Http::response([['name' => 'x', 'wanted' => true, 'ok' => true]]);
    });

    $this->artisan('dply:edge:scale-queue-workers')->assertSuccessful();

    $scales = Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/_dply/workers/scale'))
        ->mapWithKeys(fn ($pair) => [$pair[0]['group'] => $pair[0]['count']])->all();
    expect($scales)->toBe(['' => 1, 'high' => 4]);
});

test('groups are added, edited and saved from the card', function () {
    Queue::fake();
    Process::fake();
    $app = laravelApp(['database' => ['engine' => 'sql', 'provider' => null], 'connections' => [['kind' => 'redis', 'name' => 'REDIS', 'host' => 'redis.internal', 'target' => 'dply-valkey:x']], 'container' => ['workers' => ['enabled' => true]]]);
    $user = User::factory()->create();
    $app->organization->users()->attach($user->id, ['role' => 'owner']);
    $app->forceFill(['user_id' => $user->id, 'type' => SiteType::Static, 'status' => Site::STATUS_EDGE_ACTIVE])->save();
    $app->server->forceFill(['user_id' => $user->id, 'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE]])->save();

    Livewire::actingAs($user)->test(Resources::class, ['server' => $app->server, 'site' => $app])
        ->assertSee('Add a group')
        ->call('addWorkerGroup')
        ->assertSet('workers.groups.0.queues', 'high')
        ->assertSet('pending', true)
        ->set('workers.groups.0.instances', 2)
        ->set('workers.groups.0.autoscale', true)
        ->set('workers.groups.0.max_instances', 4)
        ->assertSee('worker-high-N')
        ->assertSee('3–5 × basic') // main 1 + high 2 always on, up to 1 + 4
        ->call('redeploySettings')
        ->assertHasNoErrors();

    $groups = EdgeQueueWorkers::groups($app->fresh());
    expect(array_column($groups, 'key'))->toBe(['', 'high'])
        ->and($groups[1])->toMatchArray(['queues' => 'high', 'instances' => 2, 'autoscale' => true, 'max_instances' => 4, 'capacity' => 4]);

    Livewire::actingAs($user)->test(Resources::class, ['server' => $app->server, 'site' => $app->fresh()])
        ->call('removeWorkerGroup', 0)
        ->assertSet('workers.groups', [])
        ->assertSet('pending', true);
});

test('each autoscaling group gets its own chart and status', function () {
    $app = laravelApp(['container' => ['workers' => [
        'enabled' => true, 'autoscale' => true, 'max_instances' => 3,
        'groups' => [['key' => 'high', 'queues' => 'high', 'autoscale' => true, 'max_instances' => 2]],
    ]]]);
    $user = User::factory()->create();
    $app->organization->users()->attach($user->id, ['role' => 'owner']);
    $app->forceFill(['user_id' => $user->id, 'type' => SiteType::Static, 'status' => Site::STATUS_EDGE_ACTIVE])->save();
    $app->server->forceFill(['user_id' => $user->id, 'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE]])->save();
    $S = ScaleEdgeQueueWorkersCommand::class;
    $t = now()->getTimestamp();
    Cache::put($S::historyKey($app), [['at' => $t - 120, 'count' => 1, 'backlog' => 3], ['at' => $t, 'count' => 3, 'backlog' => 80]], 3600);
    Cache::put($S::historyKey($app, 'high'), [['at' => $t - 120, 'count' => 1, 'backlog' => 0], ['at' => $t, 'count' => 2, 'backlog' => 250]], 3600);
    Cache::put($S::stateKey($app, 'high'), ['count' => 2, 'backlog' => 250, 'oldest_age' => 12, 'at' => $t, 'error' => null], 3600);

    Livewire::actingAs($user)->test(Resources::class, ['server' => $app->server, 'site' => $app])
        ->assertSee('waiting, peak 80')
        ->assertSee('waiting, peak 250')
        ->assertSee('[high] Autoscaler: 2 running for 250 waiting, oldest 12 s', false);
});

test('the database panel shows the round trip measured from the app', function () {
    $app = laravelApp(['database' => ['remote_id' => 'pg-x', 'host' => 'pg-x.db.dply.test'], 'placement' => ['location' => 'ewr01', 'region' => 'ENAM', 'rtt_ms' => 12.7, 'at' => now()->getTimestamp()]]);
    $user = User::factory()->create();
    $app->organization->users()->attach($user->id, ['role' => 'owner']);
    $app->forceFill(['user_id' => $user->id, 'type' => SiteType::Static, 'status' => Site::STATUS_EDGE_ACTIVE])->save();
    $app->server->forceFill(['user_id' => $user->id, 'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE]])->save();
    Http::fake();

    Livewire::actingAs($user)->test(Resources::class, ['server' => $app->server, 'site' => $app])
        ->call('openPanel', 'databases')
        ->assertSee('12.7 ms per round trip')
        ->assertSee('running in ewr01 (ENAM)');
});

test('the scheduler runs in worker-0 when the app has workers, otherwise on a Cron Trigger', function () {
    $withWorkers = laravelApp(['container' => ['scheduler' => true, 'workers' => ['enabled' => true]]]);
    $without = laravelApp(['container' => ['scheduler' => true]]);

    expect(EdgeQueueWorkers::runsScheduler($withWorkers))->toBeTrue()
        ->and(EdgeContainerDeployer::cronHandlers($withWorkers, null))->toBe([])
        ->and(EdgeQueueWorkers::runsScheduler($without))->toBeFalse()
        ->and(EdgeContainerDeployer::cronHandlers($without, null))->toBe(['* * * * *' => ['schedule:run']]);

    $dir = sys_get_temp_dir().'/dply-workers-test-'.bin2hex(random_bytes(4));
    (new EdgeContainerDeployer)->scaffold($dir, $withWorkers, '/x/Dockerfile', 8080, []);
    expect(File::get($dir.'/src/index.js'))->toContain('const SCHEDULER_WORKER = "worker-0";')
        ->toContain("ctx.id.name === SCHEDULER_WORKER ? { DPLY_WORKER_SCHEDULER: '1' } : {}");
    File::deleteDirectory($dir);

    File::ensureDirectoryExists($dir);
    File::put($dir.'/composer.json', '{"require":{"php":"^8.3"}}');
    File::put($dir.'/artisan', '');
    expect(File::get(EdgeContainerDockerfile::prepare($dir)['path']))->toContain('php artisan schedule:work');
    File::deleteDirectory($dir);
});

test('the scheduler is a resource: added from the picker, run on demand, removed', function () {
    $app = laravelApp(['live_url' => 'https://shop.on-dply.live', 'container' => ['workers' => ['enabled' => true]]]);
    $user = User::factory()->create();
    $app->organization->users()->attach($user->id, ['role' => 'owner']);
    $app->forceFill(['user_id' => $user->id, 'type' => SiteType::Static, 'status' => Site::STATUS_EDGE_ACTIVE])->save();
    $app->server->forceFill(['user_id' => $user->id, 'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE]])->save();
    Http::fake(['shop.on-dply.live/_dply/schedule' => Http::response(['command' => 'schedule:run', 'exit' => 0, 'output' => '  Running [reports:send] .... DONE'])]);

    Livewire::actingAs($user)->test(Resources::class, ['server' => $app->server, 'site' => $app])
        ->call('addScheduler')
        ->assertSet('scheduler', true)
        ->assertSet('pending', true)
        ->assertSee('Runs in worker-0 beside the queue workers')
        ->call('runSchedulerNow')
        ->assertSee('Running [reports:send] .... DONE')
        ->call('removeScheduler')
        ->assertSet('scheduler', false)
        ->assertSet('pending', false);

    Http::assertSent(fn (Request $r): bool => str_ends_with($r->url(), '/_dply/schedule') && $r['handler'] === 'schedule:run');
});

test('re-placement stops when Cloudflare picks the same place again', function () {
    $app = laravelApp(['live_url' => 'https://shop.on-dply.live']);
    Http::fake(function (Request $r) {
        return str_ends_with($r->url(), '/_dply/replace')
            ? Http::response(['ok' => true])
            : Http::response(['ok' => true, 'region' => 'ENAM', 'location' => 'atl13', 'rtt_median_ms' => 73.0]);
    });
    $lines = [];
    (new EdgeContainerDeployer)->recordPlacement($app, function (string $line) use (&$lines) {
        $lines[] = trim($line);
    });

    expect(Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/_dply/replace')))->toHaveCount(1)
        ->and(end($lines))->toBe('Now running in atl13 (ENAM), 73 ms to the database.');
});

test('the plan caps workers: instances, autoscaling and groups', function () {
    $wants = ['container' => ['workers' => [
        'enabled' => true, 'instances' => 3, 'max_instances' => 6, 'autoscale' => true,
        'groups' => [
            ['key' => 'high', 'queues' => 'high', 'instances' => 2, 'max_instances' => 4, 'autoscale' => true],
            ['key' => 'mail', 'queues' => 'mail', 'instances' => 1],
            ['key' => 'slow', 'queues' => 'slow', 'instances' => 1],
        ],
    ]]];

    // Free: one worker, no autoscaling, no groups.
    $free = EdgeQueueWorkers::groups(laravelApp($wants, Organization::factory()->create()));
    expect(array_column($free, 'key'))->toBe([''])
        ->and($free[0])->toMatchArray(['instances' => 1, 'autoscale' => false, 'capacity' => 1]);

    // Pro: five in all and two groups; the main group is served first
    // (up to 6 asked, 5 allowed), so nothing is left for `high`.
    $pro = EdgeQueueWorkers::groups(laravelApp($wants, proOrg()));
    expect(array_column($pro, 'key'))->toBe([''])
        ->and($pro[0])->toMatchArray(['instances' => 3, 'max_instances' => 5, 'capacity' => 5]);

    // Team: ten in all, four groups: main 6 + high 4, nothing left for the rest.
    $team = EdgeQueueWorkers::groups(laravelApp($wants));
    expect(array_column($team, 'key'))->toBe(['', 'high'])
        ->and(array_column($team, 'capacity'))->toBe([6, 4]);
});

test('the card says what the plan allows and does not offer more', function () {
    $app = laravelApp(['container' => ['workers' => ['enabled' => true, 'instances' => 3]]], Organization::factory()->create());
    $user = User::factory()->create();
    $app->organization->users()->attach($user->id, ['role' => 'owner']);
    $app->forceFill(['user_id' => $user->id, 'type' => SiteType::Static, 'status' => Site::STATUS_EDGE_ACTIVE])->save();
    $app->server->forceFill(['user_id' => $user->id, 'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE]])->save();

    Livewire::actingAs($user)->test(Resources::class, ['server' => $app->server, 'site' => $app])
        ->assertSee('Free runs 1 worker instance(s) per app, without autoscaling, no extra groups.')
        ->assertSee('Groups are on Pro and Team.')
        ->assertDontSee('Add a group');
    expect(EdgeContainerSettings::for($app)['worker_instances'])->toBe(1);
});
