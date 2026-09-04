<div>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        @php
            $interval = $summary['interval'] ?? 'month';
            $monthlyCents = (int) ($summary['monthly_total_cents'] ?? 0);
            $yearlyCents = (int) ($summary['yearly_total_cents'] ?? 0);
            $displayCents = $interval === 'year' ? $yearlyCents : $monthlyCents;
            $forecastProjectedMonthEndCents = (int) ($forecast['projected_month_end_cents'] ?? 0);
            $forecastDeltaVsThirtyDays = $forecast['delta_vs_thirty_days_cents'] ?? null;
            $spendTrendThirty = is_array($spendTrend['series_30'] ?? null) ? $spendTrend['series_30'] : [];
            $spendTrendNinety = is_array($spendTrend['series_90'] ?? null) ? $spendTrend['series_90'] : [];
            $maxSpendTrendCents = max(1, collect($spendTrendNinety)->max('total_cents') ?? 1);
            $maxEdgeRequests = max(1, collect($edgeUsageDaily)->max('requests') ?? 1);
            $maxInvoiceCents = max(1, collect($invoiceHistory)->max('total_cents') ?? 1);

            // Surface flags — hide Edge / Cloud / Serverless lines and sections when
            // those surfaces aren't enabled for this org. The numbers come from the
            // controller (which doesn't know about flags), so we filter at render time
            // and only show what's actually a product for this account.
            $edgeOn = \Laravel\Pennant\Feature::active('surface.edge');
            $cloudOn = \Laravel\Pennant\Feature::active('surface.cloud');
            $serverlessOn = \Laravel\Pennant\Feature::active('surface.serverless');
            $hasManagedSurfaces = $edgeOn || $cloudOn || $serverlessOn;

            // Matches on labels rather than slugs because the controller emits plain
            // labels — "Edge", "Cloud", "Serverless".
            $offSurface = function (array $row) use ($edgeOn, $cloudOn, $serverlessOn): bool {
                $label = strtolower((string) ($row['label'] ?? ''));

                return (! $edgeOn && str_contains($label, 'edge'))
                    || (! $cloudOn && str_contains($label, 'cloud'))
                    || (! $serverlessOn && str_contains($label, 'serverless'));
            };

            $categoryBreakdown = collect($categoryBreakdown)->reject($offSurface)->values()->all();
            $lineItems = collect($lineItems)->reject($offSurface)->values()->all();
            $totalBreakdownCents = max(1, collect($categoryBreakdown)->sum('cents'));

            // Resource-count parts the hero stat shows — only surfaces the org has.
            $resourceParts = collect([
                ['count' => $summary['server_count'] ?? 0, 'label' => __('VM'), 'visible' => true],
                ['count' => $summary['edge_count'] ?? 0, 'label' => __('Apps'), 'visible' => $edgeOn],
                ['count' => $summary['cloud_count'] ?? 0, 'label' => __('Cloud'), 'visible' => $cloudOn],
                ['count' => $summary['serverless_count'] ?? 0, 'label' => __('Fn'), 'visible' => $serverlessOn],
            ])->filter(fn (array $p) => $p['visible'])->values();
            $billableResources = $resourceParts->sum('count');

            // Status palette mirrors billing.show — same dot tokens so an admin reading
            // both pages sees a consistent visual vocabulary.
            if (! empty($summary['subscribed'])) {
                $statusDot = 'bg-brand-sage';
                $statusLabel = ucfirst((string) ($summary['stripe_status'] ?? __('Active')));
                $statusSub = ! empty($summary['next_invoice_at'])
                    ? __('Next invoice :date', ['date' => \Illuminate\Support\Carbon::parse($summary['next_invoice_at'])->toFormattedDateString()])
                    : ($interval === 'year' ? __('Billed annually') : __('Billed monthly'));
            } elseif (! empty($summary['on_trial'])) {
                $statusDot = 'bg-sky-500';
                $statusLabel = __('Trial');
                $days = (int) ($summary['trial_days_left'] ?? 0);
                $statusSub = trans_choice(':days day left|:days days left', $days, ['days' => $days]);
            } else {
                $statusDot = 'bg-brand-ink/15';
                $statusLabel = __('Not subscribed');
                $statusSub = __('Estimate only until you add a plan');
            }

            // One cell of a hairline stat/metric strip. Every number on this page is
            // money or a count with a caption, so they all render through this.
            $cell = 'bg-white px-3 py-2';
            $cellLabel = 'text-2xs font-semibold uppercase tracking-wide text-brand-mist';
            $cellValue = 'mt-0.5 font-mono text-lg font-semibold tabular-nums text-brand-ink';
            $cellNote = 'mt-0.5 truncate text-xs text-brand-moss';
            $th = 'px-3 py-1.5 sm:px-4';
            $td = 'px-3 py-2 sm:px-4';
        @endphp

        <x-organization-shell
            :organization="$organization"
            section="billing-analytics"
            :title="__('Billing analytics')"
            :description="__('Live estimate, resource breakdown, Edge meters, and Stripe invoice history.')"
            icon="heroicon-o-chart-bar"
            :breadcrumb="[
                ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
                ['label' => $organization->name, 'href' => route('organizations.show', $organization), 'icon' => 'building-office-2'],
                ['label' => __('Billing analytics'), 'icon' => 'chart-bar'],
            ]"
        >
            <x-slot:actions>
                <x-outline-link size="xxs" href="{{ route('billing.show', $organization) }}" wire:navigate>
                    <x-heroicon-o-credit-card class="h-3.5 w-3.5 shrink-0 opacity-90" aria-hidden="true" />
                    {{ __('Billing & plan') }}
                </x-outline-link>
                <x-outline-link size="xxs" href="{{ route('billing.invoices', $organization) }}" wire:navigate>
                    <x-heroicon-o-document class="h-3.5 w-3.5 shrink-0 opacity-90" aria-hidden="true" />
                    {{ __('Invoices') }}
                </x-outline-link>
            </x-slot:actions>

            <x-slot:stats>
                <dl class="grid grid-cols-3 gap-px bg-brand-ink/5" aria-label="{{ __('Billing analytics at a glance') }}">
                    <div class="{{ $cell }}">
                        <dt class="{{ $cellLabel }}">{{ __('Status') }}</dt>
                        <dd class="mt-0.5 flex items-center gap-1.5">
                            <span class="inline-block h-2 w-2 shrink-0 rounded-full {{ $statusDot }}" aria-hidden="true"></span>
                            <span class="truncate text-sm font-semibold text-brand-ink">{{ $statusLabel }}</span>
                        </dd>
                        <p class="{{ $cellNote }}" title="{{ $statusSub }}">{{ $statusSub }}</p>
                    </div>
                    <div class="{{ $cell }}">
                        <dt class="{{ $cellLabel }}">{{ __('Estimated') }}</dt>
                        <dd class="mt-0.5 flex items-baseline gap-1">
                            <span class="font-mono text-lg font-semibold tabular-nums text-brand-ink">${{ number_format($displayCents / 100, 0) }}</span>
                            <span class="text-xs text-brand-moss">{{ $interval === 'year' ? '/'.__('yr') : '/'.__('mo') }}</span>
                        </dd>
                        <p class="{{ $cellNote }}">{{ __('Daily run rate $:n', ['n' => number_format(($summary['daily_run_rate_cents'] ?? 0) / 100, 2)]) }}</p>
                    </div>
                    <div class="{{ $cell }}">
                        <dt class="{{ $cellLabel }}">{{ __('Resources') }}</dt>
                        <dd class="mt-0.5 flex items-baseline gap-1">
                            <span class="font-mono text-lg font-semibold tabular-nums text-brand-ink">{{ $billableResources }}</span>
                            <span class="text-xs text-brand-moss">{{ __('billable') }}</span>
                        </dd>
                        <p class="{{ $cellNote }}">{{ $resourceParts->map(fn (array $p) => $p['count'].' '.$p['label'])->implode(' · ') }}</p>
                    </div>
                </dl>
            </x-slot:stats>


            {{-- Cost forecast. Deliberately NOT "recurring revenue": this page is
                 gated by authorize('update', $organization), so the reader is the
                 org paying the bill, not us. The same number is our revenue and
                 their spend — the label has to be written from their side.
                 MRR/ARR tiles lived here and were dropped for the same reason:
                 they were vendor metrics, and MRR duplicated billing.show. --}}
            <section class="border-b border-brand-ink/10 last:border-b-0">
                <x-workspace-panel-head
                    dense
                    class="border-b border-brand-ink/10"
                    icon="heroicon-o-arrow-trending-up"
                    :title="__('Cost forecast')"
                    :note="__('What this organization is on track to be charged this month, and how that is trending.')"
                />

                <dl class="grid gap-px bg-brand-ink/5 sm:grid-cols-2">
                    <div class="{{ $cell }}">
                        <dt class="{{ $cellLabel }}">{{ __('Projected this month') }}</dt>
                        <dd class="{{ $cellValue }}">${{ number_format($forecastProjectedMonthEndCents / 100, 2) }}</dd>
                        <p class="{{ $cellNote }}">{{ __('Plan and add-ons, plus Edge usage so far extrapolated to month end') }}</p>
                    </div>
                    <div class="{{ $cell }}">
                        <dt class="{{ $cellLabel }}">{{ __('Δ vs 30 days') }}</dt>
                        @if (is_int($forecastDeltaVsThirtyDays))
                            <dd class="{{ $cellValue }} {{ $forecastDeltaVsThirtyDays >= 0 ? '!text-brand-rust' : '!text-brand-forest' }}">
                                {{ $forecastDeltaVsThirtyDays >= 0 ? '+' : '-' }}${{ number_format(abs($forecastDeltaVsThirtyDays) / 100, 2) }}
                            </dd>
                            <p class="{{ $cellNote }}">{{ __('Change in your estimated monthly cost') }}</p>
                        @else
                            <dd class="mt-0.5 text-sm font-semibold text-brand-ink">{{ __('Not enough history') }}</dd>
                            <p class="{{ $cellNote }}">{{ __('Appears once snapshots accumulate') }}</p>
                        @endif
                    </div>
                </dl>
            </section>

            {{-- Spend trend --}}
            <section class="border-b border-brand-ink/10 last:border-b-0">
                <x-workspace-panel-head
                    dense
                    class="border-b border-brand-ink/10"
                    icon="heroicon-o-presentation-chart-line"
                    :title="__('Historical spend')"
                    :note="__('Daily billing snapshots for the last 90 days.')"
                />

                @if ($spendTrendNinety === [])
                    <div class="px-3 py-6 text-center sm:px-4">
                        <x-empty-state
                            borderless
                            compact
                            icon="heroicon-o-chart-bar"
                            :title="__('No snapshots yet')"
                            :description="__('Daily snapshots populate this trend automatically — check back tomorrow.')"
                        />
                    </div>
                @else
                    <div class="px-3 py-3 sm:px-4">
                        <div class="flex h-16 items-end gap-1" aria-hidden="true">
                            @foreach ($spendTrendNinety as $day)
                                <div class="min-w-0 flex-1">
                                    <div
                                        class="w-full rounded-t bg-brand-ink/25 transition-colors hover:bg-brand-ink/45"
                                        style="height: {{ max(4, round(($day['total_cents'] / $maxSpendTrendCents) * 100)) }}%"
                                        title="{{ $day['label'] }} — ${{ number_format(($day['total_cents'] ?? 0) / 100, 2) }}"
                                    ></div>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    @if ($spendTrendThirty !== [])
                        {{-- 30 rows of dailies is reference, not something to read now:
                             the sparkline above already carries the shape. --}}
                        <details class="group border-t border-brand-ink/10">
                            <summary class="flex cursor-pointer list-none items-center gap-1.5 px-3 py-2 text-xs font-medium text-brand-moss hover:text-brand-ink sm:px-4">
                                <x-heroicon-o-chevron-right class="h-3.5 w-3.5 shrink-0 transition-transform group-open:rotate-90" aria-hidden="true" />
                                {{ __('Daily detail — last 30 days') }}
                            </summary>
                            <table class="w-full border-t border-brand-ink/10 text-sm">
                                <thead class="bg-brand-sand/35 text-2xs font-semibold uppercase tracking-wide text-brand-moss">
                                    <tr>
                                        <th class="{{ $th }} text-left">{{ __('Date') }}</th>
                                        <th class="{{ $th }} text-right">{{ __('Total') }}</th>
                                        <th class="{{ $th }} text-right">{{ __('Apps usage') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-brand-ink/5">
                                    @foreach ($spendTrendThirty as $day)
                                        <tr class="transition-colors hover:bg-brand-sand/15">
                                            <td class="{{ $td }} text-brand-ink">{{ \Illuminate\Support\Carbon::parse($day['date'])->toFormattedDateString() }}</td>
                                            <td class="{{ $td }} text-right font-mono tabular-nums text-brand-ink">${{ number_format(($day['total_cents'] ?? 0) / 100, 2) }}</td>
                                            <td class="{{ $td }} text-right font-mono tabular-nums text-brand-moss">${{ number_format(($day['edge_usage_cents'] ?? 0) / 100, 2) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </details>
                    @endif
                @endif
            </section>

            {{-- Spend by category --}}
            <section class="border-b border-brand-ink/10 last:border-b-0">
                <x-workspace-panel-head
                    dense
                    class="border-b border-brand-ink/10"
                    icon="heroicon-o-chart-pie"
                    :title="__('Spend by category')"
                    :note="__('Current-cycle estimate — updates when your fleet changes.')"
                />

                <div class="space-y-2 px-3 py-3 sm:px-4">
                    <div class="flex h-3 w-full overflow-hidden rounded-full bg-brand-cream/80">
                        @foreach ($categoryBreakdown as $segment)
                            @if (($segment['cents'] ?? 0) > 0)
                                <div
                                    class="{{ $segment['color'] ?? 'bg-brand-moss' }} min-w-[2px]"
                                    style="width: {{ max(2, round(($segment['cents'] / $totalBreakdownCents) * 100, 1)) }}%"
                                    title="{{ $segment['label'] }} — ${{ number_format($segment['cents'] / 100, 2) }}"
                                ></div>
                            @endif
                        @endforeach
                    </div>

                    <div class="flex flex-wrap gap-x-3 gap-y-1 text-xs text-brand-moss">
                        @foreach ($categoryBreakdown as $segment)
                            @if (($segment['cents'] ?? 0) > 0)
                                <span class="inline-flex items-center gap-1.5">
                                    <span class="h-2.5 w-2.5 rounded-sm {{ $segment['color'] ?? 'bg-brand-moss' }}"></span>
                                    {{ $segment['label'] }} · <span class="font-mono tabular-nums">${{ number_format($segment['cents'] / 100, 2) }}</span>
                                </span>
                            @endif
                        @endforeach
                    </div>
                </div>

                <table class="w-full border-t border-brand-ink/10 text-sm">
                    <thead class="bg-brand-sand/35 text-2xs font-semibold uppercase tracking-wide text-brand-moss">
                        <tr>
                            <th class="{{ $th }} text-left">{{ __('Line item') }}</th>
                            <th class="{{ $th }} text-right">{{ __('Qty') }}</th>
                            <th class="{{ $th }} text-right">{{ __('Unit') }}</th>
                            <th class="{{ $th }} text-right">{{ __('Monthly') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-brand-ink/5">
                        @foreach ($lineItems as $item)
                            <tr class="transition-colors hover:bg-brand-sand/15">
                                <td class="{{ $td }} text-brand-ink">
                                    {{ $item['label'] }}
                                    @if (! empty($item['detail']))
                                        <span class="block text-xs text-brand-moss">{{ $item['detail'] }}</span>
                                    @endif
                                </td>
                                <td class="{{ $td }} text-right font-mono tabular-nums text-brand-moss">{{ $item['quantity'] }}</td>
                                <td class="{{ $td }} text-right font-mono tabular-nums text-brand-moss">${{ number_format($item['unit_cents'] / 100, 2) }}</td>
                                <td class="{{ $td }} text-right font-mono font-semibold tabular-nums text-brand-ink">${{ number_format($item['line_cents'] / 100, 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="bg-brand-sand/30">
                        <tr>
                            <td colspan="3" class="{{ $td }} text-right text-xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('Estimated total') }}</td>
                            <td class="{{ $td }} text-right font-mono font-semibold tabular-nums text-brand-ink">${{ number_format($monthlyCents / 100, 2) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </section>

            @if ($edgeOn)
                {{-- Edge sites --}}
                <section class="border-b border-brand-ink/10 last:border-b-0">
                    <x-workspace-panel-head
                        dense
                        class="border-b border-brand-ink/10"
                        icon="heroicon-o-bolt"
                        :title="__('Apps')"
                        :count="count($edgeSites) ?: null"
                        :note="__('Per-site platform fee, delivery usage (MTD), and daily request trends.')"
                    />

                    @if ($edgeSites === [])
                        <div class="px-3 py-6 text-center sm:px-4">
                            <p class="text-sm text-brand-moss">{{ __('No live Edge sites in this organization yet.') }}</p>
                        </div>
                    @else
                        @if ($edgeUsageDaily !== [])
                            <div class="px-3 py-3 sm:px-4">
                                <p class="{{ $cellLabel }}">{{ __('Org total — daily requests') }}</p>
                                <div class="mt-2 flex h-14 items-end gap-1" aria-hidden="true">
                                    @foreach ($edgeUsageDaily as $day)
                                        <div class="min-w-0 flex-1">
                                            <div
                                                class="w-full rounded-t bg-brand-ink/20 transition-colors hover:bg-brand-ink/40"
                                                style="height: {{ max(4, round(($day['requests'] / $maxEdgeRequests) * 100)) }}%"
                                            ></div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endif

                        <div class="grid gap-3 border-t border-brand-ink/10 px-3 py-3 sm:px-4 lg:grid-cols-2">
                            @foreach ($edgeSites as $edgeSite)
                                @include('livewire.billing.partials.edge-site-billing-card', ['site' => $edgeSite])
                            @endforeach
                        </div>
                    @endif
                </section>
            @endif

            @if ($hasManagedSurfaces)
                {{-- Managed products --}}
                @php
                    $managedCatalog = array_filter([
                        'edge' => $edgeOn ? ['title' => __('Apps'), 'icon' => 'heroicon-o-globe-alt'] : null,
                        'cloud' => $cloudOn ? ['title' => __('Cloud apps'), 'icon' => 'heroicon-o-cube'] : null,
                        'serverless' => $serverlessOn ? ['title' => __('Serverless apps'), 'icon' => 'heroicon-o-bolt'] : null,
                    ]);
                @endphp
                <section class="border-b border-brand-ink/10 last:border-b-0">
                    <x-workspace-panel-head
                        dense
                        class="border-b border-brand-ink/10"
                        icon="heroicon-o-cube"
                        :title="__('Managed products')"
                        :note="__('Live Cloud, Edge, and Serverless sites billed per unit — separate from BYO VM tiers.')"
                    />

                    <div class="grid gap-px bg-brand-ink/5 lg:grid-cols-3">
                        @foreach ($managedCatalog as $key => $meta)
                            @php $rows = $managedProducts[$key] ?? []; @endphp
                            <div class="bg-white px-3 py-2">
                                <div class="flex items-center gap-1.5">
                                    <x-dynamic-component :component="$meta['icon']" class="h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
                                    <h4 class="text-sm font-semibold text-brand-ink">{{ $meta['title'] }}</h4>
                                    <span class="text-xs text-brand-mist">{{ count($rows) }}</span>
                                </div>
                                @if ($rows === [])
                                    <p class="mt-1 text-xs text-brand-mist">{{ __('None active') }}</p>
                                @else
                                    <ul class="mt-1 space-y-0.5 text-sm">
                                        @foreach ($rows as $row)
                                            <li class="flex items-start justify-between gap-2">
                                                <span class="truncate text-brand-ink" title="{{ $row['name'] }}">{{ $row['name'] }}</span>
                                                <span class="shrink-0 font-mono tabular-nums text-brand-moss">${{ number_format(($row['unit_cents'] ?? 0) / 100, 2) }}</span>
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </section>
            @endif

            {{-- BYO fleet --}}
            <section class="border-b border-brand-ink/10 last:border-b-0">
                <x-workspace-panel-head
                    dense
                    class="border-b border-brand-ink/10"
                    icon="heroicon-o-server-stack"
                    :title="__('BYO server fleet')"
                    :count="count($billableServers) ?: null"
                    :note="__('Spec-tiered VMs you SSH into — counted separately from managed products.')"
                />

                @if ($billableServers === [] && $excludedServers === [])
                    <div class="px-3 py-6 text-center sm:px-4">
                        <p class="text-sm text-brand-moss">{{ __('No servers in this organization.') }}</p>
                    </div>
                @else
                    <table class="w-full text-sm">
                        <thead class="bg-brand-sand/35 text-2xs font-semibold uppercase tracking-wide text-brand-moss">
                            <tr>
                                <th class="{{ $th }} text-left">{{ __('Server') }}</th>
                                <th class="{{ $th }} text-left">{{ __('Status') }}</th>
                                <th class="{{ $th }} text-right">{{ __('Monthly') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-brand-ink/5">
                            @foreach ($billableServers as $server)
                                <tr class="transition-colors hover:bg-brand-sand/15">
                                    <td class="{{ $td }} font-medium text-brand-ink">{{ $server['name'] }}</td>
                                    <td class="{{ $td }} text-brand-moss">{{ __('Billable') }}</td>
                                    <td class="{{ $td }} text-right font-mono tabular-nums text-brand-ink">${{ number_format($server['monthly_cents'] / 100, 2) }}</td>
                                </tr>
                            @endforeach
                            @foreach ($excludedServers as $row)
                                <tr class="opacity-70 transition-colors hover:bg-brand-sand/15">
                                    <td class="{{ $td }} text-brand-ink">{{ $row['name'] }}</td>
                                    <td class="{{ $td }} text-brand-moss">{{ $row['reason'] }}</td>
                                    <td class="{{ $td }} text-right text-brand-mist">—</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </section>

            {{-- Stripe sync events --}}
            <section class="border-b border-brand-ink/10 last:border-b-0">
                <x-workspace-panel-head
                    dense
                    class="border-b border-brand-ink/10"
                    icon="heroicon-o-arrow-path"
                    :title="__('Stripe sync events')"
                    :note="__('Recent billing reconciliation runs, including no-op and failed runs.')"
                />

                @if ($syncEvents === [])
                    <div class="px-3 py-6 text-center sm:px-4">
                        <p class="text-sm text-brand-moss">{{ __('No sync events yet.') }}</p>
                    </div>
                @else
                    <table class="w-full text-sm">
                        <thead class="bg-brand-sand/35 text-2xs font-semibold uppercase tracking-wide text-brand-moss">
                            <tr>
                                <th class="{{ $th }} text-left">{{ __('Time') }}</th>
                                <th class="{{ $th }} text-left">{{ __('Trigger') }}</th>
                                <th class="{{ $th }} text-left">{{ __('Status') }}</th>
                                <th class="{{ $th }} text-right">{{ __('Changes') }}</th>
                                <th class="{{ $th }} text-right">{{ __('Monthly total') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-brand-ink/5">
                            @foreach ($syncEvents as $event)
                                @php
                                    $eventStatus = $event['status'] ?? 'unknown';
                                    $statusClasses = match ($eventStatus) {
                                        'failed' => 'border-red-200 bg-red-50 text-red-700',
                                        'success' => 'border-emerald-200 bg-emerald-50 text-emerald-700',
                                        default => 'border-brand-ink/10 bg-brand-sand/40 text-brand-moss',
                                    };
                                @endphp
                                <tr class="transition-colors hover:bg-brand-sand/15">
                                    <td class="{{ $td }} text-brand-ink" title="{{ $event['created_at'] ?? '' }}">
                                        {{ ! empty($event['created_at']) ? \Illuminate\Support\Carbon::parse($event['created_at'])->diffForHumans() : '—' }}
                                    </td>
                                    <td class="{{ $td }} text-brand-moss">{{ str_replace('_', ' ', $event['trigger'] ?? 'manual') }}</td>
                                    <td class="{{ $td }}">
                                        <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-2xs font-semibold uppercase tracking-wide {{ $statusClasses }}">
                                            {{ $eventStatus }}
                                        </span>
                                        @if (! empty($event['error_message']))
                                            <p class="mt-1 text-xs text-red-700">{{ $event['error_message'] }}</p>
                                        @endif
                                    </td>
                                    <td class="{{ $td }} text-right font-mono tabular-nums text-brand-moss">{{ $event['change_count'] ?? 0 }}</td>
                                    <td class="{{ $td }} text-right font-mono tabular-nums text-brand-ink">${{ number_format(($event['monthly_total_cents'] ?? 0) / 100, 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </section>

            {{-- Invoice history --}}
            <section class="border-b border-brand-ink/10 last:border-b-0">
                <x-workspace-panel-head
                    dense
                    class="border-b border-brand-ink/10"
                    icon="heroicon-o-document"
                    :title="__('Invoice history')"
                    :note="__('Recent Stripe invoices — up to 24 months of paid charges.')"
                >
                    <x-slot:actions>
                        <a href="{{ route('billing.invoices', $organization) }}" wire:navigate class="shrink-0 text-xs font-medium text-brand-sage hover:text-brand-ink">{{ __('All invoices') }} →</a>
                    </x-slot:actions>
                </x-workspace-panel-head>

                @if ($invoiceHistory === [])
                    <div class="px-3 py-6 text-center sm:px-4">
                        <p class="text-sm text-brand-moss">{{ __('No invoices yet — subscribe and complete checkout to see history here.') }}</p>
                    </div>
                @else
                    <div class="px-3 py-3 sm:px-4">
                        <div class="flex h-16 items-end gap-2" aria-hidden="true">
                            @foreach (array_slice($invoiceHistory, 0, 12) as $invoice)
                                <div class="min-w-0 flex-1">
                                    <div
                                        class="w-full rounded-t {{ ($invoice['paid'] ?? false) ? 'bg-brand-forest/70' : 'bg-brand-gold/60' }}"
                                        style="height: {{ max(8, round(($invoice['total_cents'] / $maxInvoiceCents) * 100)) }}%"
                                        title="{{ $invoice['date'] ?? '' }} — ${{ number_format($invoice['total_cents'] / 100, 2) }}"
                                    ></div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                    <table class="w-full border-t border-brand-ink/10 text-sm">
                        <thead class="bg-brand-sand/35 text-2xs font-semibold uppercase tracking-wide text-brand-moss">
                            <tr>
                                <th class="{{ $th }} text-left">{{ __('Date') }}</th>
                                <th class="{{ $th }} text-left">{{ __('Number') }}</th>
                                <th class="{{ $th }} text-left">{{ __('Status') }}</th>
                                <th class="{{ $th }} text-right">{{ __('Total') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-brand-ink/5">
                            @foreach (array_slice($invoiceHistory, 0, 12) as $invoice)
                                <tr class="transition-colors hover:bg-brand-sand/15">
                                    <td class="{{ $td }} text-brand-ink">
                                        {{ $invoice['date'] !== '' ? \Illuminate\Support\Carbon::parse($invoice['date'])->toFormattedDateString() : '—' }}
                                    </td>
                                    <td class="{{ $td }} font-mono text-xs text-brand-moss">{{ $invoice['number'] ?? '—' }}</td>
                                    <td class="{{ $td }} capitalize text-brand-moss">{{ $invoice['status'] ?? '—' }}</td>
                                    <td class="{{ $td }} text-right font-mono font-semibold tabular-nums text-brand-ink">${{ number_format($invoice['total_cents'] / 100, 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </section>
        </x-organization-shell>
    </div>
</div>
