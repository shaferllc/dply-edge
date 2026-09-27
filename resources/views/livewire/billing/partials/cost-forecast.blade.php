{{--
  Callers: livewire.billing.show only (@include).
  API: reads $this->costForecast (projected_month_end_cents, delta_vs_thirty_days_cents,
  period_start/end), $this->billingState->usageLines(); saves usage_alert_dollars.
  User: "we can probably merge …/billing and …/billing/analytics and …/invoices
  to simplify billing, it shlu,ld be real easy to read"
--}}
@php
    $forecast = $this->costForecast;
    $projectedMonthEndCents = (int) ($forecast['projected_month_end_cents'] ?? 0);
    $deltaVsThirtyDays = $forecast['delta_vs_thirty_days_cents'] ?? null;
    $usageState = $this->billingState;
    $usageLines = $usageState->usageLines();
    $periodFrom = \Illuminate\Support\Carbon::parse((string) ($forecast['period_start'] ?? now()->startOfMonth()->toDateString()));
    $periodTo = \Illuminate\Support\Carbon::parse((string) ($forecast['period_end'] ?? now()->startOfMonth()->addMonth()->toDateString()));
    $alertCents = \App\Modules\Billing\Services\UsageAlerts::limitCents($this->organization, $usageState);
@endphp
<section class="border-b border-brand-ink/10">
    <x-workspace-panel-head
        dense
        icon="heroicon-o-arrow-trending-up"
        :title="__('Cost forecast')"
        :note="__('What this organization is on track to be charged this billing period (:from – :to).', ['from' => $periodFrom->format('M j'), 'to' => $periodTo->format('M j')])"
    />
    <dl class="grid gap-px bg-brand-ink/5 sm:grid-cols-2">
        <div class="bg-white px-3 py-2.5 sm:px-4">
            <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Projected this period') }}</dt>
            <dd class="mt-0.5 font-mono text-base font-semibold tabular-nums text-brand-ink">${{ number_format($projectedMonthEndCents / 100, 2) }}</dd>
            <p class="mt-0.5 text-xs text-brand-moss">{{ __('Plan fee, plus usage so far run out to the period end, less the included credit') }}</p>
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
    <div class="grid gap-px bg-brand-ink/5 sm:grid-cols-2">
        <div class="bg-white px-3 py-2.5 sm:px-4">
            <p class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Usage so far this period') }}</p>
            <p class="mt-0.5 font-mono text-base font-semibold tabular-nums text-brand-ink">${{ number_format(array_sum($usageLines) / 100, 2) }}</p>
            <p class="text-xs text-brand-ink tabular-nums">{{ __('Usage this period :usage · included credit :credit · estimated charge :charge', [
                'usage' => '$'.number_format(($forecast['usage_cents'] ?? 0) / 100, 2),
                'credit' => '$'.number_format(($forecast['credit_cents'] ?? 0) / 100, 2),
                'charge' => '$'.number_format(($forecast['estimated_charge_cents'] ?? 0) / 100, 2),
            ]) }}</p>
            @foreach ($usageLines as $key => $cents)
                <p class="text-xs text-brand-moss">{{ \App\Modules\Billing\Services\DesiredBillingState::usageLineLabel($key) }} · ${{ number_format($cents / 100, 2) }}</p>
            @endforeach
            <p class="mt-0.5 text-xs text-brand-moss">{{ __('Billed on the invoice after the period ends, for exactly this period, less your plan’s included credit.') }}</p>
        </div>
        @if ($this->canManageBilling)
            <form wire:submit="saveUsageAlert" class="bg-white px-3 py-2.5 sm:px-4">
                <label for="usage_alert_dollars" class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Usage alert') }}</label>
                <div class="mt-1 flex items-center gap-2">
                    <span class="text-sm text-brand-moss">$</span>
                    <input id="usage_alert_dollars" type="number" min="1" step="1" inputmode="decimal" wire:model="usage_alert_dollars"
                           placeholder="{{ number_format($alertCents / 100, 0, '.', '') }}"
                           class="w-28 rounded-md border border-brand-ink/15 px-2 py-1 text-sm tabular-nums" />
                    <button type="submit" class="text-sm font-semibold text-brand-sage hover:text-brand-ink">{{ __('Save') }}</button>
                </div>
                @error('usage_alert_dollars') <p class="mt-1 text-xs text-brand-rust">{{ $message }}</p> @enderror
                <p class="mt-0.5 text-xs text-brand-moss">{{ __('Owners are emailed when usage past the included credit reaches 50%, 80% and 100% of this each period. Leave empty for twice the plan price. Nothing is paused.') }}</p>
            </form>
        @endif
    </div>
</section>
