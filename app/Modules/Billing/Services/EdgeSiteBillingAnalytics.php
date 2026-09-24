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
 * Per Edge site billing: site fee, delivery usage (MTD + daily), and totals.
 *
 * The site fee matches the org bill. Static and hybrid sites inside the
 * plan's included count are $0. Sites past that count are the extra-site
 * rate. Every Worker-native SSR site is the SSR rate on Pro and Team.
 * Free and Enterprise owe nothing through this path.
 */
final class EdgeSiteBillingAnalytics
{
    public function __construct(
        private readonly EdgeOrganizationUsageReader $usageReader,
        private readonly EdgeUsageCostCalculator $usageCostCalculator,
        private readonly OrganizationBillingStateComputer $billingState,
    ) {}

    /**
     * @var array<string, array<string, array{cents: int, kind: string}>>
     */
    private array $platformFeeMaps = [];

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
            $fee = $this->platformFee($site);

            $result[] = $this->formatSiteRow(
                site: $site,
                platformCents: $fee['cents'],
                platformKind: $fee['kind'],
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

        $fee = $this->platformFee($site);

        $payload = $this->formatSiteRow(
            site: $site,
            platformCents: $fee['cents'],
            platformKind: $fee['kind'],
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
     * Site fee for one live site. Oldest static/hybrid sites fill the plan's
     * included slots; later ones are extras. SSR never uses an included slot.
     *
     * @return array{cents: int, kind: string}
     */
    public function platformFee(Site $site): array
    {
        $organization = $site->relationLoaded('organization')
            ? $site->organization
            : $site->organization()->first();

        if (! $organization instanceof Organization) {
            return ['cents' => 0, 'kind' => 'included'];
        }

        $map = $this->platformFees($organization);

        return $map[(string) $site->id] ?? ['cents' => 0, 'kind' => 'included'];
    }

    /**
     * @return array<string, array{cents: int, kind: string}>
     */
    private function platformFees(Organization $organization): array
    {
        $orgId = (string) $organization->id;
        if (isset($this->platformFeeMaps[$orgId])) {
            return $this->platformFeeMaps[$orgId];
        }

        $memoKey = 'edge.billing.platform_fees.'.$orgId;
        if (app()->bound('request') && request()->attributes->has($memoKey)) {
            /** @var array<string, array{cents: int, kind: string}> $cached */
            $cached = request()->attributes->get($memoKey);
            $this->platformFeeMaps[$orgId] = $cached;

            return $cached;
        }

        $map = $this->buildPlatformFeeMap($organization);
        $this->platformFeeMaps[$orgId] = $map;
        if (app()->bound('request')) {
            request()->attributes->set($memoKey, $map);
        }

        return $map;
    }

    /**
     * @return array<string, array{cents: int, kind: string}>
     */
    private function buildPlatformFeeMap(Organization $organization): array
    {
        $state = $this->billingState->compute($organization);
        $billable = in_array($state->planKey, ['pro', 'team'], true);
        $includedRaw = config('subscription.standard.tiers.'.$state->planKey.'.sites');
        $included = $includedRaw === null ? PHP_INT_MAX : (int) $includedRaw;
        $extraUnit = $billable ? (int) config('subscription.standard.edge_cents', 200) : 0;
        $ssrUnit = $billable ? (int) config('subscription.standard.edge_ssr_cents', 700) : 0;

        $sites = $this->billableEdgeSites($organization);
        $base = $sites
            ->filter(fn (Site $site): bool => $this->runtimeMode($site) !== 'ssr')
            ->sortBy(fn (Site $site): string => ($site->created_at?->format('Y-m-d H:i:s.u') ?? '').'|'.$site->id)
            ->values();

        $map = [];
        foreach ($sites as $site) {
            if ($this->runtimeMode($site) !== 'ssr') {
                continue;
            }

            $map[(string) $site->id] = [
                'cents' => $ssrUnit,
                'kind' => $ssrUnit > 0 ? 'ssr' : 'included',
            ];
        }

        foreach ($base as $index => $site) {
            $extra = $extraUnit > 0 && $index >= $included;
            $map[(string) $site->id] = [
                'cents' => $extra ? $extraUnit : 0,
                'kind' => $extra ? 'extra' : 'included',
            ];
        }

        return $map;
    }

    private function runtimeMode(Site $site): string
    {
        return strtolower((string) ($site->edgeMeta()['runtime_mode'] ?? 'static'));
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
        string $platformKind,
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
            'platform_kind' => $platformKind,
            'platform_label' => match ($platformKind) {
                'extra' => __('Extra site'),
                'ssr' => __('SSR site'),
                default => __('Site fee'),
            },
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
