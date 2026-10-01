<?php

declare(strict_types=1);

namespace App\Modules\Edge\Support;

use App\Models\EdgeDeployment;
use App\Models\Site;
use App\Modules\Billing\Services\EdgeContainerComputeCost;

/**
 * Per-site container settings (`edgeMeta()['container']`), read by
 * EdgeContainerDeployer on every deploy. Missing keys fall back to
 * `edge.build.containers.*`.
 */
final class EdgeContainerSettings
{
    /**
     * Instance sizes: [vCPU, memory GiB, disk GB]. Named Cloudflare types,
     * plus `custom-*`: Cloudflare custom shapes (at least 1 vCPU and 3 GiB
     * per vCPU) deployed as {vcpu, memory_mib, disk_mb}. The legacy types
     * (EdgeSizeLadder::LEGACY_CONTAINER_TYPES) stay valid for apps on them
     * but are not offered.
     */
    public const INSTANCE_TYPES = [
        'lite' => [1 / 16, 0.25, 2],
        'basic' => [0.25, 1, 4],
        'standard-1' => [0.5, 4, 8],
        'custom-1' => [1, 3, 6],
        'custom-2' => [2, 6, 12],
        'standard-4' => [4, 12, 20],
        'standard-2' => [1, 6, 12],
        'standard-3' => [2, 8, 16],
    ];

    public const SLEEP_AFTER = ['5m', '10m', '30m', '1h', '6h', '24h'];

    public const JURISDICTIONS = ['', 'eu', 'fedramp'];

    /** Cloudflare container placement regions. */
    public const REGIONS = [
        'ENAM' => 'Eastern North America',
        'WNAM' => 'Western North America',
        'EEUR' => 'Eastern Europe',
        'WEUR' => 'Western Europe',
        'APAC' => 'Asia Pacific',
        'SAM' => 'South America',
        'ME' => 'Middle East',
        'OC' => 'Oceania',
        'AFR' => 'Africa',
    ];

    /** Regions allowed inside a jurisdiction. An empty jurisdiction allows all. */
    public const JURISDICTION_REGIONS = [
        'eu' => ['EEUR', 'WEUR'],
        'fedramp' => ['ENAM', 'WNAM'],
    ];

    public const MAX_INSTANCES = 20;

    /**
     * Days a recurring window applies to. A window can also name one date
     * (Y-m-d): that beats a single weekday, which beats weekdays/weekends,
     * which beat daily.
     */
    public const SCHEDULE_DAYS = ['daily', 'weekdays', 'weekends', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    /** wrangler deploy `--containers-rollout`. */
    public const ROLLOUT_MODES = ['gradual', 'immediate', 'none'];

    /** `rollout_active_grace_period` is seconds. 0 is Cloudflare's default. */
    public const ROLLOUT_GRACE_MAX = 3600;

    /**
     * Frameworks that boot PHP per request. lite (256 MB) kills them.
     * basic (1 GiB) is the floor. Resident servers need standard-1 (4 GiB).
     */
    public const PHP_FRAMEWORKS = ['laravel', 'symfony', 'php', 'wordpress', 'drupal', 'statamic'];

    public const PHP_RESIDENT_SERVERS = ['frankenphp', 'swoole', 'roadrunner'];

    /**
     * The stored instance size is kept. `$phpServer` is the detected PHP
     * server from deploy; it does not change the size the operator picked.
     *
     * @return array{instance_type: string, max_instances: int, min_instances: int, sleep_after: string, migrate_on_boot: bool, worker_mode: bool, jurisdiction: string, regions: list<string>, scheduler: bool, rollout_mode: string, rollout_step_percentage: list<int>, rollout_active_grace_period: int, scheduling: string}
     */
    public static function for(Site $site, string $phpServer = 'fpm'): array
    {
        $raw = is_array($site->edgeMeta()['container'] ?? null) ? $site->edgeMeta()['container'] : [];
        $type = (string) ($raw['instance_type'] ?? config('edge.build.containers.instance_type', 'basic'));
        $sleep = (string) ($raw['sleep_after'] ?? config('edge.build.containers.sleep_after', '5m'));
        $jurisdiction = (string) ($raw['jurisdiction'] ?? '');
        $mode = (string) ($raw['rollout_mode'] ?? 'gradual');
        // The plan's app-instance cap (subscription.standard.tiers.*.app_instances; Starter 1).
        $planCap = $site->organization?->tierAllowances()['app_instances'] ?? null;
        $max = max(1, min(self::MAX_INSTANCES, $planCap === null ? self::MAX_INSTANCES : (int) $planCap, (int) ($raw['max_instances'] ?? config('edge.build.containers.max_instances', 5))));

        // A trial runs the smallest rung, one instance, asleep when idle (EdgeTrialLimits).
        return EdgeTrialLimits::container($site, [
            'instance_type' => $type === 'custom' || array_key_exists($type, self::INSTANCE_TYPES) ? $type : 'basic',
            'max_instances' => $max,
            // Instances kept awake. 0 = scale to zero. Never above max.
            'min_instances' => max(0, min($max, (int) ($raw['min_instances'] ?? 0))),
            'sleep_after' => in_array($sleep, self::SLEEP_AFTER, true) ? $sleep : '5m',
            // Off by default: this runs a second full framework boot at the
            // moment a cold-starting container has the least memory, and it
            // re-runs on every wake from sleep. Opt in per site.
            'migrate_on_boot' => (bool) ($raw['migrate_on_boot'] ?? false),
            // Octane on FrankenPHP (EdgeContainerDockerfile::supportsWorkerMode).
            // Off by default: the app stays booted, so state leaks between requests.
            'worker_mode' => (bool) ($raw['worker_mode'] ?? false),
            'jurisdiction' => in_array($jurisdiction, self::JURISDICTIONS, true) ? $jurisdiction : '',
            'regions' => self::normalizeRegions(is_array($raw['regions'] ?? null) ? $raw['regions'] : [], in_array($jurisdiction, self::JURISDICTIONS, true) ? $jurisdiction : ''),
            // Laravel: run `schedule:run` every minute via a Cron Trigger.
            'scheduler' => (bool) ($raw['scheduler'] ?? false),
            'sticky_sessions' => (bool) ($raw['sticky_sessions'] ?? true),
            'dedicated_jobs' => (bool) ($raw['dedicated_jobs'] ?? false),
            // The jobs instance stays awake instead of sleeping with the app.
            'jobs_always_on' => (bool) ($raw['jobs_always_on'] ?? false),
            // Always-on queue:work instances (EdgeQueueWorkers); 0 when off.
            'worker_instances' => EdgeQueueWorkers::runningInstances($site),
            // Scaling windows can raise max past the default, never past the plan's cap.
            'schedules' => array_map(
                static fn (array $row): array => $planCap === null ? $row : ['max' => max(1, min($row['max'], (int) $planCap)), 'min' => min($row['min'], max(1, (int) $planCap))] + $row,
                self::normalizeSchedules(is_array($raw['schedules'] ?? null) ? $raw['schedules'] : []),
            ),
            'rollout_mode' => in_array($mode, self::ROLLOUT_MODES, true) ? $mode : 'gradual',
            // Cloudflare's durable_object scheduling: faster starts, the image
            // and size chosen at start. Opt in per app while it is proven.
            // It cannot pin regions or a jurisdiction, so those apps stay put.
            // Faster starts ship /app as a release in R2 instead of a new image
            // (EdgeReleaseBundle): on unless an app turns it off.
            'release_bundle' => (bool) ($raw['release_bundle'] ?? true),
            // A recent copy of a public page while the app wakes (the Worker's WAKE_COPY).
            'wake_copy' => (bool) ($raw['wake_copy'] ?? true),
            'scheduling' => config('edge.build.containers.durable_object_scheduling') && ($raw['scheduling'] ?? '') === 'durable_object' && $jurisdiction === '' && (array) ($raw['regions'] ?? []) === [] ? 'durable_object' : 'default',
            'rollout_step_percentage' => self::validRolloutSteps($raw['rollout_step_percentage'] ?? []),
            'rollout_active_grace_period' => max(0, min(self::ROLLOUT_GRACE_MAX, (int) ($raw['rollout_active_grace_period'] ?? 0))),
        ], $phpServer);
    }

    /**
     * Scaling windows: min/max instances for a time of day. Rows that don't
     * make sense are dropped rather than rejected, so a bad stored row can
     * never break a deploy. Recurring windows start and end on the same day.
     *
     * @return list<array{days: string, start: string, end: string, timezone: string, min: int, max: int}>
     */
    public static function normalizeSchedules(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $days = (string) ($row['days'] ?? '');
            $start = (string) ($row['start'] ?? '');
            $end = (string) ($row['end'] ?? '');
            $timezone = (string) ($row['timezone'] ?? 'UTC');
            $max = max(1, min(self::MAX_INSTANCES, (int) ($row['max'] ?? 1)));
            $time = '/^([01]\d|2[0-3]):[0-5]\d$/';
            if (! self::isScheduleDays($days)
                || preg_match($time, $start) !== 1 || preg_match($time, $end) !== 1 || $start >= $end
                || ! in_array($timezone, timezone_identifiers_list(), true)) {
                continue;
            }
            $out[] = ['days' => $days, 'start' => $start, 'end' => $end, 'timezone' => $timezone, 'min' => max(0, min($max, (int) ($row['min'] ?? 0))), 'max' => $max];
        }

        return $out;
    }

    public static function isScheduleDays(string $days): bool
    {
        if (in_array($days, self::SCHEDULE_DAYS, true)) {
            return true;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $days);

        return $date !== false && $date->format('Y-m-d') === $days;
    }

    /** Most instances any window (or the default) can ask for. Wrangler's cap is sized from this. */
    public static function peakInstances(array $settings): int
    {
        return max([$settings['max_instances'], ...array_column($settings['schedules'] ?? [], 'max')]);
    }

    /**
     * Blank uses Cloudflare's default steps. Otherwise each step is the
     * percent of instances on the new image, increasing, ending at 100.
     */
    public static function rolloutStepsError(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        $parts = preg_split('/\s*,\s*/', $raw) ?: [];
        if (count($parts) > 10) {
            return 'Use at most 10 rollout steps.';
        }

        $previous = 0;
        foreach ($parts as $part) {
            if (! ctype_digit($part)) {
                return 'Rollout steps are whole numbers from 1 to 100, separated by commas.';
            }
            $step = (int) $part;
            if ($step < 1 || $step > 100 || $step <= $previous) {
                return 'Rollout steps must increase, and each one is from 1 to 100.';
            }
            $previous = $step;
        }

        return $previous === 100 ? null : 'The last rollout step must be 100.';
    }

    /**
     * @return list<int>
     */
    public static function parseRolloutSteps(string $raw): array
    {
        if (self::rolloutStepsError($raw) !== null || trim($raw) === '') {
            return [];
        }

        return array_values(array_map(intval(...), preg_split('/\s*,\s*/', trim($raw)) ?: []));
    }

    /**
     * @return list<int>
     */
    public static function validRolloutSteps(mixed $steps): array
    {
        if (is_int($steps)) {
            $steps = [$steps];
        }
        if (! is_array($steps)) {
            return [];
        }

        $joined = implode(', ', array_map(static fn (mixed $step): string => is_int($step) || (is_string($step) && ctype_digit($step)) ? (string) (int) $step : 'x', $steps));

        return self::parseRolloutSteps($joined);
    }

    /**
     * @param  list<mixed>  $regions
     * @return list<string>
     */
    public static function normalizeRegions(array $regions, string $jurisdiction): array
    {
        $allowed = self::JURISDICTION_REGIONS[$jurisdiction] ?? array_keys(self::REGIONS);
        $kept = [];
        foreach ($regions as $region) {
            if (is_string($region) && in_array($region, $allowed, true) && ! in_array($region, $kept, true)) {
                $kept[] = $region;
            }
        }

        return $kept;
    }

    /**
     * The region an app runs in when it left placement open but uses a dply
     * database or dply Valkey: next to that data. Every query is a round trip,
     * and from the other side of the continent one costs ~145 ms instead of
     * ~13 (measured on waypost: queue throughput went 2 → 6.8 jobs/s). Null
     * when the app chose regions or a jurisdiction, or keeps no data with dply.
     */
    public static function dataRegion(Site $site): ?string
    {
        $settings = self::for($site);
        if ($settings['regions'] !== [] || $settings['jurisdiction'] !== '') {
            return null;
        }
        // The Cloudflare region paired with the region its data is in.
        $region = DataRegion::cloudflareFor($site);

        return $region !== null && isset(self::REGIONS[$region]) ? $region : null;
    }

    /**
     * A smaller, cheaper size when a week of memory peaks says the app never
     * needs what it has: the smallest size whose memory leaves 30% headroom
     * over the highest peak. Needs six hourly samples on the current size.
     * Never suggests a bigger one.
     *
     * @return array{type: string, peak_mb: float, samples: int, save_per_hour: float}|null
     */
    public static function sizeSuggestion(Site $site): ?array
    {
        $current = self::for($site)['instance_type'];
        $memory = $site->edgeMeta()['memory'] ?? [];
        if (! isset(self::INSTANCE_TYPES[$current]) || ($memory['type'] ?? null) !== $current) {
            return null;
        }
        $since = now()->subDays(7)->getTimestamp();
        $peaks = array_map(static fn ($s): float => (float) $s[1], array_filter((array) ($memory['samples'] ?? []), static fn ($s): bool => is_array($s) && ($s[0] ?? 0) >= $since));
        if (count($peaks) < 6) {
            return null;
        }
        $peak = max($peaks);
        $cost = app(EdgeContainerComputeCost::class);
        $perHour = static fn (string $type): float => $cost->perMinuteMillicents(...self::INSTANCE_TYPES[$type]) * 60 / 100_000;
        foreach (self::bySize() as $type) {
            if ($type === $current || self::INSTANCE_TYPES[$type][1] >= self::INSTANCE_TYPES[$current][1]) {
                return null; // nothing smaller fits
            }
            if (self::INSTANCE_TYPES[$type][1] * 1024 * 0.7 >= $peak && $perHour($type) < $perHour($current)) {
                return ['type' => $type, 'peak_mb' => $peak, 'samples' => count($peaks), 'save_per_hour' => round($perHour($current) - $perHour($type), 4)];
            }
        }

        return null;
    }

    /**
     * Wrangler `containers.constraints`. Null when the operator left placement open.
     *
     * @return array{regions?: list<string>, jurisdiction?: string}|null
     */
    public static function constraints(Site $site): ?array
    {
        $settings = self::for($site);
        $constraints = [];
        if ($settings['regions'] !== []) {
            $constraints['regions'] = $settings['regions'];
        } elseif (($near = self::dataRegion($site)) !== null) {
            $constraints['regions'] = [$near];
        }
        if ($settings['jurisdiction'] !== '') {
            $constraints['jurisdiction'] = $settings['jurisdiction'];
        }

        return $constraints === [] ? null : $constraints;
    }

    /**
     * What wrangler `max_instances` should be for this deploy.
     *
     * The first start uses only the instances the operator asked for. A later
     * gradual rollout starts the new image while the previous one is still up,
     * so that deploy's cap is one higher. A site set to 1 otherwise fails with
     * "Maximum number of running container instances exceeded". A dedicated
     * jobs container is another instance on top of that.
     */
    public static function wranglerMaxInstances(int $desired, bool $dedicatedJobs = false, bool $overlap = false, int $workers = 0): int
    {
        $desired = max(1, min(self::MAX_INSTANCES, $desired));

        return $desired + ($overlap ? 1 : 0) + ($dedicatedJobs ? 1 : 0) + max(0, $workers);
    }

    /**
     * True when a gradual deploy has a live container to overlap with.
     */
    public static function deployOverlap(Site $site): bool
    {
        if (self::for($site)['rollout_mode'] !== 'gradual') {
            return false;
        }

        return EdgeDeployment::query()
            ->where('site_id', $site->id)
            ->where('status', EdgeDeployment::STATUS_LIVE)
            ->exists();
    }

    /**
     * Smallest instance that stays up for this site.
     *
     * A PHP app on `lite` is what took laravel-starter down: 256 MB, the
     * process exits, and every request gets "The container is not running".
     */
    public static function minimumInstanceType(Site $site, string $phpServer = 'fpm'): string
    {
        $framework = strtolower((string) ($site->edgeMeta()['build']['framework'] ?? ''));
        $runtime = strtolower((string) ($site->runtime ?? ''));
        $php = in_array($framework, self::PHP_FRAMEWORKS, true) || in_array($runtime, ['php', 'laravel'], true);
        if (! $php) {
            return 'lite';
        }

        return in_array($phpServer, self::PHP_RESIDENT_SERVERS, true) ? 'standard-1' : 'basic';
    }

    /**
     * Custom sizes start at 1 vCPU. Memory is at least 3 GiB per vCPU and at
     * most 12 GiB. Disk is at most 2 GB per GiB of memory and at most 20 GB.
     */
    public static function customError(int $vcpu, int $memoryGib, int $diskGb): ?string
    {
        if ($vcpu < 1 || $vcpu > 4) {
            return 'vCPU must be from 1 to 4.';
        }
        if ($memoryGib < $vcpu * 3 || $memoryGib > 12) {
            return 'Memory must be at least 3 GiB per vCPU and at most 12 GiB.';
        }
        if ($diskGb < 1 || $diskGb > min(20, $memoryGib * 2)) {
            return 'Disk must be at most 2 GB per GiB of memory, and at most 20 GB.';
        }

        return null;
    }

    /**
     * @return array{vcpu: float, memory_gib: float, disk_gb: float, custom: bool}
     */
    public static function shape(Site $site): array
    {
        $raw = is_array($site->edgeMeta()['container'] ?? null) ? $site->edgeMeta()['container'] : [];
        $type = self::for($site)['instance_type'];
        if ($type === 'custom') {
            $vcpu = (int) ($raw['custom_vcpu'] ?? 1);
            $memory = (int) ($raw['custom_memory_gib'] ?? 3);
            $disk = (int) ($raw['custom_disk_gb'] ?? 6);
            if (self::customError($vcpu, $memory, $disk) === null) {
                return ['vcpu' => $vcpu, 'memory_gib' => $memory, 'disk_gb' => $disk, 'custom' => true];
            }
        }

        [$vcpu, $memory, $disk] = self::INSTANCE_TYPES[$type] ?? self::INSTANCE_TYPES['basic'];

        return ['vcpu' => (float) $vcpu, 'memory_gib' => (float) $memory, 'disk_gb' => (float) $disk, 'custom' => false];
    }

    /** @return string|array{vcpu: int, memory_mib: int, disk_mb: int} */
    /** Release bundles need Faster starts: the image is chosen at start. */
    public static function releaseBundle(array $settings): bool
    {
        return self::durableObjectScheduling($settings) && ($settings['release_bundle'] ?? false);
    }

    /** @param  array<string, mixed>  $settings  from for() */
    public static function durableObjectScheduling(array $settings): bool
    {
        return ($settings['scheduling'] ?? 'default') === 'durable_object';
    }

    /**
     * The instance size for ctx.container.start(): lite and standard-1..4 by
     * name, anything else (basic, custom) as vcpu / memoryMib / diskMb.
     *
     * @return string|array{vcpu: float|int, memoryMib: int, diskMb: int}
     */
    public static function durableObjectInstance(Site $site): string|array
    {
        $type = self::wranglerInstanceType($site);
        if (is_array($type)) {
            return ['vcpu' => $type['vcpu'], 'memoryMib' => $type['memory_mib'], 'diskMb' => $type['disk_mb']];
        }
        if (in_array($type, ['lite', 'standard-1', 'standard-2', 'standard-3', 'standard-4'], true)) {
            return $type;
        }
        [$vcpu, $gib, $disk] = self::INSTANCE_TYPES[$type] ?? self::INSTANCE_TYPES['basic'];

        return ['vcpu' => $vcpu, 'memoryMib' => (int) ($gib * 1024), 'diskMb' => $disk * 1000];
    }

    public static function wranglerInstanceType(Site $site): string|array
    {
        $shape = self::shape($site);
        $type = self::for($site)['instance_type'];
        if (! $shape['custom'] && ! str_starts_with($type, 'custom-')) {
            return $type;
        }

        return [
            'vcpu' => (int) $shape['vcpu'],
            'memory_mib' => (int) $shape['memory_gib'] * 1024,
            'disk_mb' => (int) $shape['disk_gb'] * 1000,
        ];
    }

    /**
     * Requests one instance takes before the Worker starts the next one.
     * PHP: one request per worker, and the worker count comes from memory
     * (phpFpmPool).
     */
    public static function requestsPerInstance(Site $site, string $phpServer = 'fpm'): int
    {
        if (self::minimumInstanceType($site) !== 'lite') { // PHP; only non-PHP apps may run on lite
            return self::phpFpmPool(self::for($site)['instance_type'], $site, $phpServer)['max_children'];
        }

        // ponytail: flat guess for Node/Ruby event-loop servers; make it a
        // setting when someone's app needs a different number.
        return 50;
    }

    /** Average resident memory of a php-fpm child or FrankenPHP thread serving Laravel; opcache is shared. */
    public const PHP_WORKER_MB = 56;

    /** An Octane worker (Swoole, RoadRunner) keeps the booted app resident. */
    public const OCTANE_WORKER_MB = 96;

    /** nginx, the php-fpm master, 64 MB opcache and the OS. */
    public const PHP_RESERVED_MB = 192;

    /**
     * PHP workers per instance: php-fpm `pm.max_children`, FrankenPHP
     * `num_threads`, Octane `--workers` (all read DPLY_PHP_FPM_MAX_CHILDREN).
     * Memory sets it: (memory - reserved) / per-worker average, so 1 GiB runs
     * 14 fpm children where it used to run 2. CPU caps it at 32 per vCPU
     * (at least 12) so a CPU-bound app spills to another instance instead of
     * queueing on one core.
     *
     * ponytail: sized on the average, not memory_limit: children × 128M can
     * exceed the instance if every request peaks at once. raiseForMemoryCrash
     * steps the size up when that happens; lower PHP_WORKER_MB if it does often.
     *
     * `workers` is how many processes start: max_children, except Octane
     * (swoole/roadrunner), whose workers each boot Laravel at startup, all at
     * once on the same CPU. There it follows the CPU: ceil(4 x vCPU), at least
     * 2. Measured (2026-09-30, 1/4 vCPU): 8 workers answered the first request
     * after ~11s, 2 after ~4.2s. max_children still sets requestsPerInstance,
     * so Swoole queues a burst briefly instead of the Worker waking another
     * instance (a cold start costs more than a short queue).
     *
     * @return array{max_children: int, workers: int, memory_limit: string}
     */
    public static function phpFpmPool(string $instanceType, ?Site $site = null, string $phpServer = 'fpm'): array
    {
        if ($instanceType === 'custom' && $site !== null) {
            $shape = self::shape($site);
            $vcpu = $shape['vcpu'];
            $memoryGib = $shape['memory_gib'];
        } else {
            $type = array_key_exists($instanceType, self::INSTANCE_TYPES) ? $instanceType : 'basic';
            $vcpu = self::INSTANCE_TYPES[$type][0];
            $memoryGib = self::INSTANCE_TYPES[$type][1];
        }
        $mib = (int) round($memoryGib * 1024);
        $perWorker = in_array($phpServer, ['swoole', 'roadrunner'], true) ? self::OCTANE_WORKER_MB : self::PHP_WORKER_MB;
        $byMemory = intdiv(max(0, $mib - self::PHP_RESERVED_MB), $perWorker);
        $byCpu = max(12, (int) floor($vcpu * 32));

        $max = max(1, min(128, $byMemory, $byCpu));

        return [
            'max_children' => $max,
            'workers' => in_array($phpServer, ['swoole', 'roadrunner'], true) ? min($max, max(2, (int) ceil($vcpu * 4))) : $max,
            // Per child, so one runaway request dies instead of the container.
            'memory_limit' => '128M',
        ];
    }

    /**
     * Offered sizes, smallest memory first (then fewest vCPU). Legacy types
     * are left out: nothing steps onto them.
     *
     * @return list<string>
     */
    public static function bySize(): array
    {
        $types = array_values(array_diff(array_keys(self::INSTANCE_TYPES), array_keys(EdgeSizeLadder::LEGACY_CONTAINER_TYPES)));
        usort($types, static fn (string $a, string $b): int => [self::INSTANCE_TYPES[$a][1], self::INSTANCE_TYPES[$a][0]] <=> [self::INSTANCE_TYPES[$b][1], self::INSTANCE_TYPES[$b][0]]);

        return $types;
    }

    /**
     * Sizes a picker lists: every offered type, plus `$current` when the app
     * is still on a legacy one.
     *
     * @return array<string, array{0: float, 1: float, 2: float}>
     */
    public static function offeredTypes(?string $current = null): array
    {
        return array_filter(
            self::INSTANCE_TYPES,
            static fn (string $type): bool => $type === $current || ! isset(EdgeSizeLadder::LEGACY_CONTAINER_TYPES[$type]),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /** The smallest offered size with more memory, or `$type` at the top. */
    public static function nextLarger(string $type): string
    {
        if (! array_key_exists($type, self::INSTANCE_TYPES)) {
            return 'basic';
        }
        foreach (self::bySize() as $candidate) {
            if (self::INSTANCE_TYPES[$candidate][1] > self::INSTANCE_TYPES[$type][1]) {
                return $candidate;
            }
        }

        return $type;
    }

    /**
     * Step up one size when logs show the cgroup or PHP killed the process.
     * Each size is raised at most once, so a repeated crash stops at the top.
     */
    public static function raiseForMemoryCrash(Site $site, string $evidence): bool
    {
        if (! self::looksLikeMemoryCrash($evidence)) {
            return false;
        }

        $raw = is_array($site->edgeMeta()['container'] ?? null) ? $site->edgeMeta()['container'] : [];
        $current = self::for($site)['instance_type'];
        if ($current === 'custom') {
            return false;
        }
        $already = is_array($raw['memory_bumped_from'] ?? null) ? $raw['memory_bumped_from'] : [];
        if (in_array($current, $already, true) || self::nextLarger($current) === $current) {
            return false;
        }

        $site->mergeEdgeMeta(['container' => array_merge($raw, [
            'instance_type' => self::nextLarger($current),
            'memory_bumped_from' => array_values(array_unique([...$already, $current])),
        ])]);
        $site->save();

        return true;
    }

    public static function looksLikeMemoryCrash(string $text): bool
    {
        return preg_match('/out of memory|oom-kill|oom killer|cannot allocate memory|Allowed memory size|exited with code 137|signal 9|Killed process/i', $text) === 1;
    }
}
