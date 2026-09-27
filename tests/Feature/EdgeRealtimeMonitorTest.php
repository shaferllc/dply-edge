<?php

declare(strict_types=1);

use App\Models\NotificationEvent;
use App\Models\User;
use App\Modules\Edge\Console\CheckEdgeRealtimeCommand;
use App\Modules\Edge\Services\Realtime\EdgeRealtimeMonitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'edge.realtime.kv_namespace_id' => 'ns-test',
        'edge.realtime.host' => 'realtime.test',
        'edge.realtime.monitor.enabled' => true,
        'edge.cloudflare.api_token' => 'cf',
        'edge.cloudflare.account_id' => 'acct',
        'admin.allowed_emails' => 'ops@dply.test',
    ]);
    User::factory()->create(['email' => 'ops@dply.test']);
    Http::fake(['*' => Http::response(['success' => true, 'result' => []])]);
    // The KV write happened long ago: no post-write grace.
    Cache::put('edge:realtime:monitor:written_at', time() - 3600, 3600);
    $this->socketUp = true;
    app()->bind(EdgeRealtimeMonitor::class, fn () => new EdgeRealtimeMonitor(function (string $url, string $channel, string $event, string $nonce, Closure $publish): void {
        expect($url)->toStartWith('wss://realtime.test/app/rtk_monitor_');
        if (! $this->socketUp) {
            throw new RuntimeException('timed out after 10000ms while connecting');
        }
        $publish();
    }));
});

function checkRealtime(): void
{
    test()->artisan('dply:edge:check-realtime')->assertSuccessful();
}

it('records a passing round trip with its latency, publishing a signed event', function (): void {
    checkRealtime();

    $last = Cache::get(CheckEdgeRealtimeCommand::LAST_KEY);
    expect($last['ok'])->toBeTrue()
        ->and($last['latency_ms'])->toBeInt()
        ->and(NotificationEvent::query()->count())->toBe(0);
    Http::assertSent(fn ($r) => str_starts_with($r->url(), 'https://realtime.test/apps/monitor-')
        && str_contains($r->url(), 'auth_signature=')
        && str_contains($r->body(), 'nonce'));
});

it('alerts once after two consecutive failures and recovers once', function (): void {
    $this->socketUp = false;

    checkRealtime();
    expect(NotificationEvent::query()->count())->toBe(0);

    checkRealtime();
    checkRealtime();
    checkRealtime();
    $down = NotificationEvent::query()->where('event_key', 'platform.realtime.down')->get();
    expect($down)->toHaveCount(1)
        ->and($down->first()->body)->toContain('timed out');

    $this->socketUp = true;
    checkRealtime();
    checkRealtime();
    expect(NotificationEvent::query()->where('event_key', 'platform.realtime.recovered')->count())->toBe(1);

    // A new outage is a new alert.
    $this->socketUp = false;
    checkRealtime();
    checkRealtime();
    expect(NotificationEvent::query()->where('event_key', 'platform.realtime.down')->count())->toBe(2);
});

it('does not count failures right after the monitor record is written', function (): void {
    Cache::forget('edge:realtime:monitor:written_at');
    $this->socketUp = false;

    checkRealtime();
    checkRealtime();

    expect(NotificationEvent::query()->count())->toBe(0)
        ->and(Cache::get(CheckEdgeRealtimeCommand::FAILS_KEY))->toBeNull();
    Http::assertSent(fn ($r) => $r->method() === 'PUT' && str_contains($r->url(), '/storage/kv/namespaces/ns-test/values/key%3Artk_monitor_'));
});

it('does nothing when the relay namespace is not configured', function (): void {
    config(['edge.realtime.kv_namespace_id' => '']);

    $this->artisan('dply:edge:check-realtime')->expectsOutputToContain('Realtime monitor off')->assertSuccessful();

    Http::assertNothingSent();
    expect(Cache::get(CheckEdgeRealtimeCommand::LAST_KEY))->toBeNull()
        ->and(EdgeRealtimeMonitor::enabled())->toBeFalse();
});

it('can be switched off even when configured', function (): void {
    config(['edge.realtime.monitor.enabled' => false]);

    expect(EdgeRealtimeMonitor::enabled())->toBeFalse();
});
