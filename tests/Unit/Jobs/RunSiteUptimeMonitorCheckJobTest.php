<?php

namespace Tests\Unit\Jobs\RunSiteUptimeMonitorCheckJobTest;

use App\Jobs\RunSiteUptimeMonitorCheckJob;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\SiteUptimeMonitor;
use App\Modules\Edge\Services\Containers\EdgeContainerDeployer;
use App\Modules\Notifications\Services\NotificationPublisher;
use App\Services\Sites\SiteUptimeCheckUrlResolver;
use App\Services\Status\MonitorOperationalState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

test('job records successful check', function () {
    Http::fake(fn () => Http::response('ok', 200));

    $site = Site::factory()->create(['status' => Site::STATUS_NGINX_ACTIVE]);
    SiteDomain::query()->create([
        'site_id' => $site->id,
        'hostname' => 'app.example.test',
        'is_primary' => true,
    ]);

    $monitor = SiteUptimeMonitor::factory()->create([
        'site_id' => $site->id,
        'path' => null,
        'last_ok' => null,
    ]);

    (new RunSiteUptimeMonitorCheckJob($monitor->id))->handle(
        app(SiteUptimeCheckUrlResolver::class),
        app(NotificationPublisher::class),
    );

    $monitor->refresh();
    expect($monitor->last_ok)->toBeTrue();
    expect($monitor->last_http_status)->toBe(200);
    expect($monitor->last_checked_at)->not->toBeNull();
});

test('an https check does not fall back to http', function () {
    Http::fake([
        'https://app.example.test' => Http::response('fail', 500),
        'http://app.example.test' => Http::response('ok', 200),
    ]);

    $site = Site::factory()->create(['status' => Site::STATUS_NGINX_ACTIVE]);
    SiteDomain::query()->create([
        'site_id' => $site->id,
        'hostname' => 'app.example.test',
        'is_primary' => true,
    ]);

    $monitor = SiteUptimeMonitor::factory()->create([
        'site_id' => $site->id,
        'check_type' => SiteUptimeMonitor::CHECK_HTTPS,
        'path' => null,
        'last_ok' => null,
    ]);

    (new RunSiteUptimeMonitorCheckJob($monitor->id))->handle(
        app(SiteUptimeCheckUrlResolver::class),
        app(NotificationPublisher::class),
    );

    $monitor->refresh();
    expect($monitor->last_ok)->toBeFalse();
    expect($monitor->last_http_status)->toBe(500);

    Http::assertSent(fn ($request) => $request->url() === 'https://app.example.test');
    Http::assertNotSent(fn ($request) => $request->url() === 'http://app.example.test');
});

test('an http check does not fall back to https', function () {
    Http::fake([
        'https://app.example.test' => Http::response('ok', 200),
        'http://app.example.test' => Http::response('fail', 500),
    ]);

    $site = Site::factory()->create(['status' => Site::STATUS_NGINX_ACTIVE]);
    SiteDomain::query()->create([
        'site_id' => $site->id,
        'hostname' => 'app.example.test',
        'is_primary' => true,
    ]);

    $monitor = SiteUptimeMonitor::factory()->create([
        'site_id' => $site->id,
        'check_type' => SiteUptimeMonitor::CHECK_HTTP,
        'path' => null,
        'last_ok' => null,
    ]);

    (new RunSiteUptimeMonitorCheckJob($monitor->id))->handle(
        app(SiteUptimeCheckUrlResolver::class),
        app(NotificationPublisher::class),
    );

    $monitor->refresh();
    expect($monitor->last_ok)->toBeFalse();
    expect($monitor->last_http_status)->toBe(500);

    Http::assertSent(fn ($request) => $request->url() === 'http://app.example.test');
    Http::assertNotSent(fn ($request) => $request->url() === 'https://app.example.test');
});

test('a sleeping container app is not woken by its uptime check; an awake one is checked', function () {
    $site = Site::factory()->create([
        'status' => Site::STATUS_EDGE_ACTIVE,
        'edge_backend' => 'dply_edge',
        'meta' => ['edge' => ['runtime_mode' => 'container', 'live_url' => 'https://app.example.test']],
    ]);
    $monitor = SiteUptimeMonitor::factory()->create(['site_id' => $site->id, 'path' => null, 'last_ok' => true]);
    $run = fn () => (new RunSiteUptimeMonitorCheckJob($monitor->id))->handle(app(SiteUptimeCheckUrlResolver::class), app(NotificationPublisher::class));

    $status = 'stopped';
    Http::fake(function ($request) use (&$status) {
        return str_contains($request->url(), '/_dply/instances')
            ? Http::response([['name' => 'instance-0', 'status' => $status]])
            : Http::response('ok', 200);
    });
    $run();
    Http::assertNotSent(fn ($r) => ! str_contains($r->url(), '/_dply/instances') && str_contains($r->url(), 'app.example.test'));
    expect($monitor->fresh()->last_checked_at)->not->toBeNull()
        // Shown as asleep, not an outage, and it opens no incident.
        ->and($monitor->fresh()->last_state)->toBe(MonitorOperationalState::ASLEEP)
        ->and(app(MonitorOperationalState::class)->state($monitor->fresh()))->toBe(MonitorOperationalState::ASLEEP)
        ->and($monitor->incidents()->count())->toBe(0);

    Cache::flush();
    $status = 'running';
    $run();
    // The check says it is dply's (the Worker neither wakes an instance nor counts it as activity).
    Http::assertSent(fn ($r) => ! str_contains($r->url(), '/_dply/instances') && str_contains($r->url(), 'app.example.test')
        && $r->hasHeader('x-dply-uptime', EdgeContainerDeployer::uptimeToken($site)));
    expect($monitor->fresh()->last_state)->toBe(MonitorOperationalState::OPERATIONAL);
});

test('a check the Worker answers as asleep records asleep, not an outage', function () {
    $site = Site::factory()->create([
        'status' => Site::STATUS_EDGE_ACTIVE,
        'edge_backend' => 'dply_edge',
        'meta' => ['edge' => ['runtime_mode' => 'container', 'live_url' => 'https://app.example.test']],
    ]);
    $monitor = SiteUptimeMonitor::factory()->create(['site_id' => $site->id, 'path' => null, 'last_ok' => true]);
    Http::fake(fn ($request) => str_contains($request->url(), '/_dply/instances')
        ? Http::response([['name' => 'instance-0', 'status' => 'running']])
        : Http::response('', 204, ['x-dply-asleep' => '1']));

    (new RunSiteUptimeMonitorCheckJob($monitor->id))->handle(app(SiteUptimeCheckUrlResolver::class), app(NotificationPublisher::class));

    expect($monitor->fresh()->last_state)->toBe(MonitorOperationalState::ASLEEP)
        ->and($monitor->fresh()->last_ok)->toBeTrue()
        ->and($monitor->incidents()->count())->toBe(0);
});

test('an app awake only because of the last uptime check is left to sleep; a later visitor request means it is checked', function () {
    $site = Site::factory()->create([
        'status' => Site::STATUS_EDGE_ACTIVE,
        'edge_backend' => 'dply_edge',
        'meta' => ['edge' => ['runtime_mode' => 'container', 'live_url' => 'https://app.example.test']],
    ]);
    $checkedAt = now()->subMinutes(5);
    $monitor = SiteUptimeMonitor::factory()->create(['site_id' => $site->id, 'path' => null, 'last_ok' => true, 'last_checked_at' => $checkedAt]);
    Cache::put('uptime-probed:'.$site->id, $checkedAt->timestamp);
    $run = fn () => (new RunSiteUptimeMonitorCheckJob($monitor->id))->handle(app(SiteUptimeCheckUrlResolver::class), app(NotificationPublisher::class));

    $lastActivity = $checkedAt->timestamp + 2; // our own check
    Http::fake(function ($request) use (&$lastActivity) {
        return str_contains($request->url(), '/_dply/instances')
            ? Http::response([['name' => 'instance-0', 'status' => 'running', 'lastActivity' => $lastActivity * 1000]])
            : Http::response('ok', 200);
    });
    $run();
    Http::assertNotSent(fn ($r) => ! str_contains($r->url(), '/_dply/instances') && str_contains($r->url(), 'app.example.test'));

    Cache::forget('edge-container-instances:'.$site->id);
    $lastActivity = now()->subMinute()->timestamp; // a visitor after our check
    $run();
    Http::assertSent(fn ($r) => ! str_contains($r->url(), '/_dply/instances') && str_contains($r->url(), 'app.example.test'));
});
