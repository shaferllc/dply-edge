<?php

declare(strict_types=1);

namespace App\Modules\Edge\Services\Containers;

use App\Models\EdgeContainerUsage;
use App\Models\Site;
use App\Modules\Providers\Cloudflare\EdgeCloudflareClient;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pulls one day of container usage from Cloudflare and stores it per site.
 * Container applications are matched to sites by name: wrangler names them
 * after the Worker script (`dply-ctr-<site>`, see EdgeContainerDeployer).
 * reply_bytes (what the app's Worker counted going back to visitors, from
 * Analytics Engine) is null for a site with no data point in 30 days: its
 * Worker predates the counter, so its outbound is not billed.
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
                $siteByApp[self::appKey($app['id'])] = $m[1];
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
            $site = $sites[$siteByApp[self::appKey((string) $appId)] ?? ''] ?? null;
            if ($site === null || $site->organization_id === null) {
                continue;
            }
            $key = (string) $site->id;
            $bySite[$key] ??= ['site' => $site, 'application_id' => $appId, 'totals' => []];
            foreach ($totals as $field => $value) {
                $bySite[$key]['totals'][$field] = ($bySite[$key]['totals'][$field] ?? 0) + $value;
            }
        }

        $replies = $this->replyBytes($client, $date);

        $written = 0;
        foreach ($bySite as $row) {
            $written++;
            if ($dryRun) {
                continue;
            }
            EdgeContainerUsage::query()->updateOrCreate(
                ['site_id' => $row['site']->id, 'date' => $date->toDateString()],
                ['organization_id' => $row['site']->organization_id, 'application_id' => $row['application_id']] + $row['totals']
                    + ($replies === null ? [] : ['reply_bytes' => $this->replyFor($replies, strtolower((string) $row['site']->id))]),
            );
        }

        return ['sites' => $written, 'applications' => count($usage)];
    }

    /**
     * @param  array{day: array<string, int>, metered: array<string, true>, streamed: array<string, true>}  $replies
     */
    private function replyFor(array $replies, string $siteId): ?int
    {
        // Null bills no outbound: a Worker older than the counter, or a day
        // it served a socket or event stream, whose bytes it can't count.
        if (! isset($replies['metered'][$siteId]) || isset($replies['streamed'][$siteId])) {
            return null;
        }

        return $replies['day'][$siteId] ?? 0;
    }

    /**
     * Reply bytes per site for the day, and which sites wrote any in 30 days.
     * Null when Analytics Engine cannot be read: the column is left as it was.
     *
     * @return array{day: array<string, int>, metered: array<string, true>, streamed: array<string, true>}|null
     */
    private function replyBytes(EdgeCloudflareClient $client, CarbonInterface $date): ?array
    {
        $dataset = EdgeContainerDeployer::REPLY_BYTES_DATASET;
        $from = $date->copy()->utc()->startOfDay();
        $at = static fn (CarbonInterface $t): string => "toDateTime('".$t->format('Y-m-d H:i:s')."')";
        try {
            $day = $client->queryAnalyticsEngineSql("SELECT blob1 AS site, SUM(_sample_interval * double1) AS bytes FROM {$dataset} WHERE timestamp >= {$at($from)} AND timestamp < {$at($from->copy()->addDay())} GROUP BY site");
            $seen = $client->queryAnalyticsEngineSql("SELECT blob1 AS site FROM {$dataset} WHERE timestamp >= {$at($from->copy()->subDays(29))} AND timestamp < {$at($from->copy()->addDay())} GROUP BY site");
            $streamed = $client->queryAnalyticsEngineSql("SELECT blob1 AS site FROM {$dataset} WHERE blob2 = 'stream' AND timestamp >= {$at($from)} AND timestamp < {$at($from->copy()->addDay())} GROUP BY site");
        } catch (Throwable $e) {
            Log::warning('Container reply bytes unreadable; reply_bytes left as it was', ['date' => $from->toDateString(), 'error' => $e->getMessage()]);

            return null;
        }
        $out = ['day' => [], 'metered' => [], 'streamed' => []];
        foreach ($streamed as $row) {
            $out['streamed'][(string) ($row['site'] ?? '')] = true;
        }
        foreach ($day as $row) {
            $out['day'][(string) ($row['site'] ?? '')] = (int) round((float) ($row['bytes'] ?? 0));
        }
        foreach ($seen as $row) {
            $out['metered'][(string) ($row['site'] ?? '')] = true;
        }

        return $out;
    }

    /**
     * Usage names an application by dashed UUID; a Faster starts (durable_object)
     * application is listed by its Durable Object namespace id, the same hex
     * without dashes. Compare them without dashes, or its usage goes unbilled.
     */
    private static function appKey(string $id): string
    {
        return strtolower(str_replace('-', '', $id));
    }
}
