<?php

declare(strict_types=1);

namespace App\Modules\Edge\Services;

use App\Models\EdgeKvUsage;
use App\Models\Site;
use App\Modules\Edge\Support\EdgeContainerConnections;
use App\Modules\Providers\Cloudflare\EdgeCloudflareClient;
use Carbon\CarbonInterface;

/**
 * Called by CollectEdgeKvUsageCommand. Reads KV GraphQL analytics.
 * Writes edge_kv_usage. User request: "continue buuiikding out key value".
 */
final class EdgeKvUsageCollector
{
    public function __construct(private ?EdgeCloudflareClient $client = null) {}

    /**
     * @return array{sites: int}
     */
    public function collectForDate(CarbonInterface $date, bool $dryRun = false): array
    {
        $usage = ($this->client ?? EdgeCloudflareClient::fromConfig())->kvUsageForDate($date);
        $sites = 0;
        // Worker (ssr/hybrid) apps bind KV too, so any app with connections, not only containers.
        Site::query()->whereNotNull('meta->edge->connections')->orderBy('id')->each(function (Site $site) use ($usage, $date, $dryRun, &$sites): void {
            $wrote = false;
            foreach (EdgeContainerConnections::for($site) as $connection) {
                $id = (string) ($connection['target'] ?? '');
                // Asleep too: Cloudflare still stores its data, and the live deploy binds it until the next one.
                if ($connection['kind'] !== 'key_value' || $id === '' || ! isset($usage[$id])) {
                    continue;
                }
                $wrote = true;
                if ($dryRun) {
                    continue;
                }
                EdgeKvUsage::query()->updateOrCreate(
                    ['namespace_id' => $id, 'date' => $date->toDateString()],
                    ['organization_id' => $site->organization_id, 'site_id' => $site->id] + $usage[$id],
                );
            }
            if ($wrote) {
                $sites++;
            }
        });

        return ['sites' => $sites];
    }
}
