<?php

declare(strict_types=1);

namespace App\Modules\Edge\Services\Realtime;

use App\Models\EdgeRealtimeApp;
use App\Models\Organization;
use App\Models\Site;
use App\Modules\Edge\Support\EdgeTestingDomains;
use App\Modules\Providers\Cloudflare\EdgeCloudflareClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Pusher\Pusher;
use RuntimeException;

/**
 * Realtime apps on the customer relay (packages/realtime-worker --env apps).
 * dply never redeploys the relay: an app exists once its record is in the
 * relay's KV namespace under id:{id} and key:{key}. See docs/edge-realtime.md.
 */
final class EdgeRealtimeApps
{
    /** Concurrent-socket sizes offered when creating one. */
    public const MAX_CONNECTION_SIZES = [100, 200, 500, 1000, 5000, 10000, 20000];

    /**
     * @param  array{max_connections?: int, allowed_origins?: list<string>, client_events?: bool}  $options
     */
    public function provision(Site $site, string $name, array $options = []): EdgeRealtimeApp
    {
        $namespace = self::namespaceId();
        $maxConnections = max(1, (int) ($options['max_connections'] ?? config('edge.realtime.default_max_connections', 200)));
        $cap = self::maxConnectionsFor($site->organization);
        if ($maxConnections > $cap) {
            throw new RuntimeException($cap === 0
                ? __('Your plan does not include Realtime. Choose a plan to add it.')
                : __('Your plan allows up to :cap connections per Realtime app. Pick a smaller size or upgrade.', ['cap' => number_format($cap)]));
        }
        $app = EdgeRealtimeApp::query()->create([
            'organization_id' => $site->organization_id,
            'site_id' => $site->id,
            'name' => trim($name) !== '' ? trim($name) : (string) $site->name,
            'hostname' => self::uniqueHostname(self::labelFor($site, $name)),
            'app_key' => 'rtk_'.Str::random(24),
            'app_secret' => 'rts_'.Str::random(40),
            'status' => EdgeRealtimeApp::STATUS_ACTIVE,
            'max_connections' => $maxConnections,
            'allowed_origins' => $options['allowed_origins'] ?? [],
            'client_events' => (bool) ($options['client_events'] ?? false),
        ]);

        try {
            $this->write($app, $namespace);
        } catch (\Throwable $e) {
            $app->delete();

            throw $e;
        }

        try {
            $this->attachDomain($app);
        } catch (\Throwable $e) {
            // Without its hostname the app is only half made: undo it all.
            $client = EdgeCloudflareClient::fromConfig();
            $client->deleteKvValue($namespace, 'id:'.$app->id);
            $client->deleteKvValue($namespace, 'key:'.$app->app_key);
            $app->delete();

            throw $e;
        }

        return $app;
    }

    /** Custom domains are on and the app has its own hostname. */
    public static function usesCustomDomain(EdgeRealtimeApp $app): bool
    {
        return (bool) config('edge.realtime.custom_domains') && (string) $app->hostname !== '';
    }

    /**
     * Attach the app's hostname to the relay Worker as a Workers Custom
     * Domain, so Cloudflare issues its certificate and routes it to the relay.
     */
    public function attachDomain(EdgeRealtimeApp $app): void
    {
        if (! self::usesCustomDomain($app)) {
            return;
        }
        $zone = (string) config('edge.realtime.zone_id');
        if ($zone === '') {
            throw new RuntimeException('Realtime custom domains are on but EDGE_REALTIME_ZONE_ID is not set.');
        }
        EdgeCloudflareClient::fromConfig()->attachWorkerDomain(
            (string) $app->hostname,
            (string) config('edge.realtime.worker', 'dply-realtime-apps'),
            $zone,
        );
    }

    /** Detach the app's custom domain. Best effort: a leftover domain only points at a relay that refuses the key. */
    public function detachDomain(EdgeRealtimeApp $app): void
    {
        if (! self::usesCustomDomain($app)) {
            return;
        }
        $client = EdgeCloudflareClient::fromConfig();
        $domain = $client->findWorkerDomain((string) $app->hostname);
        if (is_array($domain) && (string) ($domain['id'] ?? '') !== '') {
            $client->detachWorkerDomain((string) $domain['id']);
        }
    }

    /**
     * Rewrite both KV keys from the row. enabled comes from status; the size
     * is clamped to the plan (a downgrade shrinks the relay's limit without
     * touching the row, so sleep, wake and rotate keep working).
     */
    public function sync(EdgeRealtimeApp $app): void
    {
        $this->write($app, self::namespaceId());
    }

    /**
     * The map card's sleep switch. Asleep: the relay refuses connects and
     * publishes (enabled false) and open sockets are closed. Awake: enabled
     * again. The KV write goes first; if it fails the status is put back.
     */
    public function setAsleep(EdgeRealtimeApp $app, bool $asleep): void
    {
        $previous = $app->status;
        $app->status = $asleep ? EdgeRealtimeApp::STATUS_DISABLED : EdgeRealtimeApp::STATUS_ACTIVE;
        try {
            $this->sync($app);
        } catch (\Throwable $e) {
            $app->status = $previous;

            throw $e;
        }
        $app->save();
        if ($asleep) {
            try {
                $this->disconnectAll($app);
            } catch (\Throwable $e) {
                // KV already says disabled: new connects and publishes are refused.
                report($e);
            }
        }
    }

    /** Close every open socket (Pusher error 4003, app disabled). */
    public function disconnectAll(EdgeRealtimeApp $app): void
    {
        $this->operatorPost($app, '/disconnect');
    }

    /** Reset the relay's peak_connections to the live count (totals never reset). */
    public function resetPeak(EdgeRealtimeApp $app): void
    {
        $this->operatorPost($app, '/stats/reset');
    }

    /** The largest max_connections an app may have on the org's plan. */
    public static function maxConnectionsFor(Organization $organization): int
    {
        $tier = $organization->tierAllowances();
        if (! array_key_exists('realtime_max_connections', $tier)) {
            return 0;
        }

        return $tier['realtime_max_connections'] === null ? PHP_INT_MAX : max(0, (int) $tier['realtime_max_connections']);
    }

    public function rotateSecret(EdgeRealtimeApp $app): EdgeRealtimeApp
    {
        $app->app_secret = 'rts_'.Str::random(40);
        $app->save();
        $this->sync($app);

        return $app;
    }

    /**
     * Close open sockets, bill usage since the last run, delete both KV keys,
     * then the row (usage rows stay). Disconnect goes
     * first: sockets outlive the KV record, and the relay authenticates
     * /disconnect against it. Best-effort, so a relay outage cannot block it.
     */
    public function destroy(EdgeRealtimeApp $app): void
    {
        $namespace = self::namespaceId();
        try {
            $this->disconnectAll($app);
        } catch (\Throwable $e) {
            report($e);
        }
        try {
            // Bill the stretch since the last hourly run (disconnect flushed the closed sockets' time).
            app(EdgeRealtimeUsageCollector::class)->collectOne($app);
        } catch (\Throwable $e) {
            report($e);
        }
        $client = EdgeCloudflareClient::fromConfig();
        $client->deleteKvValue($namespace, 'id:'.$app->id);
        $client->deleteKvValue($namespace, 'key:'.$app->app_key);
        try {
            $this->detachDomain($app);
        } catch (\Throwable $e) {
            report($e);
        }
        $app->delete();
    }

    /**
     * The relay's counters for this app (contract: docs/edge-realtime.md → Stats).
     *
     * @return array{connections: int, peak_connections: int, connection_seconds: int, messages_in: int, messages_out: int, updated_at: int}
     */
    public function stats(EdgeRealtimeApp $app): array
    {
        $response = Http::timeout(5)
            ->withHeaders(['X-Dply-Key' => $app->app_key, 'X-Dply-Secret' => $app->app_secret])
            ->acceptJson()
            ->get(self::baseUrl().'/apps/'.$app->id.'/stats');
        $body = $response->json();
        if (! $response->successful() || ! is_array($body)) {
            throw new RuntimeException('Realtime stats could not be read (HTTP '.$response->status().').');
        }

        $out = [];
        foreach (['connections', 'peak_connections', 'connection_seconds', 'messages_in', 'messages_out', 'updated_at'] as $field) {
            $out[$field] = (int) ($body[$field] ?? 0);
        }

        return $out;
    }

    /**
     * Publish one event the way a Pusher/Reverb server SDK does: a signed
     * POST /apps/{id}/events. $data is sent as-is when a string, else JSON.
     *
     * @param  string|array<mixed>  $data
     */
    public function publish(EdgeRealtimeApp $app, string $channel, string $event, string|array $data): void
    {
        $path = '/apps/'.$app->id.'/events';
        $body = json_encode([
            'name' => $event,
            'channels' => [$channel],
            'data' => is_string($data) ? $data : json_encode($data, JSON_THROW_ON_ERROR),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $response = Http::timeout(5)
            ->withBody($body, 'application/json')
            ->post(self::baseUrl().$path.'?'.http_build_query(self::signedQuery($app->app_key, $app->app_secret, $path, $body)));
        if (! $response->successful()) {
            throw new RuntimeException('The relay refused the event (HTTP '.$response->status().'): '.$response->body());
        }
    }

    /**
     * Pusher HTTP API auth params (auth_key, auth_timestamp, auth_version,
     * body_md5, auth_signature). body_md5 is over the exact bytes sent.
     *
     * @return array<string, string>
     */
    public static function signedQuery(string $key, string $secret, string $path, string $body, ?string $timestamp = null): array
    {
        // pusher/pusher-php-server comes with laravel/reverb.
        return array_map('strval', Pusher::build_auth_query_params($key, $secret, 'POST', $path, ['body_md5' => md5($body)], '1.0', $timestamp));
    }

    /** The KV record, keys in contract order. $maxConnections overrides the row's size (plan clamp). */
    public static function record(EdgeRealtimeApp $app, ?int $maxConnections = null): string
    {
        $maxConnections ??= (int) $app->max_connections;

        return json_encode([
            'id' => $app->id,
            'key' => $app->app_key,
            'secret' => $app->app_secret,
            'enabled' => $app->status === EdgeRealtimeApp::STATUS_ACTIVE,
            'maxConnections' => $maxConnections,
            'allowedOrigins' => array_values($app->allowed_origins ?? []),
            'clientEvents' => (bool) $app->client_events,
            'maxMessageBytes' => (int) config('edge.realtime.max_message_bytes', 10240),
            'hostname' => $app->hostname,
            // Never fewer than the app has had: a shrink strands sockets and counters.
            'shards' => max(self::shardsFor($maxConnections), (int) (($app->meta ?? [])['shards'] ?? 1)),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Hub Durable Objects the relay spreads an app over: one per
     * edge.realtime.shard_size sockets, 1–32 (the relay's own ceiling).
     * Shrinking an app's count strands the higher shards' sockets and counters
     * (docs/edge-realtime.md → Sharding).
     */
    public static function shardsFor(int $maxConnections): int
    {
        $size = max(1, (int) config('edge.realtime.shard_size', 10000));

        return max(1, min(32, (int) ceil($maxConnections / $size)));
    }

    /** The shared relay host: control-plane calls always go here. */
    public static function host(): string
    {
        return (string) config('edge.realtime.host');
    }

    /** Where this app's browsers and server connect: its own host when per-app hosts are on, else the shared one. */
    public static function hostFor(?EdgeRealtimeApp $app): string
    {
        $own = (string) ($app?->hostname ?? '');

        return $own !== '' && (bool) config('edge.realtime.per_app_hosts') ? $own : self::host();
    }

    /**
     * The DNS label for an app: the site's own label on its dply delivery
     * host (shop-a1b2c3.on-dply.site → shop-a1b2c3), else a slug of the site
     * (or app) name plus a short random suffix.
     */
    public static function labelFor(?Site $site, string $name = ''): string
    {
        if ($site !== null) {
            $host = $site->edgeHostname();
            $zone = EdgeTestingDomains::zoneForHost($host);
            $label = $zone === null ? '' : substr($host, 0, -strlen('.'.$zone));
            if (self::isDnsLabel($label)) {
                return $label;
            }
        }
        $base = trim(substr(Str::slug((string) ($site->name ?? '') ?: $name), 0, 56), '-');

        return ($base === '' ? 'app' : $base).'-'.strtolower(Str::random(6));
    }

    /** {label}.{suffix}, with -2, -3… on the label until no other app has it. */
    public static function uniqueHostname(string $label, ?string $exceptId = null): string
    {
        $suffix = trim((string) config('edge.realtime.app_host_suffix', 'realtime.dply.io'), '.');
        // ponytail: check-then-insert; the unique index turns a lost race into an error, fine at this rate.
        for ($n = 1; ; $n++) {
            $tail = $n === 1 ? '' : '-'.$n;
            $host = trim(substr($label, 0, 63 - strlen($tail)), '-').$tail.'.'.$suffix;
            $taken = EdgeRealtimeApp::query()->where('hostname', $host)
                ->when($exceptId !== null, fn ($q) => $q->whereKeyNot($exceptId))->exists();
            if (! $taken) {
                return $host;
            }
        }
    }

    /** a-z, 0-9 and hyphens, 1–63 long, no hyphen at either end. */
    public static function isDnsLabel(string $label): bool
    {
        return preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $label) === 1;
    }

    private function write(EdgeRealtimeApp $app, string $namespace): void
    {
        $organization = $app->organization;
        $record = self::record($app, $organization === null ? null : min((int) $app->max_connections, self::maxConnectionsFor($organization)));
        $shards = (int) json_decode($record, true, flags: JSON_THROW_ON_ERROR)['shards'];
        if ($app->exists && $shards > (int) (($app->meta ?? [])['shards'] ?? 1)) {
            $app->forceFill(['meta' => ['shards' => $shards] + ($app->meta ?? [])])->saveQuietly();
        }
        $client = EdgeCloudflareClient::fromConfig();
        $client->putKvValue($namespace, 'id:'.$app->id, $record);
        $client->putKvValue($namespace, 'key:'.$app->app_key, $record);
    }

    private function operatorPost(EdgeRealtimeApp $app, string $suffix): void
    {
        $response = Http::timeout(5)
            ->withHeaders(['X-Dply-Key' => $app->app_key, 'X-Dply-Secret' => $app->app_secret])
            ->acceptJson()
            ->post(self::baseUrl().'/apps/'.$app->id.$suffix);
        if (! $response->successful()) {
            throw new RuntimeException('The relay refused '.$suffix.' (HTTP '.$response->status().').');
        }
    }

    private static function baseUrl(): string
    {
        return 'https://'.self::host();
    }

    private static function namespaceId(): string
    {
        $id = trim((string) config('edge.realtime.kv_namespace_id'));
        if ($id === '') {
            throw new RuntimeException('Realtime is not configured: set EDGE_REALTIME_KV_NAMESPACE_ID to the realtime relay\'s KV namespace.');
        }

        return $id;
    }
}
