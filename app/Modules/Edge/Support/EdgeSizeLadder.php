<?php

declare(strict_types=1);

namespace App\Modules\Edge\Support;

/**
 * One size ladder shared by container apps, dply databases and Valkey
 * (ruling r-2zxevg4sj675qn1m). Stored size keys stay as they were; this maps
 * each product's keys onto the rung whose display name it shows. Memory per
 * rung differs by product. Prices come from UsagePrice::sizes().
 */
final class EdgeSizeLadder
{
    /** Rung key => display name. Database size keys are the rung keys. */
    public const RUNGS = [
        '0.25' => '0.25 vCPU',
        '0.5' => '0.5 vCPU',
        '1' => '1 vCPU',
        '2' => '2 vCPU',
        '4' => '4 vCPU',
    ];

    /**
     * Container instance type => rung. `lite` sits below the ladder. The 1
     * and 2 vCPU rungs are Cloudflare custom shapes (1/3 GiB, 2/6 GiB):
     * memory is most of the cost, and the named types carry 6 and 8 GiB.
     */
    public const CONTAINER_TYPES = [
        'basic' => '0.25',
        'standard-1' => '0.5',
        'custom-1' => '1',
        'custom-2' => '2',
        'standard-4' => '4',
    ];

    /**
     * Retired rung types => the rung they sat on. Apps already on them keep
     * them (and are billed their real shape) until the owner picks a size.
     */
    public const LEGACY_CONTAINER_TYPES = [
        'standard-2' => '1',
        'standard-3' => '2',
    ];

    /** Valkey class => rung. pro_25g / pro_50g are not offered. */
    public const VALKEY_CLASSES = [
        'flex_250m' => '0.25',
        'flex_1g' => '0.5',
        'flex_2_5g' => '1',
        'pro_5g' => '2',
        'pro_12g' => '4',
    ];

    /** Display name for a container instance type. */
    public static function containerLabel(string $instanceType): string
    {
        if (isset(self::CONTAINER_TYPES[$instanceType])) {
            return self::RUNGS[self::CONTAINER_TYPES[$instanceType]];
        }
        if (isset(self::LEGACY_CONTAINER_TYPES[$instanceType])) {
            $memory = EdgeContainerSettings::INSTANCE_TYPES[$instanceType][1];

            return self::RUNGS[self::LEGACY_CONTAINER_TYPES[$instanceType]].' · '.$memory.' GB (retired)';
        }

        return $instanceType === 'lite' ? '1/16 vCPU' : $instanceType;
    }
}
