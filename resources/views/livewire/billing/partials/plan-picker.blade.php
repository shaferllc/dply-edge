{{--
  Callers: livewire.billing.show (@include), right after payment-method.
  Actions: Show::subscribeTier (no subscription) and Show::changeTier.
  Tiers come from config subscription.standard.tiers (ruling r-zdescb7y05vp1bxx).
--}}
@php
    $tiers = collect(config('subscription.standard.tiers'))->only(['free', 'pro', 'team']);
    $current = $this->organization->billingTier();
    $hasSubscription = (bool) $this->subscription;
    $extraSite = '$'.number_format(((int) config('subscription.standard.edge_cents', 200)) / 100, 0);
    $ssrSite = '$'.number_format(((int) config('subscription.standard.edge_ssr_cents', 700)) / 100, 0);
    $num = fn (?int $n) => $n === null ? __('Unlimited') : number_format($n);
@endphp
<section id="plans" class="border-b border-brand-ink/10">
    <x-workspace-panel-head
        dense
        icon="heroicon-o-squares-2x2"
        :title="__('Plan')"
        :note="__('Billed monthly. Usage over your plan’s allowance is added to the same invoice.')"
    />
    <div class="grid gap-3 px-3 py-3 sm:px-4 md:grid-cols-3">
        @foreach ($tiers as $key => $tier)
            @php $isCurrent = $key === $current; @endphp
            <div @class([
                'flex flex-col rounded-2xl border bg-white p-4 dark:bg-zinc-900',
                'border-brand-sage ring-1 ring-brand-sage' => $isCurrent,
                'border-brand-ink/10' => ! $isCurrent,
            ])>
                <div class="flex items-baseline justify-between gap-2">
                    <h3 class="text-base font-semibold text-brand-ink">{{ $tier['label'] }}</h3>
                    @if ($isCurrent)
                        <span class="rounded-full bg-brand-sage/15 px-2 py-0.5 text-2xs font-semibold uppercase tracking-wide text-brand-sage">{{ __('Current') }}</span>
                    @endif
                </div>
                <p class="mt-1">
                    <span class="font-mono text-2xl font-bold text-brand-ink">${{ number_format($tier['price_cents'] / 100, 0) }}</span>
                    <span class="text-sm text-brand-moss">{{ __('/mo') }}</span>
                </p>
                <ul class="mt-3 flex-1 space-y-1.5 text-sm text-brand-moss">
                    <li>{{ $tier['sites'] === null ? __('Unlimited sites') : trans_choice(':count site|:count sites', (int) $tier['sites'], ['count' => $num($tier['sites'])]) }}@if ($key !== 'free'){{ __(', then :price each', ['price' => $extraSite]) }}@endif</li>
                    <li>{{ $tier['ssr'] ? __('SSR sites :price each', ['price' => $ssrSite]) : __('Static and hybrid sites only') }}</li>
                    <li>
                        {{ $tier['seats'] === null ? __('Unlimited seats') : trans_choice(':count seat|:count seats', (int) $tier['seats'], ['count' => $num($tier['seats'])]) }}@if ($tier['extra_seat_cents']){{ __(', then $:price each', ['price' => number_format($tier['extra_seat_cents'] / 100, 0)]) }}@endif
                    </li>
                    <li>{{ __(':minutes build minutes · :concurrent concurrent · :timeout-min timeout', ['minutes' => $num($tier['build_minutes']), 'concurrent' => $tier['concurrent_builds'], 'timeout' => $tier['build_timeout_minutes']]) }}</li>
                    <li>{{ __(':requests requests · :egress GB egress', ['requests' => $tier['requests'] !== null && $tier['requests'] >= 1_000_000 ? ($tier['requests'] / 1_000_000).'M' : $num($tier['requests']), 'egress' => $tier['egress_gb'] === null ? __('Unlimited') : $num($tier['egress_gb'])]) }}</li>
                    <li>{{ __(':n custom domains · :queues managed queues', ['n' => $num($tier['custom_domains_per_site']), 'queues' => $num($tier['queues'] ?? null)]) }}</li>
                    @if (($tier['spending_limit_cents'] ?? null) !== null)
                        <li>{{ __('$ :amount usage credit, then apps pause', ['amount' => number_format(((int) $tier['spending_limit_cents']) / 100, 0)]) }}</li>
                    @endif
                    <li>{{ $tier['containers'] ? __('Container apps with :credit compute included. Sleeps when idle.', ['credit' => '$'.number_format(($tier['compute_credit_cents'] ?? 0) / 100, 0)]) : __('No container apps') }}</li>
                    @if ($tier['audit_log'])
                        <li>{{ __('Audit log') }}</li>
                    @endif
                </ul>
                <div class="mt-4">
                    @if ($isCurrent)
                        <p class="text-xs text-brand-mist">{{ $key === 'free' ? __('No card needed.') : __('Your current plan.') }}</p>
                    @elseif ($key === 'free')
                        <p class="text-xs text-brand-mist">{{ __('Cancel your subscription below to return to Free.') }}</p>
                    @elseif (! $hasSubscription)
                        <x-primary-button type="button" class="w-full justify-center" wire:click="subscribeTier('{{ $key }}')" wire:loading.attr="disabled" wire:target="subscribeTier" @disabled(! $this->standardPricingAvailable)>
                            {{ __('Choose :plan', ['plan' => $tier['label']]) }}
                        </x-primary-button>
                    @else
                        <x-secondary-button type="button" class="w-full justify-center" wire:click="changeTier('{{ $key }}')" wire:confirm="{{ __('Switch to :plan? The prorated difference is invoiced now.', ['plan' => $tier['label']]) }}" wire:loading.attr="disabled" wire:target="changeTier">
                            {{ __('Switch to :plan', ['plan' => $tier['label']]) }}
                        </x-secondary-button>
                    @endif
                </div>
            </div>
        @endforeach
    </div>
</section>
