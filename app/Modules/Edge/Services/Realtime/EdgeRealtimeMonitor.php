<?php

declare(strict_types=1);

namespace App\Modules\Edge\Services\Realtime;

use App\Models\EdgeRealtimeApp;
use App\Modules\Providers\Cloudflare\EdgeCloudflareClient;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Synthetic round trip through the customer realtime relay: open a socket,
 * subscribe, publish over the HTTP API, see the event arrive.
 *
 * The monitor app is never an EdgeRealtimeApp row (no bill, no UI): an
 * unsaved model whose id/key/secret derive from APP_KEY, so every install
 * gets its own stable record and local runs never clobber production's.
 */
final class EdgeRealtimeMonitor
{
    public const TIMEOUT_SECONDS = 10;

    /** A fresh KV record can take ~60s to reach every edge; failures right after a write don't count. */
    public const WRITE_GRACE_SECONDS = 120;

    private const WRITTEN_KEY = 'edge:realtime:monitor:written_at';

    /**
     * @param  (Closure(string $url, string $channel, string $event, string $nonce, Closure $publish): void)|null  $socket
     *                                                                                                                      The socket leg; throws on failure. Defaults to the Node script.
     */
    public function __construct(private readonly ?Closure $socket = null) {}

    public static function enabled(): bool
    {
        return trim((string) config('edge.realtime.kv_namespace_id')) !== ''
            && (bool) config('edge.realtime.monitor.enabled', true);
    }

    public static function app(): EdgeRealtimeApp
    {
        $seed = static fn (string $what): string => hash_hmac('sha256', 'dply-realtime-monitor:'.$what, (string) config('app.key'));
        $app = new EdgeRealtimeApp([
            'name' => 'dply realtime monitor',
            'hostname' => null,
            'app_key' => 'rtk_monitor_'.substr($seed('key'), 0, 24),
            'app_secret' => 'rts_'.substr($seed('secret'), 0, 40),
            'status' => EdgeRealtimeApp::STATUS_ACTIVE,
            'max_connections' => 5,
            'allowed_origins' => [],
            'client_events' => false,
        ]);
        $app->id = 'monitor-'.substr($seed('id'), 0, 16);

        return $app;
    }

    /**
     * @return array{ok: bool, latency_ms: int, error: string|null, grace: bool}
     */
    public function check(): array
    {
        $started = hrtime(true);
        $grace = false;
        try {
            $grace = $this->ensureRecord();
            $app = self::app();
            $channel = 'dply-monitor';
            $event = 'ping';
            $nonce = Str::random(16);
            ($this->socket ?? $this->nodeRoundTrip(...))(
                'wss://'.EdgeRealtimeApps::host().'/app/'.$app->app_key.'?protocol=7&client=js&version=8.4.0',
                $channel,
                $event,
                $nonce,
                fn () => app(EdgeRealtimeApps::class)->publish($app, $channel, $event, ['nonce' => $nonce]),
            );
            $error = null;
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }

        return [
            'ok' => $error === null,
            'latency_ms' => (int) round((hrtime(true) - $started) / 1e6),
            'error' => $error,
            'grace' => $grace,
        ];
    }

    /** Write the KV record at most daily (heals a deleted one). True while inside the post-write grace. */
    private function ensureRecord(): bool
    {
        $writtenAt = Cache::get(self::WRITTEN_KEY);
        if ($writtenAt === null) {
            $record = EdgeRealtimeApps::record($app = self::app());
            $namespace = trim((string) config('edge.realtime.kv_namespace_id'));
            $client = EdgeCloudflareClient::fromConfig();
            $client->putKvValue($namespace, 'id:'.$app->id, $record);
            $client->putKvValue($namespace, 'key:'.$app->app_key, $record);
            Cache::put(self::WRITTEN_KEY, $writtenAt = time(), now()->addDay());
        }

        return time() - (int) $writtenAt < (EdgeRealtimeApps::instantKv() ? 10 : self::WRITE_GRACE_SECONDS);
    }

    private function nodeRoundTrip(string $url, string $channel, string $event, string $nonce, Closure $publish): void
    {
        $process = new Process([
            (string) config('edge.realtime.monitor.node', 'node'),
            base_path('packages/realtime-worker/scripts/monitor-roundtrip.mjs'),
            $url, $channel, $event, $nonce, (string) (self::TIMEOUT_SECONDS * 1000),
        ]);
        $process->setTimeout(self::TIMEOUT_SECONDS + 5);
        $process->start();
        try {
            $subscribed = $process->waitUntil(fn (): bool => str_contains($process->getOutput(), 'subscribed'));
            if (! $subscribed) {
                throw new RuntimeException(trim($process->getErrorOutput()) ?: 'The socket closed before subscribing.');
            }
            $publish();
            $process->wait();
            if (! $process->isSuccessful()) {
                throw new RuntimeException(trim($process->getErrorOutput()) ?: 'The published event never arrived.');
            }
        } finally {
            if ($process->isRunning()) {
                $process->stop(0);
            }
        }
    }
}
