<?php

namespace App\Modules\Billing\Services;

use App\Models\LookoutProject;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use Illuminate\Support\Collection;

/**
 * Builds a {@see DesiredBillingState} for an organization by scanning its
 * currently *billable* units. dply-edge bills exactly one kind — **Edge
 * sites**: edge_active sites with `edge_backend = dply_edge`, excluding branch
 * previews, plus their metered usage (requests / egress / R2 storage).
 *
 * Age filter: units younger than min_billable_age_days are excluded.
 */
class OrganizationBillingStateComputer
{
    public function __construct(
        private EdgeOrganizationUsageReader $usageReader,
        private EdgeUsageCostCalculator $usageCostCalculator,
        private SubscriptionPlanResolver $planResolver,
    ) {}

    /**
     * READY servers past the min-billable age, with the latest metric snapshot
     * eager-loaded. Request-scoped (static) memo: the server scan here,
     * {@see BillingAnalytics::billableServers()}, and
     * {@see Organization::currentSubscriptionPlan()} all need this set —
     * sharing it collapses duplicate ready-server SELECTs into one.
     *
     * @var array<string, Collection<int, Server>>
     */
    private static array $readyBillableServersMemo = [];

    /**
     * Full {@see DesiredBillingState} per org for the request. Livewire billing
     * blades, trial banners ({@see Organization::owesNothingThisCycle}), and
     * analytics all call {@see compute()} — without this each access re-runs
     * site scans + usage SUMs (Debugbar duplicate-query noise).
     *
     * @var array<string, DesiredBillingState>
     */
    private static array $desiredStateMemo = [];

    /**
     * @return Collection<int, Server>
     */
    public function readyBillableServers(Organization $organization): Collection
    {
        $key = (string) $organization->id;
        if (isset(self::$readyBillableServersMemo[$key])) {
            return self::$readyBillableServersMemo[$key];
        }

        $ageCutoff = now()->subDays(max(0, (int) config('subscription.standard.min_billable_age_days', 1)));

        return self::$readyBillableServersMemo[$key] = $organization->servers()
            ->where('status', Server::STATUS_READY)
            ->where('created_at', '<=', $ageCutoff)
            ->get();
    }

    /**
     * BYO server count used to pick the flat plan — same filter as
     * {@see compute()}'s `$serverCount` (excludes managed-product hosts and
     * dply-hosted VMs billed cost-plus).
     */
    public function billableByoServerCount(Organization $organization): int
    {
        return $this->readyBillableServers($organization)
            ->reject(fn (Server $server) => $server->isManagedProductHost() || $server->usesManagedHosting())
            ->count();
    }

    /**
     * Drop request-scoped memos (ready servers + desired state + schema/usage
     * helpers). Call from TestCase tearDown and after fleet mutations that must
     * be visible to a later compute() in the same process.
     */
    public static function flushReadyBillableServersMemo(?string $organizationId = null): void
    {
        self::flushMemo($organizationId);
    }

    public static function flushMemo(?string $organizationId = null): void
    {
        if ($organizationId === null) {
            self::$readyBillableServersMemo = [];
            self::$desiredStateMemo = [];

            return;
        }

        unset(
            self::$readyBillableServersMemo[$organizationId],
            self::$desiredStateMemo[$organizationId],
        );
    }

    public function compute(Organization $organization): DesiredBillingState
    {
        $key = (string) $organization->id;
        if (isset(self::$desiredStateMemo[$key])) {
            return self::$desiredStateMemo[$key];
        }

        return self::$desiredStateMemo[$key] = $this->computeFresh($organization);
    }

    /**
     * Does this org's fleet bill to nothing this cycle? Same answer as
     * {@see compute()}->isFree(), reached without the full scan when it can be.
     *
     * The trial/pause banner asks this on every authenticated page render (see
     * {@see Organization::owesNothingThisCycle}), and computeFresh() is
     * expensive: site scan with a function_actions subquery, realtime + lookout
     * reads, and three usage SUMs — nine queries for one boolean.
     *
     * The shortcut is exact, not an approximation. monthlyTotalCents is
     * planPriceCents plus a series of subtotals that {@see
     * DesiredBillingState::fromPlanAndUsage} each clamp with max(0, …), and no
     * credit is ever subtracted. So a non-zero plan price alone forces the
     * total above zero — nothing the rest of the scan could find would pull it
     * back down to free. The flat plan is chosen purely by billable BYO server
     * count, which is one already-memoized query.
     *
     * Orgs under the free server ceiling still fall through to the full
     * compute: they may owe for serverless, Cloud, Edge, or metered usage, and
     * only the scan can tell. Those are the smallest fleets, so the scan is
     * cheapest exactly where it is still needed.
     */
    public function isFree(Organization $organization): bool
    {
        // Already computed this request — no reason to re-derive the plan.
        $key = (string) $organization->id;
        if (isset(self::$desiredStateMemo[$key])) {
            return self::$desiredStateMemo[$key]->isFree();
        }

        $plan = $this->planResolver->resolveForServerCount(
            $this->billableByoServerCountWithoutMetrics($organization),
        );

        if (max(0, (int) $plan['price_cents']) > 0) {
            return false;
        }

        return $this->compute($organization)->isFree();
    }

    /**
     * Billable-unit count without the metric-snapshot join. dply-edge has no
     * BYO servers, so this is structurally zero — kept as its own method
     * because {@see isFree()} deliberately avoids seeding the shared
     * ready-servers memo with relation-less models.
     */
    private function billableByoServerCountWithoutMetrics(Organization $organization): int
    {
        $key = (string) $organization->id;
        if (isset(self::$readyBillableServersMemo[$key])) {
            return $this->billableByoServerCount($organization);
        }

        $ageCutoff = now()->subDays(max(0, (int) config('subscription.standard.min_billable_age_days', 1)));

        return $organization->servers()
            ->where('status', Server::STATUS_READY)
            ->where('created_at', '<=', $ageCutoff)
            ->get()
            ->reject(fn (Server $server) => $server->isManagedProductHost() || $server->usesManagedHosting())
            ->count();
    }

    private function computeFresh(Organization $organization): DesiredBillingState
    {
        $minAgeDays = max(0, (int) config('subscription.standard.min_billable_age_days', 1));
        $ageCutoff = now()->subDays($minAgeDays);

        $edgeCount = 0;
        $edgeSsrCount = 0;

        $organization->sites()
            ->where('created_at', '<=', $ageCutoff)
            ->get()
            ->each(function (Site $site) use (&$edgeCount, &$edgeSsrCount): void {
                if (
                    $site->status !== Site::STATUS_EDGE_ACTIVE
                    || $site->edge_backend !== 'dply_edge'
                    || $site->isEdgePreview()
                ) {
                    return;
                }

                $edgeCount++;
                $runtimeMode = strtolower((string) ($site->edgeMeta()['runtime_mode'] ?? 'static'));
                if ($runtimeMode === 'ssr') {
                    $edgeSsrCount++;
                }
            });

        // Managed Lookout error-tracking projects — billed per tier, the first
        // project per org free (a loss-leader). Dark until LOOKOUT_BILLING_ENABLED
        // so no line is added today. Projects are ordered oldest-first so the free
        // allowance lands on the longest-standing project (stable across cycles).
        $lookoutTierQuantities = [];
        if ((bool) config('lookout.billing_enabled', false)) {
            $freeRemaining = max(0, (int) config('lookout.free_projects_per_org', 1));
            $organization->lookoutProjects()
                ->where('status', LookoutProject::STATUS_ACTIVE)
                // Bundle-origin projects are the free tracely+Lookout perk — never
                // billed, and filtered in the QUERY so they can't even consume the
                // org's free-project allowance below. See docs/adr/bundled-products-sso.md.
                ->where(fn ($q) => $q->whereNull('source')->orWhere('source', '!=', LookoutProject::SOURCE_BUNDLE))
                ->where('created_at', '<=', $ageCutoff)
                ->orderBy('created_at')
                ->get(['tier', 'created_at'])
                ->each(function (LookoutProject $project) use (&$lookoutTierQuantities, &$freeRemaining): void {
                    if ($freeRemaining > 0) {
                        $freeRemaining--;

                        return;
                    }
                    $slug = $project->tierSlug();
                    $lookoutTierQuantities[$slug] = ($lookoutTierQuantities[$slug] ?? 0) + 1;
                });
        }

        [$usagePeriodStart, $usagePeriodEnd] = $this->usageReader->currentMonthWindow();
        $usageTotals = $this->usageReader->totalsForOrganization($organization, $usagePeriodStart, $usagePeriodEnd);
        $edgeUsageEstimate = $this->usageCostCalculator->estimate($usageTotals, $edgeCount);
        $edgeUsageEstimate = array_merge($edgeUsageEstimate, [
            'period_start' => $usagePeriodStart->toDateString(),
            'period_end' => $usagePeriodEnd->toDateString(),
            'requests' => $usageTotals->requests,
            'bytes_egress' => $usageTotals->bytesEgress,
            'r2_storage_bytes' => $usageTotals->r2StorageBytes,
        ]);

        // dply-edge sells one product, so there is no server count to size a
        // flat plan by — the plan resolver still picks the zero-server tier and
        // every charge rides on the per-site + metered lines below.
        $plan = $this->planResolver->resolveForServerCount(0);

        return DesiredBillingState::fromPlanAndUsage(
            plan: $plan,
            edgeCount: $edgeCount,
            edgeUnitCents: (int) config('subscription.standard.edge_cents', 200),
            edgeSsrCount: $edgeSsrCount,
            edgeSsrUnitCents: (int) config('subscription.standard.edge_ssr_cents', 700),
            edgeUsageSubtotalCents: (int) $edgeUsageEstimate['subtotal_cents'],
            edgeUsageEstimate: $edgeUsageEstimate,
            lookoutTierQuantities: $lookoutTierQuantities,
        );
    }
}
