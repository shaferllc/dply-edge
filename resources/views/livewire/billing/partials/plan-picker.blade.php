{{--
  Callers: livewire.billing.show (@include), right after payment-method.
  Actions: Show::subscribeTier (no valid subscription: new, or ended and paused),
  Show::changeTier, and Show::endTrial on the current card during a Stripe trial.
  Tiers come from config subscription.standard.tiers; no Free plan, a trial instead (ruling r-f17p5zgeh120cm5t).
--}}
@php
    $tiers = collect(config('subscription.standard.tiers'))->only(\App\Modules\Billing\Services\SubscriptionPlanResolver::PAID_TIERS);
    $trialDays = (int) config('subscription.standard.trial.days', 5);
    $trialOffered = $this->organization->eligibleForTrial();
    $current = $this->organization->billingTier();
    // An ended subscription can't be changed (changeTier needs valid()): a
    // paused org resubscribes through Checkout.
    $hasSubscription = (bool) $this->subscription?->valid();
    $stripeTrial = (bool) $this->subscription?->onTrial();
    $num = fn (?int $n) => $n === null ? __('Unlimited') : number_format($n);
@endphp
<section id="plans" class="border-b border-brand-ink/10">
    <x-workspace-panel-head
        dense
        icon="heroicon-o-squares-2x2"
        :title="__('Plan')"
        :note="__('Billed monthly. Usage past your plan’s included credit is added to the next invoice.')"
    />
    @if ($trialOffered)
        <p class="px-3 pt-3 text-sm text-brand-ink sm:px-4">{{ __(':days days free, card required. It bills on day :next unless you cancel before then.', ['days' => $trialDays, 'next' => $trialDays + 1]) }}</p>
    @endif
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
                    <li>{{ __('Unlimited sites') }}</li>
                    <li>{{ __(':credit of usage included each month', ['credit' => '$'.number_format(((int) $tier['usage_credit_cents']) / 100, 0)]) }}</li>
                    <li>
                        {{ $tier['seats'] === null ? __('Unlimited seats') : trans_choice(':count seat|:count seats', (int) $tier['seats'], ['count' => $num($tier['seats'])]) }}@if ($tier['extra_seat_cents']){{ __(', then $:price each', ['price' => number_format($tier['extra_seat_cents'] / 100, 0)]) }}@endif
                    </li>
                    <li>{{ trans_choice(':concurrent build at a time|:concurrent builds at a time', (int) $tier['concurrent_builds'], ['concurrent' => $tier['concurrent_builds']]).__(' · :timeout-min timeout', ['timeout' => $tier['build_timeout_minutes']]) }}</li>
                    <li>{{ __(':n custom domains · :databases databases · :queues queues', ['n' => $num($tier['custom_domains']), 'databases' => $num($tier['databases']), 'queues' => $num($tier['queues'])]) }}</li>
                    <li>{{ $tier['containers'] ? ($tier['app_instances'] === 1 ? __('Container apps, one instance each') : __('Container apps with autoscaling')) : __('No container apps') }}</li>
                    @if ($tier['containers'] && array_key_exists('worker_instances', $tier))
                        <li>{{ ($tier['worker_instances'] === null ? __('Unlimited queue workers per app') : trans_choice(':count queue worker per app|:count queue workers per app', (int) $tier['worker_instances'])).(($tier['worker_autoscale'] ?? false) ? __(', autoscaling') : '').((int) ($tier['worker_groups'] ?? 0) > 0 ? __(', :g worker groups', ['g' => (int) $tier['worker_groups']]) : '') }}</li>
                    @endif
                    @if ($tier['audit_log'])
                        <li>{{ __('Audit log') }}</li>
                    @endif
                </ul>
                <div class="mt-4">
                    @if ($isCurrent)
                        <p class="text-xs text-brand-mist">{{ $this->organization->onTrialPlan() ? __('On trial until :date.', ['date' => $this->organization->planTrialEndsAt()?->toFormattedDayDateString()]) : __('Your current plan.') }}</p>
                        @if ($stripeTrial)
                            <x-primary-button type="button" class="mt-2 w-full justify-center" wire:click="endTrial" wire:confirm="{{ __('End the trial now? :plan is billed to your card today, and the trial’s $:limit usage cap is lifted.', ['plan' => $tier['label'], 'limit' => number_format(((int) config('subscription.standard.trial.spending_limit_cents', 500)) / 100, 0)]) }}" wire:loading.attr="disabled" wire:target="endTrial">
                                {{ __('End trial now') }}
                            </x-primary-button>
                        @elseif (! $hasSubscription && $this->organization->onTrialPlan())
                            {{-- Card-less trial: Checkout adds the card (keeping the trial's end), then End trial now. --}}
                            <x-primary-button type="button" class="mt-2 w-full justify-center" wire:click="subscribeTier('{{ $key }}')" wire:loading.attr="disabled" wire:target="subscribeTier" @disabled(! $this->standardPricingAvailable)>
                                {{ __('Add a card for :plan', ['plan' => $tier['label']]) }}
                            </x-primary-button>
                        @endif
                    @elseif (! $hasSubscription)
                        <x-primary-button type="button" class="w-full justify-center" wire:click="subscribeTier('{{ $key }}')" wire:loading.attr="disabled" wire:target="subscribeTier" @disabled(! $this->standardPricingAvailable)>
                            {{ $trialOffered ? __('Start :days-day :plan trial', ['days' => $trialDays, 'plan' => $tier['label']]) : __('Choose :plan', ['plan' => $tier['label']]) }}
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
