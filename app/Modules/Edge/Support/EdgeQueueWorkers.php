<?php

declare(strict_types=1);

namespace App\Modules\Edge\Support;

use App\Models\Site;
use App\Modules\Billing\Services\EdgeContainerComputeCost;
use App\Modules\Edge\Services\Containers\EdgeContainerDeployer;
use App\Modules\Edge\Services\EdgeAppDatabase;
use App\Modules\Providers\Cloudflare\EdgeCloudflareClient;
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
    public const MAX_INSTANCES = 10;

    public const MAX_PROCESSES = 8;

    public const CONNECTIONS = ['auto', 'redis', 'database'];

    /** Extra worker groups beside the main one (Laravel Cloud's clusters). */
    public const MAX_GROUPS = 4;

    /**
     * @return array{enabled: bool, instances: int, processes: int, connection: string, queues: string, timeout: int, tries: int, sleep: int, memory: int, max_time: int, autoscale: bool, max_instances: int, scale_per: int, max_wait: int, paused: bool, groups: list<array{key: string, queues: string, instances: int, processes: int, autoscale: bool, max_instances: int, scale_per: int, max_wait: int}>}
     */
    public static function for(Site $site): array
    {
        $raw = $site->edgeMeta()['container']['workers'] ?? [];

        return self::normalize(is_array($raw) ? $raw : []);
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array{enabled: bool, instances: int, processes: int, connection: string, queues: string, timeout: int, tries: int, sleep: int, memory: int, max_time: int, autoscale: bool, max_instances: int, scale_per: int, max_wait: int, paused: bool, groups: list<array{key: string, queues: string, instances: int, processes: int, autoscale: bool, max_instances: int, scale_per: int, max_wait: int}>}
     */
    public static function normalize(array $raw): array
    {
        $connection = (string) ($raw['connection'] ?? 'auto');
        $queues = self::cleanQueues((string) ($raw['queues'] ?? 'default'));
        $instances = max(1, min(self::MAX_INSTANCES, (int) ($raw['instances'] ?? 1)));

        $groups = [];
        foreach (array_slice(array_values(array_filter((array) ($raw['groups'] ?? []), 'is_array')), 0, self::MAX_GROUPS) as $i => $group) {
            $group = self::normalizeGroup($group, $i);
            if (! in_array($group['key'], array_column($groups, 'key'), true)) {
                $groups[] = $group;
            }
        }

        return [
            'enabled' => (bool) ($raw['enabled'] ?? false),
            'instances' => $instances,
            'processes' => max(1, min(self::MAX_PROCESSES, (int) ($raw['processes'] ?? 1))),
            'connection' => in_array($connection, self::CONNECTIONS, true) ? $connection : 'auto',
            'queues' => $queues !== '' ? $queues : 'default',
            'timeout' => max(1, min(3600, (int) ($raw['timeout'] ?? 60))),
            'tries' => max(1, min(25, (int) ($raw['tries'] ?? 3))),
            'sleep' => max(1, min(60, (int) ($raw['sleep'] ?? 3))),
            'memory' => max(64, min(2048, (int) ($raw['memory'] ?? 128))),
            // Workers restart after this long so a leak or stale config cannot build up.
            'max_time' => max(60, min(86400, (int) ($raw['max_time'] ?? 3600))),
            // Autoscaling: `instances` always run; up to `max_instances` start
            // while more than `scale_per` jobs wait per worker process.
            'autoscale' => (bool) ($raw['autoscale'] ?? false),
            'max_instances' => max($instances, min(self::MAX_INSTANCES, (int) ($raw['max_instances'] ?? $instances))),
            'scale_per' => max(1, min(1000, (int) ($raw['scale_per'] ?? 10))),
            // Also add a worker when the oldest ready job has waited this long (0 = off).
            'max_wait' => max(0, min(3600, (int) ($raw['max_wait'] ?? 60))),
            // Stopped from the workspace; they stay stopped until resumed.
            'paused' => (bool) ($raw['paused'] ?? false),
            // More workers for other queues (e.g. `high`), sized and scaled on
            // their own so a flood on one queue cannot starve another.
            'groups' => $groups,
        ];
    }

    /**
     * An extra group: its own queues, size and scaling. Connection and the
     * job options (timeout, tries, memory, …) are the main group's.
     *
     * @param  array<string, mixed>  $raw
     * @return array{key: string, queues: string, instances: int, processes: int, autoscale: bool, max_instances: int, scale_per: int, max_wait: int}
     */
    public static function normalizeGroup(array $raw, int $position = 0): array
    {
        // The key names the instances (worker-{key}-N): letters and digits only.
        $key = substr(strtolower(preg_replace('/[^A-Za-z0-9]/', '', (string) ($raw['key'] ?? '')) ?? ''), 0, 12);
        $queues = self::cleanQueues((string) ($raw['queues'] ?? ''));
        if ($key === '') {
            $key = substr(strtolower(preg_replace('/[^A-Za-z0-9]/', '', explode(',', $queues)[0] ?? '') ?? ''), 0, 12) ?: 'group'.($position + 1);
        }
        $instances = max(1, min(self::MAX_INSTANCES, (int) ($raw['instances'] ?? 1)));

        return [
            'key' => $key,
            'queues' => $queues !== '' ? $queues : 'default',
            'instances' => $instances,
            'processes' => max(1, min(self::MAX_PROCESSES, (int) ($raw['processes'] ?? 1))),
            'autoscale' => (bool) ($raw['autoscale'] ?? false),
            'max_instances' => max($instances, min(self::MAX_INSTANCES, (int) ($raw['max_instances'] ?? $instances))),
            'scale_per' => max(1, min(1000, (int) ($raw['scale_per'] ?? 10))),
            'max_wait' => max(0, min(3600, (int) ($raw['max_wait'] ?? 60))),
        ];
    }

    /**
     * Every group that runs, main first (key ''), each with the instance
     * names it can use and how many are always on.
     *
     * @return list<array{key: string, queues: string, instances: int, processes: int, autoscale: bool, max_instances: int, scale_per: int, max_wait: int, capacity: int, prefix: string}>
     */
    public static function groups(Site $site): array
    {
        $main = self::for($site);
        $all = [['key' => ''] + array_intersect_key($main, array_flip(['queues', 'instances', 'processes', 'autoscale', 'max_instances', 'scale_per', 'max_wait']))];
        foreach ($main['groups'] as $group) {
            $all[] = $group;
        }

        // The plan's allowances, main group first: extra groups beyond the
        // plan are dropped, autoscaling is off where the plan has none, and
        // instances are trimmed to the plan's total.
        $allow = self::allowance($site);
        $all = array_slice($all, 0, 1 + $allow['groups']);
        $budget = $allow['instances'];
        $out = [];
        foreach ($all as $g) {
            if (! $allow['autoscale']) {
                $g['autoscale'] = false;
            }
            $capacity = $g['autoscale'] ? $g['max_instances'] : $g['instances'];
            if ($budget !== null) {
                $capacity = min($capacity, $budget);
                if ($capacity < 1) {
                    break;
                }
                $g['instances'] = min($g['instances'], $capacity);
                $g['max_instances'] = max($g['instances'], min($g['max_instances'], $capacity));
                $budget -= $capacity;
            }
            $out[] = $g + [
                'capacity' => $capacity,
                'prefix' => 'worker-'.($g['key'] !== '' ? $g['key'].'-' : ''),
            ];
        }

        return $out;
    }

    /**
     * What the organization's plan allows for queue workers per app
     * (subscription.standard.tiers.*.worker_*). A tier without the keys is
     * not capped.
     *
     * @return array{instances: ?int, autoscale: bool, groups: int, plan: string}
     */
    public static function allowance(Site $site): array
    {
        $tier = $site->organization?->tierAllowances() ?? (array) config('subscription.standard.tiers.free');

        return [
            'instances' => array_key_exists('worker_instances', $tier) && $tier['worker_instances'] !== null ? max(1, (int) $tier['worker_instances']) : null,
            'autoscale' => (bool) ($tier['worker_autoscale'] ?? true),
            'groups' => max(0, min(self::MAX_GROUPS, (int) ($tier['worker_groups'] ?? self::MAX_GROUPS))),
            'plan' => (string) ($tier['label'] ?? ''),
        ];
    }

    /** One group by key ('' = main), or null. */
    public static function group(Site $site, string $key): ?array
    {
        foreach (self::groups($site) as $group) {
            if ($group['key'] === $key) {
                return $group;
            }
        }

        return null;
    }

    /**
     * Whether the Laravel scheduler runs inside worker-0 (schedule:work)
     * instead of a Cron Trigger that wakes the web container every minute:
     * when the scheduler is on and the app has queue workers, which are
     * always on anyway. Pausing the workers pauses the scheduler with them.
     */
    public static function runsScheduler(Site $site): bool
    {
        return EdgeContainerSettings::for($site)['scheduler'] && self::runningInstances($site) > 0;
    }

    /**
     * Worker instances an unsaved draft runs: always on, and at most.
     *
     * @param  array<string, mixed>  $draft
     * @return array{min: int, max: int}
     */
    public static function draftInstances(array $draft): array
    {
        $main = self::normalize($draft);
        $min = $main['instances'];
        $max = $main['autoscale'] ? $main['max_instances'] : $main['instances'];
        foreach ($main['groups'] as $g) {
            $min += $g['instances'];
            $max += $g['autoscale'] ? $g['max_instances'] : $g['instances'];
        }

        return ['min' => $min, 'max' => $max];
    }

    private static function cleanQueues(string $queues): string
    {
        return implode(',', array_filter(array_map(
            static fn (string $q): string => preg_replace('/[^A-Za-z0-9_\-:.]/', '', trim($q)) ?? '',
            explode(',', $queues),
        )));
    }

    /**
     * Worker instances the app is deployed with (the most that can run):
     * 0 when off or when workers cannot run here.
     */
    public static function runningInstances(Site $site): int
    {
        $settings = self::for($site);
        if (! $settings['enabled'] || self::unavailableReason($site) !== null) {
            return 0;
        }

        return array_sum(array_column(self::groups($site), 'capacity'));
    }

    /**
     * Worker instances to run for a backlog: enough that each process has
     * at most `scale_per` waiting jobs, between the always-on count and the
     * maximum.
     *
     * @param  array{instances: int, max_instances: int, processes: int, scale_per: int, max_wait?: int}  $settings
     */
    public static function targetInstances(array $settings, int $backlog, ?int $oldestAge = null, ?int $current = null): int
    {
        $needed = (int) ceil($backlog / ($settings['processes'] * $settings['scale_per']));
        // A few slow jobs can matter more than many fast ones: one that has
        // waited too long adds a worker beyond what the count asks for.
        $maxWait = (int) ($settings['max_wait'] ?? 0);
        if ($maxWait > 0 && $oldestAge !== null && $oldestAge > $maxWait) {
            $needed = max($needed, ($current ?? $settings['instances']) + 1);
        }

        return max($settings['instances'], min($settings['max_instances'], $needed));
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
        if (self::connection($site) === null) {
            $valkeyNeedsPlan = self::for($site)['connection'] === 'redis' && collect(EdgeContainerConnections::for($site))
                ->contains(fn (array $c): bool => $c['kind'] === 'redis' && ! $c['asleep'] && EdgeValkey::isTarget((string) $c['target']));

            return $valkeyNeedsPlan
                ? __('This dply Valkey size needs a paid plan. Choose a plan or a Flex size, or set Connection to database.')
                : __('Workers are set to pull from :connection, which this app does not have yet.', ['connection' => self::for($site)['connection']]);
        }

        return null;
    }

    /**
     * What a worker instance gets on top of the app's env (DPLY_ROLE=worker
     * switches the image's start command to the worker supervisor).
     *
     * @return array<string, string>
     */
    public static function env(Site $site, string $group = ''): array
    {
        $s = self::for($site);
        $g = self::group($site, $group) ?? self::groups($site)[0];

        return [
            'DPLY_ROLE' => 'worker',
            'DPLY_WORKER_CONNECTION' => (string) self::connection($site),
            'DPLY_WORKER_QUEUES' => $g['queues'],
            'DPLY_WORKER_PROCESSES' => (string) $g['processes'],
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
     * @return list<array{name: string, status: string, since: ?int, exit_code: ?int, wanted: bool}>
     */
    public static function status(Site $site): array
    {
        $rows = self::internal($site)->get(rtrim((string) $site->edgeLiveUrl(), '/').'/_dply/workers')->throw()->json();

        return array_values(array_map(static fn (array $row): array => [
            'name' => (string) ($row['name'] ?? ''),
            'group' => (string) ($row['group'] ?? ''),
            'status' => (string) ($row['status'] ?? 'unknown'),
            'since' => isset($row['lastChange']) ? intdiv((int) $row['lastChange'], 1000) : null,
            'exit_code' => isset($row['exitCode']) ? (int) $row['exitCode'] : null,
            'wanted' => (bool) ($row['wanted'] ?? true),
            'paused' => (bool) ($row['paused'] ?? false),
        ], array_filter(is_array($rows) ? $rows : [], 'is_array')));
    }

    /**
     * Start any stopped workers now instead of at the next scheduled warm,
     * and report each one's outcome (Cloudflare's reason when it fails).
     *
     * @return list<array{name: string, ok: bool, error: ?string}>
     */
    public static function start(Site $site): array
    {
        $rows = Http::timeout(60)->withHeaders(['x-dply-queue-token' => EdgeContainerDeployer::queueToken($site)])
            ->post(rtrim((string) $site->edgeLiveUrl(), '/').'/_dply/workers/start')->throw()->json();

        return array_values(array_map(static fn (array $row): array => [
            'name' => (string) ($row['name'] ?? ''),
            'ok' => (bool) ($row['ok'] ?? false),
            'error' => isset($row['error']) ? (string) $row['error'] : null,
        ], array_filter(is_array($rows) ? $rows : [], 'is_array')));
    }

    /**
     * Run the first $count workers and stop the rest (stopping lets the
     * running job finish).
     *
     * @return list<array{name: string, wanted: bool, ok: bool, error: ?string}>
     */
    public static function scale(Site $site, int $count, string $group = ''): array
    {
        $rows = self::internal($site)->post(rtrim((string) $site->edgeLiveUrl(), '/').'/_dply/workers/scale', ['count' => $count, 'group' => $group])->throw()->json();

        return array_values(array_map(static fn (array $row): array => [
            'name' => (string) ($row['name'] ?? ''),
            'wanted' => (bool) ($row['wanted'] ?? false),
            'ok' => (bool) ($row['ok'] ?? false),
            'error' => isset($row['error']) ? (string) $row['error'] : null,
        ], array_filter(is_array($rows) ? $rows : [], 'is_array')));
    }

    /**
     * Where each worker landed and how far its data is, from the line it
     * logs at start (dply/laravel `dply:probe`). Newest per worker.
     *
     * @return array<string, array{location: string, region: string, db_ms: ?float, redis_ms: ?float, at: ?string}>
     */
    public static function placements(Site $site, int $minutes = 1440): array
    {
        $client = EdgeCloudflareClient::fromConfig();
        $out = [];
        foreach ($client->workerLogs(EdgeContainerDeployer::logServices($site, $client), $minutes, 200, '] probe {') as $line) {
            if (preg_match('/\[dply-worker ([a-z0-9-]+)\] probe (\{.*\})\s*$/', $line['message'], $m) !== 1 || isset($out[$m[1]])) {
                continue;
            }
            $probe = json_decode($m[2], true);
            if (! is_array($probe)) {
                continue;
            }
            $out[$m[1]] = [
                'location' => (string) ($probe['location'] ?? ''),
                'region' => (string) ($probe['region'] ?? ''),
                'db_ms' => isset($probe['db_ms']) ? (float) $probe['db_ms'] : null,
                'redis_ms' => isset($probe['redis_ms']) ? (float) $probe['redis_ms'] : null,
                'at' => $line['at'],
            ];
        }

        return $out;
    }

    /**
     * Recent worker output from the app's Workers Logs, newest first:
     * supervisor lines ("[dply-worker worker-N] …") and queue:work's job
     * lines (… RUNNING / DONE / FAIL).
     *
     * @return list<array{at: ?string, level: string, message: string}>
     */
    public static function logs(Site $site, int $minutes = 60): array
    {
        $client = EdgeCloudflareClient::fromConfig();
        $events = $client->workerLogs(EdgeContainerDeployer::logServices($site, $client), $minutes, 1000);

        return array_values(array_filter(
            $events,
            static fn (array $e): bool => preg_match('/\[dply-worker |\s(RUNNING|DONE|FAIL)\s*$/', $e['message']) === 1,
        ));
    }

    /**
     * Pause (stop every worker, letting running jobs finish) or resume, and
     * remember it on the site so warms, scaling and deploys leave them be.
     *
     * @return list<array{name: string, ok: bool, error: ?string}>
     */
    public static function pause(Site $site, bool $paused): array
    {
        $rows = self::internal($site)->post(rtrim((string) $site->edgeLiveUrl(), '/').'/_dply/workers/pause', ['paused' => $paused])->throw()->json();
        $container = $site->edgeMeta()['container'] ?? [];
        $container['workers'] = array_merge(self::for($site), ['paused' => $paused]);
        $site->mergeEdgeMeta(['container' => $container]);
        $site->save();

        return array_values(array_map(static fn (array $row): array => [
            'name' => (string) ($row['name'] ?? ''),
            'ok' => (bool) ($row['ok'] ?? false),
            'error' => isset($row['error']) ? (string) $row['error'] : null,
        ], array_filter(is_array($rows) ? $rows : [], 'is_array')));
    }

    /**
     * Queue test jobs (php artisan inspire) through the app itself, on the
     * workers' first queue.
     *
     * @return array{queued: int, connection: string, queue: string}
     */
    public static function sendTestJobs(Site $site, int $count = 1): array
    {
        $body = self::command($site, 'queue-test', ['count' => $count, 'queue' => explode(',', self::for($site)['queues'])[0]]);

        return ['queued' => (int) ($body['queued'] ?? 0), 'connection' => (string) ($body['connection'] ?? ''), 'queue' => (string) ($body['queue'] ?? '')];
    }

    /**
     * Jobs waiting on the workers' queues. Read from the queue itself when
     * dply runs it (Valkey, or a dply Postgres/MySQL): the workers keep those
     * awake anyway, while asking the app would wake its web container on
     * every check and keep it from ever sleeping. Other queues are asked of
     * the app.
     */
    public static function backlog(Site $site, string $group = ''): int
    {
        return self::queueState($site, $group)['waiting'];
    }

    /**
     * Jobs waiting and how long the oldest ready one has waited (null when
     * the queue cannot say).
     *
     * @return array{waiting: int, oldest_age: ?int}
     */
    public static function queueState(Site $site, string $group = ''): array
    {
        $queues = explode(',', (self::group($site, $group) ?? self::groups($site)[0])['queues']);
        $connection = self::connection($site);
        $env = fn (string $key): string => (string) ($site->edgeEnvVars()->where('scope', 'production')->where('key', $key)->first()?->value ?? '');

        if ($connection === 'redis') {
            $valkey = collect(EdgeContainerConnections::for($site))->first(fn (array $c): bool => $c['kind'] === 'redis' && EdgeValkey::isTarget((string) $c['target']));
            $password = rawurldecode((string) (parse_url($env('REDIS_URL'), PHP_URL_PASS) ?? ''));
            if (is_array($valkey) && $password !== '') {
                return EdgeValkey::queueBacklog((string) $valkey['target'], $password, $queues);
            }
        }
        $database = $site->edgeMeta()['database'] ?? [];
        if ($connection === 'database' && is_array($database) && ($database['provider'] ?? '') === 'dply'
            && in_array($database['engine'] ?? '', ['postgres', 'mysql'], true) && $env('DB_PASSWORD') !== '') {
            $backlog = EdgeDplyDatabaseStats::queueBacklog((string) $database['engine'], (string) $database['host'], (string) $database['remote_id'], $env('DB_PASSWORD'), $queues);

            return ['waiting' => array_sum($backlog['queues']), 'oldest_age' => $backlog['oldest_age']];
        }

        $body = self::command($site, 'queue-size', ['connection' => (string) $connection, 'queues' => $queues]);

        return ['waiting' => (int) ($body['total'] ?? 0), 'oldest_age' => isset($body['oldest_age']) ? (int) $body['oldest_age'] : null];
    }

    /**
     * Run an allowlisted command in the live app (dply/laravel's
     * /_dply/command) and return its JSON.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function command(Site $site, string $command, array $input = []): array
    {
        $url = $site->edgeLiveUrl();
        if (! is_string($url) || $url === '') {
            throw new \RuntimeException(__('This app has no live URL yet. Deploy it first.'));
        }
        $response = Http::timeout(60)
            ->withHeaders(['x-dply-queue-token' => EdgeContainerDeployer::queueToken($site)])
            ->post(rtrim($url, '/').'/_dply/command', ['command' => $command] + $input);
        $body = $response->json();
        if ($response->status() === 422) {
            throw new \RuntimeException(__('The app has an older dply package. Redeploy it to use this.'));
        }
        if (! $response->successful() || ! is_array($body)) {
            throw new \RuntimeException(is_array($body) && isset($body['error']) ? (string) $body['error'] : __('The app answered HTTP :status.', ['status' => $response->status()]));
        }

        return $body;
    }

    private static function internal(Site $site): PendingRequest
    {
        return Http::timeout(15)->withHeaders(['x-dply-queue-token' => EdgeContainerDeployer::queueToken($site)]);
    }

    /**
     * Processes per instance to start with. Queue jobs mostly wait on the
     * database, Redis or an API, so one instance runs several at once: about
     * three per GiB, 1 to MAX_PROCESSES.
     */
    public static function recommendedProcesses(Site $site): int
    {
        $type = EdgeContainerSettings::for($site)['instance_type'];
        $memoryGib = (float) (EdgeContainerSettings::INSTANCE_TYPES[$type][1] ?? ($site->edgeMeta()['container']['custom_memory_gib'] ?? 1));

        return max(1, min(self::MAX_PROCESSES, (int) floor($memoryGib * 3)));
    }

    /**
     * Env that makes the app dispatch where its workers pull from. Workers
     * read one connection; an app still on Laravel's default (database)
     * after the workers moved to Redis would queue jobs nobody runs.
     *
     * @return array<string, string>
     */
    public static function dispatchEnv(Site $site): array
    {
        $connection = self::runningInstances($site) > 0 ? self::connection($site) : null;

        return $connection !== null ? ['QUEUE_CONNECTION' => $connection] : [];
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
        // The same rule that decides whether the app gets REDIS_*: workers (and
        // QUEUE_CONNECTION) on Redis the app cannot reach would break the app.
        return collect(EdgeContainerConnections::for($site))->contains(fn (array $c): bool => EdgeContainerConnections::redisSuppliesEnv($site, $c));
    }

    private static function hasSharedDatabase(Site $site): bool
    {
        $engine = (string) ($site->edgeMeta()['database']['engine'] ?? '');

        return in_array($engine, ['postgres', 'mysql'], true) && in_array($engine, EdgeAppDatabase::ENGINES, true);
    }
}
