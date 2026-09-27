<?php

declare(strict_types=1);

namespace Tests\Feature\EdgeBuilderHeartbeatTest;

use App\Models\User;
use App\Modules\Edge\Support\EdgeBuilderHeartbeat;
use App\Modules\Notifications\Services\NotificationPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Mockery;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    config(['edge.build.work_root' => sys_get_temp_dir().'/dply-builder-check-'.getmypid()]);
});

test('a healthy builder passes the runtime check and stamps the heartbeat', function () {
    config(['dply_runtime.mode' => 'builder', 'queue.default' => 'redis']);
    Process::fake([
        '*docker*info*' => Process::result('29.4.0'),
        '*git*--version*' => Process::result('git version 2.43.0'),
    ]);

    $this->artisan('dply:runtime:check')->expectsOutputToContain('DPLY_RUNTIME=builder; queues: dply-provision,dply-builder')->assertSuccessful();

    expect(EdgeBuilderHeartbeat::alive())->toBeTrue();
});

test('a builder without docker or with an old git fails and does not stamp', function () {
    config(['dply_runtime.mode' => 'builder', 'queue.default' => 'redis']);
    Process::fake([
        '*docker*info*' => Process::result(errorOutput: 'Cannot connect', exitCode: 1),
        '*git*--version*' => Process::result('git version 2.25.1'),
    ]);

    $this->artisan('dply:runtime:check')
        ->expectsOutputToContain('Docker does not answer')
        ->expectsOutputToContain('git 2.31.0 or newer is required (found 2.25.1)')
        ->assertFailed();

    expect(EdgeBuilderHeartbeat::last())->toBeNull();
});

test('no builder heartbeat for five minutes alerts once, then recovers', function () {
    $publisher = Mockery::mock(NotificationPublisher::class);
    $publisher->shouldReceive('publish')->withArgs(fn (...$a) => ($a['eventKey'] ?? $a[0]) === 'platform.builders.down')->once();
    $publisher->shouldReceive('publish')->withArgs(fn (...$a) => ($a['eventKey'] ?? $a[0]) === 'platform.builders.recovered')->once();
    app()->instance(NotificationPublisher::class, $publisher);
    User::factory()->create(['email' => 'ops@dply.test']);
    config(['admin.allowed_emails' => 'ops@dply.test']);

    EdgeBuilderHeartbeat::beat('builder-1');
    $this->travel(6)->minutes();

    $this->artisan('dply:edge:check-builders')->expectsOutputToContain('No build server has checked in')->assertSuccessful();
    $this->artisan('dply:edge:check-builders')->assertSuccessful(); // no second alert

    EdgeBuilderHeartbeat::beat('builder-2');
    $this->artisan('dply:edge:check-builders')->expectsOutputToContain('Builders alive')->assertSuccessful();
});
