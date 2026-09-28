{{--
  Org billing — "Billing 2 · tabs, usage by app" (redesign 2026-09-27).
  One header number (usage this period vs included credit); everything else
  lives in a tab (Show::$tab, ?tab=). Only the active tab's partial renders,
  so the per-app usage queries run on the Usage tab alone.
--}}
@php
    $org = $this->organization;
    $state = $this->billingState;
    $money = fn (int $cents, int $decimals = 2): string => '$'.number_format($cents / 100, $decimals);
    $hasCard = $this->paymentSummary !== 'No payment method';
    $card = $hasCard ? trim(($org->pm_type ? ucfirst((string) $org->pm_type).' ' : '').$this->paymentSummary) : null;
    $planPrice = $money($state->managedSubtotalCents(), $state->managedSubtotalCents() % 100 === 0 ? 0 : 2);

    if ($org->isComped()) {
        $parts = [$org->planTierLabel(), __('comped until :date, nothing due', ['date' => $org->comped_until?->toFormattedDateString()])];
    } elseif ($org->onTrialPlan()) {
        $parts = [__(':plan trial', ['plan' => $org->planTierLabel()]), __('ends :date', ['date' => $org->planTrialEndsAt()?->toFormattedDateString()])];
    } elseif (! $org->hasPlan()) {
        $parts = [$org->billing_paused_at ? __('Paused') : __('No plan'), $org->eligibleForTrial()
            ? __('start a :days-day trial', ['days' => (int) config('subscription.standard.trial.days', 5)])
            : __('choose a plan to keep your apps running')];
    } elseif ($this->onGracePeriod) {
        $parts = [$org->planTierLabel(), __(':price/mo', ['price' => $planPrice]), __('cancelled, access until :date', ['date' => $this->subscriptionEndsAt?->toFormattedDateString()])];
    } else {
        $parts = [$org->planTierLabel(), __(':price/mo', ['price' => $planPrice])];
        if ($this->nextInvoiceAt) {
            $parts[] = __('renews :date', ['date' => $this->nextInvoiceAt->format('M j')]);
        }
    }
    if ($card !== null) {
        $parts[] = $card;
    }

    $tabs = [
        'usage' => __('Usage'),
        'plan' => __('Plan'),
        'invoices' => __('Invoices'),
        'payment' => __('Payment & details'),
        'limits' => __('Limits'),
    ];
@endphp

{{-- Old deep links (#plans from Register / plan-upsell, #invoices, #payment-method) open their tab. --}}
<div x-init="(t => t && $wire.tab !== t && $wire.set('tab', t))({ '#plans': 'plan', '#invoices': 'invoices', '#payment-method': 'payment' }[location.hash])">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <x-organization-shell
            :organization="$organization"
            section="billing"
            :breadcrumb="[
                ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
                ['label' => $organization->name, 'href' => route('organizations.show', $organization), 'icon' => 'building-office-2'],
                ['label' => __('Billing'), 'icon' => 'credit-card'],
            ]"
        >
            <div class="space-y-5">
                <div class="flex flex-wrap items-end justify-between gap-4">
                    <div class="min-w-0">
                        <h1 class="text-2xl font-semibold tracking-tight text-brand-ink">{{ __('Billing') }}</h1>
                        <p class="mt-1 text-sm text-brand-moss">{{ implode(' · ', $parts) }}</p>
                    </div>
                    <div class="flex flex-col items-end gap-1 text-right">
                        <span class="text-2xs font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Usage this period') }}</span>
                        <span class="text-2xl font-semibold tabular-nums text-brand-ink">
                            {{ $money($this->usageState->usageLineCents()) }}
                            @if ($state->usageCreditCents > 0)
                                <span class="text-sm font-normal text-brand-moss">{{ __('of :credit credit', ['credit' => $money($state->usageCreditCents, 0)]) }}</span>
                            @endif
                        </span>
                    </div>
                </div>

                <nav class="flex gap-1 overflow-x-auto border-b border-brand-ink/10" aria-label="{{ __('Billing sections') }}">
                    @foreach ($tabs as $key => $label)
                        <a href="{{ route('billing.show', ['organization' => $organization, 'tab' => $key]) }}"
                           wire:click.prevent="$set('tab', '{{ $key }}')"
                           @if ($tab === $key) aria-current="page" @endif
                           @class([
                               '-mb-px shrink-0 border-b-2 px-3 pb-3 pt-2 text-sm font-medium transition-colors',
                               'border-brand-sage text-brand-ink' => $tab === $key,
                               'border-transparent text-brand-moss hover:text-brand-ink' => $tab !== $key,
                           ])>{{ $label }}</a>
                    @endforeach
                </nav>

                {{-- Flashes and the Stripe loader show on every tab. --}}
                @if ($errors->isNotEmpty())
                    <x-livewire-validation-errors />
                @endif

                @if (
                    request()->query('checkout') === 'success'
                    || session('billing_status')
                    || session('billing_error')
                    || request()->query('checkout') === 'cancelled'
                    || $errors->has('plan')
                    || $errors->has('billing')
                )
                    <div class="space-y-3">
                        @if (request()->query('checkout') === 'success')
                            <x-alert tone="success">{{ __('Billing updated.') }}</x-alert>
                        @endif
                        @if (session('billing_status'))
                            <x-alert tone="success">{{ session('billing_status') }}</x-alert>
                        @endif
                        @if (session('billing_error'))
                            <x-alert tone="error">{{ session('billing_error') }}</x-alert>
                        @endif
                        @if (request()->query('checkout') === 'cancelled')
                            <x-alert tone="warning">{{ __('Checkout was cancelled.') }}</x-alert>
                        @endif
                        @error('plan')<x-alert tone="error">{{ $message }}</x-alert>@enderror
                        @error('billing')<x-alert tone="error">{{ $message }}</x-alert>@enderror
                    </div>
                @endif

                <div wire:loading.flex wire:target="subscribeTier,changeTier,endTrial,portal,cancelSubscription,resumeSubscription,confirmActionModal"
                     class="hidden items-center gap-3 rounded-xl border border-brand-gold/30 bg-brand-gold/10 px-4 py-2.5">
                    <x-spinner variant="ink" size="sm" />
                    <span class="text-sm font-medium text-brand-ink">{{ __('Updating your subscription with Stripe…') }}</span>
                </div>

                @if (! $org->hasPlan() && $tab !== 'plan')
                    <div class="dply-card flex flex-wrap items-center gap-4 px-5 py-4 sm:px-6">
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-semibold text-brand-ink">{{ $org->billing_paused_at ? __('This organization is paused') : __('No plan yet') }}</p>
                            <p class="mt-0.5 text-sm text-brand-moss">{{ $org->eligibleForTrial()
                                ? __('Start a :days-day trial of any plan. Card required, cancel before it bills.', ['days' => (int) config('subscription.standard.trial.days', 5)])
                                : __('Choose a plan to bring your apps back.') }}</p>
                        </div>
                        <button type="button" wire:click="$set('tab', 'plan')"
                                class="inline-flex h-9 items-center rounded-lg bg-brand-ink px-3.5 text-sm font-semibold text-brand-cream transition-colors hover:bg-brand-forest">
                            {{ __('Choose a plan') }}
                        </button>
                    </div>
                @endif

                @switch($tab)
                    @case('plan')
                        @include('livewire.billing.partials.plan-picker')
                        @include('livewire.billing.partials.subscription-cancel')
                        @include('livewire.billing.partials.how-billing-works')
                        @break
                    @case('invoices')
                        @include('livewire.billing.partials.invoices-list')
                        @break
                    @case('payment')
                        @include('livewire.billing.partials.payment-method')
                        @include('livewire.billing.partials.billing-details')
                        @break
                    @case('limits')
                        @include('livewire.billing.partials.limits')
                        @break
                    @default
                        @include('livewire.billing.partials.usage-by-app')
                @endswitch
            </div>
        </x-organization-shell>
    </div>

    @include('livewire.partials.confirm-action-modal')
</div>
