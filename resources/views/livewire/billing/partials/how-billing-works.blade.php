@php
    $minAgeDays = (int) config('subscription.standard.min_billable_age_days', 1);
    $extraSite = '$'.number_format(((int) config('subscription.standard.edge_cents', 200)) / 100, 0);
    $ssrSite = '$'.number_format(((int) config('subscription.standard.edge_ssr_cents', 700)) / 100, 0);
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
                <dd class="mt-1 text-brand-moss">{{ __('Free, Pro or Team. Each plan includes sites, seats, build minutes, requests and egress for the month. Preview deployments don’t take a site slot, but their builds, traffic and compute count toward your usage.') }}</dd>
            </div>
            <div>
                <dt class="font-semibold text-brand-ink">{{ __('Extra sites and SSR') }}</dt>
                <dd class="mt-1 text-brand-moss">{{ __('On Pro and Team, static or hybrid sites beyond your plan cost :extra/mo each. Worker SSR sites cost :ssr/mo each.', ['extra' => $extraSite, 'ssr' => $ssrSite]) }}</dd>
            </div>
            <div>
                <dt class="font-semibold text-brand-ink">{{ __('Usage over the allowance') }}</dt>
                <dd class="mt-1 text-brand-moss">{{ __('Requests, egress, storage and build minutes beyond your plan are metered and added to your monthly invoice. On Free, builds pause when the month’s minutes run out.') }}</dd>
            </div>
            <div>
                <dt class="font-semibold text-brand-ink">
                    {{ trans_choice('{0} No grace window|{1} :days-day grace window for new sites|[2,*] :days-day grace window for new sites', $minAgeDays, ['days' => $minAgeDays]) }}
                </dt>
                <dd class="mt-1 text-brand-moss">{{ __('A site added past your plan isn\'t billed as an extra site until it\'s been live past the grace window. Spin up, test, tear down — no charge.') }}</dd>
            </div>
            <div>
                <dt class="font-semibold text-brand-ink">{{ __('Changes are billed immediately') }}</dt>
                <dd class="mt-1 text-brand-moss">{{ __('Switching plans, or adding a site or seat past your plan, invoices the prorated amount now. Removing one credits the unused portion to your next invoice.') }}</dd>
            </div>
        </dl>
    </div>
</section>
