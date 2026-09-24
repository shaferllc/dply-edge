@php
    $billing = $edgeSiteBilling ?? null;
    $showBilling = ($edgeUsageBillingEnabled ?? false) || (($edgeManagedFee ?? 0) > 0) || $billing !== null;
    $maxRequests = max(1, collect($billing['daily'] ?? [])->max('requests') ?? 1);
    $maxEgress = max(1, collect($billing['daily'] ?? [])->max('bytes_egress') ?? 1);
    $usageDetail = is_array($billing['usage_detail'] ?? null) ? $billing['usage_detail'] : [];
@endphp

@if (! $showBilling)
    <p class="px-5 py-10 text-center text-sm text-brand-moss sm:px-6">{{ __('Billing details for this Edge site are not available yet.') }}</p>
@else
    <div>
        @include('livewire.sites.partials.edge.guardrail-card')

        @if ($billing !== null)
            <section class="border-b border-brand-ink/10 px-5 py-4 sm:px-6">
                <p class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('This site') }}</p>
                <dl class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <div>
                        <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Est. / mo') }}</dt>
                        <dd class="mt-1 text-xl font-semibold tabular-nums text-brand-ink">${{ number_format(($billing['total_cents'] ?? 0) / 100, 2) }}</dd>
                    </div>
                    <div>
                        <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ $billing['platform_label'] ?? __('Site fee') }}</dt>
                        <dd class="mt-1 text-xl font-semibold tabular-nums text-brand-ink">
                            @if (($billing['platform_cents'] ?? 0) > 0)
                                ${{ number_format(($billing['platform_cents'] ?? 0) / 100, 2) }}
                            @else
                                {{ __('Included') }}
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Usage MTD') }}</dt>
                        <dd class="mt-1 text-xl font-semibold tabular-nums text-brand-ink">${{ number_format(($billing['usage_cents'] ?? 0) / 100, 2) }}</dd>
                    </div>
                    <div>
                        <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Requests MTD') }}</dt>
                        <dd class="mt-1 text-xl font-semibold tabular-nums text-brand-ink">{{ number_format($billing['requests'] ?? 0) }}</dd>
                    </div>
                </dl>

                <dl class="mt-4 grid gap-2 border-t border-brand-ink/8 pt-3 text-sm sm:grid-cols-2">
                    <div class="flex justify-between gap-2">
                        <dt class="text-brand-moss">{{ __('Egress MTD') }}</dt>
                        <dd class="tabular-nums font-medium text-brand-ink">{{ number_format(($billing['bytes_egress'] ?? 0) / (1024 ** 3), 2) }} GB</dd>
                    </div>
                    @if (($billing['r2_storage_bytes'] ?? 0) > 0)
                        <div class="flex justify-between gap-2">
                            <dt class="text-brand-moss">{{ __('Storage') }}</dt>
                            <dd class="tabular-nums font-medium text-brand-ink">{{ number_format(($billing['r2_storage_bytes'] ?? 0) / (1024 ** 3), 2) }} GB</dd>
                        </div>
                    @endif
                </dl>

                @if (($billing['usage_billing_enabled'] ?? false) && ! empty($usageDetail['included_requests']))
                    <p class="mt-3 text-xs text-brand-mist">
                        {{ __('Includes :requests requests and :egress GB egress before overage.', [
                            'requests' => number_format((int) ($usageDetail['included_requests'] ?? 0)),
                            'egress' => number_format(((int) ($usageDetail['included_bytes_egress'] ?? 0)) / (1024 ** 3), 1),
                        ]) }}
                    </p>
                @endif
            </section>

            @if (($billing['daily'] ?? []) !== [])
                @php
                    $billingDaily = $billing['daily'];
                    $billingLastIdx = count($billingDaily) - 1;
                    $billingMidIdx = (int) floor($billingLastIdx / 2);
                    $maxEgressMb = ($maxEgress / (1024 ** 2));
                @endphp
                <div class="grid border-b border-brand-ink/10 lg:grid-cols-2 lg:divide-x lg:divide-brand-ink/10">
                    <section class="px-5 py-4 sm:px-6">
                        <div class="flex items-baseline justify-between gap-2">
                            <p class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Daily requests') }}</p>
                            <span class="font-mono text-2xs text-brand-mist">{{ __('max :n', ['n' => number_format((int) $maxRequests)]) }}</span>
                        </div>
                        <div class="mt-3 flex h-20 items-end gap-0.5">
                            @foreach ($billingDaily as $day)
                                <div class="group relative flex h-full min-w-0 flex-1 cursor-help items-end">
                                    <div
                                        class="w-full rounded-t bg-brand-sage/70 transition-colors group-hover:bg-brand-forest"
                                        style="height: {{ max(4, round(($day['requests'] / $maxRequests) * 100)) }}%"
                                    ></div>
                                    <div class="pointer-events-none absolute bottom-full left-1/2 z-20 mb-1 hidden -translate-x-1/2 whitespace-nowrap rounded bg-brand-ink px-2 py-1 text-xs font-medium text-white shadow-lg group-hover:block">
                                        <span class="font-semibold">{{ $day['label'] ?? '' }}</span> · {{ number_format($day['requests'] ?? 0) }}
                                    </div>
                                </div>
                            @endforeach
                        </div>
                        <div class="mt-2 flex justify-between text-2xs text-brand-mist">
                            <span>{{ $billingDaily[0]['label'] ?? '' }}</span>
                            @if ($billingMidIdx > 0 && $billingMidIdx < $billingLastIdx)
                                <span>{{ $billingDaily[$billingMidIdx]['label'] ?? '' }}</span>
                            @endif
                            @if ($billingLastIdx > 0)
                                <span>{{ $billingDaily[$billingLastIdx]['label'] ?? '' }}</span>
                            @endif
                        </div>
                    </section>

                    <section class="border-t border-brand-ink/10 px-5 py-4 sm:px-6 lg:border-t-0">
                        <div class="flex items-baseline justify-between gap-2">
                            <p class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Daily egress') }}</p>
                            <span class="font-mono text-2xs text-brand-mist">{{ __('max :n MB', ['n' => number_format($maxEgressMb, 1)]) }}</span>
                        </div>
                        <div class="mt-3 flex h-20 items-end gap-0.5">
                            @foreach ($billingDaily as $day)
                                <div class="group relative flex h-full min-w-0 flex-1 cursor-help items-end">
                                    <div
                                        class="w-full rounded-t bg-sky-500/70 transition-colors group-hover:bg-sky-600"
                                        style="height: {{ max(4, round(($day['bytes_egress'] / $maxEgress) * 100)) }}%"
                                    ></div>
                                    <div class="pointer-events-none absolute bottom-full left-1/2 z-20 mb-1 hidden -translate-x-1/2 whitespace-nowrap rounded bg-brand-ink px-2 py-1 text-xs font-medium text-white shadow-lg group-hover:block">
                                        <span class="font-semibold">{{ $day['label'] ?? '' }}</span> · {{ number_format(($day['bytes_egress'] ?? 0) / (1024 ** 2), 1) }} MB
                                    </div>
                                </div>
                            @endforeach
                        </div>
                        <div class="mt-2 flex justify-between text-2xs text-brand-mist">
                            <span>{{ $billingDaily[0]['label'] ?? '' }}</span>
                            @if ($billingMidIdx > 0 && $billingMidIdx < $billingLastIdx)
                                <span>{{ $billingDaily[$billingMidIdx]['label'] ?? '' }}</span>
                            @endif
                            @if ($billingLastIdx > 0)
                                <span>{{ $billingDaily[$billingLastIdx]['label'] ?? '' }}</span>
                            @endif
                        </div>
                    </section>
                </div>
            @elseif (! ($billing['has_snapshots'] ?? false))
                <p class="border-b border-brand-ink/10 px-5 py-6 text-sm text-brand-moss sm:px-6">
                    {{ __('No daily snapshots yet this month. Stats appear after nightly collection.') }}
                </p>
            @endif
        @else
            <section class="border-b border-brand-ink/10 px-5 py-4 sm:px-6">
                <p class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Pricing') }}</p>
                <p class="mt-2 text-sm text-brand-ink">
                    {{ __('Sites on your plan are included. On Pro and Team, sites past that count are :extra/mo and Worker SSR sites are :ssr/mo. Usage past the plan is metered.', [
                        'extra' => '$'.number_format(((int) config('subscription.standard.edge_cents', 200)) / 100, 2),
                        'ssr' => '$'.number_format(((int) config('subscription.standard.edge_ssr_cents', 700)) / 100, 2),
                    ]) }}
                </p>
                @if ($edgeUsageBillingEnabled ?? false)
                    <p class="mt-2 text-sm text-brand-moss">{{ __('Usage beyond the plan allowance is metered on requests and egress.') }}</p>
                    <ul class="mt-2 space-y-1 text-xs text-brand-mist">
                        @if (($edgeUsageRates['requests_per_million'] ?? 0) > 0)
                            <li>{{ __(':price / million requests', ['price' => '$'.number_format($edgeUsageRates['requests_per_million'], 2)]) }}</li>
                        @endif
                        @if (($edgeUsageRates['egress_per_gb'] ?? 0) > 0)
                            <li>{{ __(':price / GB egress', ['price' => '$'.number_format($edgeUsageRates['egress_per_gb'], 2)]) }}</li>
                        @endif
                    </ul>
                @endif
            </section>
        @endif

        <div class="flex flex-wrap items-center justify-between gap-3 px-5 py-3.5 sm:px-6">
            {{--
              Callers: Edge workspace Billing tab (sites/partials/edge/billing).
              Points at merged org billing.show — analytics + invoices live there.
              User: "we can probably merge …/billing and …/billing/analytics and
              …/invoices to simplify billing, it shlu,ld be real easy to read"
            --}}
            <p class="text-xs text-brand-moss">{{ __('Compare all Edge sites and invoices for the workspace.') }}</p>
            <a
                href="{{ route('billing.show', $site->organization_id) }}"
                wire:navigate
                class="inline-flex items-center gap-1 text-xs font-medium text-brand-sage hover:underline"
            >
                {{ __('Open org billing') }}
                <x-heroicon-o-arrow-right class="h-3.5 w-3.5" />
            </a>
        </div>
    </div>
@endif
