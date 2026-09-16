<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Models\EdgeUsageSnapshot;
use App\Models\Organization;
use App\Models\Site;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Per Edge site billing: platform fee, delivery usage (MTD + daily), and totals.
 */
final class EdgeSiteBillingAnalytics
{
    public function __construct(
        private readonly EdgeOrganizationUsageReader $usageReader,
        private readonly EdgeUsageCostCalculator $usageCostCalculator,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function sitesForOrganization(Organization $organization, int $dailyDays = 30): array
    {
        $sites = $this->billableEdgeSites($organization);
        if ($sites->isEmpty()) {
            return [];
        }

        [$periodStart, $periodEnd] = $this->usageReader->currentMonthWindow();
        $siteIds = $sites->pluck('id')->all();

        $mtdBySite = $this->aggregateSnapshots($organization->id, $siteIds, $periodStart, $periodEnd);
        $dailyBySite = $this->dailySnapshotsBySite($organization->id, $siteIds, $dailyDays);

        $result = [];

        foreach ($sites as $site) {
            $siteId = (string) $site->id;
            $mtd = $mtdBySite[$siteId] ?? EdgeUsageTotals::empty();
            $usageEstimate = $this->usageCostCalculator->estimate($mtd, 1);
            $runtimeMode = strtolower((string) ($site->edgeMeta()['runtime_mode'] ?? 'static'));
            $platformCents = $runtimeMode === 'ssr'
                ? (int) config('subscription.standard.edge_ssr_cents', 700)
                : (int) config('subscription.standard.edge_cents', 200);

            $result[] = $this->formatSiteRow(
                site: $site,
                platformCents: $platformCents,
                mtd: $mtd,
                usageEstimate: $usageEstimate,
                daily: $dailyBySite[$siteId] ?? [],
            );
        }

        usort($result, fn (array $a, array $b): int => ($b['total_cents'] ?? 0) <=> ($a['total_cents'] ?? 0));

        return $result;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function forSite(Site $site, int $dailyDays = 30): ?array
    {
        $memoKey = 'edge.billing.for_site.'.$site->id.'.'.$dailyDays;
        if (app()->bound('request') && request()->attributes->has($memoKey)) {
            /** @var array<string, mixed>|null */
            return request()->attributes->get($memoKey);
        }

        if (
            $site->status !== Site::STATUS_EDGE_ACTIVE
            || $site->edge_backend !== 'dply_edge'
            || $site->isEdgePreview()
        ) {
            if (app()->bound('request')) {
                request()->attributes->set($memoKey, null);
            }

            return null;
        }

        [$periodStart, $periodEnd] = $this->usageReader->currentMonthWindow();
        $siteId = (string) $site->id;
        $mtdBySite = $this->aggregateSnapshots((string) $site->organization_id, [$site->id], $periodStart, $periodEnd);
        $dailyBySite = $this->dailySnapshotsBySite((string) $site->organization_id, [$site->id], $dailyDays);
        $mtd = $mtdBySite[$siteId] ?? EdgeUsageTotals::empty();
        $usageEstimate = $this->usageCostCalculator->estimate($mtd, 1);

        $runtimeMode = strtolower((string) ($site->edgeMeta()['runtime_mode'] ?? 'static'));
        $platformCents = $runtimeMode === 'ssr'
            ? (int) config('subscription.standard.edge_ssr_cents', 700)
            : (int) config('subscription.standard.edge_cents', 200);

        $payload = $this->formatSiteRow(
            site: $site,
            platformCents: $platformCents,
            mtd: $mtd,
            usageEstimate: $usageEstimate,
            daily: $dailyBySite[$siteId] ?? [],
        );

        if (app()->bound('request')) {
            request()->attributes->set($memoKey, $payload);
        }

        return $payload;
    }

    /**
     * @return Collection<int, Site>
     */
    private function billableEdgeSites(Organization $organization): Collection
    {
        $minAgeDays = max(0, (int) config('subscription.standard.min_billable_age_days', 1));
        $ageCutoff = now()->subDays($minAgeDays);

        return $organization->sites()
            ->with('server:id,name')
            ->where('status', Site::STATUS_EDGE_ACTIVE)
            ->where('edge_backend', 'dply_edge')
            ->where('created_at', '<=', $ageCutoff)
            ->orderBy('name')
            ->get()
            ->filter(fn (Site $site): bool => ! $site->isEdgePreview())
            ->values();
    }

    /**
     * @param  list<string>  $siteIds
     * @return array<string, EdgeUsageTotals>
     */
    private function aggregateSnapshots(
        string $organizationId,
        array $siteIds,
        Carbon $periodStart,
        Carbon $periodEnd,
    ): array {
        if ($siteIds === []) {
            return [];
        }

        $rows = EdgeUsageSnapshot::query()
            ->where('organization_id', $organizationId)
            ->whereIn('site_id', $siteIds)
            ->where('period_start', '>=', $periodStart->toDateString())
            ->where('period_start', '<=', $periodEnd->toDateString())
            ->groupBy('site_id')
            ->get([
                'site_id',
                DB::raw('COALESCE(SUM(requests), 0) as requests'),
                DB::raw('COALESCE(SUM(bytes_egress), 0) as bytes_egress'),
                DB::raw('COALESCE(MAX(r2_storage_bytes), 0) as r2_storage_bytes'),
                DB::raw('COALESCE(SUM(r2_class_a_ops), 0) as r2_class_a_ops'),
                DB::raw('COALESCE(SUM(r2_class_b_ops), 0) as r2_class_b_ops'),
            ]);

        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row->site_id] = new EdgeUsageTotals(
                requests: (int) $row->requests,
                bytesEgress: (int) $row->bytes_egress,
                r2StorageBytes: (int) $row->r2_storage_bytes,
                r2ClassAOps: (int) $row->r2_class_a_ops,
                r2ClassBOps: (int) $row->r2_class_b_ops,
            );
        }

        return $result;
    }

    /**
     * @param  list<string>  $siteIds
     * @return array<string, list<array<string, mixed>>>
     */
    private function dailySnapshotsBySite(string $organizationId, array $siteIds, int $days): array
    {
        if ($siteIds === []) {
            return [];
        }

        $start = now()->subDays(max(1, $days - 1))->startOfDay();

        $rows = EdgeUsageSnapshot::query()
            ->where('organization_id', $organizationId)
            ->whereIn('site_id', $siteIds)
            ->where('period_start', '>=', $start->toDateString())
            ->orderBy('period_start')
            ->get(['site_id', 'period_start', 'requests', 'bytes_egress', 'r2_storage_bytes', 'r2_class_a_ops', 'r2_class_b_ops']);

        $grouped = [];
        foreach ($rows as $row) {
            $siteId = (string) $row->site_id;
            $totals = new EdgeUsageTotals(
                requests: (int) $row->requests,
                bytesEgress: (int) $row->bytes_egress,
                r2StorageBytes: (int) $row->r2_storage_bytes,
                r2ClassAOps: (int) $row->r2_class_a_ops,
                r2ClassBOps: (int) $row->r2_class_b_ops,
            );
            $date = (string) $row->period_start;
            $estimate = $this->usageCostCalculator->estimate($totals, 1);

            $grouped[$siteId][] = [
                'date' => $date,
                'label' => Carbon::parse($date)->format('M j'),
                'requests' => $totals->requests,
                'bytes_egress' => $totals->bytesEgress,
                'cost_cents' => $estimate['subtotal_cents'],
            ];
        }

        return $grouped;
    }

    /**
     * @param  list<array<string, mixed>>  $daily
     * @param  array<string, mixed>  $usageEstimate
     * @return array<string, mixed>
     */
    private function formatSiteRow(
        Site $site,
        int $platformCents,
        EdgeUsageTotals $mtd,
        array $usageEstimate,
        array $daily,
    ): array {
        $usageCents = (int) ($usageEstimate['subtotal_cents'] ?? 0);
        $server = $site->relationLoaded('server') ? $site->server : $site->server()->first(['id', 'name']);

        return [
            'site_id' => (string) $site->id,
            'site_name' => (string) $site->name,
            'hostname' => $site->edgeHostname(),
            'live_url' => $site->edgeLiveUrl(),
            'workspace_url' => $server !== null
                ? route('sites.show', ['server' => $server, 'site' => $site])
                : null,
            'platform_cents' => $platformCents,
            'usage_cents' => $usageCents,
            'total_cents' => $platformCents + $usageCents,
            'requests' => $mtd->requests,
            'bytes_egress' => $mtd->bytesEgress,
            'r2_storage_bytes' => $mtd->r2StorageBytes,
            'r2_class_a_ops' => $mtd->r2ClassAOps,
            'r2_class_b_ops' => $mtd->r2ClassBOps,
            'usage_detail' => $usageEstimate,
            'daily' => $daily,
            'has_snapshots' => $mtd->requests > 0 || $mtd->bytesEgress > 0 || $daily !== [],
            'usage_billing_enabled' => $this->usageCostCalculator->isEnabled(),
        ];
    }
}
