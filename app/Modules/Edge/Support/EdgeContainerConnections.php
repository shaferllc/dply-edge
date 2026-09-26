<?php

declare(strict_types=1);

namespace App\Modules\Edge\Support;

use App\Models\Site;
use App\Modules\Providers\Cloudflare\EdgeCloudflareClient;
use App\Modules\Providers\Upstash\UpstashQstashClient;

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
        'key_value' => ['label' => 'Key-value store', 'needs_target' => true, 'hint' => 'GET or PUT http://host/key. GET http://host/ lists keys. Reads are $1 per million. Writes, deletes, and lists are $10 per million. Storage is $1 per GB-month after the first 1 GB.'],
        'durable_object' => ['label' => 'State', 'needs_target' => false, 'hint' => 'GET or PUT http://host/key. POST http://host/incr/key adds one. Use this for a counter or a lock.'],
        'redis' => ['label' => 'dply Valkey', 'needs_target' => false, 'hint' => 'Redis-compatible: your Redis client and REDIS_URL work unchanged. Start one here, or paste an address. The app connects directly. Commands and storage are billed with usage.'],
        'object_storage' => ['label' => 'Object storage', 'needs_target' => true, 'hint' => 'GET http://host/ lists objects. GET, PUT, or DELETE http://host/path'],
        'sql' => ['label' => 'SQL database', 'needs_target' => true, 'hint' => 'POST http://host/query with {"sql","params"}'],
        'queue' => ['label' => 'Queue', 'needs_target' => true, 'hint' => 'Jobs run in this app. POST http://host/send with the message body. The next deploy sets DPLY_QUEUE to this name.'],
        'http_delivery' => ['label' => 'HTTP delivery', 'needs_target' => false, 'hint' => 'POST http://host/publish with {"url","body","delay"}. The address must be https. Messages are $2 per 100,000. Bandwidth is $0.10 per GB after the first 1 GB.'],
        'ai' => ['label' => 'AI', 'needs_target' => false, 'hint' => 'POST http://host/run with {"model","input"}'],
        'vectors' => ['label' => 'Vector search', 'needs_target' => true, 'hint' => 'POST http://host/query with {"vector","topK"}'],
        'images' => ['label' => 'Images', 'needs_target' => false, 'hint' => 'POST the image to http://host/info for its size. POST http://host/?width=800&format=webp for a resized copy.'],
        'workflow' => ['label' => 'Workflow', 'needs_target' => true, 'hint' => 'POST http://host/start with {"id","params"}'],
        'database_pool' => ['label' => 'Database pool', 'needs_target' => true, 'hint' => 'GET http://host/ for the connection string'],
        'service' => ['label' => 'Another app', 'needs_target' => true, 'hint' => 'Any method on http://host/path is sent to that app'],
    ];

    /** Kinds this account can provision. The rest are attached by id, or just turned on. */
    public const CREATABLE = ['key_value', 'object_storage', 'sql', 'queue'];

    /** Account capabilities. There is nothing to name or attach. */
    public const ENABLE = ['ai', 'images'];

    /** The container Worker and the platform Worker already use these. */
    public const RESERVED_NAMES = ['APP', 'BILLING', ...EdgeEffectiveBindings::RESERVED_NAMES];

    /**
     * Kinds a Worker site (ssr, hybrid) can use. Workflows are not supported
     * in Workers for Platforms (T-016 spike). State and Another app go through
     * EdgeWorkerEntryWrapper; Redis through REDIS_URL.
     *
     * @var list<string>
     */
    public const WORKER_KINDS = ['key_value', 'durable_object', 'redis', 'object_storage', 'sql', 'queue', 'ai', 'vectors', 'images', 'database_pool', 'service'];

    /**
     * How Worker code reaches a kind, where env.NAME alone does not say enough.
     *
     * @var array<string, string>
     */
    public const WORKER_HINTS = [
        'durable_object' => "await env.NAME.fetch('https://state/key', { method: 'PUT', body: 'value' }). GET reads it back. POST https://state/incr/key adds one.",
        'service' => "await env.NAME.fetch('/path') calls that app's live address with the same method, headers, and body.",
        'redis' => 'REDIS_URL is set. Workers need a client that opens TCP sockets (cloudflare:sockets), such as node-redis with nodejs_compat.',
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
        foreach (self::for($site) as $connection) {
            if ($connection['asleep'] || $connection['kind'] === 'queue') {
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
        foreach (self::for($site) as $connection) {
            if ($connection['asleep']) {
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
        if (($site->edgeMeta()['browser'] ?? null) === false) {
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
            'database_pool' => 'Pool id',
            'service' => 'App name',
            default => 'Resource',
        };
    }

    /**
     * @return list<array{id: string, label: string}>
     */
    public static function catalog(string $kind): array
    {
        if (! in_array($kind, self::CREATABLE, true)) {
            return [];
        }

        try {
            $client = EdgeCloudflareClient::fromConfig();
        } catch (\Throwable) {
            return [];
        }

        $rows = match ($kind) {
            'key_value' => $client->listKvNamespaces(),
            'object_storage' => $client->listR2Buckets(),
            'sql' => $client->listD1Databases(),
            'queue' => $client->listQueues(),
            default => [],
        };

        $options = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $id = (string) ($row['id'] ?? $row['uuid'] ?? $row['name'] ?? $row['queue_name'] ?? $row['title'] ?? '');
            $label = (string) ($row['title'] ?? $row['name'] ?? $row['queue_name'] ?? $id);
            if ($kind === 'queue') {
                $id = (string) ($row['queue_name'] ?? $row['name'] ?? $id);
            }
            if ($kind === 'object_storage') {
                $id = (string) ($row['name'] ?? $id);
            }
            if ($id !== '') {
                $options[] = ['id' => $id, 'label' => $label !== '' ? $label : $id];
            }
        }

        return $options;
    }

    /**
     * Create the remote resource and return the id the binding stores.
     */
    public static function provision(string $kind, string $resource): string
    {
        $client = EdgeCloudflareClient::fromConfig();
        $created = match ($kind) {
            'key_value' => $client->createKvNamespace($resource),
            'object_storage' => $client->createR2Bucket($resource),
            'sql' => $client->createD1Database($resource),
            'queue' => $client->createQueue($resource),
            default => throw new \InvalidArgumentException('This resource cannot be created here.'),
        };

        return match ($kind) {
            'key_value', 'sql' => (string) ($created['id'] ?? $created['uuid'] ?? ''),
            'object_storage' => $resource,
            'queue' => (string) ($created['queue_name'] ?? $resource),
            default => '',
        };
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
     * Turn on HTTP delivery for this app. The shared account id is stored
     * on the connection. The publish token stays on the worker.
     */
    public static function provisionHttpDelivery(): string
    {
        return UpstashQstashClient::fromConfig()->ensurePaid();
    }

    /**
     * Delete the remote resource. Kinds we did not create are only unlinked.
     */
    public static function destroy(string $kind, string $target): void
    {
        if ($kind === 'redis') {
            if (EdgeValkey::isTarget($target)) {
                EdgeValkey::destroy($target);
            }

            return;
        }
        if ($target === '' || ! in_array($kind, self::CREATABLE, true)) {
            return;
        }
        $client = EdgeCloudflareClient::fromConfig();
        match ($kind) {
            'key_value' => $client->deleteKvNamespace($target),
            'object_storage' => $client->deleteR2Bucket($target),
            'sql' => $client->deleteD1Database($target),
            'queue' => $client->deleteQueue($target),
            default => null,
        };
    }
}
