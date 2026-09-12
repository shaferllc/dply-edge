@php
    $state = $this->billingState;
    $monthlyDollars = $state->monthlyTotalCents / 100;
    $yearlyDollars = $this->yearlyTotalCents / 100;
    $interval = $this->subscriptionInterval;
    $edgeSiteCount = $state->edgeCount;

    // Colors for the stacked-breakdown bar: per-site fees, then metered usage.
    $tierBarColors = [
        'edge' => 'bg-emerald-500/70',
        'edge_usage' => 'bg-brand-sage/50',
    ];

    $totalCents = max(1, $state->monthlyTotalCents);
    $segments = [];
    if ($state->edgeSubtotalCents > 0) {
        $segments[] = ['key' => 'edge', 'label' => __('Apps').' × '.$state->edgeCount, 'cents' => $state->edgeSubtotalCents];
    }
    if ($state->edgeUsageSubtotalCents > 0) {
        $segments[] = [
            'key' => 'edge_usage',
            'label' => __('Apps usage'),
            'cents' => $state->edgeUsageSubtotalCents,
        ];
    }
@endphp

<section class="border-b border-brand-ink/10">
    <div class="grid gap-5 px-3 py-3 sm:px-4 lg:grid-cols-12">
        <div class="lg:col-span-5">
            <p class="text-xs font-semibold uppercase tracking-wider text-brand-gold/90">
                @if ($this->subscription)
                    {{ __('Current billing') }}
                @else
                    {{ __('What you\'d pay today') }}
                @endif
            </p>
            <h2 class="mt-1 text-lg font-bold text-brand-ink">{{ __('Your bill') }}</h2>
            <p class="mt-2 text-sm text-brand-moss leading-relaxed">
                @if ($this->subscription && $this->nextInvoiceAt)
                    {{ __('Next invoice :date', ['date' => $this->nextInvoiceAt->toFormattedDateString()]) }}.
                    {{ __('Bill updates automatically when your live sites change.') }}
                @else
                    {{ __('Based on your live Edge sites. Subscribe to lock this in.') }}
                @endif
            </p>

            <div class="mt-6">
                <div class="flex items-baseline gap-2">
                    <span class="text-4xl font-semibold tracking-tight text-brand-ink">
                        ${{ number_format($interval === 'year' ? $yearlyDollars : $monthlyDollars, 2) }}
                    </span>
                    <span class="text-sm text-brand-moss">{{ $interval === 'year' ? __('/yr') : __('/mo') }}</span>
                </div>
                @if ($interval === 'year')
                    <p class="mt-1 text-sm text-brand-moss">${{ number_format($yearlyDollars / 12, 2) }} {{ __('/mo effective — 20% off monthly') }}</p>
                @else
                    <p class="mt-1 text-sm text-brand-moss">${{ number_format($monthlyDollars * 12 * 0.8, 2) }} {{ __('/yr on annual billing (save 20%)') }}</p>
                @endif

                {{-- Interval switch — only for an existing subscriber. Opens
                     a confirmation modal (the swap invoices immediately). --}}
                @if ($this->subscription)
                    <div class="mt-3">
                        <button type="button" x-on:click="$dispatch('open-modal', 'switch-interval')"
                                class="text-sm font-semibold text-brand-sage hover:text-brand-ink underline underline-offset-2">
                            @if ($interval === 'year')
                                {{ __('Switch to monthly billing') }}
                            @else
                                {{ __('Switch to annual billing — save 20%') }}
                            @endif
                        </button>
                    </div>
                @endif

                {{-- Usage run-rate — derived from the monthly total, no history. --}}
                <div class="mt-4 rounded-xl border border-brand-ink/10 bg-brand-cream/35 px-4 py-3">
                    <p class="text-sm text-brand-ink">
                        {{ trans_choice('{0} No live Edge sites yet|{1} :count live Edge site|[2,*] :count live Edge sites', $edgeSiteCount, ['count' => $edgeSiteCount]) }}.
                    </p>
                    <p class="mt-0.5 text-xs text-brand-moss">{{ __(':static/mo per static or hybrid site, :ssr/mo per Worker SSR site, plus delivery usage beyond each site\'s allowance.', ['static' => '$'.number_format(((int) config('subscription.standard.edge_cents', 200)) / 100, 2), 'ssr' => '$'.number_format(((int) config('subscription.standard.edge_ssr_cents', 700)) / 100, 2)]) }}</p>
                </div>

                {{-- Primary Subscribe CTA — the most important action on the
                     page for an unsubscribed org, so it sits right under the
                     price rather than buried in the Payment method section. --}}
                @if (! $this->subscription && $this->standardPricingAvailable)
                    <div class="mt-5">
                        <div class="flex flex-col sm:flex-row gap-2">
                            <button type="button" wire:click="subscribeStandard('month')"
                                    class="inline-flex items-center justify-center rounded-lg bg-brand-ink px-3 py-2 text-xs font-semibold text-brand-cream shadow-md hover:bg-brand-forest transition-colors">
                                {{ __('Subscribe — :amount/mo', ['amount' => '$'.number_format($monthlyDollars, 2)]) }}
                            </button>
                            <button type="button" wire:click="subscribeStandard('year')"
                                    class="inline-flex items-center justify-center rounded-lg border-2 border-brand-ink/15 bg-white px-3 py-2 text-xs font-semibold text-brand-ink hover:border-brand-gold/40 transition-colors">
                                {{ __('Pay yearly — save 20%') }}
                            </button>
                        </div>
                        <p class="mt-2 text-xs text-brand-moss">{{ __('Secure checkout via Stripe. Cancel anytime.') }}</p>
                    </div>
                @elseif (! $this->subscription && ! $this->standardPricingAvailable)
                    <p class="mt-5 text-sm text-brand-moss">{{ __('Billing isn\'t configured for this install yet.') }}</p>
                @endif
            </div>
        </div>

        <div class="lg:col-span-7 space-y-4">
            {{-- Stacked breakdown bar — how the total decomposes across
                 per-site fees and metered usage. --}}
            <div>
                <div class="flex h-3 w-full rounded-full overflow-hidden bg-brand-ink/5">
                    @foreach ($segments as $segment)
                        @php $pct = max(2, ($segment['cents'] / $totalCents) * 100); @endphp
                        <div class="{{ $tierBarColors[$segment['key']] ?? 'bg-brand-ink/30' }}"
                             style="width: {{ $pct }}%"
                             title="{{ $segment['label'] }}: ${{ number_format($segment['cents'] / 100, 2) }}"></div>
                    @endforeach
                </div>
                <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-brand-moss">
                    @foreach ($segments as $segment)
                        <span class="inline-flex items-center gap-1.5">
                            <span class="inline-block w-2 h-2 rounded-sm {{ $tierBarColors[$segment['key']] ?? 'bg-brand-ink/30' }}"></span>
                            <span>{{ $segment['label'] }} · ${{ number_format($segment['cents'] / 100, 2) }}</span>
                        </span>
                    @endforeach
                </div>
            </div>

            <div class="rounded-xl border border-brand-ink/10 bg-brand-cream/50 overflow-hidden">
                <div class="px-3 py-2 border-b border-brand-ink/10 bg-white/60">
                    <p class="text-xs font-semibold uppercase tracking-wider text-brand-ink/70">
                        {{ __('Breakdown') }} — {{ trans_choice('{0} no billable sites|{1} :count site|[2,*] :count sites', $edgeSiteCount, ['count' => $edgeSiteCount]) }}
                    </p>
                </div>
                <ul class="divide-y divide-brand-ink/5 text-sm">
                    @foreach ($this->tierLineItems as $item)
                        <li class="flex items-center justify-between px-3 py-1.5">
                            <div class="min-w-0">
                                <div class="flex items-baseline gap-3">
                                    <span class="font-medium text-brand-ink">{{ $item['label'] }}</span>
                                    @if ($item['quantity'] > 1)
                                        <span class="text-xs text-brand-moss">× {{ $item['quantity'] }}</span>
                                    @endif
                                </div>
                                @if (! empty($item['detail']))
                                    <p class="mt-0.5 text-xs text-brand-moss truncate">{{ $item['detail'] }}</p>
                                @endif
                            </div>
                            <div class="flex items-baseline gap-2 text-brand-ink tabular-nums">
                                @if ($item['quantity'] > 1)
                                    <span class="text-xs text-brand-moss">${{ number_format($item['unit_cents'] / 100, 2) }} {{ __('each') }}</span>
                                @endif
                                <span class="font-semibold">${{ number_format($item['line_cents'] / 100, 2) }}</span>
                            </div>
                        </li>
                    @endforeach
                </ul>
                <div class="flex items-center justify-between px-3 py-2 border-t border-brand-ink/10 bg-white/60 text-sm">
                    <span class="font-semibold text-brand-ink">{{ __('Total') }}</span>
                    <span class="font-bold text-brand-ink tabular-nums">${{ number_format($monthlyDollars, 2) }} <span class="text-xs font-normal text-brand-moss">{{ __('/mo') }}</span></span>
                </div>
            </div>
        </div>
    </div>
</section>
