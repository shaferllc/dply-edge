<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Models\EdgeContainerUsage;
use App\Models\EdgeDeployment;
use App\Models\EdgePostgresUsage;
use App\Models\EdgeRedisUsage;
use App\Models\Organization;
use App\Models\Site;
use App\Modules\Billing\Support\UsagePrice;
use App\Modules\Edge\Services\EdgeAppDatabase;
use App\Modules\Edge\Support\EdgeBuildMinutes;
use App\Modules\Edge\Support\EdgeContainerConnections;
use App\Modules\Edge\Support\EdgeContainerSettings;
use App\Modules\Edge\Support\EdgeQueueWorkers;
use App\Modules\Edge\Support\EdgeValkey;
use Illuminate\Database\Eloquent\Model;

/**
 * What a trial has spent since the collectors last ran (they run hourly),
 * so the 5-minute cap check (dply:billing:enforce --trialing) is not an hour
 * stale. Priced at customer rates like the collected usage:
 *
 *   builds in flight  seconds since each started (build_seconds is only set when it ends)
 *   container apps    instances × size, as if awake since the last collection
 *   queue workers     the same, per always-on worker
 *   dply databases    compute units, as if awake since the last collection
 *   dply Valkey       the class's per-second price, the same way
 *
 * An upper bound: an app that slept since the last collection still counts,
 * for at most LOOKBACK seconds. Delivery, KV, data and platform meters are
 * not estimated; they arrive with the hourly collection.
 */
final class TrialRunningCost
{
    /** At most this far back: the collectors run hourly, plus a little lag. */
    public const LOOKBACK = 65 * 60;

    public function cents(Organization $organization): int
    {
        $builds = 0;
        EdgeDeployment::query()
            ->where('organization_id', $organization->id)
            ->whereIn('status', [EdgeDeployment::STATUS_BUILDING, EdgeDeployment::STATUS_PUBLISHING])
            ->whereNull('build_seconds')
            ->whereNotNull('build_started_at')
            ->pluck('build_started_at')
            ->each(function ($started) use (&$builds): void {
                $builds += max(0, (int) now()->diffInSeconds($started, true));
            });
        $millicents = UsagePrice::customer(EdgeBuildMinutes::costMillicents($builds));

        $sites = Site::query()
            ->where('organization_id', $organization->id)
            ->where('meta->edge->runtime_mode', 'container')
            // Only apps that have gone live run; drafts and failed first deploys do not.
            ->whereHas('edgeDeployments', fn ($query) => $query->where('status', EdgeDeployment::STATUS_LIVE))
            // On the org's own Cloudflare account the compute is not dply's.
            ->where(fn ($query) => $query->whereNull('edge_backend')->orWhere('edge_backend', '!=', 'org_cloudflare'))
            ->get();
        foreach ($sites as $site) {
            $settings = EdgeContainerSettings::for($site);
            $shape = EdgeContainerSettings::shape($site);
            // A trial has no dedicated jobs instance (EdgeTrialLimits).
            $instances = $settings['max_instances'] + EdgeQueueWorkers::runningInstances($site);
            $millicents += $instances * UsagePrice::containerPerSecond($shape['vcpu'], $shape['memory_gib'], $shape['disk_gb'])
                * $this->seconds(EdgeContainerUsage::class, $site);

            $database = $site->edgeMeta()['database'] ?? null;
            if (is_array($database) && EdgeAppDatabase::isDply($database)) {
                $cu = UsagePrice::databaseBilledCu((string) ($database['size'] ?? ''));
                $millicents += $cu * UsagePrice::rate('database_compute_millicents_per_cu_second') * $this->seconds(EdgePostgresUsage::class, $site);
            }

            foreach (EdgeContainerConnections::for($site) as $connection) {
                if ($connection['kind'] === 'redis' && EdgeValkey::isTarget($connection['target']) && ! $connection['asleep']) {
                    $millicents += EdgeValkey::spec((string) $connection['plan'])['per_second'] * 100_000 * $this->seconds(EdgeRedisUsage::class, $site);
                }
            }
        }

        return (int) ceil($millicents / 1000);
    }

    /**
     * Seconds since this site's usage was last collected, at most LOOKBACK.
     *
     * @param  class-string<Model>  $usage
     */
    private function seconds(string $usage, Site $site): int
    {
        $last = $usage::query()->where('site_id', $site->id)->max('updated_at');
        $since = $last === null ? self::LOOKBACK : (int) now()->diffInSeconds($last, true);

        return max(0, min(self::LOOKBACK, $since));
    }
}
