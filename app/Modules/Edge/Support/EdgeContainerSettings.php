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

    public const MAX_INSTANCES = 20;

    /**
     * Frameworks that boot PHP per request. lite (256 MB) kills them.
     * basic (1 GiB) is the floor. Resident servers need standard-1 (4 GiB).
     */
    public const PHP_FRAMEWORKS = ['laravel', 'symfony', 'php', 'wordpress', 'drupal', 'statamic'];

    public const PHP_RESIDENT_SERVERS = ['frankenphp', 'swoole', 'roadrunner'];

    /**
     * @return array{instance_type: string, max_instances: int, sleep_after: string, migrate_on_boot: bool, jurisdiction: string, scheduler: bool}
     */
    public static function for(Site $site, string $phpServer = 'fpm'): array
    {
        $raw = is_array($site->edgeMeta()['container'] ?? null) ? $site->edgeMeta()['container'] : [];
        $type = (string) ($raw['instance_type'] ?? config('edge.build.containers.instance_type', 'basic'));
        $sleep = (string) ($raw['sleep_after'] ?? config('edge.build.containers.sleep_after', '10m'));
        $jurisdiction = (string) ($raw['jurisdiction'] ?? '');

        return [
            'instance_type' => self::atLeast(
                array_key_exists($type, self::INSTANCE_TYPES) ? $type : 'basic',
                self::minimumInstanceType($site, $phpServer),
            ),
            'max_instances' => max(1, min(self::MAX_INSTANCES, (int) ($raw['max_instances'] ?? config('edge.build.containers.max_instances', 5)))),
            'sleep_after' => in_array($sleep, self::SLEEP_AFTER, true) ? $sleep : '10m',
            // Off by default: this runs a second full framework boot at the
            // moment a cold-starting container has the least memory, and it
            // re-runs on every wake from sleep. Opt in per site.
            'migrate_on_boot' => (bool) ($raw['migrate_on_boot'] ?? false),
            'jurisdiction' => in_array($jurisdiction, self::JURISDICTIONS, true) ? $jurisdiction : '',
            // Laravel: run `schedule:run` every minute via a Cron Trigger.
            'scheduler' => (bool) ($raw['scheduler'] ?? false),
        ];
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
    public static function wranglerMaxInstances(int $desired): int
    {
        $desired = max(1, min(self::MAX_INSTANCES, $desired));

        return $desired + 1;
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

    /** @return array{max_children: int, memory_limit: string} */
    public static function phpFpmPool(string $instanceType): array
    {
        $type = array_key_exists($instanceType, self::INSTANCE_TYPES) ? $instanceType : 'basic';
        $mib = (int) round(self::INSTANCE_TYPES[$type][1] * 1024);
        // nginx + php-fpm master + 64 MB opcache. Each child is capped at
        // memory_limit so a request dies instead of the whole container.
        $memoryLimit = 128;
        $byMemory = max(1, intdiv(max(0, $mib - 128), $memoryLimit));
        $byCpu = max(1, (int) floor(self::INSTANCE_TYPES[$type][0] * 8));

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
