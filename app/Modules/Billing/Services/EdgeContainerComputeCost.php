<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Models\EdgeContainerUsage;
use App\Models\Organization;
use App\Models\Site;
use App\Modules\Billing\Support\UsagePrice;
use App\Modules\Edge\Support\EdgeContainerSettings;
use Carbon\CarbonInterface;

/**
 * Prices container compute (container apps and queue workers) per second
 * awake: vCPU-, GiB-memory- and GB-disk-seconds, at cost from
 * dply.edge.usage_billing.container_*, priced by UsagePrice (one margin),
 * with a monthly cap per app instance (capMillicents) that never goes
 * below cost.
 *
 * Container egress (tx_bytes) is recorded but NOT billed. Every visitor
 * request to a container app goes through its Worker on the site's
 * hostname, so the response bytes are already in the zone's
 * edgeResponseBytes and billed once as delivery bandwidth ($0.06/GB,
 * EdgeUsageCostCalculator). tx_bytes counts the same bytes again on their
 * way from the container to the Worker, plus the container's own outbound
 * calls, which Cloudflare covers with 1 TB/month included (NA/EU).
 */
class EdgeContainerComputeCost
{
    /**
     * The period's compute, each app capped (capMillicents) and the total
     * rounded once.
     *
     * @return array{cpu_seconds: float, memory_gib_seconds: float, disk_gb_seconds: float, tx_bytes: int, cents: int}
     */
    public function forOrganization(Organization $organization, CarbonInterface $from, CarbonInterface $to): array
    {
        $rows = EdgeContainerUsage::query()
            ->where('organization_id', $organization->id)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('site_id')
            ->selectRaw('site_id, COALESCE(SUM(cpu_seconds), 0) AS cpu, COALESCE(SUM(memory_gib_seconds), 0) AS memory, COALESCE(SUM(disk_gb_seconds), 0) AS disk, COALESCE(SUM(tx_bytes), 0) AS tx')
            ->toBase()
            ->get();
        $sites = Site::query()->whereIn('id', $rows->pluck('site_id')->filter()->all())->get()->keyBy('id');

        $totals = ['cpu_seconds' => 0.0, 'memory_gib_seconds' => 0.0, 'disk_gb_seconds' => 0.0, 'tx_bytes' => 0];
        $millicents = 0.0;
        foreach ($rows as $row) {
            $totals['cpu_seconds'] += (float) $row->cpu;
            $totals['memory_gib_seconds'] += (float) $row->memory;
            $totals['disk_gb_seconds'] += (float) $row->disk;
            $totals['tx_bytes'] += (int) $row->tx;
            $millicents += $this->siteMillicents($sites->get($row->site_id), (float) $row->cpu, (float) $row->memory, (float) $row->disk);
        }

        return $totals + ['cents' => (int) round(round($millicents, 6) / 1000)];
    }

    /** Customer cents for one app's usage, capped. */
    public function siteCents(?Site $site, float $cpuSeconds, float $memoryGibSeconds, float $diskGbSeconds): int
    {
        return (int) round(round($this->siteMillicents($site, $cpuSeconds, $memoryGibSeconds, $diskGbSeconds), 6) / 1000);
    }

    /**
     * Customer millicents for one app: the metered price, at most the cap for
     * as many instance-months as its memory-seconds add up to, and never
     * below dply's cost. Without a site (deleted) there is no cap.
     */
    public function siteMillicents(?Site $site, float $cpuSeconds, float $memoryGibSeconds, float $diskGbSeconds): float
    {
        $cost = $this->costMillicents($cpuSeconds, $memoryGibSeconds, $diskGbSeconds);
        $price = UsagePrice::customer($cost);
        $cap = $site !== null ? $this->capMillicents($site, $memoryGibSeconds) : null;

        return $cap === null ? $price : max($cost, min($price, $cap));
    }

    /**
     * The monthly cap per instance (UsagePrice::containerCapMillicents) times
     * the instance-months used, at least one: memory is billed on what is
     * provisioned, so memory-seconds / (instance memory × a 720 h month)
     * counts instances. Two always-on instances get twice the cap. Reads
     * the stored size, not EdgeContainerSettings::for(): that resolves plan
     * and trial limits this path does not need.
     */
    public function capMillicents(Site $site, float $memoryGibSeconds): ?float
    {
        $raw = is_array($site->edgeMeta()['container'] ?? null) ? $site->edgeMeta()['container'] : [];
        $type = (string) ($raw['instance_type'] ?? config('edge.build.containers.instance_type', 'basic'));
        $custom = [(int) ($raw['custom_vcpu'] ?? 1), (int) ($raw['custom_memory_gib'] ?? 3), (int) ($raw['custom_disk_gb'] ?? 6)];
        $shape = $type === 'custom' && EdgeContainerSettings::customError(...$custom) === null
            ? array_map(floatval(...), $custom)
            : (EdgeContainerSettings::INSTANCE_TYPES[$type] ?? null);
        if ($shape === null || $shape[1] <= 0) {
            return null;
        }
        $instanceMonths = $memoryGibSeconds / ($shape[1] * UsagePrice::MONTH_HOURS * 3600);

        return UsagePrice::containerCapMillicents(...$shape) * max(1.0, $instanceMonths);
    }

    /** Customer cents for the usage, rounded once. */
    public function cents(float $cpuSeconds, float $memoryGibSeconds, float $diskGbSeconds, int $txBytes): int
    {
        return UsagePrice::cents($this->costMillicents($cpuSeconds, $memoryGibSeconds, $diskGbSeconds, $txBytes));
    }

    /** $txBytes is accepted for the callers' totals but not billed (see the class doc). */
    public function costMillicents(float $cpuSeconds, float $memoryGibSeconds, float $diskGbSeconds, int $txBytes = 0): float
    {
        return $cpuSeconds * UsagePrice::cost('container_vcpu_millicents_per_second')
            + $memoryGibSeconds * UsagePrice::cost('container_memory_millicents_per_gib_second')
            + $diskGbSeconds * UsagePrice::cost('container_disk_millicents_per_gb_second');
    }

    /**
     * Customer price of one instance type running for a minute (all vCPU
     * busy), in millicents — for size pickers and estimates.
     */
    public function perMinuteMillicents(float $vcpu, float $memoryGib, float $diskGb): float
    {
        return UsagePrice::containerPerSecond($vcpu, $memoryGib, $diskGb) * 60;
    }
}
