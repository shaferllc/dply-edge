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
 * Outbound traffic (ruling r-48pkfdnq9f75j8mw): container egress
 * (tx_bytes, what Cloudflare bills) minus the reply bytes the app's Worker
 * counted (reply_bytes), per app per day, never below zero. That is the
 * app's own calls out (APIs, S3); replies to visitors already bill as
 * delivery bandwidth. Priced at Cloudflare's cost + margin
 * (container_outbound_millicents_per_gb), outside the compute cap. A row
 * with reply_bytes null (Worker predates the counter) bills no outbound.
 */
class EdgeContainerComputeCost
{
    /**
     * The period's compute, each app capped (capMillicents) and the total
     * rounded once.
     *
     * @return array{cpu_seconds: float, memory_gib_seconds: float, disk_gb_seconds: float, tx_bytes: int, outbound_bytes: int, cents: int}
     */
    public function forOrganization(Organization $organization, CarbonInterface $from, CarbonInterface $to): array
    {
        $rows = EdgeContainerUsage::query()
            ->where('organization_id', $organization->id)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('site_id')
            ->selectRaw('site_id, COALESCE(SUM(cpu_seconds), 0) AS cpu, COALESCE(SUM(memory_gib_seconds), 0) AS memory, COALESCE(SUM(disk_gb_seconds), 0) AS disk, COALESCE(SUM(tx_bytes), 0) AS tx, '.self::OUTBOUND_SQL.' AS outbound')
            ->toBase()
            ->get();
        $sites = Site::query()->whereIn('id', $rows->pluck('site_id')->filter()->all())->get()->keyBy('id');

        $totals = ['cpu_seconds' => 0.0, 'memory_gib_seconds' => 0.0, 'disk_gb_seconds' => 0.0, 'tx_bytes' => 0, 'outbound_bytes' => 0];
        $millicents = 0.0;
        foreach ($rows as $row) {
            $totals['cpu_seconds'] += (float) $row->cpu;
            $totals['memory_gib_seconds'] += (float) $row->memory;
            $totals['disk_gb_seconds'] += (float) $row->disk;
            $totals['tx_bytes'] += (int) $row->tx;
            $outbound = (int) $row->outbound;
            $totals['outbound_bytes'] += $outbound;
            $millicents += $this->siteMillicents($sites->get($row->site_id), (float) $row->cpu, (float) $row->memory, (float) $row->disk)
                + $this->outboundMillicents($outbound);
        }

        return $totals + ['cents' => (int) round(round($millicents, 6) / 1000)];
    }

    /** Outbound bytes summed over rows: each app-day's tx minus replies, floored at 0; unmeasured rows add nothing. */
    public const OUTBOUND_SQL = 'COALESCE(SUM(CASE WHEN reply_bytes IS NULL THEN 0 WHEN tx_bytes > reply_bytes THEN tx_bytes - reply_bytes ELSE 0 END), 0)';

    /** Customer millicents for outbound bytes: Cloudflare's container egress cost + margin. */
    public function outboundMillicents(int $bytes): float
    {
        return UsagePrice::customer($bytes / 1024 ** 3 * UsagePrice::cost('container_outbound_millicents_per_gb'));
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

    /** $txBytes is accepted for the callers' totals; outbound egress is priced by outboundMillicents(). */
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
