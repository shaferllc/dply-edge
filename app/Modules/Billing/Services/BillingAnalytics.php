<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Models\BillingSubscriptionSyncEvent;
use App\Models\EdgeUsageSnapshot;
use App\Models\Organization;
use App\Models\OrganizationBillingSnapshot;
use App\Models\Server;
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
            'billable_servers' => $this->billableServersList($organization),
            'excluded_servers' => $this->excludedServersList($organization),
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
            'on_trial' => $organization->onDplyTrial(),
            'trial_days_left' => $organization->trial_ends_at
                ? max(0, (int) ceil(now()->diffInDays($organization->trial_ends_at, false)))
                : 0,
            'subscribed' => $defaultSubscription?->valid() ?? false,
            'stripe_status' => $defaultSubscription?->stripe_status,
            'next_invoice_at' => $this->nextInvoiceAt($organization)?->toDateString(),
            'server_count' => $state->serverCount(),
            'serverless_count' => $state->serverlessCount,
            'cloud_count' => $state->cloudCount,
            'edge_count' => $state->edgeCount,
            'realtime_count' => $state->realtimeCount,
        ];
    }

    /**
     * @return list<array{key: string, label: string, cents: int, color: string}>
     */
    private function categoryBreakdown(DesiredBillingState $state): array
    {
        $serverCount = $state->serverCount();
        $segments = [
            [
                'key' => 'plan',
                'label' => $serverCount > 0
                    ? $state->planLabel.' · '.$serverCount.' '.($serverCount === 1 ? __('server') : __('servers'))
                    : $state->planLabel,
                'cents' => $state->planPriceCents,
                'color' => 'bg-brand-ink/80',
            ],
        ];

        if ($state->serverlessSubtotalCents > 0) {
            $segments[] = [
                'key' => 'serverless',
                'label' => __('Serverless').' × '.$state->serverlessCount,
                'cents' => $state->serverlessSubtotalCents,
                'color' => 'bg-violet-500/70',
            ];
        }

        if ($state->serverlessUsageSubtotalCents > 0) {
            $segments[] = [
                'key' => 'serverless_usage',
                'label' => __('Serverless usage'),
                'cents' => $state->serverlessUsageSubtotalCents,
                'color' => 'bg-violet-500/40',
            ];
        }

        if ($state->managedServerSubtotalCents > 0) {
            $segments[] = [
                'key' => 'managed_server',
                'label' => __('Managed servers').' × '.$state->managedServerCount,
                'cents' => $state->managedServerSubtotalCents,
                'color' => 'bg-sky-500/70',
            ];
        }

        if ($state->cloudSubtotalCents > 0) {
            $segments[] = [
                'key' => 'cloud',
                'label' => __('Cloud').' × '.$state->cloudCount,
                'cents' => $state->cloudSubtotalCents,
                'color' => 'bg-sky-500/70',
            ];
        }

        if ($state->cloudResourceSubtotalCents > 0) {
            $segments[] = [
                'key' => 'cloud_resources',
                'label' => __('Cloud resources'),
                'cents' => $state->cloudResourceSubtotalCents,
                'color' => 'bg-sky-500/40',
            ];
        }

        if ($state->edgeSubtotalCents > 0) {
            $segments[] = [
                'key' => 'edge',
                'label' => __('Edge').' × '.$state->edgeCount,
                'cents' => $state->edgeSubtotalCents,
                'color' => 'bg-emerald-500/70',
            ];
        }

        if ($state->realtimeSubtotalCents > 0) {
            $segments[] = [
                'key' => 'realtime',
                'label' => __('Realtime').' × '.$state->realtimeCount,
                'cents' => $state->realtimeSubtotalCents,
                'color' => 'bg-violet-500/70',
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
        $serverCount = $state->serverCount();

        $items = [[
            'label' => __('dply plan — :plan', ['plan' => $state->planLabel]),
            'quantity' => 1,
            'unit_cents' => $state->planPriceCents,
            'line_cents' => $state->planPriceCents,
            'detail' => $serverCount > 0
                ? trans_choice(':count server|:count servers', $serverCount, ['count' => $serverCount])
                : null,
        ]];

        if ($state->serverlessCount > 0) {
            $unit = (int) config('subscription.standard.serverless_cents', 200);
            $items[] = [
                'label' => __('dply serverless function'),
                'quantity' => $state->serverlessCount,
                'unit_cents' => $unit,
                'line_cents' => $state->serverlessSubtotalCents,
                'detail' => null,
            ];
        }

        if ($state->serverlessUsageSubtotalCents > 0) {
            $items[] = [
                'label' => __('dply serverless usage'),
                'quantity' => 1,
                'unit_cents' => $state->serverlessUsageSubtotalCents,
                'line_cents' => $state->serverlessUsageSubtotalCents,
                'detail' => __('Metered invocations, managed databases & caches'),
            ];
        }

        if ($state->managedServerSubtotalCents > 0) {
            $items[] = [
                'label' => __('dply managed server'),
                'quantity' => $state->managedServerCount,
                'unit_cents' => $state->managedServerCount > 0
                    ? (int) round($state->managedServerSubtotalCents / $state->managedServerCount)
                    : $state->managedServerSubtotalCents,
                'line_cents' => $state->managedServerSubtotalCents,
                'detail' => __('All-in cost-plus — dply-hosted VM'),
            ];
        }

        if ($state->cloudCount > 0) {
            $unit = (int) config('subscription.standard.cloud_cents', 500);
            $items[] = [
                'label' => __('dply Cloud app'),
                'quantity' => $state->cloudCount,
                'unit_cents' => $unit,
                'line_cents' => $state->cloudSubtotalCents,
                'detail' => null,
            ];
        }

        if ($state->cloudResourceSubtotalCents > 0) {
            $items[] = [
                'label' => __('dply Cloud resources'),
                'quantity' => 1,
                'unit_cents' => $state->cloudResourceSubtotalCents,
                'line_cents' => $state->cloudResourceSubtotalCents,
                'detail' => __('Metered compute, workers & databases'),
            ];
        }

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

        // Managed Realtime — one line per connection-tier in use.
        $realtimeTiers = (array) config('realtime.tiers', []);
        foreach ($state->realtimeTierQuantities as $tier => $quantity) {
            if ($quantity <= 0) {
                continue;
            }
            $tierConfig = $realtimeTiers[(string) $tier] ?? [];
            $unitCents = (int) ($tierConfig['price_cents'] ?? config('subscription.standard.realtime_cents', 900));
            $label = (string) ($tierConfig['label'] ?? ucfirst((string) $tier));
            $items[] = [
                'label' => __('dply Realtime app').' — '.$label,
                'quantity' => $quantity,
                'unit_cents' => $unitCents,
                'line_cents' => $unitCents * $quantity,
                'detail' => null,
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
     * @return array{serverless: list<array>, cloud: list<array>, edge: list<array>}
     */
    private function managedProducts(Organization $organization): array
    {
        $sites = $organization->sites()->orderBy('name')->get();

        $serverless = [];
        $cloud = [];
        $edge = [];

        foreach ($sites as $site) {
            if ($site->status === Site::STATUS_FUNCTIONS_ACTIVE) {
                $serverless[] = [
                    'id' => $site->id,
                    'name' => $site->name,
                    'status' => $site->status,
                    'unit_cents' => (int) config('subscription.standard.serverless_cents', 200),
                ];
            }

            if ($site->status === Site::STATUS_CONTAINER_ACTIVE && $site->isDplyCloudSite() && ! $site->isCloudPreview()) {
                $cloud[] = [
                    'id' => $site->id,
                    'name' => $site->name,
                    'live_url' => $site->containerLiveUrl(),
                    'unit_cents' => (int) config('subscription.standard.cloud_cents', 500),
                ];
            }

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

        return compact('serverless', 'cloud', 'edge');
    }

    /**
     * @return list<array{id: string, name: string, reason: string}>
     */
    private function excludedServersList(Organization $organization): array
    {
        return $this->excludedServers($organization)
            ->map(fn (array $row): array => [
                'id' => (string) $row['server']->id,
                'name' => (string) $row['server']->name,
                'reason' => (string) $row['reason'],
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{id: string, name: string, monthly_cents: int}>
     */
    private function billableServersList(Organization $organization): array
    {
        return $this->billableServers($organization)
            ->map(fn (Server $server): array => [
                'id' => (string) $server->id,
                'name' => (string) $server->name,
                // Per-server dply fee is $0 under the flat-plan model — the
                // plan price (by server count) is billed once, not per server.
                'monthly_cents' => 0,
            ])
            ->values()
            ->all();
    }

    /** @var array<string, Collection<int, Server>> */
    private array $billableServersCache = [];

    /**
     * @return Collection<int, Server>
     */
    private function billableServers(Organization $organization): Collection
    {
        // Memoized per org: billableServersList() and excludedServers() both
        // call this within one render, and re-fetching re-ran the whole server
        // query (plus its metric-snapshot eager load) a second time.
        if (isset($this->billableServersCache[$organization->id])) {
            return $this->billableServersCache[$organization->id];
        }

        // Reuse the billing-state computer's memoised READY-server set (same
        // status + age filter, with latestMetricSnapshot eager-loaded) so the
        // expensive snapshot subquery runs once for the whole page instead of
        // being duplicated here. Sort by name in PHP since the shared set is
        // unordered.
        return $this->billableServersCache[$organization->id] = $this->billingStateComputer
            ->readyBillableServers($organization)
            ->reject(fn (Server $server): bool => $server->isManagedProductHost())
            ->sortBy(fn (Server $server): string => (string) $server->name, SORT_NATURAL | SORT_FLAG_CASE)
            ->values();
    }

    private function excludedServers(Organization $organization): Collection
    {
        $minAge = max(0, (int) config('subscription.standard.min_billable_age_days', 1));
        $cutoff = now()->subDays($minAge);
        $billableIds = $this->billableServers($organization)->pluck('id')->all();

        return $organization->servers()
            ->orderBy('name')
            ->get()
            ->reject(fn (Server $s) => in_array($s->id, $billableIds, true))
            ->map(function (Server $server) use ($cutoff, $minAge): array {
                $reason = match (true) {
                    $server->isManagedProductHost() => match (true) {
                        $server->isDplyCloudHost() => __('Billed as dply Cloud app'),
                        $server->isDplyEdgeHost() => __('Billed as dply Edge site'),
                        $server->isServerlessHost() => __('Billed as serverless function'),
                        default => __('Billed as managed product'),
                    },
                    $server->status !== Server::STATUS_READY => __('Status: :status', ['status' => $server->status]),
                    $server->created_at->gt($cutoff) => __('Under the :days-day billable threshold', ['days' => $minAge]),
                    default => __('Excluded'),
                };

                return ['server' => $server, 'reason' => $reason];
            })
            ->values();
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

        $yearlyIds = array_merge(
            array_values((array) config('subscription.standard.stripe.plans_yearly', [])),
            array_values((array) config('subscription.standard.stripe.realtime_tiers_yearly', [])),
            [
                (string) (config('subscription.standard.stripe.serverless_yearly') ?? ''),
                (string) (config('subscription.standard.stripe.cloud_yearly') ?? ''),
                (string) (config('subscription.standard.stripe.edge_yearly') ?? ''),
                (string) (config('subscription.standard.stripe.realtime_yearly') ?? ''),
            ],
        );

        foreach ($yearlyIds as $priceId) {
            $priceId = (string) $priceId;
            if ($priceId !== '' && $sub->hasPrice($priceId)) {
                return 'year';
            }
        }

        return 'month';
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
                'servers' => $state->serverCount(),
                'serverless' => $state->serverlessCount,
                'managed_servers' => $state->managedServerCount,
                'cloud' => $state->cloudCount,
                'edge' => $state->edgeCount,
                'realtime' => $state->realtimeCount,
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
