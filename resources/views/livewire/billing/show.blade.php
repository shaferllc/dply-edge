@php
    // At-a-glance figures for the hero stat strip. The "status" tile is the
    // big one — color-coded so it doubles as a banner.
    $edgeSiteCount = $this->billingState->edgeCount;
    $monthlyCents = (int) ($this->billingState->monthlyTotalCents ?? 0);
    $intervalLabel = $this->subscriptionInterval === 'year' ? __('billed annually') : __('billed monthly');

    $betaFeeWaived = $this->organization->betaFeeWaived();

    if ($betaFeeWaived) {
        $statusTone = 'success';
        $statusLabel = __('Beta');
        $statusSub = __('$0 — nothing due');
    } elseif ($this->onGracePeriod) {
        $statusTone = 'warning';
        $statusLabel = __('Cancelled');
        $statusSub = $this->subscriptionEndsAt ? __('Access until :date', ['date' => $this->subscriptionEndsAt->toFormattedDateString()]) : __('In grace period');
    } elseif ($this->subscription) {
        $statusTone = 'success';
        $statusLabel = __('Active');
        $statusSub = $intervalLabel;
    } else {
        $statusTone = 'neutral';
        $statusLabel = __('No plan');
        $statusSub = __('Pick a plan below');
    }

    $statusTiles = [
        'success' => 'border-brand-sage/30 bg-brand-sage/8',
        'info' => 'border-sky-200 bg-sky-50',
        'warning' => 'border-amber-200 bg-amber-50',
        'danger' => 'border-red-200 bg-red-50',
        'neutral' => 'border-brand-ink/10 bg-white',
    ];
    $statusDot = [
        'success' => 'bg-brand-sage',
        'info' => 'bg-sky-500',
        'warning' => 'bg-amber-500',
        'danger' => 'bg-red-500',
        'neutral' => 'bg-brand-ink/15',
    ];
@endphp

<div>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <x-organization-shell
            :organization="$organization"
            section="billing"
            :title="__('Billing & plan')"
            :description="__('Simple pricing for :org. A flat monthly fee per live production Edge site, plus metered delivery usage — previews are free.', ['org' => $organization->name])"
            icon="heroicon-o-credit-card"
            :breadcrumb="[
                ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
                ['label' => $organization->name, 'href' => route('organizations.show', $organization), 'icon' => 'building-office-2'],
                ['label' => __('Billing & plan'), 'icon' => 'credit-card'],
            ]"
        >
            <x-slot:actions>
                <x-outline-link size="xxs" href="{{ route('billing.analytics', $organization) }}" wire:navigate>
                    <x-heroicon-o-chart-bar class="h-3.5 w-3.5 shrink-0 opacity-90" aria-hidden="true" />
                    {{ __('Analytics') }}
                </x-outline-link>
                @if ($this->canManageBilling)
                    <x-outline-link size="xxs" href="{{ route('billing.invoices', $organization) }}" wire:navigate>
                        <x-heroicon-o-document class="h-3.5 w-3.5 shrink-0 opacity-90" aria-hidden="true" />
                        {{ __('All invoices') }}
                    </x-outline-link>
                @endif
            </x-slot:actions>

            {{-- Hairline strip, matching invoices / org settings / notification
                 channels. The status tile keeps its tone tint — it doubles as the
                 subscription banner. --}}
            <x-slot:stats>
                <dl class="grid grid-cols-3 gap-px bg-brand-ink/5" aria-label="{{ __('Billing at a glance') }}">
                    <div class="px-3 py-2 {{ $statusTiles[$statusTone] }}">
                        <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Status') }}</dt>
                        <dd class="mt-0.5 flex items-baseline gap-1.5">
                            <span class="inline-block h-1.5 w-1.5 shrink-0 self-center rounded-full {{ $statusDot[$statusTone] }}" aria-hidden="true"></span>
                            <span class="text-sm font-semibold text-brand-ink">{{ $statusLabel }}</span>
                            <span class="truncate text-xs text-brand-moss" title="{{ $statusSub }}">{{ $statusSub }}</span>
                        </dd>
                    </div>
                    <div class="bg-white px-3 py-2">
                        <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Edge sites') }}</dt>
                        <dd class="mt-0.5 flex items-baseline gap-1.5">
                            <span class="font-mono text-base font-semibold tabular-nums text-brand-ink">{{ $edgeSiteCount }}</span>
                            <span class="truncate text-xs text-brand-moss">{{ __('live · billable') }}</span>
                        </dd>
                    </div>
                    <div class="bg-white px-3 py-2">
                        <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Current bill') }}</dt>
                        <dd class="mt-0.5 flex items-baseline gap-1.5">
                            <span class="font-mono text-base font-semibold tabular-nums text-brand-ink">${{ number_format($monthlyCents / 100, 0) }}</span>
                            <span class="truncate text-xs text-brand-moss">
                                @if ($this->subscriptionInterval === 'year')
                                    {{ __('/mo · $:n/yr', ['n' => number_format($this->yearlyTotalCents / 100, 0)]) }}
                                @else
                                    {{ __('/mo · :interval', ['interval' => $intervalLabel]) }}
                                @endif
                            </span>
                        </dd>
                    </div>
                </dl>
            </x-slot:stats>

            @if ($errors->isNotEmpty())
                <div class="border-b border-brand-ink/10 px-3 py-2 sm:px-4">
                    <x-livewire-validation-errors />
                </div>
            @endif

            @if ($betaFeeWaived)
                <div class="border-b border-brand-ink/10 bg-brand-gold/8 px-3 py-2 sm:px-4">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div class="min-w-0">
                            <p class="flex items-center gap-2 text-sm font-semibold text-brand-ink">
                                <x-heroicon-o-sparkles class="h-4 w-4 shrink-0 text-brand-gold" aria-hidden="true" />
                                {{ __('You’re in the dply beta — $0, nothing due') }}
                            </p>
                            <p class="mt-1 text-sm text-brand-moss">
                                {{ __('Your Edge site fees are waived during the beta. Want to lock in early? Subscribe any time below.') }}
                            </p>
                        </div>
                        <button type="button" wire:click="subscribeStandard('month')" wire:loading.attr="disabled" wire:target="subscribeStandard"
                                class="shrink-0 inline-flex items-center gap-2 rounded-lg border border-brand-ink/15 bg-white px-4 py-2 text-sm font-semibold text-brand-ink hover:border-brand-sage/40 disabled:opacity-60">
                            {{ __('Subscribe early') }}
                        </button>
                    </div>
                </div>
            @endif

            @if (
                request()->query('checkout') === 'success'
                || session('billing_status')
                || session('billing_error')
                || request()->query('checkout') === 'cancelled'
                || $errors->has('plan')
                || $errors->has('billing')
            )
                <div class="space-y-3 border-b border-brand-ink/10 px-3 py-2 sm:px-4">
                    @if (request()->query('checkout') === 'success')
                        <x-alert tone="success">{{ __('Subscription updated successfully.') }}</x-alert>
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

            <div wire:loading.flex wire:target="switchInterval,cancelSubscription,resumeSubscription"
                 class="hidden items-center gap-3 border-b border-brand-ink/10 bg-brand-gold/10 px-3 py-2 sm:px-4">
                <x-spinner variant="ink" size="sm" />
                <span class="text-sm font-medium text-brand-ink">{{ __('Updating your subscription with Stripe…') }}</span>
            </div>

            @include('livewire.billing.partials.bill-hero')

            {{-- Payment method --}}
            <section class="border-b border-brand-ink/10">
                <x-workspace-panel-head dense icon="heroicon-o-credit-card" :title="__('Payment method')" :note="__('Default card on file. Update from the Stripe portal.')" />
                {{-- "No payment method" is a blocking gap, not a neutral value:
                     nothing can be charged until it's fixed. Compacting the page
                     had flattened it into grey body text that read as disabled,
                     so the empty state gets amber chrome + an icon while a card
                     on file stays quiet. --}}
                @php
                    // Deliberately the block form, not the inline parenthesised one.
                    // Blade pairs raw php blocks with a text regex, so an inline
                    // opener inside a file that also uses block form swallows
                    // everything up to the next terminator — which here ate the
                    // canManageBilling conditional below and orphaned its close.
                    // Directive names stay spelled out in prose: writing the literal
                    // tokens in this comment would terminate this very block.
                    $hasPaymentMethod = $this->paymentSummary !== 'No payment method';
                @endphp
                <div @class([
                    'flex flex-wrap items-center justify-between gap-2 px-3 py-2.5 sm:px-4',
                    'bg-amber-50/60' => ! $hasPaymentMethod,
                ])>
                    @if ($hasPaymentMethod)
                        <p class="inline-flex items-center gap-1.5 font-mono text-sm tabular-nums text-brand-ink">
                            <x-heroicon-o-credit-card class="h-3.5 w-3.5 shrink-0 text-brand-moss" aria-hidden="true" />
                            {{ $this->paymentSummary }}
                        </p>
                    @else
                        <p class="inline-flex items-center gap-1.5 text-sm font-semibold text-amber-900">
                            <x-heroicon-m-exclamation-triangle class="h-4 w-4 shrink-0 text-amber-600" aria-hidden="true" />
                            {{ __('No payment method') }}
                        </p>
                    @endif

                    @if ($this->canManageBilling)
                        <x-secondary-button size="xs" type="button" wire:click="portal">
                            <x-heroicon-o-arrow-top-right-on-square class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                            {{ $hasPaymentMethod ? __('Manage in Stripe') : __('Add a card') }}
                        </x-secondary-button>
                    @else
                        <p class="text-xs font-medium text-amber-900/80">{{ __('Pick a plan above to add a payment method.') }}</p>
                    @endif
                </div>
            </section>

            {{-- Billing details. Org-scoped invoice email, VAT, currency,
                 legal details — printed on Stripe invoices for this org's
                 subscription. Migrated off the user-level profile page in
                 2026-05 because subscriptions are org-scoped. --}}
            @if ($this->canManageBilling)
                @php
                    $currencies = config('profile_options.currencies', []);
                @endphp
                <section class="border-b border-brand-ink/10">
                    <x-workspace-panel-head dense icon="heroicon-o-identification" :title="__('Billing details')" :note="__('Printed on every Stripe invoice for this organization’s subscription.')" />
                    <form wire:submit="saveBillingDetails" class="space-y-3 px-3 py-3 sm:px-4">
                        <div class="grid gap-3 sm:grid-cols-2">
                            <div>
                                <x-input-label for="org_invoice_email" :value="__('Invoice email')" />
                                <x-text-input id="org_invoice_email" wire:model="invoice_email" type="email" class="mt-1 block w-full" autocomplete="email" />
                                <p class="mt-1 text-xs text-brand-mist">{{ __('Where invoices land — defaults to the org owner\'s email when blank.') }}</p>
                                <x-input-error :messages="$errors->get('invoice_email')" />
                            </div>
                            <div>
                                <x-input-label for="org_vat_number" :value="__('VAT number')" />
                                <x-text-input id="org_vat_number" wire:model="vat_number" type="text" class="mt-1 block w-full" placeholder="NL123456789B01" autocomplete="off" />
                                <p class="mt-1 text-xs text-brand-mist">{{ __('Include the country code. EU businesses may receive a VAT exemption notice when valid.') }}</p>
                                <x-input-error :messages="$errors->get('vat_number')" />
                            </div>
                        </div>
                        <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <x-input-label for="org_billing_currency" :value="__('Currency')" />
                            <select
                                id="org_billing_currency"
                                wire:model="billing_currency"
                                class="mt-1 block w-full rounded-lg border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                            >
                                <option value="">{{ __('Select a currency') }}</option>
                                @foreach ($currencies as $code => $label)
                                    <option value="{{ $code }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            <p class="mt-1 text-xs text-brand-mist">{{ __('Preferred currency for invoices and payment references.') }}</p>
                            <x-input-error :messages="$errors->get('billing_currency')" />
                        </div>
                        <div>
                            <x-input-label for="org_billing_details" :value="__('Legal details')" />
                            <textarea
                                id="org_billing_details"
                                wire:model="billing_details"
                                rows="2"
                                class="mt-1 block w-full rounded-lg border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink shadow-sm placeholder:text-brand-mist focus:border-brand-sage focus:ring-brand-sage"
                                placeholder="{{ __('Legal name, address, and other details to show on invoices') }}"
                            ></textarea>
                            <p class="mt-1 text-xs text-brand-mist">{{ __('Printed on newly created invoices when provided.') }}</p>
                            <x-input-error :messages="$errors->get('billing_details')" />
                        </div>
                        </div>
                        <div class="flex items-center justify-end border-t border-brand-ink/10 pt-2">
                            <button type="submit" wire:loading.attr="disabled" wire:target="saveBillingDetails"
                                    class="inline-flex h-7 items-center gap-1 rounded-md bg-brand-ink px-2.5 text-xs font-semibold text-brand-cream shadow-sm transition-colors hover:bg-brand-forest disabled:opacity-70">
                                <span wire:loading.remove wire:target="saveBillingDetails" class="inline-flex items-center gap-1">
                                    <x-heroicon-o-check class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                    {{ __('Save billing details') }}
                                </span>
                                <span wire:loading wire:target="saveBillingDetails" class="inline-flex items-center gap-1">
                                    <x-spinner variant="cream" size="sm" />
                                    {{ __('Saving…') }}
                                </span>
                            </button>
                        </div>
                    </form>
                </section>
            @endif

            {{-- Subscription — cancel / resume --}}
            @if ($this->canManageBilling)
                <section class="border-b border-brand-ink/10">
                    <x-workspace-panel-head
                        dense
                        :icon="$this->onGracePeriod ? 'heroicon-o-clock' : 'heroicon-o-arrow-path'"
                        :title="__('Cancel or resume')"
                        :note="__('Cancel keeps your sites and data — billing just stops at the end of the period.')"
                    />
                    <div class="px-3 py-3 sm:px-4">
                        @if ($this->onGracePeriod)
                            <div class="rounded-xl border border-amber-300 bg-amber-50 px-4 py-3">
                                <p class="text-sm font-semibold text-amber-950">
                                    {{ __('Subscription ends :date.', ['date' => $this->subscriptionEndsAt?->toFormattedDateString()]) }}
                                </p>
                                <p class="mt-0.5 text-sm text-amber-900/80">{{ __('You keep full access until then. Change your mind?') }}</p>
                                <button type="button" wire:click="resumeSubscription"
                                        wire:loading.attr="disabled" wire:target="resumeSubscription"
                                        class="mt-3 inline-flex items-center gap-2 rounded-lg bg-brand-ink px-4 py-2 text-xs font-semibold text-brand-cream hover:bg-brand-forest disabled:opacity-70">
                                    <span wire:loading.remove wire:target="resumeSubscription" class="inline-flex items-center gap-2">
                                        <x-heroicon-o-arrow-uturn-left class="h-4 w-4 shrink-0" aria-hidden="true" />
                                        {{ __('Resume subscription') }}
                                    </span>
                                    <span wire:loading wire:target="resumeSubscription" class="inline-flex items-center gap-2">
                                        <x-spinner size="sm" variant="cream" />
                                        {{ __('Resuming…') }}
                                    </span>
                                </button>
                            </div>
                        @else
                            <button type="button" x-on:click="$dispatch('open-modal', 'cancel-subscription')"
                                    class="inline-flex items-center gap-1.5 text-sm font-semibold text-red-700 underline underline-offset-2 hover:text-red-900">
                                <x-heroicon-o-x-circle class="h-4 w-4 shrink-0" aria-hidden="true" />
                                {{ __('Cancel subscription') }}
                            </button>
                        @endif
                    </div>
                </section>
            @endif

            {{-- Invoices --}}
            @if ($this->canManageBilling)
                <section class="border-b border-brand-ink/10">
                    <x-workspace-panel-head dense icon="heroicon-o-document" :title="__('Invoices')" :note="__('Recent invoices from Stripe.')">
                        <x-slot:actions>
                            <a href="{{ route('billing.invoices', $organization) }}" wire:navigate class="shrink-0 text-xs font-semibold text-brand-sage hover:text-brand-ink">{{ __('View all') }} →</a>
                        </x-slot:actions>
                    </x-workspace-panel-head>
                    @if ($this->invoices->isEmpty())
                        <div class="px-3 py-8 text-center sm:px-4">
                            <span class="mx-auto inline-flex h-9 w-9 items-center justify-center rounded-xl bg-brand-sand/45 text-brand-mist ring-1 ring-brand-ink/10">
                                <x-heroicon-o-document class="h-4 w-4" aria-hidden="true" />
                            </span>
                            <x-empty-state
                                borderless
                                compact
                                icon="heroicon-o-document-text"
                                :title="__('No invoices yet')"
                                :description="__('Invoices appear here once this organization moves onto a paid plan.')"
                            />
                        </div>
                    @else
                        <ul class="divide-y divide-brand-ink/10">
                            @foreach ($this->invoices as $invoice)
                                @php $hosted = $invoice->asStripeInvoice()->hosted_invoice_url ?? null; @endphp
                                <li class="flex items-center justify-between gap-4 px-3 py-2 transition-colors hover:bg-brand-sand/15 sm:px-4">
                                    <div class="min-w-0">
                                        <p class="text-sm font-semibold text-brand-ink">{{ $invoice->date()->toFormattedDateString() }}</p>
                                        <p class="mt-0.5 font-mono text-xs text-brand-moss tabular-nums">{{ $invoice->total() }}</p>
                                    </div>
                                    @if ($hosted)
                                        <a href="{{ $hosted }}" target="_blank" rel="noopener noreferrer" class="inline-flex shrink-0 items-center gap-1.5 text-xs font-medium text-brand-sage hover:text-brand-ink">
                                            <x-heroicon-o-arrow-top-right-on-square class="h-4 w-4 shrink-0" aria-hidden="true" />
                                            {{ __('Open in Stripe') }}
                                        </a>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>
            @endif

            @include('livewire.billing.partials.how-billing-works')

            {{-- Confirmation modals --}}
            @if ($this->subscription)
                @php $interval = $this->subscriptionInterval; @endphp
                <x-modal name="switch-interval" maxWidth="md">
                    <div class="p-6">
                        <h3 class="text-lg font-semibold text-brand-ink">
                            {{ $interval === 'year' ? __('Switch to monthly billing') : __('Switch to annual billing') }}
                        </h3>
                        <p class="mt-3 text-sm text-brand-moss leading-relaxed">
                            @if ($interval === 'year')
                                {{ __('You\'ll move to monthly billing. A prorated adjustment for the rest of your current period appears on your next invoice, and you\'ll lose the 20% annual discount.') }}
                            @else
                                {{ __('You\'ll be charged a prorated amount for the rest of your current cycle now, then :amount/yr going forward — a 20% saving versus monthly.', ['amount' => '$'.number_format($this->yearlyTotalCents / 100, 2)]) }}
                            @endif
                            {{ __('The switch takes effect immediately.') }}
                        </p>
                        <div class="mt-6 flex justify-end gap-3">
                            <x-secondary-button type="button" x-on:click="$dispatch('close-modal', 'switch-interval')">
                                {{ __('Never mind') }}
                            </x-secondary-button>
                            <button type="button"
                                    wire:click="switchInterval"
                                    x-on:click="$dispatch('close-modal', 'switch-interval')"
                                    class="inline-flex items-center rounded-xl bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream hover:bg-brand-forest">
                                {{ $interval === 'year' ? __('Switch to monthly') : __('Switch to annual') }}
                            </button>
                        </div>
                    </div>
                </x-modal>

                <x-modal name="cancel-subscription" maxWidth="md">
                    <div class="p-6">
                        <h3 class="text-lg font-semibold text-brand-ink">{{ __('Cancel subscription') }}</h3>
                        <p class="mt-3 text-sm text-brand-moss leading-relaxed">
                            @if ($this->nextInvoiceAt)
                                {{ __('You\'ll keep full access until :date — no further charges after that.', ['date' => $this->nextInvoiceAt->toFormattedDateString()]) }}
                            @else
                                {{ __('You\'ll keep full access until the end of your current billing period — no further charges after that.') }}
                            @endif
                        </p>
                        <p class="mt-2 text-sm text-brand-moss leading-relaxed">
                            {{ __('Your sites and data stay intact. You can resume anytime before the period ends.') }}
                        </p>
                        <div class="mt-6 flex justify-end gap-3">
                            <x-secondary-button type="button" x-on:click="$dispatch('close-modal', 'cancel-subscription')">
                                {{ __('Keep subscription') }}
                            </x-secondary-button>
                            <button type="button"
                                    wire:click="cancelSubscription"
                                    x-on:click="$dispatch('close-modal', 'cancel-subscription')"
                                    class="inline-flex items-center rounded-xl bg-red-700 px-4 py-2 text-sm font-semibold text-white hover:bg-red-800">
                                {{ __('Cancel subscription') }}
                            </button>
                        </div>
                    </div>
                </x-modal>
            @endif
        </x-organization-shell>
    </div>
</div>
