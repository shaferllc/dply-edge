<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Models\BillingSubscriptionSyncEvent;
use App\Models\EdgeUsageSnapshot;
use App\Models\Organization;
use App\Models\OrganizationBillingSnapshot;
use App\Models\Site;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Billing\Models\SubscriptionItem;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Laravel\Cashier\Invoice;
use Throwable;

/**
 * Aggregates billing analytics for the org billing dashboard — current
 * estimates, category breakdown, Edge usage history, and Stripe invoices.
 */
final class BillingAnalytics
{
    public function __construct(
        private readonly OrganizationBillingStateComputer $billingStateComputer,
        private readonly EdgeSiteBillingAnalytics $edgeSiteBillingAnalytics,
        private readonly BillingForecastCalculator $forecastCalculator,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forOrganization(Organization $organization): array
    {
        $state = $this->billingStateComputer->compute($organization);
        $spendTrend = $this->spendTrend($organization);
        $snapshotThirtyDaysAgo = $this->snapshotThirtyDaysAgo($organization);

        return [
            'summary' => $this->summary($organization, $state),
            'forecast' => $this->forecast($organization, $state, $snapshotThirtyDaysAgo),
            'spend_trend' => $spendTrend,
            'category_breakdown' => $this->categoryBreakdown($state),
            'line_items' => $this->lineItems($state),
            'edge_usage_daily' => $this->edgeUsageDaily($organization, $state->edgeCount, 30),
            'edge_sites' => $this->edgeSiteBillingAnalytics->sitesForOrganization($organization),
            'sync_events' => $this->recentSyncEvents($organization),
            'invoice_history' => $this->invoiceHistory($organization),
            'managed_products' => $this->managedProducts($organization),
            'subscription' => $this->subscriptionSnapshot($organization),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Organization $organization, DesiredBillingState $state): array
    {
        $interval = $this->subscriptionInterval($organization);
        $monthlyCents = $state->monthlyTotalCents;
        $annualPct = (int) config('subscription.standard.annual_discount_pct', 20);
        $yearlyCents = (int) round($monthlyCents * 12 * (100 - $annualPct) / 100);
        /** @var Subscription|null $defaultSubscription */
        $defaultSubscription = $organization->subscription('default');

        return [
            'monthly_total_cents' => $monthlyCents,
            'yearly_total_cents' => $yearlyCents,
            'daily_run_rate_cents' => (int) round($monthlyCents / 30),
            'interval' => $interval,
            'subscribed' => $defaultSubscription?->valid() ?? false,
            'stripe_status' => $defaultSubscription?->stripe_status,
            'next_invoice_at' => $this->nextInvoiceAt($organization)?->toDateString(),
            'edge_count' => $state->edgeCount,
        ];
    }

    /**
     * @return list<array{key: string, label: string, cents: int, color: string}>
     */
    private function categoryBreakdown(DesiredBillingState $state): array
    {
        $segments = [];

        if ($state->edgeSubtotalCents > 0) {
            $segments[] = [
                'key' => 'edge',
                'label' => __('Edge').' × '.$state->edgeCount,
                'cents' => $state->edgeSubtotalCents,
                'color' => 'bg-emerald-500/70',
            ];
        }

        if ($state->edgeUsageSubtotalCents > 0) {
            $segments[] = [
                'key' => 'edge_usage',
                'label' => __('Edge delivery usage'),
                'cents' => $state->edgeUsageSubtotalCents,
                'color' => 'bg-brand-sage/50',
            ];
        }

        return $segments;
    }

    /**
     * @return list<array{label: string, quantity: int, unit_cents: int, line_cents: int, detail: ?string}>
     */
    private function lineItems(DesiredBillingState $state): array
    {
        $items = [];

        $edgeBaseCount = $state->edgeBaseCount();
        if ($edgeBaseCount > 0) {
            $unit = (int) config('subscription.standard.edge_cents', 200);
            $items[] = [
                'label' => __('dply Edge site'),
                'quantity' => $edgeBaseCount,
                'unit_cents' => $unit,
                'line_cents' => $edgeBaseCount * $unit,
                'detail' => null,
            ];
        }

        if ($state->edgeSsrCount > 0) {
            $ssrUnit = (int) config('subscription.standard.edge_ssr_cents', 700);
            $items[] = [
                'label' => __('dply Edge SSR site'),
                'quantity' => $state->edgeSsrCount,
                'unit_cents' => $ssrUnit,
                'line_cents' => $state->edgeSsrCount * $ssrUnit,
                'detail' => null,
            ];
        }

        if ($state->edgeUsageSubtotalCents > 0) {
            $items[] = [
                'label' => __('dply Edge delivery usage'),
                'quantity' => 1,
                'unit_cents' => $state->edgeUsageSubtotalCents,
                'line_cents' => $state->edgeUsageSubtotalCents,
                'detail' => $this->formatEdgeUsageDetail($state->edgeUsageEstimate),
            ];
        }

        return $items;
    }

    /**
     * @return list<array{date: string, label: string, requests: int, bytes_egress: int, cost_cents: int}>
     */
    private function edgeUsageDaily(Organization $organization, int $edgeSiteCount, int $days): array
    {
        $start = now()->subDays(max(1, $days - 1))->startOfDay();

        $rows = EdgeUsageSnapshot::query()
            ->where('organization_id', $organization->id)
            ->where('period_start', '>=', $start->toDateString())
            ->groupBy('period_start')
            ->orderBy('period_start')
            ->get([
                'period_start',
                DB::raw('COALESCE(SUM(requests), 0) as requests'),
                DB::raw('COALESCE(SUM(bytes_egress), 0) as bytes_egress'),
                DB::raw('COALESCE(MAX(r2_storage_bytes), 0) as r2_storage_bytes'),
                DB::raw('COALESCE(SUM(r2_class_a_ops), 0) as r2_class_a_ops'),
                DB::raw('COALESCE(SUM(r2_class_b_ops), 0) as r2_class_b_ops'),
            ]);

        $calculator = app(EdgeUsageCostCalculator::class);
        $edgeSiteCount = max(1, $edgeSiteCount);
        $series = [];

        foreach ($rows as $row) {
            $totals = new EdgeUsageTotals(
                requests: (int) $row->requests,
                bytesEgress: (int) $row->bytes_egress,
                r2StorageBytes: (int) $row->r2_storage_bytes,
                r2ClassAOps: (int) $row->r2_class_a_ops,
                r2ClassBOps: (int) $row->r2_class_b_ops,
            );
            $date = (string) $row->period_start;

            $series[] = [
                'date' => $date,
                'label' => Carbon::parse($date)->format('M j'),
                'requests' => $totals->requests,
                'bytes_egress' => $totals->bytesEgress,
                'cost_cents' => $calculator->estimate($totals, max(1, $edgeSiteCount))['subtotal_cents'],
            ];
        }

        return $series;
    }

    /**
     * @return array{
     *     series_30: list<array{date: string, label: string, total_cents: int, edge_usage_cents: int}>,
     *     series_90: list<array{date: string, label: string, total_cents: int, edge_usage_cents: int}>
     * }
     */
    private function spendTrend(Organization $organization): array
    {
        $start = now()->subDays(89)->toDateString();
        $rows = OrganizationBillingSnapshot::query()
            ->where('organization_id', $organization->id)
            ->where('snapshot_date', '>=', $start)
            ->orderBy('snapshot_date')
            ->get(['snapshot_date', 'monthly_total_cents', 'edge_usage_cents']);

        $points = $rows->map(function (OrganizationBillingSnapshot $snapshot): array {
            $date = $snapshot->snapshot_date->toDateString();

            return [
                'date' => $date,
                'label' => Carbon::parse($date)->format('M j'),
                'total_cents' => (int) $snapshot->monthly_total_cents,
                'edge_usage_cents' => (int) $snapshot->edge_usage_cents,
            ];
        })->values()->all();

        return [
            'series_30' => array_slice($points, -30),
            'series_90' => $points,
        ];
    }

    /**
     * @return array<string, int|null|string>
     */
    private function forecast(
        Organization $organization,
        DesiredBillingState $state,
        ?OrganizationBillingSnapshot $snapshotThirtyDaysAgo,
    ): array {
        $interval = $this->subscriptionInterval($organization);

        return $this->forecastCalculator->calculate(
            state: $state,
            subscriptionInterval: $interval,
            snapshotThirtyDaysAgo: $snapshotThirtyDaysAgo,
        );
    }

    /**
     * @return list<array{
     *     created_at: string,
     *     trigger: string,
     *     status: string,
     *     monthly_total_cents: int,
     *     change_count: int,
     *     error_message: ?string
     * }>
     */
    private function recentSyncEvents(Organization $organization): array
    {
        return BillingSubscriptionSyncEvent::query()
            ->where('organization_id', $organization->id)
            ->orderByDesc('created_at')
            ->limit(15)
            ->get(['trigger', 'status', 'changes', 'monthly_total_cents', 'error_message', 'created_at'])
            ->map(function (BillingSubscriptionSyncEvent $event): array {
                $changes = is_array($event->changes) ? $event->changes : [];

                return [
                    'created_at' => $event->created_at->toDateTimeString(),
                    'trigger' => (string) $event->trigger,
                    'status' => (string) $event->status,
                    'monthly_total_cents' => (int) $event->monthly_total_cents,
                    'change_count' => count($changes),
                    'error_message' => is_string($event->error_message) && $event->error_message !== ''
                        ? $event->error_message
                        : null,
                ];
            })
            ->values()
            ->all();
    }

    private function snapshotThirtyDaysAgo(Organization $organization): ?OrganizationBillingSnapshot
    {
        return OrganizationBillingSnapshot::query()
            ->where('organization_id', $organization->id)
            ->where('snapshot_date', '<=', now()->subDays(30)->toDateString())
            ->orderByDesc('snapshot_date')
            ->first();
    }

    /**
     * @return list<array{id: string, number: ?string, date: string, total_cents: int, status: string, paid: bool}>
     */
    private function invoiceHistory(Organization $organization): array
    {
        if (! $organization->hasStripeId()) {
            return [];
        }

        try {
            /** @var Collection<int, Invoice> $invoices */
            $invoices = $organization->invoices(false, ['limit' => 24]);
        } catch (Throwable) {
            return [];
        }

        return $invoices->map(function (Invoice $invoice): array {
            $stripeInvoice = $invoice->asStripeInvoice();

            return [
                'id' => (string) $stripeInvoice->id,
                'number' => is_string($stripeInvoice->number) ? $stripeInvoice->number : null,
                'date' => $invoice->date()->toDateString(),
                'total_cents' => (int) $invoice->rawTotal(),
                'status' => (string) ($invoice->asStripeInvoice()->status ?? 'unknown'),
                'paid' => $invoice->asStripeInvoice()->paid ?? false,
            ];
        })->values()->all();
    }

    /**
     * @return array{edge: list<array<string, mixed>>}
     */
    private function managedProducts(Organization $organization): array
    {
        $sites = $organization->sites()->orderBy('name')->get();

        $edge = [];

        foreach ($sites as $site) {
            if ($site->status === Site::STATUS_EDGE_ACTIVE && $site->edge_backend === 'dply_edge' && ! $site->isEdgePreview()) {
                $runtimeMode = strtolower((string) ($site->edgeMeta()['runtime_mode'] ?? 'static'));
                $edge[] = [
                    'id' => $site->id,
                    'name' => $site->name,
                    'live_url' => $site->edgeLiveUrl(),
                    'unit_cents' => $runtimeMode === 'ssr'
                        ? (int) config('subscription.standard.edge_ssr_cents', 700)
                        : (int) config('subscription.standard.edge_cents', 200),
                ];
            }
        }

        return compact('edge');
    }

    /**
     * @return array<string, mixed>
     */
    private function subscriptionSnapshot(Organization $organization): array
    {
        /** @var Subscription|null $subscription */
        $subscription = $organization->subscription('default');

        if ($subscription === null) {
            return [
                'active' => false,
                'items' => [],
            ];
        }

        $items = [];
        foreach ($subscription->items as $item) {
            /** @var SubscriptionItem $item */
            $items[] = [
                'price_id' => $item->stripe_price,
                'quantity' => (int) $item->quantity,
            ];
        }

        return [
            'active' => $subscription->valid(),
            'status' => $subscription->stripe_status,
            'on_grace_period' => $subscription->onGracePeriod(),
            'ends_at' => $subscription->ends_at?->toDateString(),
            'interval' => $this->subscriptionInterval($organization),
            'items' => $items,
        ];
    }

    private function subscriptionInterval(Organization $organization): ?string
    {
        $sub = $organization->subscription('default');
        if ($sub === null) {
            return null;
        }

        return SubscriptionPlanResolver::isYearly($sub) ? 'year' : 'month';
    }

    private function nextInvoiceAt(Organization $organization): ?CarbonInterface
    {
        if ($organization->subscription('default') === null) {
            return null;
        }

        try {
            return $organization->upcomingInvoice()?->date();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $estimate
     */
    private function formatEdgeUsageDetail(array $estimate): ?string
    {
        $requests = (int) ($estimate['requests'] ?? 0);
        $egress = (int) ($estimate['bytes_egress'] ?? 0);

        if ($requests === 0 && $egress === 0) {
            return null;
        }

        $parts = [];
        if ($requests > 0) {
            $parts[] = number_format($requests).' '.__('requests');
        }
        if ($egress > 0) {
            $parts[] = number_format($egress / (1024 ** 3), 2).' GB '.__('egress');
        }

        return implode(' · ', $parts);
    }

    /**
     * @return array<string, mixed>
     */
    public function apiSummary(Organization $organization): array
    {
        $state = $this->billingStateComputer->compute($organization);

        return [
            'organization_id' => (string) $organization->id,
            'summary' => $this->summary($organization, $state),
            'plan' => [
                'key' => $state->planKey,
                'label' => $state->planLabel,
                'price_cents' => $state->planPriceCents,
            ],
            'monthly_total_cents' => $state->monthlyTotalCents,
            'managed_subtotal_cents' => $state->managedSubtotalCents(),
            'is_free' => $state->isFree(),
            'counts' => [
                'edge' => $state->edgeCount,
            ],
            'subscription' => $this->subscriptionSnapshot($organization),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function apiBreakdown(Organization $organization): array
    {
        $state = $this->billingStateComputer->compute($organization);

        return [
            'monthly_total_cents' => $state->monthlyTotalCents,
            'categories' => $this->categoryBreakdown($state),
            'line_items' => $this->lineItems($state),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function apiInvoices(Organization $organization): array
    {
        return [
            'invoices' => $this->invoiceHistory($organization),
        ];
    }
}
