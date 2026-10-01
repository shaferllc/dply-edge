{{-- Limits tab: spending cap (Show::saveSpendingCap), usage alert (Show::saveUsageAlert) and the AI / browser / vector search cap (Show::saveMeteredCap). --}}
@if ($this->canManageBilling)
    @php
        $alertCents = \App\Modules\Billing\Services\UsageAlerts::limitCents($this->organization, $this->billingState);
        $metered = \App\Modules\Edge\Support\EdgeMeter::spent($this->organization);
        $credit = (int) ($this->organization->tierAllowances()['usage_credit_cents'] ?? 0);
        // Only priced when a cap is set: status() reads every cost table.
        $spend = $this->organization->spending_cap_cents !== null && ! $this->organization->onTrialPlan()
            ? app(\App\Modules\Billing\Services\StarterUsageBudget::class)->status($this->organization) : null;
        $input = 'dply-input mt-0 w-32 py-1.5 tabular-nums';
        $save = 'inline-flex h-8 items-center rounded-lg bg-brand-ink px-3 text-xs font-semibold text-brand-cream transition-colors hover:bg-brand-forest disabled:opacity-70';
    @endphp
    <section class="dply-card overflow-hidden p-0">
        <form wire:submit="saveSpendingCap" class="px-5 py-4 sm:px-6">
            <label for="spending_cap_dollars" class="text-2xs font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Spending cap') }}</label>
            <div class="mt-2 flex items-center gap-2">
                <span class="text-sm text-brand-moss">$</span>
                <input id="spending_cap_dollars" type="number" min="0" step="1" inputmode="decimal" wire:model="spending_cap_dollars"
                       placeholder="{{ __('No cap') }}" class="{{ $input }}" />
                <button type="submit" wire:loading.attr="disabled" wire:target="saveSpendingCap" class="{{ $save }}">{{ __('Save') }}</button>
            </div>
            @error('spending_cap_dollars') <p class="mt-1 text-xs text-brand-rust">{{ $message }}</p> @enderror
            <p class="mt-2 text-xs text-brand-moss">{{ __('The most you’ll pay each period for usage past your plan’s :credit included credit. 0 means never more than the plan fee. Past it, your sites pause until the next period or until you raise the cap. Checked every few minutes, so a busy hour can go a little over. Leave empty for no cap.', ['credit' => '$'.number_format($credit / 100, 0)]) }}
                @if ($spend !== null && $spend['limit_cents'] !== null)
                    {{ __(':used of :limit used this period.', ['used' => '$'.number_format($spend['used_cents'] / 100, 2), 'limit' => '$'.number_format($spend['limit_cents'] / 100, 2)]) }}
                @endif
            </p>
        </form>
        <form wire:submit="saveUsageAlert" class="border-t border-brand-ink/10 px-5 py-4 sm:px-6">
            <label for="usage_alert_dollars" class="text-2xs font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Usage alert') }}</label>
            <div class="mt-2 flex items-center gap-2">
                <span class="text-sm text-brand-moss">$</span>
                <input id="usage_alert_dollars" type="number" min="1" step="1" inputmode="decimal" wire:model="usage_alert_dollars"
                       placeholder="{{ number_format($alertCents / 100, 0, '.', '') }}" class="{{ $input }}" />
                <button type="submit" wire:loading.attr="disabled" wire:target="saveUsageAlert" class="{{ $save }}">{{ __('Save') }}</button>
            </div>
            @error('usage_alert_dollars') <p class="mt-1 text-xs text-brand-rust">{{ $message }}</p> @enderror
            <p class="mt-2 text-xs text-brand-moss">{{ __('Owners are emailed when usage past the included credit reaches 50%, 80% and 100% of this each period. Leave empty for twice the plan price. Nothing is paused.') }}</p>
        </form>
        <form wire:submit="saveMeteredCap" class="border-t border-brand-ink/10 px-5 py-4 sm:px-6">
            <label for="metered_cap_dollars" class="text-2xs font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('AI, browser and vector search limit') }}</label>
            <div class="mt-2 flex items-center gap-2">
                <span class="text-sm text-brand-moss">$</span>
                <input id="metered_cap_dollars" type="number" min="0" step="1" inputmode="decimal" wire:model="metered_cap_dollars"
                       placeholder="{{ number_format(config('edge.metered_services.default_cap_cents', 2500) / 100, 0, '.', '') }}" class="{{ $input }}" />
                <button type="submit" wire:loading.attr="disabled" wire:target="saveMeteredCap" class="{{ $save }}">{{ __('Save') }}</button>
            </div>
            @error('metered_cap_dollars') <p class="mt-1 text-xs text-brand-rust">{{ $message }}</p> @enderror
            <p class="mt-2 text-xs text-brand-moss">{{ __(':used used this period. Past the limit, calls to AI, browser rendering and vector search are refused until the next period. Owners are emailed at 80% and 100%. Leave empty for :default; 0 removes the limit (up to :ceiling).', [
                'used' => '$'.number_format($metered['cents'] / 100, 2),
                'default' => '$'.number_format(config('edge.metered_services.default_cap_cents', 2500) / 100, 0),
                'ceiling' => '$'.number_format(config('edge.metered_services.ceiling_cents', 100000) / 100, 0),
            ]) }}</p>
        </form>
    </section>
@else
    <section class="dply-card px-5 py-4 text-sm text-brand-moss sm:px-6">
        {{ __('Usage alerts and the AI, browser and vector search limit can be set once the organization has a subscription.') }}
    </section>
@endif
