<?php

declare(strict_types=1);

namespace App\Modules\Edge\Support;

use App\Models\Organization;
use App\Models\Site;

/**
 * What a trial may run (docs/pricing-review.md §8 item 9). A trial is not
 * charged, so everything that bills while awake is held to the smallest
 * ladder rung, one instance, and sleeps when idle:
 *
 *   container apps  smallest rung, 1 instance, none kept awake, sleep after 5m,
 *                   no dedicated or always-on jobs instance
 *   queue workers   1 instance, no autoscale, no extra groups (EdgeQueueWorkers::allowance)
 *   builds          1 at a time (EdgeBuildSlots)
 *   databases       smallest size, never "stays on"
 *   Valkey          smallest (sleeping) class, never "stays on"
 *
 * Sizes only go down: an app already below the rung keeps its size. The
 * stored settings are left alone, so the owner's choice comes back once the
 * trial converts.
 */
final class EdgeTrialLimits
{
    public const SLEEP_AFTER = '5m';

    public const NOTE = 'Available after your trial';

    public static function applies(?Organization $organization): bool
    {
        return $organization?->onTrialPlan() ?? false;
    }

    /** Container sizes a trial may run: the smallest rung, and lite below it. */
    public const CONTAINER_TYPES = ['lite', 'basic'];

    /** The smallest container rung, which a larger size is held to. */
    public static function containerType(): string
    {
        return 'basic';
    }

    public static function databaseSize(): string
    {
        return (string) array_key_first(EdgeSizeLadder::RUNGS);
    }

    public static function valkeyClass(): string
    {
        return (string) array_key_first(EdgeSizeLadder::VALKEY_CLASSES);
    }

    /** Whether a trial may pick this container size. */
    public static function allowsContainerType(string $type): bool
    {
        return in_array($type, self::CONTAINER_TYPES, true);
    }

    /**
     * EdgeContainerSettings::for() on a trial.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public static function container(Site $site, array $settings, string $phpServer = 'fpm'): array
    {
        if (! self::applies($site->organization)) {
            return $settings;
        }
        // A resident PHP server (FrankenPHP, Swoole, RoadRunner) can't start
        // below its minimum size, so a trial gets that minimum instead of a
        // crash — the trial is when the app most needs to work.
        $minimum = EdgeContainerSettings::minimumInstanceType($site, $phpServer);
        $trialType = self::allowsContainerType($minimum) ? self::containerType() : $minimum;
        if (! self::allowsContainerType((string) $settings['instance_type']) && $settings['instance_type'] !== $trialType) {
            $settings['instance_type'] = $trialType;
        }
        $settings['max_instances'] = 1;
        $settings['min_instances'] = 0;
        $settings['sleep_after'] = self::SLEEP_AFTER;
        $settings['dedicated_jobs'] = false;
        $settings['jobs_always_on'] = false;
        $settings['schedules'] = array_map(static fn (array $row): array => ['max' => 1, 'min' => 0] + $row, $settings['schedules'] ?? []);

        return $settings;
    }

    /**
     * A dply database on a trial: smallest size, and it sleeps.
     *
     * @return array{0: string, 1: int}
     */
    public static function database(Site $site, string $size, int $suspend): array
    {
        if (! self::applies($site->organization)) {
            return [$size, $suspend];
        }

        return [self::databaseSize(), $suspend === -1 ? 300 : $suspend];
    }

    /**
     * dply Valkey on a trial: the smallest class (it sleeps), never "stays on".
     *
     * @return array{0: string, 1: int}
     */
    public static function valkey(Site $site, string $class, int $sleep): array
    {
        if (! self::applies($site->organization)) {
            return [$class, $sleep];
        }

        return [self::valkeyClass(), $sleep === 0 ? EdgeValkey::DEFAULT_SLEEP : $sleep];
    }
}
