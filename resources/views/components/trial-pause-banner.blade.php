@props([
    'organization',
])

@php
    // App-wide billing state (ruling r-f17p5zgeh120cm5t): no Free plan, a
    // trial instead. In order: paused (trial over, unpaid), not started, on
    // a trial, a canceled subscription still inside its paid period.
    $banner = null;
    if ($organization !== null && ! $organization->isComped()) {
        $billing = route('billing.show', $organization);
        $days = (int) config('subscription.standard.trial.days', 5);
        if (! $organization->hasPlan() && $organization->billing_paused_at !== null) {
            $keepUntil = \App\Modules\Billing\Services\OrganizationBillingEnforcer::deleteAt($organization);
            $banner = [
                'tone' => 'danger',
                'title' => __('Your sites are paused.'),
                'body' => config('subscription.standard.trial.purge_enabled')
                    ? __('The trial ended without a plan. Everything is kept until :date, then deleted. Choose a plan to bring it all back.', ['date' => $keepUntil->toFormattedDateString()])
                    : __('The trial ended without a plan. Choose a plan to bring your sites back.'),
                'action' => __('Choose a plan'),
            ];
        } elseif (! $organization->hasPlan() && ! $organization->eligibleForTrial()) {
            $banner = [
                'tone' => 'danger',
                'title' => __('Your trial has ended.'),
                'body' => __('Choose a plan to keep your sites running. They are paused within the hour otherwise.'),
                'action' => __('Choose a plan'),
            ];
        } elseif (! $organization->hasPlan()) {
            $banner = [
                'tone' => 'info',
                'title' => __('Start your :days-day trial.', ['days' => $days]),
                'body' => __('Try any plan free for :days days. A card is needed; you’re billed on day :next unless you cancel.', ['days' => $days, 'next' => $days + 1]),
                'action' => __('Start trial'),
            ];
        } elseif ($organization->onTrialPlan() && $organization->billing_paused_at !== null) {
            $banner = [
                'tone' => 'danger',
                'title' => __('Your sites are paused.'),
                'body' => __('The trial’s $:limit usage cap is used up. End the trial early on the billing page to start your plan and bring them back.', ['limit' => number_format(((int) config('subscription.standard.trial.spending_limit_cents')) / 100, 0)]),
                'action' => __('Billing'),
            ];
        } elseif ($organization->onTrialPlan()) {
            $ends = $organization->planTrialEndsAt();
            $carded = $organization->subscription('default')?->onTrial() ?? false;
            $banner = [
                'tone' => $ends !== null && $ends->lte(now()->addDay()) ? 'warning' : 'info',
                'title' => __('Trial until :date.', ['date' => $ends?->toDayDateTimeString()]),
                'body' => $carded
                    ? __(':plan starts billing then unless you cancel.', ['plan' => $organization->planTierLabel()])
                    : __('Choose a plan before then or your sites will be paused.'),
                'action' => $carded ? __('Billing') : __('Choose a plan'),
            ];
        } elseif ($organization->onSubscriptionGracePeriod()) {
            $endsAt = $organization->subscriptionEndsAt();
            $banner = [
                'tone' => 'warning',
                'title' => $endsAt
                    ? __('Your subscription ends :date.', ['date' => $endsAt->toFormattedDateString()])
                    : __('Your subscription is set to cancel.'),
                'body' => __('You keep full access until then. After that your sites are paused. Resume anytime to stay on.'),
                'action' => __('Resume subscription'),
            ];
        }
    }
    $tones = [
        'danger' => ['border-red-300 bg-red-50', 'text-red-700'],
        'warning' => ['border-amber-300 bg-amber-50', 'text-amber-700'],
        'info' => ['border-brand-sage/40 bg-brand-sage/10', 'text-brand-sage'],
    ];
@endphp

@if ($banner)
    <div class="border-b {{ $tones[$banner['tone']][0] }}" role="status">
        <div class="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-x-6 gap-y-2 px-4 py-2.5 sm:px-6 lg:px-8">
            <div class="flex min-w-0 flex-1 items-start gap-2.5">
                <x-heroicon-o-clock class="mt-0.5 h-4 w-4 shrink-0 {{ $tones[$banner['tone']][1] }}" aria-hidden="true" />
                <p class="min-w-0 text-sm leading-snug text-brand-ink">
                    <span class="font-semibold">{{ $banner['title'] }}</span>
                    {{ $banner['body'] }}
                </p>
            </div>
            <a
                href="{{ $billing }}"
                wire:navigate
                class="inline-flex shrink-0 items-center rounded-lg bg-brand-ink px-3 py-1.5 text-xs font-semibold whitespace-nowrap text-brand-cream hover:bg-brand-forest"
            >
                {{ $banner['action'] }}
            </a>
        </div>
    </div>
@endif
