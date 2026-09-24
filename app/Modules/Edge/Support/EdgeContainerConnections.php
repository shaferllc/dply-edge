<?php

declare(strict_types=1);

namespace App\Modules\Edge\Support;

use App\Models\Site;
use App\Modules\Edge\Services\EdgeRedisUsageCollector;
use App\Modules\Providers\Cloudflare\EdgeCloudflareClient;
use App\Modules\Providers\Upstash\UpstashRedisClient;

/**
 * Container connections. Called from EdgeContainerDeployer::scaffold and
 * the Resources page. Stored on edge meta `connections` as
 * {kind, name, host, target}. The app calls http://host/…; the worker
 * resolves that host to the attached resource.
 *
 * User request: support workers-connections and Workers bindings as
 * resources, without telling people it is Cloudflare.
 *
 * @phpstan-type Connection array{kind: string, name: string, host: string, target: string, asleep: bool}
 */
final class EdgeContainerConnections
{
    /**
     * @var array<string, array{label: string, needs_target: bool, hint: string}>
     */
    public const KINDS = [
        'key_value' => ['label' => 'Key-value store', 'needs_target' => true, 'hint' => 'GET or PUT http://host/key. GET http://host/ lists keys.'],
        'durable_object' => ['label' => 'State', 'needs_target' => false, 'hint' => 'GET or PUT http://host/key. POST http://host/incr/key adds one. Use this for a counter or a lock.'],
        'redis' => ['label' => 'Redis', 'needs_target' => false, 'hint' => 'Start one here, or paste an address. The app connects directly. Commands and storage are billed with usage.'],
        'object_storage' => ['label' => 'Object storage', 'needs_target' => true, 'hint' => 'GET http://host/ lists objects. GET, PUT, or DELETE http://host/path'],
        'sql' => ['label' => 'SQL database', 'needs_target' => true, 'hint' => 'POST http://host/query with {"sql","params"}'],
        'queue' => ['label' => 'Queue', 'needs_target' => true, 'hint' => 'POST http://host/send with the message body. The next deploy sets DPLY_QUEUE to this name.'],
        'ai' => ['label' => 'AI', 'needs_target' => false, 'hint' => 'POST http://host/run with {"model","input"}'],
        'vectors' => ['label' => 'Vector search', 'needs_target' => true, 'hint' => 'POST http://host/query with {"vector","topK"}'],
        'images' => ['label' => 'Images', 'needs_target' => false, 'hint' => 'POST http://host/info with the image body'],
        'workflow' => ['label' => 'Workflow', 'needs_target' => true, 'hint' => 'POST http://host/start with {"id","params"}'],
        'database_pool' => ['label' => 'Database pool', 'needs_target' => true, 'hint' => 'GET http://host/ for the connection string'],
        'service' => ['label' => 'Another app', 'needs_target' => true, 'hint' => 'Any method on http://host/path is sent to that app'],
    ];

    /** Kinds this account can provision. The rest are attached by id, or just turned on. */
    public const CREATABLE = ['key_value', 'object_storage', 'sql', 'queue'];

    /** Account capabilities. There is nothing to name or attach. */
    public const ENABLE = ['ai', 'images'];

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
    public static function redisDriverEnv(Site $site): array
    {
        foreach (self::for($site) as $connection) {
            if ($connection['kind'] === 'redis' && ! $connection['asleep'] && $site->isLaravelFrameworkDetected()) {
                return ['CACHE_STORE' => 'redis', 'REDIS_CLIENT' => 'phpredis'];
            }
        }

        return [];
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
        $name = strtoupper(trim((string) ($row['name'] ?? '')));
        $host = strtolower(trim((string) ($row['host'] ?? '')));
        $target = trim((string) ($row['target'] ?? ''));
        if (! isset(self::KINDS[$kind]) || ! preg_match('/^[A-Z][A-Z0-9_]{0,40}$/', $name)) {
            return null;
        }
        if (in_array($name, ['APP', 'BILLING'], true) || ! preg_match('/^[a-z0-9]([a-z0-9-]{0,20}\.)+[a-z]{2,12}$/', $host)) {
            return null;
        }
        if (self::KINDS[$kind]['needs_target'] && $target === '') {
            return null;
        }

        return ['kind' => $kind, 'name' => $name, 'host' => $host, 'target' => $target, 'asleep' => (bool) ($row['asleep'] ?? false)];
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
            if ($connection['kind'] === 'queue') {
                $config['queues']['producers'] = array_merge($config['queues']['producers'] ?? [], [[
                    'binding' => $connection['name'],
                    'queue' => $connection['target'],
                ]]);

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
        foreach ($peers as $peer) {
            if ($peer->isEdgePreview() || ($peer->edgeMeta()['runtime_mode'] ?? '') !== 'container') {
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
    public static function identity(string $label): ?array
    {
        $resource = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $label) ?? '', '-'));
        $name = strtoupper(str_replace('-', '_', $resource));
        if ($resource === '' || ! preg_match('/^[A-Z][A-Z0-9_]{0,40}$/', $name)) {
            return null;
        }

        return ['name' => $name, 'host' => $resource.'.internal', 'resource' => $resource];
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
     * @return array<string, string>
     */
    public static function redisRegions(): array
    {
        return UpstashRedisClient::REGIONS;
    }

    /**
     * Start a Redis on the platform account. The id is stored on the
     * connection. The URL is stored as an encrypted env var by the caller.
     *
     * @return array{id: string, url: string}
     */
    public static function provisionRedis(Site $site, string $resource, string $region): array
    {
        $slug = strtolower((string) ($site->slug !== '' ? $site->slug : $site->name));
        $app = trim((string) preg_replace('/[^a-z0-9]+/', '-', $slug), '-');
        $label = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($resource)), '-');
        $name = trim($app.'-'.$label, '-');
        if ($name === '') {
            $name = 'redis-'.substr((string) $site->id, -8);
        }

        return UpstashRedisClient::fromConfig()->create(substr($name, 0, 64), $region);
    }

    /**
     * Delete the remote resource. Kinds we did not create are only unlinked.
     */
    public static function destroy(string $kind, string $target): void
    {
        if ($kind === 'redis') {
            if (EdgeRedisUsageCollector::isProvisionedId($target)) {
                UpstashRedisClient::fromConfig()->delete($target);
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
