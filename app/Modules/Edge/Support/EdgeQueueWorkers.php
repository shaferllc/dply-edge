<?php

declare(strict_types=1);

namespace App\Modules\Edge\Support;

use App\Models\Site;
use App\Modules\Billing\Services\EdgeContainerComputeCost;
use App\Modules\Edge\Services\Containers\EdgeContainerDeployer;
use App\Modules\Edge\Services\EdgeAppDatabase;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Queue workers: always-on `php artisan queue:work` processes for a container
 * app, like Laravel Cloud's queue clusters. They run the app's own image as
 * App instances named worker-0, worker-1, … (EdgeContainerDeployer), started
 * in worker mode (DPLY_ROLE=worker, EdgeContainerDockerfile), and pull from
 * the app's Redis (dply Valkey) or its database.
 *
 * Stored on edge meta container.workers. Pull workers cannot learn that a job
 * arrived, so they never sleep; the push queue (a Queue resource) is the
 * scale-to-zero option.
 */
final class EdgeQueueWorkers
{
    public const MAX_INSTANCES = 5;

    public const MAX_PROCESSES = 8;

    public const CONNECTIONS = ['auto', 'redis', 'database'];

    /**
     * @return array{enabled: bool, instances: int, processes: int, connection: string, queues: string, timeout: int, tries: int, sleep: int, memory: int, max_time: int}
     */
    public static function for(Site $site): array
    {
        $raw = $site->edgeMeta()['container']['workers'] ?? [];

        return self::normalize(is_array($raw) ? $raw : []);
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array{enabled: bool, instances: int, processes: int, connection: string, queues: string, timeout: int, tries: int, sleep: int, memory: int, max_time: int}
     */
    public static function normalize(array $raw): array
    {
        $connection = (string) ($raw['connection'] ?? 'auto');
        $queues = implode(',', array_filter(array_map(
            static fn (string $q): string => preg_replace('/[^A-Za-z0-9_\-:.]/', '', trim($q)) ?? '',
            explode(',', (string) ($raw['queues'] ?? 'default')),
        )));

        return [
            'enabled' => (bool) ($raw['enabled'] ?? false),
            'instances' => max(1, min(self::MAX_INSTANCES, (int) ($raw['instances'] ?? 1))),
            'processes' => max(1, min(self::MAX_PROCESSES, (int) ($raw['processes'] ?? 1))),
            'connection' => in_array($connection, self::CONNECTIONS, true) ? $connection : 'auto',
            'queues' => $queues !== '' ? $queues : 'default',
            'timeout' => max(1, min(3600, (int) ($raw['timeout'] ?? 60))),
            'tries' => max(1, min(25, (int) ($raw['tries'] ?? 3))),
            'sleep' => max(1, min(60, (int) ($raw['sleep'] ?? 3))),
            'memory' => max(64, min(2048, (int) ($raw['memory'] ?? 128))),
            // Workers restart after this long so a leak or stale config cannot build up.
            'max_time' => max(60, min(86400, (int) ($raw['max_time'] ?? 3600))),
        ];
    }

    /** Instances to keep running: 0 when off or when workers cannot run here. */
    public static function runningInstances(Site $site): int
    {
        $settings = self::for($site);

        return $settings['enabled'] && self::unavailableReason($site) === null ? $settings['instances'] : 0;
    }

    /**
     * The Laravel queue connection the workers pull from: auto picks Redis
     * when the app has one, else its database.
     */
    public static function connection(Site $site, ?string $choice = null): ?string
    {
        $choice ??= self::for($site)['connection'];
        $redis = self::hasRedis($site);
        $database = self::hasSharedDatabase($site);

        return match ($choice) {
            'redis' => $redis ? 'redis' : null,
            'database' => $database ? 'database' : null,
            default => $redis ? 'redis' : ($database ? 'database' : null),
        };
    }

    /** Why workers cannot run for this app, or null when they can. */
    public static function unavailableReason(Site $site): ?string
    {
        $meta = $site->edgeMeta();
        if (($meta['runtime_mode'] ?? '') !== 'container') {
            return __('Queue workers run next to a container app.');
        }
        if (! $site->isLaravelFrameworkDetected()) {
            return __('Queue workers run php artisan queue:work, so they need a Laravel app.');
        }
        if (self::connection($site, 'auto') === null) {
            return __('Workers pull jobs from Redis or a database both the app and the workers can reach. Add dply Valkey or a Postgres or MySQL database first. SQLite lives inside one container.');
        }

        return null;
    }

    /**
     * What a worker instance gets on top of the app's env (DPLY_ROLE=worker
     * switches the image's start command to the worker supervisor).
     *
     * @return array<string, string>
     */
    public static function env(Site $site): array
    {
        $s = self::for($site);

        return [
            'DPLY_ROLE' => 'worker',
            'DPLY_WORKER_CONNECTION' => (string) self::connection($site),
            'DPLY_WORKER_QUEUES' => $s['queues'],
            'DPLY_WORKER_PROCESSES' => (string) $s['processes'],
            'DPLY_WORKER_TIMEOUT' => (string) $s['timeout'],
            'DPLY_WORKER_TRIES' => (string) $s['tries'],
            'DPLY_WORKER_SLEEP' => (string) $s['sleep'],
            'DPLY_WORKER_MEMORY' => (string) $s['memory'],
            'DPLY_WORKER_MAX_TIME' => (string) $s['max_time'],
        ];
    }

    /**
     * Each worker instance's state from the live app's Worker (running,
     * healthy, stopping, stopped or stopped_with_code). Reading it never
     * starts one.
     *
     * @return list<array{name: string, status: string, since: ?int, exit_code: ?int}>
     */
    public static function status(Site $site): array
    {
        $rows = self::internal($site)->get(rtrim((string) $site->edgeLiveUrl(), '/').'/_dply/workers')->throw()->json();

        return array_values(array_map(static fn (array $row): array => [
            'name' => (string) ($row['name'] ?? ''),
            'status' => (string) ($row['status'] ?? 'unknown'),
            'since' => isset($row['lastChange']) ? intdiv((int) $row['lastChange'], 1000) : null,
            'exit_code' => isset($row['exitCode']) ? (int) $row['exitCode'] : null,
        ], array_filter(is_array($rows) ? $rows : [], 'is_array')));
    }

    /** Start any stopped workers now instead of at the next scheduled warm. */
    public static function start(Site $site): void
    {
        self::internal($site)->post(rtrim((string) $site->edgeLiveUrl(), '/').'/_dply/warm')->throw();
    }

    private static function internal(Site $site): PendingRequest
    {
        return Http::timeout(15)->withHeaders(['x-dply-queue-token' => EdgeContainerDeployer::queueToken($site)]);
    }

    /** Estimated cents a month for $instances workers on the app's instance size, always on. */
    public static function monthlyCents(Site $site, int $instances): int
    {
        $type = EdgeContainerSettings::for($site)['instance_type'];
        $container = is_array($site->edgeMeta()['container'] ?? null) ? $site->edgeMeta()['container'] : [];
        [$vcpu, $memory, $disk] = EdgeContainerSettings::INSTANCE_TYPES[$type]
            ?? [(float) ($container['custom_vcpu'] ?? 1), (float) ($container['custom_memory_gib'] ?? 3), (float) ($container['custom_disk_gb'] ?? 6)];
        $perMinute = app(EdgeContainerComputeCost::class)->perMinuteMillicents((float) $vcpu, (float) $memory, (float) $disk);

        return (int) round($perMinute * 60 * 730 / 1000 * $instances);
    }

    private static function hasRedis(Site $site): bool
    {
        return collect(EdgeContainerConnections::for($site))->contains(fn (array $c): bool => $c['kind'] === 'redis' && ! $c['asleep']);
    }

    private static function hasSharedDatabase(Site $site): bool
    {
        $engine = (string) ($site->edgeMeta()['database']['engine'] ?? '');

        return in_array($engine, ['postgres', 'mysql'], true) && in_array($engine, EdgeAppDatabase::ENGINES, true);
    }
}
