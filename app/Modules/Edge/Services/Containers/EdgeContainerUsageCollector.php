<?php

declare(strict_types=1);

namespace App\Modules\Edge\Services\Containers;

use App\Models\EdgeContainerUsage;
use App\Models\Site;
use App\Modules\Providers\Cloudflare\EdgeCloudflareClient;
use Carbon\CarbonInterface;

/**
 * Pulls one day of container usage from Cloudflare and stores it per site.
 * Container applications are matched to sites by name: wrangler names them
 * after the Worker script (`dply-ctr-<site>`, see EdgeContainerDeployer).
 */
class EdgeContainerUsageCollector
{
    public function __construct(private ?EdgeCloudflareClient $client = null) {}

    /**
     * @return array{sites: int, applications: int}
     */
    public function collectForDate(CarbonInterface $date, bool $dryRun = false): array
    {
        $client = $this->client ?? EdgeCloudflareClient::fromConfig();
        $usage = $client->containerUsageForDate($date);
        if ($usage === []) {
            return ['sites' => 0, 'applications' => 0];
        }

        $siteByApp = [];
        foreach ($client->listContainerApplications() as $app) {
            if (preg_match('/^dply-ctr-([0-9a-z]{26})/', strtolower($app['name']), $m) === 1) {
                $siteByApp[$app['id']] = $m[1];
            }
        }

        $sites = Site::query()
            ->whereIn('id', array_map('strtoupper', array_unique(array_values($siteByApp))))
            ->orWhereIn('id', array_unique(array_values($siteByApp)))
            ->get(['id', 'organization_id'])
            ->keyBy(fn (Site $site) => strtolower((string) $site->id));

        // A site can run several container applications (the web app plus
        // queue-worker groups); add them up so none overwrites another.
        $bySite = [];
        foreach ($usage as $appId => $totals) {
            $site = $sites[$siteByApp[$appId] ?? ''] ?? null;
            if ($site === null || $site->organization_id === null) {
                continue;
            }
            $key = (string) $site->id;
            $bySite[$key] ??= ['site' => $site, 'application_id' => $appId, 'totals' => []];
            foreach ($totals as $field => $value) {
                $bySite[$key]['totals'][$field] = ($bySite[$key]['totals'][$field] ?? 0) + $value;
            }
        }

        $written = 0;
        foreach ($bySite as $row) {
            $written++;
            if ($dryRun) {
                continue;
            }
            EdgeContainerUsage::query()->updateOrCreate(
                ['site_id' => $row['site']->id, 'date' => $date->toDateString()],
                ['organization_id' => $row['site']->organization_id, 'application_id' => $row['application_id']] + $row['totals'],
            );
        }

        return ['sites' => $written, 'applications' => count($usage)];
    }
}
