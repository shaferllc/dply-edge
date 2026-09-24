<?php

declare(strict_types=1);

namespace App\Modules\Edge\Services;

use App\Models\EdgePostgresUsage;
use App\Models\Site;
use App\Modules\Providers\Neon\NeonClient;
use Carbon\CarbonInterface;

/**
 * Called by CollectEdgePostgresUsageCommand. Reads project consumption.
 * Writes edge_postgres_usage. User request: "ok lets move ahead with imp,emeting neon and postgres first".
 */
final class EdgePostgresUsageCollector
{
    public function __construct(private ?NeonClient $client = null) {}

    /**
     * @return array{sites: int}
     */
    public function collectForDate(CarbonInterface $date, bool $dryRun = false): array
    {
        if (! NeonClient::configured()) {
            return ['sites' => 0];
        }

        $sites = Site::query()
            ->where('meta->edge->runtime_mode', 'container')
            ->orderBy('id')
            ->get()
            ->filter(function (Site $site): bool {
                $database = $site->edgeMeta()['database'] ?? null;

                return is_array($database)
                    && ($database['engine'] ?? '') === 'postgres'
                    && (string) ($database['remote_id'] ?? '') !== '';
            });
        $ids = $sites->map(fn (Site $site): string => (string) $site->edgeMeta()['database']['remote_id'])->values()->all();
        $usage = ($this->client ?? NeonClient::fromConfig())->consumption($date, $ids);
        $wrote = 0;
        foreach ($sites as $site) {
            $id = (string) $site->edgeMeta()['database']['remote_id'];
            if (! isset($usage[$id])) {
                continue;
            }
            $wrote++;
            if ($dryRun) {
                continue;
            }
            EdgePostgresUsage::query()->updateOrCreate(
                ['project_id' => $id, 'date' => $date->toDateString()],
                ['organization_id' => $site->organization_id, 'site_id' => $site->id] + $usage[$id],
            );
        }

        return ['sites' => $wrote];
    }
}
