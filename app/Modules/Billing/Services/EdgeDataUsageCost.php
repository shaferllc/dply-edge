<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Models\EdgeDataUsage;
use App\Models\Organization;
use Carbon\CarbonInterface;

/**
 * Prices D1 (rows read/written, storage) and Queues (operations) usage at
 * Cloudflare list price plus the usage markup.
 */
class EdgeDataUsageCost
{
    /**
     * @return array{d1_rows_read: int, d1_rows_written: int, d1_storage_bytes: int, queue_operations: int, cents: int}
     */
    public function forOrganization(Organization $organization, CarbonInterface $from, CarbonInterface $to): array
    {
        $row = EdgeDataUsage::query()
            ->where('organization_id', $organization->id)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('COALESCE(SUM(d1_rows_read),0) r, COALESCE(SUM(d1_rows_written),0) w, COALESCE(MAX(d1_storage_bytes),0) s, COALESCE(SUM(queue_operations),0) q')
            ->first();

        $totals = [
            'd1_rows_read' => (int) ($row->r ?? 0),
            'd1_rows_written' => (int) ($row->w ?? 0),
            'd1_storage_bytes' => (int) ($row->s ?? 0),
            'queue_operations' => (int) ($row->q ?? 0),
        ];

        return $totals + ['cents' => $this->cents(...array_values($totals))];
    }

    /** Storage is billed as the month's peak size for a full month. */
    public function cents(int $rowsRead, int $rowsWritten, int $storageBytes, int $queueOperations): int
    {
        $rate = static fn (string $key): float => (float) config('dply.edge.usage_billing.'.$key, 0);

        $millicents = $rowsRead / 1_000_000 * $rate('d1_rows_read_millicents_per_million')
            + $rowsWritten / 1_000_000 * $rate('d1_rows_written_millicents_per_million')
            + $storageBytes / 1024 ** 3 * $rate('d1_storage_millicents_per_gb_month')
            + $queueOperations / 1_000_000 * $rate('queue_operations_millicents_per_million');

        return (int) ceil($millicents * (100 + max(0, (int) config('dply.edge.usage_billing.markup_percent', 0))) / 100 / 1000);
    }
}
