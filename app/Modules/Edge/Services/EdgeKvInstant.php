<?php

declare(strict_types=1);

namespace App\Modules\Edge\Services;

use App\Models\EdgeDeployment;
use App\Models\EdgeRealtimeApp;
use App\Models\Site;
use App\Modules\Edge\Jobs\EdgeKvWriteJob;
use App\Modules\Edge\Services\Realtime\EdgeRealtimeApps;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Small, hot keys kept out of the host map (KV Instant, edge.kv_instant):
 *
 *  - gates (edge.cloudflare.gates_kv_namespace_id): container-pause:{site}
 *    and queue:{name}, read on every container request and queue batch.
 *  - routes (routes_kv_namespace_id, only with kv_instant.enabled):
 *    hostname -> the payload key the platform Worker reads from the host map.
 *  - realtime app records (edge.realtime.kv_namespace_id, when instant).
 *
 * A write names only the key and what it is; the value is worked out from
 * the database when it is written, so a retried or reordered write cannot
 * put back an old value. In instant mode writes are queued (EdgeKvWriteJob:
 * one per second per namespace, a key already queued is not queued again;
 * each write costs $0.10). Classic KV is written straight away.
 */
final class EdgeKvInstant
{
    public const GATE = 'gate';

    public const QUEUE_ROUTE = 'queue-route';

    public const ROUTE = 'route';

    public const REALTIME = 'realtime';

    /** Delete the key, whatever the database says. */
    public const DELETE = 'delete';

    /** Delete an old payload:{hostname}:{hash}, unless the hostname points back at it. */
    public const PAYLOAD_GC = 'payload-gc';

    public static function enabled(): bool
    {
        return (bool) config('edge.kv_instant.enabled');
    }

    /** The gates namespace, or null: gate keys stay in the host map. */
    public static function gatesNamespace(): ?string
    {
        $id = trim((string) config('edge.cloudflare.gates_kv_namespace_id', ''));

        return $id !== '' ? $id : null;
    }

    /** The routes namespace, used only in instant mode (on classic KV it would add a read for nothing). */
    public static function routesNamespace(): ?string
    {
        $id = trim((string) config('edge.cloudflare.routes_kv_namespace_id', ''));

        return $id !== '' && self::enabled() ? $id : null;
    }

    /** Keep writing the host map's copy of gate keys (Workers deployed before gates read only it). */
    public static function dualWrite(): bool
    {
        return self::gatesNamespace() === null || (bool) config('edge.kv_instant.dual_write', true);
    }

    /**
     * Write $key in $namespace from the current state: queued when the
     * namespace is an instant one ($queued, default edge.kv_instant.enabled),
     * else now.
     *
     * @param  array<int, string>  $args
     */
    public function sync(string $namespace, string $key, string $kind, array $args = [], ?bool $queued = null): void
    {
        if ($queued ?? self::enabled()) {
            EdgeKvWriteJob::dispatch($namespace, $key, $kind, $args);

            return;
        }
        $this->apply($namespace, $key, $kind, $args);
    }

    /** @param  array<int, string>  $args */
    public function apply(string $namespace, string $key, string $kind, array $args = []): void
    {
        $value = $this->value($key, $kind, $args);
        if ($value === false) {
            return;
        }
        $value === null ? $this->delete($namespace, $key) : $this->put($namespace, $key, $value);
    }

    /**
     * The key's value now: a string to write, null to delete, false to leave
     * it alone (nothing to go on).
     *
     * @param  array<int, string>  $args
     */
    public function value(string $key, string $kind, array $args = []): string|false|null
    {
        return match ($kind) {
            self::GATE => $this->gateValue($args[0] ?? ''),
            self::QUEUE_ROUTE => $this->queueRouteValue($key, $args[0] ?? '', $args[1] ?? ''),
            // The latest hash the publisher recorded; none (unpublished) deletes the pointer.
            self::ROUTE => is_string($hash = Cache::get(EdgeHostMapPublisher::ROUTE_CACHE.$key)) ? $hash : null,
            self::PAYLOAD_GC => Cache::get(EdgeHostMapPublisher::ROUTE_CACHE.($args[0] ?? '')) === ($args[1] ?? '') ? false : null,
            self::REALTIME => $this->realtimeValue($key, $args[0] ?? ''),
            self::DELETE => null,
            default => false,
        };
    }

    public function put(string $namespace, string $key, string $value): void
    {
        Http::withToken($this->token())
            ->withBody($value, 'text/plain')
            ->put($this->url($namespace, $key))
            ->throw();
    }

    public function delete(string $namespace, string $key): void
    {
        $response = Http::withToken($this->token())->delete($this->url($namespace, $key));
        if ($response->status() !== 404) {
            $response->throw();
        }
    }

    private function gateValue(string $siteId): string|false|null
    {
        $site = Site::query()->find($siteId);
        if ($site === null) {
            return null;
        }
        $paused = $site->edgeMeta()['traffic_gate'] ?? null;

        return $paused === null ? false : ($paused ? '1' : null);
    }

    private function queueRouteValue(string $key, string $siteId, string $deploymentId): string|false|null
    {
        $site = Site::query()->find($siteId);
        $deployment = EdgeDeployment::query()->find($deploymentId);
        if ($site === null || $deployment === null) {
            return false;
        }
        $script = EdgeQueueConsumers::liveScript($site, $deployment);

        return $script === '' ? false : (string) json_encode(['script' => $script, 'token' => EdgeQueueConsumers::token($site)]);
    }

    /** id:{app} and key:{app key} hold the same record; a key: entry for an old key is deleted. */
    private function realtimeValue(string $key, string $appId): ?string
    {
        $app = EdgeRealtimeApp::query()->find($appId);
        if ($app === null || (str_starts_with($key, 'key:') && $key !== 'key:'.$app->app_key)) {
            return null;
        }

        return EdgeRealtimeApps::recordFor($app);
    }

    private function token(): string
    {
        return (string) config('edge.cloudflare.api_token');
    }

    private function url(string $namespace, string $key): string
    {
        return 'https://api.cloudflare.com/client/v4/accounts/'.config('edge.cloudflare.account_id')
            .'/storage/kv/namespaces/'.$namespace.'/values/'.rawurlencode($key);
    }
}
