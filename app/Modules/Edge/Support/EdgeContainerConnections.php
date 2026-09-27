<?php

declare(strict_types=1);

namespace App\Modules\Edge\Support;

use App\Models\EdgeDatabase;
use App\Models\EdgeDeployment;
use App\Models\EdgeQueue;
use App\Models\EdgeRealtimeApp;
use App\Models\Organization;
use App\Models\Site;
use App\Modules\Edge\Services\Realtime\EdgeRealtimeApps;
use App\Modules\Providers\Cloudflare\EdgeCloudflareClient;
use Illuminate\Http\Client\ConnectionException;

/**
 * Container connections. Called from EdgeContainerDeployer::scaffold and
 * the Resources page. Stored on edge meta `connections` as
 * {kind, name, host, target}. The app calls http://host/…; the worker
 * resolves that host to the attached resource.
 *
 * User request: support workers-connections and Workers bindings as
 * resources, without telling people it is Cloudflare.
 *
 * @phpstan-type Connection array{kind: string, name: string, host: string, target: string, asleep: bool, plan: string, read_regions: int}
 */
final class EdgeContainerConnections
{
    /**
     * @var array<string, array{label: string, needs_target: bool, hint: string}>
     */
    public const KINDS = [
        'key_value' => ['label' => 'Key-value store', 'needs_target' => true, 'hint' => 'GET or PUT http://host/key. GET http://host/ lists keys. Billed per read, write and GB stored.'],
        'durable_object' => ['label' => 'State', 'needs_target' => false, 'hint' => 'GET or PUT http://host/key. POST http://host/incr/key adds one. Use this for a counter or a lock.'],
        'redis' => ['label' => 'dply Valkey', 'needs_target' => false, 'hint' => 'Redis-compatible: your Redis client and REDIS_URL work unchanged. Start one here, or paste an address. The app connects directly. Commands and storage are billed with usage.'],
        'object_storage' => ['label' => 'Object storage', 'needs_target' => true, 'hint' => 'GET http://host/ lists objects. GET, PUT, or DELETE http://host/path'],
        'sql' => ['label' => 'SQL database', 'needs_target' => true, 'hint' => 'POST http://host/query with {"sql","params"}'],
        'queue' => ['label' => 'Queue', 'needs_target' => true, 'hint' => 'Jobs run in this app. POST http://host/send with the message body. The next deploy sets DPLY_QUEUE to this name.'],
        'ai' => ['label' => 'AI', 'needs_target' => false, 'hint' => 'POST http://host/run with {"model","input"}'],
        'vectors' => ['label' => 'Vector search', 'needs_target' => true, 'hint' => 'POST http://host/query with {"vector","topK"}'],
        'images' => ['label' => 'Images', 'needs_target' => false, 'hint' => 'POST the image to http://host/info for its size. POST http://host/?width=800&format=webp for a resized copy.'],
        'workflow' => ['label' => 'Workflow', 'needs_target' => true, 'hint' => 'POST http://host/start with {"id","params"}'],
        'database_pool' => ['label' => 'Database pool', 'needs_target' => true, 'hint' => 'GET http://host/ for the connection string'],
        'service' => ['label' => 'Another app', 'needs_target' => true, 'hint' => 'Any method on http://host/path is sent to that app'],
        'realtime' => ['label' => 'Realtime', 'needs_target' => false, 'hint' => 'WebSockets for Laravel Reverb, Echo, and Pusher clients. The next deploy sets the REVERB_* and PUSHER_* keys, and VITE_* for the asset build.'],
    ];

    /** Kinds this account can provision. The rest are attached by id, or just turned on. */
    public const CREATABLE = ['key_value', 'object_storage', 'sql', 'queue', 'vectors', 'database_pool'];

    /**
     * Kinds the Add a resource list leaves out. Existing rows still show.
     * A workflow binding needs a WorkflowEntrypoint class the container
     * Worker does not export, so its deploy fails (see the workflow sheet).
     */
    public const HIDDEN_FROM_BUILDER = ['workflow'];

    /** Vector index sizes offered when creating one (Vectorize allows up to 1536). */
    public const VECTOR_DIMENSIONS = [384, 768, 1024, 1536];

    public const VECTOR_METRICS = ['cosine', 'euclidean', 'dot-product'];

    /** Account capabilities. There is nothing to name or attach. */
    public const ENABLE = ['ai', 'images'];

    /**
     * Unmetered on our shared account (AI, Browser Rendering, Images,
     * Vectorize), so only a paid plan past its trial gets them: attach,
     * provision, and every deploy check {@see paidFeatures}.
     */
    public const PAID_ONLY = ['ai', 'browser', 'images', 'vectors'];

    /** The container Worker and the platform Worker already use these. */
    public const RESERVED_NAMES = ['APP', 'BILLING', ...EdgeEffectiveBindings::RESERVED_NAMES];

    /**
     * Kinds a Worker site (ssr, hybrid) can use. Workflows are not supported
     * in Workers for Platforms (T-016 spike). State and Another app go through
     * EdgeWorkerEntryWrapper; Redis through REDIS_URL.
     *
     * @var list<string>
     */
    public const WORKER_KINDS = ['key_value', 'durable_object', 'redis', 'object_storage', 'sql', 'queue', 'ai', 'vectors', 'images', 'database_pool', 'service', 'realtime'];

    /**
     * How Worker code reaches a kind, where env.NAME alone does not say enough.
     *
     * @var array<string, string>
     */
    public const WORKER_HINTS = [
        'durable_object' => "await env.NAME.fetch('https://state/key', { method: 'PUT', body: 'value' }). GET reads it back. POST https://state/incr/key adds one.",
        'service' => "await env.NAME.fetch('/path') calls that app's live address with the same method, headers, and body.",
        'redis' => 'REDIS_URL is set. Workers need a client that opens TCP sockets (cloudflare:sockets), such as node-redis with nodejs_compat.',
        'realtime' => 'env.REVERB_APP_KEY, env.REVERB_HOST and the PUSHER_* equivalents are set. Publish with any Pusher server SDK; browsers connect with Echo.',
    ];

    /**
     * Upload-API binding descriptors for a Worker site's connections.
     * Queue rows are left out: EdgeEffectiveBindings carries them.
     *
     * @return list<array<string, mixed>>
     */
    public static function workerBindings(Site $site): array
    {
        $out = [];
        $paid = self::paidFeatures($site->organization);
        foreach (self::for($site) as $connection) {
            if ($connection['asleep'] || $connection['kind'] === 'queue' || (! $paid && in_array($connection['kind'], self::PAID_ONLY, true))) {
                continue;
            }
            $name = $connection['name'];
            $target = $connection['target'];
            $binding = match ($connection['kind']) {
                'key_value' => ['type' => 'kv_namespace', 'namespace_id' => $target],
                'object_storage' => ['type' => 'r2_bucket', 'bucket_name' => $target],
                'sql' => ['type' => 'd1', 'id' => $target],
                'ai' => ['type' => 'ai'],
                'vectors' => ['type' => 'vectorize', 'index_name' => $target],
                'images' => ['type' => 'images'],
                'database_pool' => ['type' => 'hyperdrive', 'id' => $target],
                default => null,
            };
            if ($binding !== null) {
                $out[] = ['name' => $name] + $binding;
            }
        }
        if (self::browserEnabled($site)) {
            $out[] = ['name' => 'BROWSER', 'type' => 'browser'];
        }

        return $out;
    }

    /**
     * Add or replace a connection by name. Returns an error, or null.
     */
    public static function attach(Site $site, string $kind, string $name, string $target): ?string
    {
        $refused = self::attachError($site, $kind, $target);
        if ($refused !== null) {
            return $refused;
        }
        $resource = strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '-', $name), '-'));
        $row = self::normalize([
            'kind' => $kind,
            'name' => $name,
            'host' => self::resourceHost($site, $resource !== '' ? $resource : 'resource'),
            'target' => $target,
        ]);
        if ($row === null) {
            return __('That name cannot be used. Use letters, numbers, and underscores.');
        }
        $rows = array_values(array_filter(self::for($site), static fn (array $c): bool => $c['name'] !== $row['name'] && $c['host'] !== $row['host']));
        $rows[] = $row;
        $site->mergeEdgeMeta(['connections' => $rows]);
        $site->save();

        return null;
    }

    /**
     * Why this site may not bind the target, or null. Every org shares one
     * Cloudflare account, so a creatable kind must be this org's own
     * resource, and a paid-only kind needs a paid plan.
     */
    public static function attachError(Site $site, string $kind, string $target): ?string
    {
        $organization = $site->organization;
        if (in_array($kind, self::PAID_ONLY, true) && ! self::paidFeatures($organization)) {
            return self::paidOnlyReason();
        }
        if (! in_array($kind, self::CREATABLE, true)) {
            return null;
        }
        try {
            $owned = $organization !== null && self::owns($kind, $target, $organization);
        } catch (\Throwable) {
            $owned = false;
        }

        return $owned ? null : __('That :resource does not belong to this organization.', ['resource' => strtolower(self::targetLabel($kind))]);
    }

    /** AI, Browser, Images and vector search: a paid plan, not a trial. Comped orgs count. */
    public static function paidFeatures(?Organization $organization): bool
    {
        if (app()->isLocal() && config('edge.skip_card_check')) {
            return true;
        }

        return $organization !== null && $organization->onAnyPaidPlan() && ! $organization->onTrialPlan();
    }

    public static function paidOnlyReason(): string
    {
        return __('Needs a paid plan. Not included in the trial.');
    }

    /**
     * Paid plan on the workspace (a card is on file). DPLY_EDGE_SKIP_CARD_CHECK
     * lets a local install start paid resources without a card, for testing;
     * it does nothing outside APP_ENV=local.
     */
    public static function cardOnFile(?Organization $organization): bool
    {
        if (app()->isLocal() && config('edge.skip_card_check')) {
            return true;
        }

        return (bool) $organization?->onAnyPaidPlan();
    }

    /**
     * Drop a connection by name. The resource itself is left alone.
     */
    public static function detach(Site $site, string $name): void
    {
        $rows = array_values(array_filter(self::for($site), static fn (array $c): bool => $c['name'] !== $name));
        $site->mergeEdgeMeta(['connections' => $rows]);
        $site->save();
    }

    /**
     * Env the queue driver reads when a queue is attached. The operator's
     * own env is merged on top, so a saved QUEUE_CONNECTION or DPLY_QUEUE wins.
     *
     * @return array<string, string>
     */
    public static function queueDriverEnv(Site $site): array
    {
        $name = null;
        foreach (self::for($site) as $connection) {
            if ($connection['kind'] === 'queue' && ! $connection['asleep']) {
                $name = $connection['name'];
                break;
            }
        }
        if ($name === null) {
            return [];
        }

        $env = ['DPLY_QUEUE' => $name];
        if ($site->isLaravelFrameworkDetected()) {
            $env['QUEUE_CONNECTION'] = 'dply';
        }

        return $env;
    }

    /**
     * Laravel cache defaults when a Redis address is attached. REDIS_URL
     * itself is stored as an encrypted env var. A saved CACHE_STORE wins.
     *
     * @return array<string, string>
     */
    /** @var list<string> */
    public const MANAGED_REDIS_KEYS = ['REDIS_URL', 'REDIS_USERNAME', 'REDIS_PASSWORD', 'REDIS_HOST', 'REDIS_PORT'];

    public static function redisDriverEnv(Site $site): array
    {
        $awake = false;
        foreach (self::for($site) as $connection) {
            if (self::redisSuppliesEnv($site, $connection)) {
                $awake = true;
                break;
            }
        }
        if (! $awake) {
            return [];
        }

        $env = [];
        if ($site->isLaravelFrameworkDetected()) {
            $env['CACHE_STORE'] = 'redis';
            $env['REDIS_CLIENT'] = 'phpredis';
            // One TLS connection per php-fpm worker instead of a TLS handshake
            // + AUTH on every request (Laravel 11+ reads it into phpredis'
            // `persistent` option). The app's own env var still wins.
            $env['REDIS_PERSISTENT'] = 'true';
        }
        $stored = $site->edgeEnvVars()
            ->where('scope', 'production')
            ->whereIn('key', self::MANAGED_REDIS_KEYS)
            ->get()
            ->keyBy('key');
        $url = (string) ($stored->get('REDIS_URL')?->value ?? '');
        $parts = parse_url($url) ?: [];
        $derived = [
            'REDIS_URL' => $url,
            'REDIS_USERNAME' => rawurldecode((string) ($parts['user'] ?? '')) ?: 'default',
            'REDIS_PASSWORD' => rawurldecode((string) ($parts['pass'] ?? '')),
            'REDIS_HOST' => (string) ($parts['host'] ?? ''),
            'REDIS_PORT' => (string) ($parts['port'] ?? (str_starts_with($url, 'rediss://') ? 6379 : '')),
        ];
        foreach (self::MANAGED_REDIS_KEYS as $key) {
            $storedValue = (string) ($stored->get($key)?->value ?? '');
            $value = $storedValue !== '' ? $storedValue : ($derived[$key] ?? '');
            if ($value !== '') {
                $env[$key] = $value;
            }
        }

        return $env;
    }

    /**
     * Sleep keeps the database and drops its address from the app env.
     *
     * @param  array<string, string>  $env
     * @return array<string, string>
     */
    public static function omitAsleepRedis(Site $site, array $env): array
    {
        foreach (self::for($site) as $connection) {
            if ($connection['kind'] === 'redis' && ! self::redisSuppliesEnv($site, $connection)) {
                foreach (self::MANAGED_REDIS_KEYS as $key) {
                    unset($env[$key]);
                }
            }
        }

        return $env;
    }

    /**
     * A Redis we started stays off the app until the organization has a card.
     * A pasted address is not billed here.
     *
     * @param  array{kind: string, asleep: bool, target: string}  $connection
     */
    /** Whether a Redis connection's address actually reaches the app (dply Valkey's Pro sizes need a paid plan). */
    public static function redisSuppliesEnv(Site $site, array $connection): bool
    {
        if ($connection['kind'] !== 'redis' || $connection['asleep']) {
            return false;
        }

        if (! EdgeValkey::isTarget((string) $connection['target'])) {
            return true;
        }
        // dply Valkey is on every plan; only the advanced Pro sizes (always
        // on, append-only file) need a paid plan.
        $class = (string) ($connection['plan'] ?? EdgeValkey::DEFAULT_CLASS);

        return (EdgeValkey::CLASSES[$class]['sleeps'] ?? true) || (bool) $site->organization?->onAnyPaidPlan();
    }

    /**
     * Values shown under From resources. The password stays masked.
     *
     * @return list<array{key: string, value: string, from: string}>
     */
    public static function redisInjectionPreview(Site $site): array
    {
        $rows = [];
        foreach (self::redisDriverEnv($site) as $key => $value) {
            $shown = match ($key) {
                'REDIS_URL' => self::maskRedisUrl($value),
                'REDIS_PASSWORD' => '••••',
                default => $value,
            };
            $rows[] = ['key' => $key, 'value' => $shown, 'from' => 'Redis'];
        }

        return $rows;
    }

    private static function maskRedisUrl(string $url): string
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['host'])) {
            return '••••';
        }
        $scheme = (string) ($parts['scheme'] ?? 'rediss');
        $user = rawurldecode((string) ($parts['user'] ?? 'default'));
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return $scheme.'://'.$user.':••••@'.$parts['host'].$port;
    }

    /**
     * S3-shaped env for an attached bucket. The operator's env is merged
     * on top, so a saved FILESYSTEM_DISK or AWS_ACCESS_KEY_ID wins.
     * dply/laravel turns this into Storage::disk('s3') and Storage::put().
     *
     * @return array<string, string>
     */
    public static function storageDriverEnv(Site $site): array
    {
        $pairs = [];
        $host = null;
        $disk = null;
        foreach (self::for($site) as $connection) {
            if ($connection['kind'] !== 'object_storage' || $connection['asleep']) {
                continue;
            }
            $name = strtolower($connection['name']);
            $pairs[] = $name.'='.$connection['host'];
            if ($host === null) {
                $host = $connection['host'];
                $disk = $name;
            }
        }
        if ($host === null || $disk === null) {
            return [];
        }

        $env = [
            'DPLY_STORAGE_HOST' => $host,
            'DPLY_STORAGE_DISK' => $disk,
            'DPLY_STORAGE_DISKS' => implode(',', $pairs),
        ];
        if ($site->isLaravelFrameworkDetected()) {
            $env['FILESYSTEM_DISK'] = $disk;
        }

        return $env;
    }

    /**
     * Cache env for an attached key-value store. dply/laravel registers
     * Cache::store($name). dply-rails uses Rails.cache when Redis is absent.
     *
     * @return array<string, string>
     */
    public static function kvDriverEnv(Site $site): array
    {
        $pairs = [];
        $host = null;
        $store = null;
        foreach (self::for($site) as $connection) {
            if ($connection['kind'] !== 'key_value' || $connection['asleep']) {
                continue;
            }
            $name = strtolower($connection['name']);
            $pairs[] = $name.'='.$connection['host'];
            if ($host === null) {
                $host = $connection['host'];
                $store = $name;
            }
        }
        if ($host === null || $store === null) {
            return [];
        }

        $env = [
            'DPLY_KV_HOST' => $host,
            'DPLY_KV_STORE' => $store,
            'DPLY_KV_STORES' => implode(',', $pairs),
        ];
        $hasRedis = false;
        foreach (self::for($site) as $connection) {
            if (self::redisSuppliesEnv($site, $connection)) {
                $hasRedis = true;
            }
        }
        if ($site->isLaravelFrameworkDetected() && ! $hasRedis) {
            $env['CACHE_STORE'] = $store;
        }

        return $env;
    }

    /**
     * Reverb / Pusher env for an attached Realtime app (docs/edge-realtime.md).
     * The operator's env is merged on top, so a saved BROADCAST_CONNECTION wins.
     * VITE_* also reach the asset build ({@see realtimeBuildEnv}).
     *
     * @return array<string, string>
     */
    public static function realtimeDriverEnv(Site $site): array
    {
        $app = self::realtimeApp($site);
        if ($app === null) {
            return [];
        }

        $host = EdgeRealtimeApps::hostFor($app);
        $env = [];
        if ($site->isLaravelFrameworkDetected()) {
            $env['BROADCAST_CONNECTION'] = 'reverb';
        }
        foreach (['REVERB', 'PUSHER'] as $driver) {
            $env += [
                $driver.'_APP_ID' => $app->id,
                $driver.'_APP_KEY' => $app->app_key,
                $driver.'_APP_SECRET' => $app->app_secret,
                $driver.'_HOST' => $host,
                $driver.'_PORT' => '443',
                $driver.'_SCHEME' => 'https',
            ];
        }
        $env['PUSHER_APP_CLUSTER'] = 'mt1';
        foreach (['REVERB', 'PUSHER'] as $driver) {
            $env += [
                'VITE_'.$driver.'_APP_KEY' => $app->app_key,
                'VITE_'.$driver.'_HOST' => $host,
                'VITE_'.$driver.'_PORT' => '443',
                'VITE_'.$driver.'_SCHEME' => 'https',
            ];
        }
        $env['VITE_PUSHER_APP_CLUSTER'] = 'mt1';

        return $env;
    }

    /**
     * The VITE_* half of realtimeDriverEnv: Vite bakes these into the JS, so
     * the build needs them, not just the running app.
     *
     * @return array<string, string>
     */
    public static function realtimeBuildEnv(Site $site): array
    {
        return array_filter(self::realtimeDriverEnv($site), static fn (string $key): bool => str_starts_with($key, 'VITE_'), ARRAY_FILTER_USE_KEY);
    }

    /**
     * realtimeDriverEnv as Worker bindings (ssr / hybrid). Names in $taken
     * (the site's own env) are left out so the upload has no duplicates.
     * Secrets go as secret_text, the rest as plain_text.
     *
     * @param  list<string>  $taken
     * @return list<array{name: string, type: string, text: string}>
     */
    public static function realtimeWorkerBindings(Site $site, array $taken = []): array
    {
        $out = [];
        foreach (self::realtimeDriverEnv($site) as $key => $value) {
            if (in_array($key, $taken, true)) {
                continue;
            }
            $out[] = ['name' => $key, 'type' => str_ends_with($key, '_SECRET') ? 'secret_text' : 'plain_text', 'text' => $value];
        }

        return $out;
    }

    /**
     * The Realtime app attached to this site, when this organization owns it,
     * asleep or not. Unlike the other kinds, a sleeping Realtime app keeps its
     * env: sleep is enforced by the relay (KV enabled false, sockets closed;
     * EdgeRealtimeApps::setAsleep), so waking it needs no redeploy — and the
     * VITE_* keys baked into the JS stay valid.
     */
    public static function realtimeApp(Site $site): ?EdgeRealtimeApp
    {
        foreach (self::for($site) as $connection) {
            if ($connection['kind'] !== 'realtime' || $connection['target'] === '') {
                continue;
            }

            return EdgeRealtimeApp::query()
                ->whereKey($connection['target'])
                ->where('organization_id', $site->organization_id)
                ->first();
        }

        return null;
    }

    /**
     * dply.{app}.{resource}.internal so two apps can both have a store named testing.
     */
    public static function resourceHost(Site $site, string $resource): string
    {
        $slug = strtolower(trim((string) preg_replace('/[^a-z0-9]+/', '-', (string) $site->slug), '-'));
        $slug = substr($slug !== '' ? $slug : 'app', 0, 63);
        $resource = substr($resource, 0, 63);

        return 'dply.'.$slug.'.'.$resource.'.internal';
    }

    public static function resourceLabel(string $host): string
    {
        if (preg_match('/^dply\.[a-z0-9-]+\.([a-z0-9-]+)\.internal$/', strtolower($host), $match) === 1) {
            return $match[1];
        }

        return str_replace('.internal', '', strtolower($host));
    }

    /**
     * Rewrite bare name.internal hosts. Returns true when a row changed.
     */
    public static function prefixBareHosts(Site $site): bool
    {
        $rows = self::for($site);
        $changed = false;
        foreach ($rows as $index => $connection) {
            if (preg_match('/^[a-z0-9-]+\.internal$/', $connection['host']) !== 1) {
                continue;
            }
            $label = self::resourceLabel($connection['host']);
            $host = self::resourceHost($site, $label);
            if ($host === $connection['host']) {
                continue;
            }
            $rows[$index]['host'] = $host;
            $changed = true;
        }
        if ($changed) {
            $site->mergeEdgeMeta(['connections' => $rows]);
            $site->save();
        }

        return $changed;
    }

    /**
     * @return list<Connection>
     */
    public static function for(Site $site): array
    {
        $raw = $site->edgeMeta()['connections'] ?? [];
        if (! is_array($raw)) {
            return [];
        }

        $kept = [];
        $hosts = [];
        $names = [];
        foreach ($raw as $row) {
            if (! is_array($row)) {
                continue;
            }
            $normalized = self::normalize($row);
            if ($normalized === null || isset($hosts[$normalized['host']]) || isset($names[$normalized['name']])) {
                continue;
            }
            $hosts[$normalized['host']] = true;
            $names[$normalized['name']] = true;
            $kept[] = $normalized;
        }

        return $kept;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return Connection|null
     */
    public static function normalize(array $row): ?array
    {
        $kind = (string) ($row['kind'] ?? '');
        // Case is kept: on a Worker the name is the code's env.NAME.
        $name = trim((string) ($row['name'] ?? ''));
        $host = strtolower(trim((string) ($row['host'] ?? '')));
        $target = trim((string) ($row['target'] ?? ''));
        if (! isset(self::KINDS[$kind]) || ! preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,40}$/', $name)) {
            return null;
        }
        if (in_array($name, self::RESERVED_NAMES, true) || ! preg_match('/^[a-z0-9]([a-z0-9-]{0,62}\.)+[a-z]{2,12}$/', $host)) {
            return null;
        }
        if (self::KINDS[$kind]['needs_target'] && $target === '') {
            return null;
        }

        $plan = (string) ($row['plan'] ?? '');
        if (! isset(EdgeValkey::CLASSES[$plan])) {
            $plan = '';
        }

        return [
            'kind' => $kind,
            'name' => $name,
            'host' => $host,
            'target' => $target,
            'asleep' => (bool) ($row['asleep'] ?? false),
            'plan' => $plan,
            'read_regions' => max(0, (int) ($row['read_regions'] ?? 0)),
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public static function mergeWrangler(array $config, Site $site): array
    {
        $paid = self::paidFeatures($site->organization);
        foreach (self::for($site) as $connection) {
            if ($connection['asleep'] || (! $paid && in_array($connection['kind'], self::PAID_ONLY, true))) {
                continue;
            }
            if ($connection['kind'] === 'durable_object') {
                $config['durable_objects']['bindings'][] = [
                    'name' => $connection['name'],
                    'class_name' => 'EdgeState',
                ];

                continue;
            }
            // Queues come in through EdgeEffectiveBindings with the repo's,
            // which also makes this app their consumer.
            if ($connection['kind'] === 'queue') {
                continue;
            }
            $entry = self::wranglerEntry($connection);
            if ($entry === null) {
                continue;
            }
            [$key, $value, $list] = $entry;
            if ($list) {
                $existing = $config[$key] ?? [];
                $config[$key] = array_merge(is_array($existing) ? $existing : [], [$value]);
            } else {
                $config[$key] = $value;
            }
        }

        $certificateId = self::clientCertificateId($site);
        if ($certificateId !== '') {
            $existing = $config['mtls_certificates'] ?? [];
            $config['mtls_certificates'] = array_merge(is_array($existing) ? $existing : [], [[
                'binding' => 'CLIENT_CERT',
                'certificate_id' => $certificateId,
            ]]);
        }

        if (self::browserEnabled($site)) {
            $config['browser'] = ['binding' => 'BROWSER'];
        }

        $callsAnotherApp = false;
        foreach (self::for($site) as $connection) {
            if ($connection['kind'] === 'service' && ! $connection['asleep']) {
                $callsAnotherApp = true;
                break;
            }
        }
        $namespace = trim((string) config('edge.cloudflare.dispatch_namespace_name'));
        if ($callsAnotherApp && $namespace !== '') {
            $config['dispatch_namespaces'] = [['binding' => 'DISPATCHER', 'namespace' => $namespace]];
        }

        return $config;
    }

    /**
     * Other container apps in this organization that this app can call.
     *
     * @return list<array{id: string, label: string, script: string, origin: string}>
     */
    public static function peerApps(Site $site): array
    {
        $rows = [];
        $peers = Site::query()
            ->where('organization_id', $site->organization_id)
            ->where('id', '!=', $site->id)
            ->orderBy('name')
            ->get();
        // A container calls peers through its dispatcher, so only containers
        // qualify. A Worker site calls the peer's live URL, so any app with code does.
        $runtimes = ($site->edgeMeta()['runtime_mode'] ?? '') === 'container' ? ['container'] : ['container', 'ssr', 'hybrid'];
        foreach ($peers as $peer) {
            if ($peer->isEdgePreview() || ! in_array($peer->edgeMeta()['runtime_mode'] ?? '', $runtimes, true)) {
                continue;
            }
            $origin = $peer->edgeLiveUrl();
            if ($origin === null) {
                continue;
            }
            $rows[] = [
                'id' => (string) $peer->id,
                'label' => (string) $peer->name,
                'script' => 'dply-ctr-'.strtolower((string) $peer->id),
                'origin' => $origin,
            ];
        }

        return $rows;
    }

    public static function clientCertificateId(Site $site): string
    {
        $row = $site->edgeMeta()['client_certificate'] ?? null;

        return is_array($row) ? trim((string) ($row['id'] ?? '')) : '';
    }

    public static function browserEnabled(Site $site): bool
    {
        if (($site->edgeMeta()['browser'] ?? null) === false || ! self::paidFeatures($site->organization)) {
            return false;
        }
        if (($site->edgeMeta()['browser'] ?? null) === true) {
            return true;
        }
        foreach ($site->edgeMeta()['connections'] ?? [] as $row) {
            if (is_array($row) && ($row['kind'] ?? '') === 'browser' && empty($row['asleep'])) {
                return true;
            }
        }

        return false;
    }

    public static function browserHost(Site $site): string
    {
        $slug = strtolower(trim((string) $site->slug));
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', $slug), '-');

        return 'dply.'.($slug !== '' ? $slug : 'app').'.internal';
    }

    /**
     * @param  Connection  $connection
     * @return array{0: string, 1: mixed, 2: bool}|null
     */
    private static function wranglerEntry(array $connection): ?array
    {
        $name = $connection['name'];
        $target = $connection['target'];

        return match ($connection['kind']) {
            'key_value' => ['kv_namespaces', ['binding' => $name, 'id' => $target], true],
            'object_storage' => ['r2_buckets', ['binding' => $name, 'bucket_name' => $target], true],
            'sql' => ['d1_databases', ['binding' => $name, 'database_name' => strtolower($name), 'database_id' => $target], true],
            'ai' => ['ai', ['binding' => $name], false],
            'vectors' => ['vectorize', ['binding' => $name, 'index_name' => $target], true],
            'images' => ['images', ['binding' => $name], false],
            'workflow' => ['workflows', ['binding' => $name, 'name' => $target, 'class_name' => $name], true],
            'database_pool' => ['hyperdrive', ['binding' => $name, 'id' => $target], true],
            default => null,
        };
    }

    /**
     * @return array{name: string, host: string, resource: string}|null
     */
    public static function identity(string $label, ?Site $site = null): ?array
    {
        $resource = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $label) ?? '', '-'));
        $name = strtoupper(str_replace('-', '_', $resource));
        if ($resource === '' || ! preg_match('/^[A-Z][A-Z0-9_]{0,40}$/', $name)) {
            return null;
        }

        $host = $site instanceof Site ? self::resourceHost($site, $resource) : $resource.'.internal';

        return ['name' => $name, 'host' => $host, 'resource' => $resource];
    }

    public static function targetLabel(string $kind): string
    {
        return match ($kind) {
            'key_value' => 'Store',
            'object_storage' => 'Bucket',
            'sql' => 'Database',
            'queue' => 'Queue',
            'vectors' => 'Index',
            'workflow' => 'Workflow',
            'database_pool' => 'Pool',
            'service' => 'App name',
            default => 'Resource',
        };
    }

    /**
     * @return list<array{id: string, label: string}>
     */
    /**
     * dply's Cloudflare account is shared by every organization, so each name
     * created from here carries the organization's prefix, and only names with
     * it are offered to attach or ever deleted. Same prefix as the org
     * Databases and Queues pages (EdgeDatabase / EdgeQueue::cloudflareName).
     */
    public static function ownedPrefix(Organization $organization): string
    {
        return 'dply-'.strtolower((string) $organization->id).'-';
    }

    /**
     * This organization's existing resources of a kind, to attach.
     *
     * @return list<array{id: string, label: string}>
     */
    public static function catalog(string $kind, Organization $organization): array
    {
        if ($kind === 'realtime') {
            return EdgeRealtimeApp::query()->where('organization_id', $organization->id)->orderBy('name')->get()
                ->map(static fn (EdgeRealtimeApp $app): array => ['id' => $app->id, 'label' => $app->name])->all();
        }
        if (! in_array($kind, self::CREATABLE, true)) {
            return [];
        }

        try {
            $client = EdgeCloudflareClient::fromConfig();
            $rows = match ($kind) {
                'key_value' => $client->listKvNamespaces(),
                'object_storage' => $client->listR2Buckets(),
                'sql' => $client->listD1Databases(),
                'queue' => $client->listQueues(),
                'vectors' => $client->listVectorizeIndexes(),
                'database_pool' => $client->listHyperdriveConfigs(),
                default => [],
            };
        } catch (\Throwable) {
            return [];
        }

        $prefix = self::ownedPrefix($organization);
        $options = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $name = (string) ($row['title'] ?? $row['queue_name'] ?? $row['name'] ?? '');
            if (! str_starts_with($name, $prefix)) {
                continue;
            }
            $id = match ($kind) {
                'object_storage', 'queue', 'vectors' => $name,
                default => (string) ($row['id'] ?? $row['uuid'] ?? ''),
            };
            if ($id !== '') {
                $options[] = ['id' => $id, 'label' => substr($name, strlen($prefix))];
            }
        }

        return $options;
    }

    /**
     * Create the remote resource, named under the organization's prefix, and
     * return the id the binding stores. D1 databases and queues are recorded
     * like the org pages record them, so they are metered and listed there.
     * $options['location_hint'] places a new R2 bucket (EdgeCloudflareClient::createR2Bucket).
     * A vector index takes dimensions (768) and metric (cosine); a database
     * pool needs origin {host, port, database, user, password, scheme}.
     *
     * @param  array{location_hint?: ?string, jurisdiction?: ?string, dimensions?: int, metric?: string, origin?: array<string, mixed>}  $options
     */
    public static function provision(string $kind, string $resource, Organization $organization, array $options = []): string
    {
        $refused = self::creationError($kind, $organization);
        if ($refused !== null) {
            throw new \RuntimeException($refused);
        }
        $client = EdgeCloudflareClient::fromConfig();
        $name = self::ownedPrefix($organization).($kind === 'key_value' ? $resource : strtolower($resource));
        $created = match ($kind) {
            'key_value' => $client->createKvNamespace($name),
            'object_storage' => $client->createR2Bucket($name, $options['location_hint'] ?? null, $options['jurisdiction'] ?? null),
            'sql' => $client->createD1Database($name, (string) ($options['location_hint'] ?? '') ?: 'wnam'),
            'queue' => $client->createQueue($name),
            'vectors' => $client->createVectorizeIndex(...self::vectorIndexArgs($name, $options)),
            'database_pool' => self::createPool($client, $name, $options['origin'] ?? null),
            default => throw new \InvalidArgumentException('This resource cannot be created here.'),
        };

        $target = match ($kind) {
            'key_value', 'sql' => (string) ($created['id'] ?? $created['uuid'] ?? ''),
            'object_storage', 'vectors' => $name,
            'queue' => (string) ($created['queue_name'] ?? $name),
            'database_pool' => (string) ($created['id'] ?? ''),
            default => '',
        };

        if ($kind === 'sql' && $target !== '') {
            EdgeDatabase::query()->firstOrCreate(['cloudflare_id' => $target], [
                'organization_id' => $organization->id,
                // Lowercase like the Cloudflare name, so ensure() finds it again.
                'name' => strtolower($resource),
                'created_by' => auth()->id(),
            ]);
        }
        if ($kind === 'queue') {
            EdgeQueue::query()->firstOrCreate(['cloudflare_name' => $target], [
                'organization_id' => $organization->id,
                'name' => $resource,
                'cloudflare_id' => (string) ($created['queue_id'] ?? $created['id'] ?? ''),
                'created_by' => auth()->id(),
            ]);
        }

        return $target;
    }

    /**
     * Why this organization may not create one more of this kind, or null.
     * Every creation path (Resources, Jobs bindings, repo auto-create) comes
     * through provision(), so the plan's counts and the card rule live here.
     */
    public static function creationError(string $kind, Organization $organization): ?string
    {
        if ($kind === 'key_value' && ! self::cardOnFile($organization)) {
            return __('Add a card before starting a key-value store. Reads, writes, and storage are billed to that card.');
        }
        if (in_array($kind, self::PAID_ONLY, true) && ! self::paidFeatures($organization)) {
            return self::paidOnlyReason();
        }
        [$allowance, $model, $noun] = match ($kind) {
            'sql' => ['databases', EdgeDatabase::class, 'databases'],
            'queue' => ['queues', EdgeQueue::class, 'queues'],
            default => [null, null, null],
        };
        $limit = $allowance !== null ? ($organization->tierAllowances()[$allowance] ?? null) : null;
        if ($limit !== null && $model::query()->where('organization_id', $organization->id)->count() >= $limit) {
            return __('Your :plan plan includes :count :noun. Upgrade on the billing page for more.', ['plan' => $organization->planTierLabel(), 'count' => $limit, 'noun' => $noun]);
        }

        return null;
    }

    /**
     * The org's resource named $resource under its prefix, created when there
     * is none. Used where a name is typed or declared (Jobs bindings, a repo's
     * wrangler.toml): "cache" means {prefix}cache, never someone else's cache.
     *
     * @param  array{location_hint?: ?string, jurisdiction?: ?string}  $options
     */
    public static function ensure(string $kind, string $resource, Organization $organization, array $options = []): string
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9-]{0,39}$/', $resource) !== 1) {
            throw new \InvalidArgumentException(__('Use a name of letters, numbers, and dashes.'));
        }
        $client = EdgeCloudflareClient::fromConfig();
        $name = self::ownedPrefix($organization).($kind === 'key_value' ? $resource : strtolower($resource));
        $existing = match ($kind) {
            'key_value' => (string) $client->kvNamespaceIdByTitle($name),
            'object_storage' => $client->r2BucketExists($name) ? $name : '',
            // The org pages' rows first: exact, and no API list that pages.
            'sql' => (string) (EdgeDatabase::query()->where('organization_id', $organization->id)->where('name', strtolower($resource))->value('cloudflare_id')
                ?? collect($client->listD1Databases())->firstWhere('name', $name)['uuid'] ?? ''),
            'queue' => EdgeQueue::query()->where('organization_id', $organization->id)->where('cloudflare_name', $name)->exists()
                || collect($client->listQueues())->contains('queue_name', $name) ? $name : '',
            default => throw new \InvalidArgumentException('This resource cannot be created here.'),
        };

        if ($existing !== '') {
            return $existing;
        }
        // Repos deployed before resources were prefixed bound the bare name.
        // Creating a fresh, empty `{prefix}name` would quietly run the app on
        // empty data, so stop and say so instead.
        if (($options['refuse_legacy'] ?? false) && self::legacyExists($client, $kind, $kind === 'key_value' ? $resource : strtolower($resource))) {
            throw new \RuntimeException(__('":name" is a resource created before dply kept each organization\'s resources separate. Ask support to move it into your organization; dply will not create an empty one in its place.', ['name' => $resource]));
        }
        try {
            return self::provision($kind, $resource, $organization, $options);
        } catch (\RuntimeException $e) {
            // The bucket list pages; a bucket under our prefix is ours to reuse.
            if ($kind === 'object_storage' && stripos($e->getMessage(), 'already exists') !== false) {
                return $name;
            }
            throw $e;
        }
    }

    /** An unprefixed resource of this exact name exists in the account. */
    private static function legacyExists(EdgeCloudflareClient $client, string $kind, string $name): bool
    {
        return match ($kind) {
            'key_value' => $client->kvNamespaceIdByTitle($name) !== null,
            'object_storage' => $client->r2BucketExists($name),
            'sql' => collect($client->listD1Databases())->contains('name', $name),
            'queue' => collect($client->listQueues())->contains('queue_name', $name),
            default => false,
        };
    }

    /** Whether this organization created the resource (so it may delete it). */
    public static function owns(string $kind, string $target, Organization $organization): bool
    {
        if ($kind === 'realtime') {
            return $target !== '' && EdgeRealtimeApp::query()->whereKey($target)->where('organization_id', $organization->id)->exists();
        }
        if ($target === '' || ! in_array($kind, self::CREATABLE, true)) {
            return false;
        }
        $prefix = self::ownedPrefix($organization);

        return match ($kind) {
            'object_storage' => str_starts_with($target, $prefix),
            'queue' => str_starts_with($target, $prefix)
                || EdgeQueue::query()->where('organization_id', $organization->id)->where('cloudflare_name', $target)->exists(),
            'sql' => EdgeDatabase::query()->where('organization_id', $organization->id)->where('cloudflare_id', $target)->exists()
                || (preg_match('/^[a-f0-9-]{36}$/i', $target) === 1
                    && str_starts_with((string) (EdgeCloudflareClient::fromConfig()->getD1Database($target)['name'] ?? ''), $prefix)),
            // By id: the namespace list pages, and the account holds every org's.
            'key_value' => preg_match('/^[a-f0-9]{32}$/', $target) === 1
                && str_starts_with((string) (EdgeCloudflareClient::fromConfig()->getKvNamespace($target)['title'] ?? ''), $prefix),
            // Older rows hold a typed name or id, so check its shape before it reaches an API path.
            'vectors' => preg_match('/^[a-z0-9-]{1,64}$/', $target) === 1 && str_starts_with($target, $prefix),
            'database_pool' => preg_match('/^[a-f0-9]{32}$/', $target) === 1
                && str_starts_with((string) (EdgeCloudflareClient::fromConfig()->getHyperdriveConfig($target)['name'] ?? ''), $prefix),
            default => false,
        };
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{0: string, 1: int, 2: string}
     */
    private static function vectorIndexArgs(string $name, array $options): array
    {
        $dimensions = (int) ($options['dimensions'] ?? 768);
        $metric = (string) ($options['metric'] ?? 'cosine');
        if (strlen($name) > 64 || preg_match('/^[a-z0-9-]+$/', $name) !== 1) {
            // The organization prefix takes 32 of Vectorize's 64 bytes.
            throw new \InvalidArgumentException(__('Use a shorter name: at most 32 letters, numbers, and dashes.'));
        }
        if (! in_array($dimensions, self::VECTOR_DIMENSIONS, true) || ! in_array($metric, self::VECTOR_METRICS, true)) {
            throw new \InvalidArgumentException(__('Pick one of the offered dimensions and metrics.'));
        }

        return [$name, $dimensions, $metric];
    }

    /** @return array<string, mixed> */
    private static function createPool(EdgeCloudflareClient $client, string $name, mixed $origin): array
    {
        if (! is_array($origin) || ($origin['host'] ?? '') === '' || ($origin['password'] ?? '') === '' || ! in_array($origin['scheme'] ?? '', ['postgres', 'mysql'], true)) {
            throw new \InvalidArgumentException(__('The pool needs a Postgres or MySQL database to point at.'));
        }
        try {
            return $client->createHyperdriveConfig($name, $origin);
        } catch (ConnectionException) {
            throw new \RuntimeException(__('Could not reach the database in time. It may be waking up. Try again in a minute.'));
        }
    }

    /**
     * Mint a client certificate for this app and upload it. The name and id
     * are chosen here. The operator does not type them.
     */
    public static function issueClientCertificate(Site $site): string
    {
        $config = tempnam(sys_get_temp_dir(), 'dply-leaf-');
        if ($config === false) {
            throw new \RuntimeException('Could not create a client certificate.');
        }
        file_put_contents($config, "[req]\ndistinguished_name = dn\n[dn]\n[v3_leaf]\nbasicConstraints = critical,CA:FALSE\nkeyUsage = critical,digitalSignature\nextendedKeyUsage = clientAuth\nsubjectKeyIdentifier = hash\n");
        $options = ['digest_alg' => 'sha256', 'config' => $config, 'x509_extensions' => 'v3_leaf'];
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'config' => $config]);
        $csr = $key !== false ? openssl_csr_new(['commonName' => 'client.'.$site->id], $key, $options) : false;
        $signed = ($csr !== false && $key !== false) ? openssl_csr_sign($csr, null, $key, 365, $options) : false;
        @unlink($config);
        if ($signed === false || ! openssl_x509_export($signed, $certificate) || ! openssl_pkey_export($key, $private)) {
            throw new \RuntimeException('Could not create a client certificate.');
        }

        $created = EdgeCloudflareClient::fromConfig()->uploadMtlsCertificate([
            'name' => 'client-'.$site->id,
            'ca' => false,
            'certificates' => $certificate,
            'private_key' => $private,
        ]);
        $id = (string) ($created['id'] ?? '');
        if ($id === '') {
            throw new \RuntimeException('The certificate was created but no id came back.');
        }

        return $id;
    }

    /**
     * Delete the remote resource. Returns false when there was nothing of ours
     * to delete: kinds that are only switched on or pointed at (AI, a typed
     * index name, another app), or a resource another organization created.
     */
    public static function destroy(string $kind, string $target, Organization $organization): bool
    {
        if ($kind === 'redis') {
            if (! EdgeValkey::isTarget($target)) {
                return false;
            }
            EdgeValkey::destroy($target);

            return true;
        }
        if (! self::owns($kind, $target, $organization)) {
            return false;
        }
        if ($kind === 'realtime') {
            app(EdgeRealtimeApps::class)->destroy(EdgeRealtimeApp::query()->findOrFail($target));

            return true;
        }
        $client = EdgeCloudflareClient::fromConfig();
        match ($kind) {
            'key_value' => $client->deleteKvNamespace($target),
            'object_storage' => $client->deleteR2Bucket($target),
            'sql' => $client->deleteD1Database($target),
            // The API deletes by queue id; the binding stores the name.
            'queue' => ($queueId = self::queueId($target, $organization)) !== '' ? $client->deleteQueue($queueId) : null,
            'vectors' => $client->deleteVectorizeIndex($target),
            // Only the pool goes; the database it points at is left alone.
            'database_pool' => $client->deleteHyperdriveConfig($target),
            default => null,
        };
        if ($kind === 'sql') {
            EdgeDatabase::query()->where('organization_id', $organization->id)->where('cloudflare_id', $target)->delete();
        }
        if ($kind === 'queue') {
            EdgeQueue::query()->where('organization_id', $organization->id)->where('cloudflare_name', $target)->delete();
        }

        return true;
    }

    /**
     * Kinds the live Worker binds by id. Deleting one while a live deploy still
     * binds it breaks the live app (and Cloudflare refuses outright for a
     * queue), so a delete waits for the next deploy to drop the binding.
     */
    public const DEFER_DELETE_WHILE_LIVE = ['key_value', 'object_storage', 'sql', 'queue', 'vectors', 'database_pool'];

    /** True when deleting this kind now would pull it out from under the site's live deploy. */
    public static function deleteWaitsForDeploy(Site $site, string $kind): bool
    {
        return in_array($kind, self::DEFER_DELETE_WHILE_LIVE, true)
            && EdgeDeployment::query()->where('site_id', $site->id)->where('status', EdgeDeployment::STATUS_LIVE)->exists();
    }

    /** Queue a detached resource for deletion after this site's next deploy. */
    public static function deleteAfterDeploy(Site $site, string $kind, string $target): void
    {
        $pending = (array) ($site->edgeMeta()['pending_deletes'] ?? []);
        $pending[] = ['kind' => $kind, 'target' => $target];
        $site->mergeEdgeMeta(['pending_deletes' => array_values(array_unique($pending, SORT_REGULAR))]);
    }

    /**
     * After a deploy goes live: delete what deleteAfterDeploy queued. The app
     * that queued it binding it again cancels the delete. Another app still
     * binding it keeps it queued until none does (never dropped, or it would
     * stay and bill), marked after_own_deploy: the queuing app's live deploy
     * has let go of it, so any later deploy of the organization may finish
     * it. An unmarked item waits for its own app's deploy. A failure stays
     * queued too.
     */
    public static function deletePending(Site $site): void
    {
        if ($site->organization === null) {
            return;
        }
        $sites = Site::query()->where('organization_id', $site->organization_id)->get();
        $binds = static fn (Site $app, string $kind, string $target): bool => collect(self::for($app))->contains(fn (array $c): bool => $c['kind'] === $kind && $c['target'] === $target);
        $destroyed = []; // two apps can queue the same resource
        foreach ($sites as $owner) {
            $meta = $owner->edgeMeta();
            $pending = (array) ($meta['pending_deletes'] ?? []);
            // Before pending_deletes, only queues were deferred.
            foreach ((array) ($meta['pending_queue_deletes'] ?? []) as $name) {
                $pending[] = ['kind' => 'queue', 'target' => (string) $name];
            }
            if ($pending === []) {
                continue;
            }
            $left = [];
            foreach ($pending as $item) {
                if ($owner->isNot($site) && empty($item['after_own_deploy'])) {
                    $left[] = $item; // its app's live deploy may still bind it

                    continue;
                }
                $kind = (string) ($item['kind'] ?? '');
                $target = (string) ($item['target'] ?? '');
                if ($binds($owner, $kind, $target)) {
                    continue; // added back to this app: not deleted
                }
                $waiting = ['kind' => $kind, 'target' => $target, 'after_own_deploy' => true];
                if ($sites->contains(fn (Site $other): bool => $other->isNot($owner) && $binds($other, $kind, $target))) {
                    $left[] = $waiting;

                    continue;
                }
                if (isset($destroyed[$kind.'|'.$target])) {
                    continue;
                }
                try {
                    self::destroy($kind, $target, $site->organization);
                    $destroyed[$kind.'|'.$target] = true;
                } catch (\Throwable $e) {
                    // Still bound somewhere (e.g. a preview): try again after the next deploy.
                    $left[] = $waiting;
                    report($e);
                }
            }
            if ($left === ($meta['pending_deletes'] ?? []) && ! isset($meta['pending_queue_deletes'])) {
                continue;
            }
            $fresh = $owner->fresh();
            $fresh?->mergeEdgeMeta(['pending_deletes' => $left, 'pending_queue_deletes' => null]);
            $fresh?->save();
        }
    }

    /** Another app of the organization binds this resource. */
    public static function boundElsewhere(Site $site, string $kind, string $target): bool
    {
        return Site::query()->where('organization_id', $site->organization_id)->whereKeyNot($site->id)->get()
            ->contains(fn (Site $other): bool => collect(self::for($other))->contains(fn (array $c): bool => $c['kind'] === $kind && $c['target'] === $target));
    }

    /** Cloudflare's id for a queue this app binds by name. */
    public static function queueId(string $name, Organization $organization): string
    {
        $recorded = (string) EdgeQueue::query()->where('organization_id', $organization->id)->where('cloudflare_name', $name)->value('cloudflare_id');
        if ($recorded !== '') {
            return $recorded;
        }
        foreach (EdgeCloudflareClient::fromConfig()->listQueues() as $row) {
            if (is_array($row) && (string) ($row['queue_name'] ?? '') === $name) {
                return (string) ($row['queue_id'] ?? $row['id'] ?? '');
            }
        }

        return '';
    }
}
