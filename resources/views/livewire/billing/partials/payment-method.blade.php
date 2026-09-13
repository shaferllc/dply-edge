{{--
  Callers: livewire.billing.show (@include), first strip on the merged billing page.
  Actions: Show::subscribeStandard (new card) and Show::portal (manage existing).
  No schema change.
  User: "i should have a big fucking place to go to add the credit card and manage it"
--}}
@php
    $hasPaymentMethod = $this->paymentSummary !== 'No payment method';
    $canCheckout = ! $this->subscription && $this->standardPricingAvailable;
    $canPortal = $this->subscription && $this->organization->hasStripeId();
@endphp
<section id="payment-method" class="border-b border-brand-ink/10 bg-brand-sand/30">
    <div class="flex flex-col gap-8 px-5 py-10 sm:px-8 sm:py-12">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:gap-6">
            <span @class([
                'inline-flex h-20 w-20 shrink-0 items-center justify-center rounded-3xl ring-1',
                'bg-white text-brand-ink ring-brand-ink/10' => $hasPaymentMethod,
                'bg-brand-ink text-brand-cream ring-brand-ink/20' => ! $hasPaymentMethod,
            ])>
                <x-heroicon-o-credit-card class="h-10 w-10" aria-hidden="true" />
            </span>
            <div class="min-w-0">
                <p class="text-xs font-semibold uppercase tracking-wider text-brand-gold/90">{{ __('Payment method') }}</p>
                @if ($hasPaymentMethod)
                    <h2 class="mt-1 font-mono text-3xl font-bold tracking-tight text-brand-ink sm:text-4xl">{{ $this->paymentSummary }}</h2>
                    <p class="mt-2 max-w-2xl text-base leading-relaxed text-brand-moss">
                        {{ __('Default card on file. Update it, add another, or change the billing address in Stripe.') }}
                    </p>
                @else
                    <h2 class="mt-1 text-3xl font-bold tracking-tight text-brand-ink sm:text-4xl">{{ __('Add a credit card') }}</h2>
                    <p class="mt-2 max-w-2xl text-base leading-relaxed text-brand-moss">
                        {{ __('Live Edge sites bill to this card. Stripe handles checkout — we never store the number here.') }}
                    </p>
                @endif
            </div>
        </div>

        <div class="flex w-full max-w-xl flex-col gap-3">
            @if ($hasPaymentMethod && $canPortal)
                <x-primary-button type="button" wire:click="portal" wire:loading.attr="disabled" wire:target="portal"
                                  class="min-h-16 w-full !rounded-2xl !px-8 !text-lg">
                    <span wire:loading.remove wire:target="portal" class="inline-flex items-center gap-2">
                        <x-heroicon-o-arrow-top-right-on-square class="h-5 w-5 shrink-0" aria-hidden="true" />
                        {{ __('Manage card') }}
                    </span>
                    <span wire:loading wire:target="portal" class="inline-flex items-center gap-2">
                        <x-spinner variant="cream" size="sm" />
                        {{ __('Opening Stripe…') }}
                    </span>
                </x-primary-button>
            @elseif ($canCheckout)
                <x-primary-button type="button" wire:click="subscribeStandard('month')" wire:loading.attr="disabled" wire:target="subscribeStandard"
                                  class="min-h-16 w-full !rounded-2xl !px-8 !text-lg">
                    <span wire:loading.remove wire:target="subscribeStandard" class="inline-flex items-center gap-2">
                        <x-heroicon-o-credit-card class="h-5 w-5 shrink-0" aria-hidden="true" />
                        {{ __('Add a credit card') }}
                    </span>
                    <span wire:loading wire:target="subscribeStandard" class="inline-flex items-center gap-2">
                        <x-spinner variant="cream" size="sm" />
                        {{ __('Opening Stripe…') }}
                    </span>
                </x-primary-button>
                <x-secondary-button type="button" wire:click="subscribeStandard('year')" wire:loading.attr="disabled" wire:target="subscribeStandard"
                                    class="min-h-14 w-full !rounded-2xl !text-base">
                    {{ __('Pay yearly — save 20%') }}
                </x-secondary-button>
            @elseif ($canPortal)
                <x-primary-button type="button" wire:click="portal" wire:loading.attr="disabled" wire:target="portal"
                                  class="min-h-16 w-full !rounded-2xl !px-8 !text-lg">
                    <span wire:loading.remove wire:target="portal" class="inline-flex items-center gap-2">
                        <x-heroicon-o-credit-card class="h-5 w-5 shrink-0" aria-hidden="true" />
                        {{ __('Add a credit card') }}
                    </span>
                    <span wire:loading wire:target="portal" class="inline-flex items-center gap-2">
                        <x-spinner variant="cream" size="sm" />
                        {{ __('Opening Stripe…') }}
                    </span>
                </x-primary-button>
            @elseif (! $this->standardPricingAvailable)
                <p class="text-sm text-brand-moss">{{ __('Billing isn’t configured for this install yet.') }}</p>
            @endif
            <p class="text-center text-xs text-brand-moss lg:text-left">{{ __('Secure checkout via Stripe. Cancel anytime.') }}</p>
        </div>
    </div>
</section>
