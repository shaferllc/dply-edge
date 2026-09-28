{{-- Plan tab: cancel (modal) or resume the subscription. Actions: Show::cancelSubscription, Show::resumeSubscription. --}}
@if ($this->subscription?->valid())
    @php $keepDays = (int) config('subscription.standard.trial.keep_data_days', 30); @endphp
    <section class="dply-card overflow-hidden p-0">
        <x-workspace-panel-head
            dense
            :icon="$this->onGracePeriod ? 'heroicon-o-clock' : 'heroicon-o-arrow-path'"
            :title="__('Cancel or resume')"
            :note="$this->subscription->onTrial() ? __('Cancel during the trial and you are never charged. When the trial ends, the organization is paused.') : __('Your plan runs to the end of the period. Usage from that period is invoiced once, then the organization is paused.')"
        />
        <div class="px-3 py-3 sm:px-4">
            @if ($this->onGracePeriod)
                <div class="rounded-xl border border-amber-500/30 bg-amber-500/10 px-4 py-3">
                    <p class="text-sm font-semibold text-amber-800 dark:text-amber-200">
                        {{ __('Subscription ends :date.', ['date' => $this->subscriptionEndsAt?->toFormattedDateString()]) }}
                    </p>
                    <p class="mt-0.5 text-sm text-brand-moss">{{ __('You keep full access until then. Change your mind?') }}</p>
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
                        class="inline-flex items-center gap-1.5 text-sm font-semibold text-red-700 underline underline-offset-2 hover:text-red-900 dark:text-red-400 dark:hover:text-red-300">
                    <x-heroicon-o-x-circle class="h-4 w-4 shrink-0" aria-hidden="true" />
                    {{ __('Cancel subscription') }}
                </button>
            @endif
        </div>
    </section>

    <x-modal name="cancel-subscription" maxWidth="md">
        <div class="p-6">
            <h3 class="text-lg font-semibold text-brand-ink">{{ __('Cancel subscription') }}</h3>
            <p class="mt-3 text-sm text-brand-moss leading-relaxed">
                @if ($this->subscription->onTrial())
                    {{ __('Your trial runs until :date and you won\'t be charged, for the plan or for trial usage.', ['date' => $this->organization->planTrialEndsAt()?->toFormattedDateString()]) }}
                @elseif ($this->nextInvoiceAt)
                    {{ __('You\'ll keep full access until :date. There are no further plan charges, but usage from this period is invoiced once when it ends.', ['date' => $this->nextInvoiceAt->toFormattedDateString()]) }}
                @else
                    {{ __('You\'ll keep full access until the end of your current billing period. There are no further plan charges, but usage from this period is invoiced once when it ends.') }}
                @endif
            </p>
            <p class="mt-2 text-sm text-brand-moss leading-relaxed">
                {{ __('After that the organization is paused: sites show a paused page and its data is kept :days days. You can resume anytime before the period ends.', ['days' => $keepDays]) }}
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
