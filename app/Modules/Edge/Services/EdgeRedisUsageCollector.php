<?php

declare(strict_types=1);

namespace App\Modules\Edge\Services;

use App\Models\EdgeRedisUsage;
use App\Models\Site;
use App\Modules\Edge\Support\EdgeContainerConnections;
use App\Modules\Providers\Upstash\UpstashRedisClient;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;

/**
 * Called by CollectEdgeRedisUsageCommand. Reads GET /redis/stats/{id}.
 * Writes edge_redis_usage (commands, storage_bytes, bandwidth_bytes).
 * User request: "ok so how can we implement upstash and bill for it".
 */
final class EdgeRedisUsageCollector
{
    /**
     * @return array{sites: int}
     */
    public function collectForDate(CarbonInterface $date, bool $dryRun = false): array
    {
        if (! UpstashRedisClient::configured()) {
            return ['sites' => 0];
        }

        $client = UpstashRedisClient::fromConfig();
        $day = $date->toDateString();
        $sites = 0;
        Site::query()->where('meta->edge->runtime_mode', 'container')->orderBy('id')->each(function (Site $site) use ($client, $day, $dryRun, &$sites): void {
            foreach (EdgeContainerConnections::for($site) as $connection) {
                $id = (string) ($connection['target'] ?? '');
                if ($connection['kind'] !== 'redis' || ! self::isProvisionedId($id)) {
                    continue;
                }
                try {
                    $stats = $client->stats($id);
                } catch (\Throwable $e) {
                    Log::warning('edge.redis.usage_failed', ['site_id' => $site->id, 'message' => $e->getMessage()]);

                    continue;
                }
                $sites++;
                if ($dryRun) {
                    continue;
                }
                EdgeRedisUsage::query()->updateOrCreate(
                    ['site_id' => $site->id, 'date' => $day],
                    [
                        'organization_id' => $site->organization_id,
                        'commands' => self::seriesTotal($stats['dailyrequests'] ?? null, $day),
                        'storage_bytes' => max(0, (int) ($stats['current_storage'] ?? 0)),
                        'bandwidth_bytes' => self::seriesTotal($stats['bandwidths'] ?? null, $day),
                    ],
                );
            }
        });

        return ['sites' => $sites];
    }

    public static function isProvisionedId(string $id): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id) === 1;
    }

    private static function seriesTotal(mixed $series, string $day): int
    {
        if (! is_array($series)) {
            return 0;
        }
        $total = 0;
        foreach ($series as $point) {
            if (! is_array($point) || ! str_starts_with((string) ($point['x'] ?? ''), $day)) {
                continue;
            }
            $total += max(0, (int) ($point['y'] ?? 0));
        }

        return $total;
    }
}
