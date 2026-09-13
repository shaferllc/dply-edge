{{--
  Callers: livewire.billing.show only (@include).
  API: reads $this->costForecast (projected_month_end_cents, delta_vs_thirty_days_cents).
  No schema change.
  User: "we can probably merge …/billing and …/billing/analytics and …/invoices
  to simplify billing, it shlu,ld be real easy to read"
--}}
@php
    $forecast = $this->costForecast;
    $projectedMonthEndCents = (int) ($forecast['projected_month_end_cents'] ?? 0);
    $deltaVsThirtyDays = $forecast['delta_vs_thirty_days_cents'] ?? null;
@endphp
<section class="border-b border-brand-ink/10">
    <x-workspace-panel-head
        dense
        icon="heroicon-o-arrow-trending-up"
        :title="__('Cost forecast')"
        :note="__('What this organization is on track to be charged this month.')"
    />
    <dl class="grid gap-px bg-brand-ink/5 sm:grid-cols-2">
        <div class="bg-white px-3 py-2.5 sm:px-4">
            <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Projected this month') }}</dt>
            <dd class="mt-0.5 font-mono text-base font-semibold tabular-nums text-brand-ink">${{ number_format($projectedMonthEndCents / 100, 2) }}</dd>
            <p class="mt-0.5 text-xs text-brand-moss">{{ __('Per-site fees, plus Edge usage so far extrapolated to month end') }}</p>
        </div>
        <div class="bg-white px-3 py-2.5 sm:px-4">
            <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Δ vs 30 days') }}</dt>
            @if (is_int($deltaVsThirtyDays))
                <dd @class([
                    'mt-0.5 font-mono text-base font-semibold tabular-nums',
                    'text-brand-rust' => $deltaVsThirtyDays >= 0,
                    'text-brand-forest' => $deltaVsThirtyDays < 0,
                ])>
                    {{ $deltaVsThirtyDays >= 0 ? '+' : '-' }}${{ number_format(abs($deltaVsThirtyDays) / 100, 2) }}
                </dd>
                <p class="mt-0.5 text-xs text-brand-moss">{{ __('Change in your estimated monthly cost') }}</p>
            @else
                <dd class="mt-0.5 text-sm font-semibold text-brand-ink">{{ __('Not enough history') }}</dd>
                <p class="mt-0.5 text-xs text-brand-moss">{{ __('Appears once snapshots accumulate') }}</p>
            @endif
        </div>
    </dl>
</section>
