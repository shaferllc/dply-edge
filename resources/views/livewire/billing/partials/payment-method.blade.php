{{--
  Callers: livewire.billing.show (@include), Payment & details tab.
  Actions: Show::portal (manage existing); new cards go through the plan picker
  (Plan tab, Show::subscribeTier).
--}}
@php
    $hasPaymentMethod = $this->paymentSummary !== 'No payment method';
    $canCheckout = ! $this->subscription && $this->standardPricingAvailable;
    $canPortal = $this->subscription && $this->organization->hasStripeId();
    $cardBrand = $this->organization->pm_type ? ucfirst((string) $this->organization->pm_type) : null;
    $cta = 'inline-flex h-10 items-center justify-center gap-2 rounded-lg bg-brand-ink px-4 text-sm font-semibold text-brand-cream transition-colors hover:bg-brand-forest disabled:opacity-70';
@endphp
<section id="payment-method" class="dply-card p-5 sm:p-6">
    <div class="flex flex-wrap items-center gap-4">
        <span @class([
            'inline-flex h-12 w-12 shrink-0 items-center justify-center rounded-xl ring-1',
            'bg-brand-sand/50 text-brand-ink ring-brand-ink/10' => $hasPaymentMethod,
            'bg-brand-ink text-brand-cream ring-brand-ink/20' => ! $hasPaymentMethod,
        ])>
            <x-heroicon-o-credit-card class="h-6 w-6" aria-hidden="true" />
        </span>
        <div class="min-w-0 flex-1">
            <p class="text-2xs font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Payment method') }}</p>
            @if ($hasPaymentMethod)
                <p class="mt-0.5 font-mono text-xl font-semibold tracking-tight text-brand-ink">{{ trim($cardBrand.' '.$this->paymentSummary) }}</p>
                <p class="mt-0.5 text-sm text-brand-moss">{{ __('Default card on file. Update it, add another, or change the billing address in Stripe.') }}</p>
            @else
                <p class="mt-0.5 text-xl font-semibold tracking-tight text-brand-ink">{{ __('Add a credit card') }}</p>
                <p class="mt-0.5 text-sm text-brand-moss">{{ __('Your plan and usage bill to this card. Stripe handles checkout — we never store the number here.') }}</p>
            @endif
        </div>
        <div class="flex shrink-0 flex-col items-stretch gap-1.5 sm:items-end">
            @if ($canPortal)
                <button type="button" wire:click="portal" wire:loading.attr="disabled" wire:target="portal" class="{{ $cta }}">
                    <span wire:loading.remove wire:target="portal" class="inline-flex items-center gap-2">
                        <x-heroicon-o-arrow-top-right-on-square class="h-4 w-4 shrink-0" aria-hidden="true" />
                        {{ $hasPaymentMethod ? __('Manage card') : __('Add a credit card') }}
                    </span>
                    <span wire:loading wire:target="portal" class="inline-flex items-center gap-2">
                        <x-spinner variant="cream" size="sm" />
                        {{ __('Opening Stripe…') }}
                    </span>
                </button>
            @elseif ($canCheckout)
                <button type="button" wire:click="$set('tab', 'plan')" class="{{ $cta }}">
                    <x-heroicon-o-credit-card class="h-4 w-4 shrink-0" aria-hidden="true" />
                    {{ __('Choose a plan to add a card') }}
                </button>
            @elseif (! $this->standardPricingAvailable)
                <p class="text-sm text-brand-moss">{{ __('Billing isn’t configured for this install yet.') }}</p>
            @endif
            <p class="text-xs text-brand-moss">{{ __('Secure checkout via Stripe. Cancel anytime.') }}</p>
        </div>
    </div>
</section>
