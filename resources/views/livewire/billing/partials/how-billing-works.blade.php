@php
    $minAgeDays = (int) config('subscription.standard.min_billable_age_days', 1);
    $freeEdgeSites = (int) config('subscription.standard.plans.free.max_edge_apps', 3);
    $annualPct = (int) config('subscription.standard.annual_discount_pct', 20);
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
                <dt class="font-semibold text-brand-ink">{{ __('A flat fee per live site') }}</dt>
                <dd class="mt-1 text-brand-moss">
                    {{ __('Each live production Edge site costs :static/mo (static or hybrid) or :ssr/mo (Worker SSR). Previews are always free.', ['static' => '$'.number_format(((int) config('subscription.standard.edge_cents', 200)) / 100, 2), 'ssr' => '$'.number_format(((int) config('subscription.standard.edge_ssr_cents', 700)) / 100, 2)]) }}
                </dd>
            </div>
            <div>
                <dt class="font-semibold text-brand-ink">{{ __('Delivery usage on top') }}</dt>
                <dd class="mt-1 text-brand-moss">
                    {{ __('Every live site includes a monthly allowance of requests, egress and storage. Usage beyond it is metered and billed monthly.') }}
                </dd>
            </div>
            <div>
                <dt class="font-semibold text-brand-ink">{{ trans_choice('{1} One site free without a card|[2,*] :count sites free without a card', $freeEdgeSites, ['count' => $freeEdgeSites]) }}</dt>
                <dd class="mt-1 text-brand-moss">
                    {{ __('You can run up to :count production Edge sites without a card. Adding a card removes the cap — sites then bill per use.', ['count' => $freeEdgeSites]) }}
                </dd>
            </div>
            <div>
                <dt class="font-semibold text-brand-ink">
                    {{ trans_choice('{0} No grace window|{1} :days-day grace window for new sites|[2,*] :days-day grace window for new sites', $minAgeDays, ['days' => $minAgeDays]) }}
                </dt>
                <dd class="mt-1 text-brand-moss">
                    {{ __('A freshly-launched site isn\'t billed until it\'s been live past the grace window. Spin up, test, tear down — no charge.') }}
                </dd>
            </div>
            <div>
                <dt class="font-semibold text-brand-ink">{{ __('Changes are billed immediately') }}</dt>
                <dd class="mt-1 text-brand-moss">
                    {{ __('When you add a live site, Stripe immediately bills the prorated amount for the rest of your cycle — no surprise renewal totals. Removing one credits the unused portion to your next invoice.') }}
                </dd>
            </div>
            <div>
                <dt class="font-semibold text-brand-ink">{{ __('Yearly saves :pct%', ['pct' => $annualPct]) }}</dt>
                <dd class="mt-1 text-brand-moss">
                    {{ __('Switch to yearly billing and your per-site fees drop by :pct%. Delivery usage is billed monthly.', ['pct' => $annualPct]) }}
                </dd>
            </div>
        </dl>
    </div>
</section>
