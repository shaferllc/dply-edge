@php
    $state = $this->billingState;
    $monthlyDollars = $state->monthlyTotalCents / 100;
    $edgeSiteCount = $state->edgeCount;

    $totalCents = max(1, $state->monthlyTotalCents);
    $segments = collect(app(\App\Modules\Billing\Services\BillingAnalytics::class)->categoryBreakdown($state));
    $tierBarColors = $segments->pluck('color', 'key')->all();
    $segments = $segments->all();
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
                    {{ __('Usage is billed after each period ends, less your plan’s included credit.') }}
                @else
                    {{ __('Based on your plan and usage so far. Choose a plan to start billing.') }}
                @endif
            </p>

            <div class="mt-6">
                <div class="flex items-baseline gap-2">
                    <span class="text-4xl font-semibold tracking-tight text-brand-ink">
                        ${{ number_format($monthlyDollars, 2) }}
                    </span>
                    <span class="text-sm text-brand-moss">{{ __('/mo') }}</span>
                </div>
                {{-- Usage vs the plan's included credit — the estimated charge. --}}
                <div class="mt-4 rounded-xl border border-brand-ink/10 bg-brand-cream/35 px-4 py-3">
                    <p class="text-sm text-brand-ink tabular-nums">
                        {{ __('Usage this period :usage · included credit :credit · estimated charge :charge', [
                            'usage' => '$'.number_format($state->usageLineCents() / 100, 2),
                            'credit' => '$'.number_format($state->creditAppliedCents() / 100, 2),
                            'charge' => '$'.number_format($state->monthlyTotalCents / 100, 2),
                        ]) }}
                    </p>
                    <p class="mt-0.5 text-xs text-brand-moss">{{ __(':plan plan: unlimited sites, :credit of usage included each period. :count live.', [
                        'plan' => $state->planLabel,
                        'credit' => '$'.number_format($state->usageCreditCents / 100, 0),
                        'count' => trans_choice('{0} No sites|{1} :count site|[2,*] :count sites', $edgeSiteCount, ['count' => $edgeSiteCount]),
                    ]) }}</p>
                </div>

                {{-- Card CTA is the payment-method strip at the top of the page. --}}
            </div>
        </div>

        <div class="lg:col-span-7 space-y-4">
            {{-- Stacked breakdown bar — plan, seats and usage after credit. --}}
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
                        {{ __('Breakdown') }}
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
                                <span class="font-semibold">{{ $item['line_cents'] < 0 ? '−' : '' }}${{ number_format(abs($item['line_cents']) / 100, 2) }}</span>
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
