<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Models\EdgeContainerUsage;
use App\Models\EdgeDeployment;
use App\Models\EdgeRealtimeUsage;
use App\Models\EdgeRedisUsage;
use App\Models\EdgeUsageSnapshot;
use App\Models\Organization;
use App\Models\Site;
use App\Modules\Billing\Support\UsagePrice;
use App\Modules\Edge\Support\EdgeBuildMinutes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Per Edge site billing at customer price: delivery (MTD + daily) plus the
 * site's own compute, Valkey, build and realtime usage (lines()). Databases
 * are project-scoped, so they only show on the org billing page.
 * There are no site fees (ruling r-2zxevg4sj675qn1m): platform_cents is
 * always 0 and kept only so existing views and API payloads keep their shape.
 */
final class EdgeSiteBillingAnalytics
{
    public function __construct(
        private readonly EdgeOrganizationUsageReader $usageReader,
        private readonly EdgeUsageCostCalculator $usageCostCalculator,
        private readonly EdgeContainerComputeCost $computeCost,
        private readonly EdgeRedisCost $redisCost,
        private readonly EdgeRealtimeCost $realtimeCost,
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

        [$periodStart, $periodEnd] = $this->usageReader->currentWindow($organization);
        $siteIds = $sites->pluck('id')->all();

        $mtdBySite = $this->aggregateSnapshots($organization->id, $siteIds, $periodStart, $periodEnd);
        $dailyBySite = $this->dailySnapshotsBySite($organization->id, $siteIds, $dailyDays);
        $computeBySite = $this->dailyComputeBySite($organization->id, $siteIds, $dailyDays);

        $result = [];

        foreach ($sites as $site) {
            $siteId = (string) $site->id;
            $mtd = $mtdBySite[$siteId] ?? EdgeUsageTotals::empty();
            $usageEstimate = $this->usageCostCalculator->estimate($mtd);
            $fee = $this->platformFee($site);

            $result[] = $this->formatSiteRow(
                site: $site,
                platformCents: $fee['cents'],
                platformKind: $fee['kind'],
                mtd: $mtd,
                usageEstimate: $usageEstimate,
                daily: $dailyBySite[$siteId] ?? [],
                lines: $this->lines($site, $periodStart, $periodEnd),
                dailyCompute: $computeBySite[$siteId] ?? [],
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

        [$periodStart, $periodEnd] = $site->organization instanceof Organization
            ? $this->usageReader->currentWindow($site->organization)
            : $this->usageReader->currentMonthWindow();
        $siteId = (string) $site->id;
        $mtdBySite = $this->aggregateSnapshots((string) $site->organization_id, [$site->id], $periodStart, $periodEnd);
        $dailyBySite = $this->dailySnapshotsBySite((string) $site->organization_id, [$site->id], $dailyDays);
        $computeBySite = $this->dailyComputeBySite((string) $site->organization_id, [$site->id], $dailyDays);
        $mtd = $mtdBySite[$siteId] ?? EdgeUsageTotals::empty();
        $usageEstimate = $this->usageCostCalculator->estimate($mtd);

        $fee = $this->platformFee($site);

        $payload = $this->formatSiteRow(
            site: $site,
            platformCents: $fee['cents'],
            platformKind: $fee['kind'],
            mtd: $mtd,
            usageEstimate: $usageEstimate,
            daily: $dailyBySite[$siteId] ?? [],
            lines: $this->lines($site, $periodStart, $periodEnd),
            dailyCompute: $computeBySite[$siteId] ?? [],
        );

        if (app()->bound('request')) {
            request()->attributes->set($memoKey, $payload);
        }

        return $payload;
    }

    /**
     * Site fee for one live site: always $0 — sites are unlimited.
     *
     * @return array{cents: int, kind: string}
     */
    public function platformFee(Site $site): array
    {
        return ['cents' => 0, 'kind' => 'included'];
    }

    /**
     * The site's usage beyond delivery for the window, priced the way the
     * invoice prices it. Lines with no usage are left out.
     * ponytail: 4 queries per site; batch by site_id if the org page gets slow.
     *
     * @return list<array{key: string, label: string, detail: string, cents: int}>
     */
    public function lines(Site $site, Carbon $from, Carbon $to): array
    {
        $dates = [$from->toDateString(), $to->toDateString()];
        $lines = [];

        $c = EdgeContainerUsage::query()->where('site_id', $site->id)->whereBetween('date', $dates)
            ->selectRaw('COALESCE(SUM(cpu_seconds), 0) AS cpu, COALESCE(SUM(memory_gib_seconds), 0) AS memory, COALESCE(SUM(disk_gb_seconds), 0) AS disk')
            ->toBase()->first();
        if ((float) ($c->memory ?? 0) > 0 || (float) ($c->cpu ?? 0) > 0) {
            $lines[] = [
                'key' => 'compute',
                'label' => __('App and workers (compute)'),
                'detail' => __(':cpu vCPU-h · :mem GiB-h memory · :disk GB-h disk', [
                    'cpu' => number_format((float) $c->cpu / 3600, 1),
                    'mem' => number_format((float) $c->memory / 3600, 1),
                    'disk' => number_format((float) $c->disk / 3600, 1),
                ]),
                'cents' => $this->computeCost->siteCents($site, (float) $c->cpu, (float) $c->memory, (float) $c->disk),
            ];
        }

        $valkeySeconds = (int) EdgeRedisUsage::query()->where('site_id', $site->id)->whereBetween('date', $dates)->sum('awake_seconds');
        if ($valkeySeconds > 0 && $site->organization instanceof Organization) {
            $lines[] = [
                'key' => 'valkey',
                'label' => __('Valkey'),
                'detail' => __(':h h awake', ['h' => number_format($valkeySeconds / 3600, 1)]),
                'cents' => $this->redisCost->valkeyCents($site->organization, [(string) $site->id => $valkeySeconds]),
            ];
        }

        $buildSeconds = (int) EdgeDeployment::query()->where('site_id', $site->id)
            ->where('created_at', '>=', $from->copy()->startOfDay())
            ->where('created_at', '<=', $to->copy()->endOfDay())
            ->sum('build_seconds');
        if ($buildSeconds > 0) {
            $lines[] = [
                'key' => 'builds',
                'label' => __('Build time'),
                'detail' => __(':m min', ['m' => number_format($buildSeconds / 60, 1)]),
                'cents' => UsagePrice::cents(EdgeBuildMinutes::costMillicents($buildSeconds)),
            ];
        }

        $rt = EdgeRealtimeUsage::query()->where('site_id', $site->id)->whereBetween('date', $dates)
            ->selectRaw('COALESCE(SUM(connection_seconds), 0) AS seconds, COALESCE(SUM(messages), 0) AS messages')
            ->toBase()->first();
        if ((int) ($rt->seconds ?? 0) > 0 || (int) ($rt->messages ?? 0) > 0) {
            $lines[] = [
                'key' => 'realtime',
                'label' => __('Realtime'),
                'detail' => __(':m connection-min · :n messages', [
                    'm' => number_format((int) $rt->seconds / 60),
                    'n' => number_format((int) $rt->messages),
                ]),
                'cents' => $this->realtimeCost->cents((int) $rt->seconds, (int) $rt->messages),
            ];
        }

        return $lines;
    }

    /**
     * @return Collection<int, Site>
     */
    private function billableEdgeSites(Organization $organization): Collection
    {
        return $organization->sites()
            ->with('server:id,name')
            ->where('status', Site::STATUS_EDGE_ACTIVE)
            ->where('edge_backend', 'dply_edge')
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
            $estimate = $this->usageCostCalculator->estimate($totals);

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
     * Container compute per day (app + workers), priced uncapped: the cap is
     * monthly per instance, so a single day never reaches it.
     *
     * @param  list<string>  $siteIds
     * @return array<string, list<array{date: string, label: string, cpu_hours: float, memory_gib_hours: float, cents: float}>>
     */
    private function dailyComputeBySite(string $organizationId, array $siteIds, int $days): array
    {
        if ($siteIds === []) {
            return [];
        }

        $rows = EdgeContainerUsage::query()
            ->where('organization_id', $organizationId)
            ->whereIn('site_id', $siteIds)
            ->where('date', '>=', now()->subDays(max(1, $days - 1))->toDateString())
            ->groupBy('site_id', 'date')
            ->orderBy('date')
            ->selectRaw('site_id, date, COALESCE(SUM(cpu_seconds), 0) AS cpu, COALESCE(SUM(memory_gib_seconds), 0) AS memory, COALESCE(SUM(disk_gb_seconds), 0) AS disk')
            ->toBase()
            ->get();

        $grouped = [];
        foreach ($rows as $row) {
            $date = Carbon::parse($row->date)->toDateString();
            $grouped[(string) $row->site_id][] = [
                'date' => $date,
                'label' => Carbon::parse($date)->format('M j'),
                'cpu_hours' => (float) $row->cpu / 3600,
                'memory_gib_hours' => (float) $row->memory / 3600,
                'cents' => $this->computeCost->siteMillicents(null, (float) $row->cpu, (float) $row->memory, (float) $row->disk) / 1000,
            ];
        }

        return $grouped;
    }

    /**
     * @param  list<array<string, mixed>>  $daily
     * @param  array<string, mixed>  $usageEstimate
     * @param  list<array{key: string, label: string, detail: string, cents: int}>  $lines
     * @param  list<array<string, mixed>>  $dailyCompute
     * @return array<string, mixed>
     */
    private function formatSiteRow(
        Site $site,
        int $platformCents,
        string $platformKind,
        EdgeUsageTotals $mtd,
        array $usageEstimate,
        array $daily,
        array $lines = [],
        array $dailyCompute = [],
    ): array {
        $deliveryCents = (int) ($usageEstimate['subtotal_cents'] ?? 0);
        $usageCents = $deliveryCents + array_sum(array_column($lines, 'cents'));
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
            'platform_kind' => $platformKind,
            'platform_label' => match ($platformKind) {
                'extra' => __('Extra site'),
                'ssr' => __('SSR site'),
                default => __('Site fee'),
            },
            'delivery_cents' => $deliveryCents,
            'usage_cents' => $usageCents,
            'lines' => $lines,
            'total_cents' => $platformCents + $usageCents,
            'requests' => $mtd->requests,
            'bytes_egress' => $mtd->bytesEgress,
            'r2_storage_bytes' => $mtd->r2StorageBytes,
            'r2_class_a_ops' => $mtd->r2ClassAOps,
            'r2_class_b_ops' => $mtd->r2ClassBOps,
            'usage_detail' => $usageEstimate,
            'daily' => $daily,
            'daily_compute' => $dailyCompute,
            'has_snapshots' => $mtd->requests > 0 || $mtd->bytesEgress > 0 || $daily !== [],
            'usage_billing_enabled' => $this->usageCostCalculator->isEnabled(),
        ];
    }
}
