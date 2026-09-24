<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Models\EdgeContainerUsage;
use App\Models\Organization;
use App\Models\Site;
use App\Modules\Edge\Support\EdgeContainerSettings;
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
        $rows = EdgeContainerUsage::query()
            ->where('organization_id', $organization->id)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->get();

        $sites = Site::query()->whereIn('id', $rows->pluck('site_id')->unique()->filter())->get()->keyBy('id');
        $totals = ['cpu_seconds' => 0.0, 'memory_gib_seconds' => 0.0, 'disk_gb_seconds' => 0.0, 'tx_bytes' => 0];
        $cents = 0;
        foreach ($rows as $row) {
            $totals['cpu_seconds'] += (float) $row->cpu_seconds;
            $totals['memory_gib_seconds'] += (float) $row->memory_gib_seconds;
            $totals['disk_gb_seconds'] += (float) $row->disk_gb_seconds;
            $totals['tx_bytes'] += (int) $row->tx_bytes;
            $site = $sites->get($row->site_id);
            $memory = $site instanceof Site ? EdgeContainerSettings::shape($site)['memory_gib'] : 1.0;
            $cents += $this->cents((float) $row->cpu_seconds, (float) $row->memory_gib_seconds, (float) $row->disk_gb_seconds, (int) $row->tx_bytes, $this->sizeMarkup($memory));
        }

        return $totals + ['cents' => $cents];
    }

    /**
     * Share of the usage markup kept on container compute. Small sizes keep
     * the full markup. Larger memory keeps less, so a bigger size stays
     * closer to list price.
     */
    public function sizeMarkup(float $memoryGib): int
    {
        $base = max(0, (int) config('dply.edge.usage_billing.markup_percent', 0));
        $factor = match (true) {
            $memoryGib <= 1 => 1.0,
            $memoryGib <= 4 => 0.64,
            $memoryGib <= 6 => 0.48,
            $memoryGib <= 8 => 0.40,
            default => 0.32,
        };

        return (int) round($base * $factor);
    }

    public function cents(float $cpuSeconds, float $memoryGibSeconds, float $diskGbSeconds, int $txBytes, ?int $markup = null): int
    {
        $rate = static fn (string $key): float => (float) config('dply.edge.usage_billing.'.$key, 0);

        $millicents = $cpuSeconds / 3600 * $rate('container_vcpu_millicents_per_hour')
            + $memoryGibSeconds / 3600 * $rate('container_memory_millicents_per_gib_hour')
            + $diskGbSeconds / 3600 * $rate('container_disk_millicents_per_gb_hour')
            + $txBytes / 1024 ** 3 * $rate('container_egress_millicents_per_gb');

        $markup ??= max(0, (int) config('dply.edge.usage_billing.markup_percent', 0));

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

        return $perHour / 60 * (100 + $this->sizeMarkup($memoryGib)) / 100;
    }
}
