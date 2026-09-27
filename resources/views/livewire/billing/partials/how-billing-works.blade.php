@php
    $credits = collect(\App\Modules\Billing\Services\SubscriptionPlanResolver::PAID_TIERS)
        ->map(fn (string $key) => config('subscription.standard.tiers.'.$key.'.label').' $'.number_format((int) config('subscription.standard.tiers.'.$key.'.usage_credit_cents') / 100, 0))
        ->implode(', ');
@endphp
<section class="border-b border-brand-ink/10 last:border-b-0">
    <x-workspace-panel-head
        dense
        icon="heroicon-o-document-text"
        :title="__('How billing works')"
        :note="__('A quick reference for what you’re paying for.')"
    />
    <div class="px-3 py-2.5 sm:px-4">
        <dl class="space-y-2.5 text-sm">
            <div>
                <dt class="font-semibold text-brand-ink">{{ __('A monthly plan') }}</dt>
                <dd class="mt-1 text-brand-moss">{{ __('Starter, Pro or Team, starting with a :days-day Pro trial (card required). Every plan includes unlimited sites; plans differ in seats, limits and included usage.', ['days' => (int) config('subscription.standard.trial.days', 5)]) }}</dd>
            </div>
            <div>
                <dt class="font-semibold text-brand-ink">{{ __('Included usage credit') }}</dt>
                <dd class="mt-1 text-brand-moss">{{ __('Each plan includes usage every period (:credits). Usage up to that amount costs nothing extra; the rest is added to your invoice after the period ends.', ['credits' => $credits]) }}</dd>
            </div>
            <div>
                <dt class="font-semibold text-brand-ink">{{ __('Usage') }}</dt>
                <dd class="mt-1 text-brand-moss">{{ __('Apps, workers, databases and Valkey bill by the second while awake. Requests, bandwidth, storage, operations and messages bill by the unit. During the trial nothing is charged yet, so usage is capped at $:limit: past it, sites pause until the trial ends or you end it early.', ['limit' => number_format(((int) config('subscription.standard.trial.spending_limit_cents', 500)) / 100, 0)]) }}</dd>
            </div>
            <div>
                <dt class="font-semibold text-brand-ink">{{ __('Plan changes') }}</dt>
                <dd class="mt-1 text-brand-moss">{{ __('Switching plans, or adding a seat on Team, invoices the prorated amount now. Removing one credits the unused portion to your next invoice.') }}</dd>
            </div>
        </dl>
    </div>
</section>
