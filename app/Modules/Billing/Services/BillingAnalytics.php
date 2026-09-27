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
     * Month-end projection plus Δ vs 30 days — the only analytics the
     * merged billing page needs. Skips spend charts, category pie, and
     * invoice history so Show does not pull the full observatory payload.
     *
     * Callers: Billing\Livewire\Show (merged org billing page). Existing
     * forOrganization() stays for Billing\Livewire\Analytics (legacy) and
     * BillingApiController show/breakdown/invoices. No schema change.
     *
     * User: "we can probably merge …/billing and …/billing/analytics and
     * …/invoices to simplify billing, it shlu,ld be real easy to read"
     *
     * @return array<string, int|null|string>
     */
    public function forecastFor(Organization $organization): array
    {
        $state = $this->billingStateComputer->compute($organization);

        return $this->forecast($organization, $state, $this->snapshotThirtyDaysAgo($organization));
    }

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
            'edge_usage_daily' => $this->edgeUsageDaily($organization, 30),
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
        /** @var Subscription|null $defaultSubscription */
        $defaultSubscription = $organization->subscription('default');

        return [
            'monthly_total_cents' => $monthlyCents,
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
    public function categoryBreakdown(DesiredBillingState $state): array
    {
        $segments = [];
        foreach ([
            ['plan', $state->planLabel, $state->planPriceCents, 'bg-brand-forest/70'],
            ['seats', __('Extra seats'), $state->extraSeatSubtotalCents, 'bg-amber-500/60'],
            ['usage', __('Usage after credit'), $state->usageChargeCents(), 'bg-brand-sage/50'],
        ] as [$key, $label, $cents, $color]) {
            if ($cents > 0) {
                $segments[] = ['key' => $key, 'label' => $label, 'cents' => $cents, 'color' => $color];
            }
        }

        return $segments;
    }

    /**
     * Bill lines for a state — shared by the billing page and the API: plan,
     * extra seats, one line per usage category at customer price, then the
     * included usage credit as a negative line.
     *
     * @return list<array{label: string, quantity: int, unit_cents: int, line_cents: int, detail: ?string}>
     */
    public function lineItems(DesiredBillingState $state): array
    {
        $line = static fn (string $label, int $qty, int $unit, ?string $detail = null): array => [
            'label' => $label, 'quantity' => $qty, 'unit_cents' => $unit, 'line_cents' => $qty * $unit, 'detail' => $detail,
        ];

        $items = [];
        if ($state->planPriceCents > 0) {
            $items[] = $line(__(':plan plan', ['plan' => $state->planLabel]), 1, $state->planPriceCents);
        }
        if ($state->extraSeatCount > 0) {
            $items[] = $line(__('Extra seat'), $state->extraSeatCount, intdiv($state->extraSeatSubtotalCents, $state->extraSeatCount));
        }
        foreach ($state->usageLines() as $key => $cents) {
            $detail = match ($key) {
                'delivery' => $this->formatEdgeUsageDetail($state->edgeUsageEstimate),
                'builds' => __(':minutes build minutes, billed per second', ['minutes' => number_format($state->buildSeconds / 60, 1)]),
                default => null,
            };
            $items[] = $line(__(DesiredBillingState::usageLineLabel($key)), 1, $cents, $detail);
        }
        if ($state->creditAppliedCents() > 0) {
            $items[] = $line(__(DesiredBillingState::usageLineLabel('credit')), 1, -$state->creditAppliedCents(), __(':plan includes :credit of usage each period', ['plan' => $state->planLabel, 'credit' => '$'.number_format($state->usageCreditCents / 100, 2)]));
        }

        return $items;
    }

    /**
     * Delivery usage per day. Storage is a level: each site's peak that day,
     * summed across sites (a MAX across the org was the single largest site).
     *
     * @return list<array{date: string, label: string, requests: int, bytes_egress: int, cost_cents: int}>
     */
    private function edgeUsageDaily(Organization $organization, int $days): array
    {
        $start = now()->subDays(max(1, $days - 1))->startOfDay();

        $perSite = EdgeUsageSnapshot::query()
            ->where('organization_id', $organization->id)
            ->where('period_start', '>=', $start->toDateString())
            ->groupBy('period_start', 'site_id')
            ->select([
                'period_start',
                DB::raw('SUM(requests) as requests'),
                DB::raw('SUM(bytes_egress) as bytes_egress'),
                DB::raw('MAX(r2_storage_bytes) as r2_storage_bytes'),
                DB::raw('SUM(r2_class_a_ops) as r2_class_a_ops'),
                DB::raw('SUM(r2_class_b_ops) as r2_class_b_ops'),
            ]);
        $rows = DB::query()->fromSub($perSite, 'per_site')
            ->groupBy('period_start')
            ->orderBy('period_start')
            ->get([
                'period_start',
                DB::raw('COALESCE(SUM(requests), 0) as requests'),
                DB::raw('COALESCE(SUM(bytes_egress), 0) as bytes_egress'),
                DB::raw('COALESCE(SUM(r2_storage_bytes), 0) as r2_storage_bytes'),
                DB::raw('COALESCE(SUM(r2_class_a_ops), 0) as r2_class_a_ops'),
                DB::raw('COALESCE(SUM(r2_class_b_ops), 0) as r2_class_b_ops'),
            ]);

        $calculator = app(EdgeUsageCostCalculator::class);
        $series = [];

        foreach ($rows as $row) {
            $totals = new EdgeUsageTotals(
                requests: (int) $row->requests,
                bytesEgress: (int) $row->bytes_egress,
                r2StorageBytes: (int) $row->r2_storage_bytes,
                r2ClassAOps: (int) $row->r2_class_a_ops,
                r2ClassBOps: (int) $row->r2_class_b_ops,
            );
            $date = Carbon::parse((string) $row->period_start)->toDateString();

            $series[] = [
                'date' => $date,
                'label' => Carbon::parse($date)->format('M j'),
                'requests' => $totals->requests,
                'bytes_egress' => $totals->bytesEgress,
                'r2_storage_bytes' => $totals->r2StorageBytes,
                'cost_cents' => $calculator->estimate($totals)['subtotal_cents'],
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
                $fee = $this->edgeSiteBillingAnalytics->platformFee($site);
                $edge[] = [
                    'id' => $site->id,
                    'name' => $site->name,
                    'live_url' => $site->edgeLiveUrl(),
                    'unit_cents' => $fee['cents'],
                    'platform_kind' => $fee['kind'],
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
