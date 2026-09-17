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
     * @return array{instance_type: string, max_instances: int, sleep_after: string, migrate_on_boot: bool, jurisdiction: string, scheduler: bool}
     */
    public static function for(Site $site): array
    {
        $raw = is_array($site->edgeMeta()['container'] ?? null) ? $site->edgeMeta()['container'] : [];
        $type = (string) ($raw['instance_type'] ?? config('edge.build.containers.instance_type', 'basic'));
        $sleep = (string) ($raw['sleep_after'] ?? config('edge.build.containers.sleep_after', '10m'));
        $jurisdiction = (string) ($raw['jurisdiction'] ?? '');

        return [
            'instance_type' => array_key_exists($type, self::INSTANCE_TYPES) ? $type : 'basic',
            'max_instances' => max(1, min(self::MAX_INSTANCES, (int) ($raw['max_instances'] ?? config('edge.build.containers.max_instances', 5)))),
            'sleep_after' => in_array($sleep, self::SLEEP_AFTER, true) ? $sleep : '10m',
            'migrate_on_boot' => (bool) ($raw['migrate_on_boot'] ?? true),
            'jurisdiction' => in_array($jurisdiction, self::JURISDICTIONS, true) ? $jurisdiction : '',
            // Laravel: run `schedule:run` every minute via a Cron Trigger.
            'scheduler' => (bool) ($raw['scheduler'] ?? false),
        ];
    }
}
