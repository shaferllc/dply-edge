<?php

declare(strict_types=1);

namespace Tests\Feature\EdgeQueueWorkersTest;

use App\Enums\SiteType;
use App\Livewire\Sites\Edge\Workspace\Resources;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
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
        ->and($worker)->toContain('if (isWorker(ctx.id.name)) Object.assign(this.envVars, WORKER_ENV)')
        ->and($worker)->toContain("url.pathname === '/_dply/workers'")
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
        'shop.on-dply.live/_dply/warm' => Http::response(null, 202),
        '*' => Http::response([], 500),
    ]);

    Livewire::actingAs($user)->test(Resources::class, ['server' => $app->server, 'site' => $app])
        ->call('loadWorkersBacklog')
        ->assertSet('workersStatus.0.status', 'healthy')
        ->assertSee('worker-1')
        ->assertSee('Exited (code 137)')
        ->assertSee('Start stopped workers')
        ->call('startWorkers');

    $token = EdgeContainerDeployer::queueToken($app);
    Http::assertSent(fn (Request $r): bool => $r->url() === 'https://shop.on-dply.live/_dply/workers' && $r->header('x-dply-queue-token') === [$token]);
    Http::assertSent(fn (Request $r): bool => $r->method() === 'POST' && $r->url() === 'https://shop.on-dply.live/_dply/warm');
});
