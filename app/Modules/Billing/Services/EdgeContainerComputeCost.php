<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Models\EdgeContainerUsage;
use App\Models\Organization;
use Carbon\CarbonInterface;

/**
 * Prices container compute per second — vCPU, memory, disk and egress at
 * Cloudflare list price plus the usage markup — for per-minute billing.
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
            ->selectRaw('COALESCE(SUM(cpu_seconds),0) cpu, COALESCE(SUM(memory_gib_seconds),0) mem, COALESCE(SUM(disk_gb_seconds),0) disk, COALESCE(SUM(tx_bytes),0) tx')
            ->first();

        $totals = [
            'cpu_seconds' => (float) ($row->cpu ?? 0),
            'memory_gib_seconds' => (float) ($row->mem ?? 0),
            'disk_gb_seconds' => (float) ($row->disk ?? 0),
            'tx_bytes' => (int) ($row->tx ?? 0),
        ];

        return $totals + ['cents' => $this->cents(...array_values($totals))];
    }

    public function cents(float $cpuSeconds, float $memoryGibSeconds, float $diskGbSeconds, int $txBytes): int
    {
        $rate = static fn (string $key): float => (float) config('dply.edge.usage_billing.'.$key, 0);

        $millicents = $cpuSeconds / 3600 * $rate('container_vcpu_millicents_per_hour')
            + $memoryGibSeconds / 3600 * $rate('container_memory_millicents_per_gib_hour')
            + $diskGbSeconds / 3600 * $rate('container_disk_millicents_per_gb_hour')
            + $txBytes / 1024 ** 3 * $rate('container_egress_millicents_per_gb');

        $markup = max(0, (int) config('dply.edge.usage_billing.markup_percent', 0));

        return (int) ceil($millicents * (100 + $markup) / 100 / 1000);
    }

    /**
     * Price of one instance type running for a minute (all vCPU busy), for
     * the pricing page.
     */
    public function perMinuteMillicents(float $vcpu, float $memoryGib, float $diskGb): float
    {
        $rate = static fn (string $key): float => (float) config('dply.edge.usage_billing.'.$key, 0);
        $perHour = $vcpu * $rate('container_vcpu_millicents_per_hour')
            + $memoryGib * $rate('container_memory_millicents_per_gib_hour')
            + $diskGb * $rate('container_disk_millicents_per_gb_hour');

        return $perHour / 60 * (100 + max(0, (int) config('dply.edge.usage_billing.markup_percent', 0))) / 100;
    }
}
