<?php

declare(strict_types=1);

namespace App\Modules\Edge\Support;

use App\Models\Site;

/**
 * Per-site container settings (`edgeMeta()['container']`), read by
 * EdgeContainerDeployer on every deploy. Missing keys fall back to
 * `edge.build.containers.*`.
 */
final class EdgeContainerSettings
{
    /** Cloudflare Containers instance types: [vCPU, memory GiB, disk GB]. */
    public const INSTANCE_TYPES = [
        'lite' => [1 / 16, 0.25, 2],
        'basic' => [0.25, 1, 4],
        'standard-1' => [0.5, 4, 8],
        'standard-2' => [1, 6, 12],
        'standard-3' => [2, 8, 16],
        'standard-4' => [4, 12, 20],
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
     * @return array{instance_type: string, max_instances: int, sleep_after: string, migrate_on_boot: bool, jurisdiction: string, regions: list<string>, scheduler: bool, rollout_mode: string, rollout_step_percentage: list<int>, rollout_active_grace_period: int}
     */
    public static function for(Site $site, string $phpServer = 'fpm'): array
    {
        $raw = is_array($site->edgeMeta()['container'] ?? null) ? $site->edgeMeta()['container'] : [];
        $type = (string) ($raw['instance_type'] ?? config('edge.build.containers.instance_type', 'basic'));
        $sleep = (string) ($raw['sleep_after'] ?? config('edge.build.containers.sleep_after', '10m'));
        $jurisdiction = (string) ($raw['jurisdiction'] ?? '');
        $mode = (string) ($raw['rollout_mode'] ?? 'gradual');

        return [
            'instance_type' => $type === 'custom' || array_key_exists($type, self::INSTANCE_TYPES) ? $type : 'basic',
            'max_instances' => max(1, min(self::MAX_INSTANCES, (int) ($raw['max_instances'] ?? config('edge.build.containers.max_instances', 5)))),
            'sleep_after' => in_array($sleep, self::SLEEP_AFTER, true) ? $sleep : '10m',
            // Off by default: this runs a second full framework boot at the
            // moment a cold-starting container has the least memory, and it
            // re-runs on every wake from sleep. Opt in per site.
            'migrate_on_boot' => (bool) ($raw['migrate_on_boot'] ?? false),
            'jurisdiction' => in_array($jurisdiction, self::JURISDICTIONS, true) ? $jurisdiction : '',
            'regions' => self::normalizeRegions(is_array($raw['regions'] ?? null) ? $raw['regions'] : [], in_array($jurisdiction, self::JURISDICTIONS, true) ? $jurisdiction : ''),
            // Laravel: run `schedule:run` every minute via a Cron Trigger.
            'scheduler' => (bool) ($raw['scheduler'] ?? false),
            'sticky_sessions' => (bool) ($raw['sticky_sessions'] ?? true),
            'dedicated_jobs' => (bool) ($raw['dedicated_jobs'] ?? true),
            'rollout_mode' => in_array($mode, self::ROLLOUT_MODES, true) ? $mode : 'gradual',
            'rollout_step_percentage' => self::validRolloutSteps($raw['rollout_step_percentage'] ?? []),
            'rollout_active_grace_period' => max(0, min(self::ROLLOUT_GRACE_MAX, (int) ($raw['rollout_active_grace_period'] ?? 0))),
        ];
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
        }
        if ($settings['jurisdiction'] !== '') {
            $constraints['jurisdiction'] = $settings['jurisdiction'];
        }

        return $constraints === [] ? null : $constraints;
    }

    /**
     * What wrangler `max_instances` should be for this deploy.
     *
     * Gradual rollout starts the new image while the previous instance is
     * still up, so the cap must be one higher than the traffic pool
     * (`getRandom` still uses the operator's number). Otherwise a site set
     * to 1 fails every deploy with "Maximum number of running container
     * instances exceeded".
     */
    public static function wranglerMaxInstances(int $desired, bool $dedicatedJobs = false): int
    {
        $desired = max(1, min(self::MAX_INSTANCES, $desired));

        return $desired + 1 + ($dedicatedJobs ? 1 : 0);
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
    public static function wranglerInstanceType(Site $site): string|array
    {
        $shape = self::shape($site);
        if (! $shape['custom']) {
            return self::for($site)['instance_type'];
        }

        return [
            'vcpu' => (int) $shape['vcpu'],
            'memory_mib' => (int) $shape['memory_gib'] * 1024,
            'disk_mb' => (int) $shape['disk_gb'] * 1000,
        ];
    }

    /** @return array{max_children: int, memory_limit: string} */
    public static function phpFpmPool(string $instanceType, ?Site $site = null): array
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
        // nginx + php-fpm master + 64 MB opcache. Each child is capped at
        // memory_limit so a request dies instead of the whole container.
        $memoryLimit = 128;
        $byMemory = max(1, intdiv(max(0, $mib - 128), $memoryLimit));
        $byCpu = max(1, (int) floor($vcpu * 8));

        return [
            'max_children' => min(12, $byMemory, $byCpu),
            'memory_limit' => $memoryLimit.'M',
        ];
    }

    public static function atLeast(string $current, string $floor): string
    {
        $order = array_keys(self::INSTANCE_TYPES);
        $current = array_key_exists($current, self::INSTANCE_TYPES) ? $current : 'basic';
        $floor = array_key_exists($floor, self::INSTANCE_TYPES) ? $floor : 'basic';

        return $order[max((int) array_search($current, $order, true), (int) array_search($floor, $order, true))];
    }

    public static function nextLarger(string $type): string
    {
        $order = array_keys(self::INSTANCE_TYPES);
        $index = array_search($type, $order, true);
        if ($index === false || $index >= count($order) - 1) {
            return array_key_exists($type, self::INSTANCE_TYPES) ? $type : 'basic';
        }

        return $order[$index + 1];
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
