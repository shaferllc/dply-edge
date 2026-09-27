<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Models\EdgeContainerUsage;
use App\Models\Organization;
use App\Modules\Billing\Support\UsagePrice;
use Carbon\CarbonInterface;

/**
 * Prices container compute (container apps and queue workers) per second
 * awake: vCPU-, GiB-memory- and GB-disk-seconds plus egress, at cost from
 * dply.edge.usage_billing.container_*, priced by UsagePrice (one margin).
 */
class EdgeContainerComputeCost
{
    /**
     * @return array{cpu_seconds: float, memory_gib_seconds: float, disk_gb_seconds: float, tx_bytes: int, cents: int}
     */
    public function forOrganization(Organization $organization, CarbonInterface $from, CarbonInterface $to): array
    {
        $row = EdgeContainerUsage::query()
            ->where('organization_id', $organization->id)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('COALESCE(SUM(cpu_seconds), 0) AS cpu, COALESCE(SUM(memory_gib_seconds), 0) AS memory, COALESCE(SUM(disk_gb_seconds), 0) AS disk, COALESCE(SUM(tx_bytes), 0) AS tx')
            ->toBase()
            ->first();
        $totals = [
            'cpu_seconds' => (float) ($row->cpu ?? 0),
            'memory_gib_seconds' => (float) ($row->memory ?? 0),
            'disk_gb_seconds' => (float) ($row->disk ?? 0),
            'tx_bytes' => (int) ($row->tx ?? 0),
        ];

        return $totals + ['cents' => $this->cents(...array_values($totals))];
    }

    /** Customer cents for the usage, rounded once. */
    public function cents(float $cpuSeconds, float $memoryGibSeconds, float $diskGbSeconds, int $txBytes): int
    {
        return UsagePrice::cents($this->costMillicents($cpuSeconds, $memoryGibSeconds, $diskGbSeconds, $txBytes));
    }

    public function costMillicents(float $cpuSeconds, float $memoryGibSeconds, float $diskGbSeconds, int $txBytes): float
    {
        return $cpuSeconds * UsagePrice::cost('container_vcpu_millicents_per_second')
            + $memoryGibSeconds * UsagePrice::cost('container_memory_millicents_per_gib_second')
            + $diskGbSeconds * UsagePrice::cost('container_disk_millicents_per_gb_second')
            + $txBytes / 1024 ** 3 * UsagePrice::cost('container_egress_millicents_per_gb');
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
