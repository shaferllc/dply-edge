@php
    $billing = $edgeSiteBilling ?? null;
    $showBilling = ($edgeUsageBillingEnabled ?? false) || (($edgeManagedFee ?? 0) > 0) || $billing !== null;
@endphp

@if ($showBilling)
    <section class="dply-card overflow-hidden">
        <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
            <x-icon-badge>
                <x-heroicon-o-currency-dollar class="h-5 w-5" aria-hidden="true" />
            </x-icon-badge>
            <div class="min-w-0">
                <p class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Billing') }}</p>
                <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Billing & usage') }}</h3>
                <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                    @if ($billing !== null)
                        {{ __('Est. :total / mo · :requests requests MTD', [
                            'total' => '$'.number_format(($billing['total_cents'] ?? 0) / 100, 2),
                            'requests' => number_format($billing['requests'] ?? 0),
                        ]) }}
                    @else
                        {{ __('Usage this month. Extra sites and SSR sites are billed on the organization plan.') }}
                    @endif
                </p>
            </div>
            <a
                href="{{ route('sites.show', ['server' => $server ?? $site->server, 'site' => $site, 'section' => 'billing']) }}"
                wire:navigate
                class="inline-flex shrink-0 items-center gap-1 text-sm font-medium text-brand-forest hover:underline dark:text-brand-sage"
            >
                {{ __('View stats') }}
                <x-heroicon-o-arrow-right class="h-4 w-4" />
            </a>
        </div>
    </section>
@endif
