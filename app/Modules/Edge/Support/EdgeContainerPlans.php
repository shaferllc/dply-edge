<?php

declare(strict_types=1);

namespace App\Modules\Edge\Support;

/**
 * Customer-facing container bundles chosen before the first deploy.
 * Each plan maps onto the container settings the deployer already reads.
 */
final class EdgeContainerPlans
{
    public const DEFAULT = 'flex';

    /**
     * @var array<string, array{label: string, detail: string, instance_type: string, max_instances: int, sleep_after: string, scheduler: bool, migrate_on_boot: bool}>
     */
    public const PLANS = [
        'flex' => [
            'label' => 'Flex',
            'detail' => 'One small container. Sleeps when idle.',
            'instance_type' => 'basic',
            'max_instances' => 1,
            'sleep_after' => '10m',
            'scheduler' => false,
            'migrate_on_boot' => false,
        ],
        'small' => [
            'label' => 'Small',
            'detail' => 'More memory, stays warm longer, runs the scheduler.',
            'instance_type' => 'standard-1',
            'max_instances' => 1,
            'sleep_after' => '30m',
            'scheduler' => true,
            'migrate_on_boot' => false,
        ],
        'medium' => [
            'label' => 'Medium',
            'detail' => 'Two containers, scheduler, and migrations on boot.',
            'instance_type' => 'standard-2',
            'max_instances' => 2,
            'sleep_after' => '1h',
            'scheduler' => true,
            'migrate_on_boot' => true,
        ],
    ];

    /**
     * @return array<string, array{label: string, detail: string, instance_type: string, max_instances: int, sleep_after: string, scheduler: bool, migrate_on_boot: bool, vcpu: float, memory: float, disk: float}>
     */
    public static function choices(): array
    {
        $choices = [];
        foreach (self::PLANS as $key => $plan) {
            [$vcpu, $memory, $disk] = EdgeContainerSettings::INSTANCE_TYPES[$plan['instance_type']];
            $choices[$key] = $plan + [
                'vcpu' => $vcpu,
                'memory' => $memory,
                'disk' => $disk,
            ];
        }

        return $choices;
    }

    /**
     * Settings stored on the site and applied by the first container deploy.
     * A plan fills the defaults. Overrides replace any key the operator set.
     *
     * @param  array<string, mixed>  $overrides
     * @return array{plan: string, instance_type: string, max_instances: int, sleep_after: string, scheduler: bool, migrate_on_boot: bool, jurisdiction: string}
     */
    public static function settings(string $plan, array $overrides = []): array
    {
        $key = array_key_exists($plan, self::PLANS) ? $plan : self::DEFAULT;
        $chosen = self::PLANS[$key];

        $type = (string) ($overrides['instance_type'] ?? $chosen['instance_type']);
        if (! array_key_exists($type, EdgeContainerSettings::INSTANCE_TYPES)) {
            $type = $chosen['instance_type'];
        }

        $sleep = (string) ($overrides['sleep_after'] ?? $chosen['sleep_after']);
        if (! in_array($sleep, EdgeContainerSettings::SLEEP_AFTER, true)) {
            $sleep = $chosen['sleep_after'];
        }

        $jurisdiction = (string) ($overrides['jurisdiction'] ?? '');
        if (! in_array($jurisdiction, EdgeContainerSettings::JURISDICTIONS, true)) {
            $jurisdiction = '';
        }

        $instances = array_key_exists('max_instances', $overrides)
            ? (int) $overrides['max_instances']
            : $chosen['max_instances'];

        return [
            'plan' => $key,
            'instance_type' => $type,
            'max_instances' => max(1, min(EdgeContainerSettings::MAX_INSTANCES, $instances)),
            'sleep_after' => $sleep,
            'scheduler' => array_key_exists('scheduler', $overrides) ? (bool) $overrides['scheduler'] : $chosen['scheduler'],
            'migrate_on_boot' => array_key_exists('migrate_on_boot', $overrides) ? (bool) $overrides['migrate_on_boot'] : $chosen['migrate_on_boot'],
            'jurisdiction' => $jurisdiction,
        ];
    }
}
