@props([])

@php
    /*
     | Tier estimator. Prices the same inputs on Pro and Team and points at the
     | cheaper one — the arithmetic OrganizationBillingStateComputer runs:
     | fee + extra sites + SSR sites + extra seats + build-minute and delivery
     | overage past the plan's org-wide allowance.
     */
    $plan = static fn (array $t): array => [
        'label' => $t['label'],
        'price' => $t['price_cents'] / 100,
        'sites' => $t['sites'],
        'seats' => $t['seats'],
        'seatPrice' => $t['extra_seat_cents'] === null ? null : $t['extra_seat_cents'] / 100,
        'minutes' => $t['build_minutes'],
        'minutePrice' => ($t['build_minute_overage_millicents'] ?? 0) / 100_000,
        'requestsM' => $t['requests'] / 1_000_000,
        'egressGb' => $t['egress_gb'],
        'computeCredit' => ($t['compute_credit_cents'] ?? 0) / 100,
    ];
    $calc = [
        'sitePrice' => $sitePrice,
        'ssrPrice' => $ssrPrice,
        'requestsPerMillion' => (float) $rates['requests_per_million'],
        'egressPerGb' => (float) $rates['egress_per_gb'],
        'plans' => ['pro' => $plan($tiers['pro']), 'team' => $plan($tiers['team'])],
        // Container compute per hour for a `basic` instance (¼ vCPU, 1 GiB) with the CPU busy.
        'containerHour' => app(\App\Modules\Billing\Services\EdgeContainerComputeCost::class)->perMinuteMillicents(0.25, 1, 4) * 60 / 100_000,
    ];

    $presets = [
        ['label' => __('Side project'), 'hint' => __('3 sites, just you'), 'static' => 3, 'ssr' => 0, 'seats' => 1, 'minutes' => 200, 'requests' => 2, 'egress' => 40, 'hours' => 0],
        ['label' => __('Agency'), 'hint' => __('20 client sites, 3 people'), 'static' => 20, 'ssr' => 0, 'seats' => 3, 'minutes' => 900, 'requests' => 30, 'egress' => 400, 'hours' => 0],
        ['label' => __('SaaS'), 'hint' => __('2 static + 2 SSR, 8 people'), 'static' => 2, 'ssr' => 2, 'seats' => 8, 'minutes' => 2500, 'requests' => 40, 'egress' => 900, 'hours' => 0],
        ['label' => __('Laravel app'), 'hint' => __('1 container app, busy 8h a day'), 'static' => 1, 'ssr' => 0, 'seats' => 2, 'minutes' => 400, 'requests' => 5, 'egress' => 60, 'hours' => 240],
    ];
@endphp

<div
    x-data="{
        staticSites: 3,
        ssrSites: 0,
        seats: 1,
        minutes: 300,
        requestsM: 5,
        egressGb: 100,
        containerHours: 0,
        c: @js($calc),

        n(v) { const x = Number(v); return Number.isFinite(x) && x > 0 ? x : 0; },
        cost(p) {
            if (p.seatPrice === null && this.n(this.seats) > p.seats) return null;
            const extraSites = Math.max(0, this.n(this.staticSites) - p.sites) * this.c.sitePrice;
            const ssr = this.n(this.ssrSites) * this.c.ssrPrice;
            const seats = p.seatPrice === null ? 0 : Math.max(0, this.n(this.seats) - p.seats) * p.seatPrice;
            const minutes = Math.ceil(Math.max(0, this.n(this.minutes) - p.minutes) * p.minutePrice * 100) / 100;
            const requests = Math.max(0, this.n(this.requestsM) - p.requestsM) * this.c.requestsPerMillion;
            const egress = Math.max(0, this.n(this.egressGb) - p.egressGb) * this.c.egressPerGb;
            const compute = Math.max(0, this.n(this.containerHours) * this.c.containerHour - p.computeCredit);
            const usage = minutes + requests + egress + compute;
            return { fee: p.price, sites: extraSites + ssr, seats, usage, total: p.price + extraSites + ssr + seats + usage };
        },
        get pro() { return this.cost(this.c.plans.pro); },
        get team() { return this.cost(this.c.plans.team); },
        get best() { return this.pro === null || (this.team && this.team.total < this.pro.total) ? 'team' : 'pro'; },
        get pick() { return this[this.best]; },
        money(v) { return '$' + (Math.round(v * 100) / 100).toFixed(2); },
        apply(p) { Object.assign(this, { staticSites: p.static, ssrSites: p.ssr, seats: p.seats, minutes: p.minutes, requestsM: p.requests, egressGb: p.egress, containerHours: p.hours }); },
    }"
    class="mt-8 border border-edge-line bg-edge-panel"
>
    <div class="flex flex-wrap items-center gap-2 border-b border-edge-line px-5 py-3">
        <span class="font-terminal text-[11px] uppercase tracking-[0.16em] text-edge-faint">{{ __('Start from') }}</span>
        @foreach ($presets as $preset)
            <button
                type="button"
                x-on:click="apply(@js($preset))"
                class="border border-edge-line px-3 py-1.5 text-left transition-colors hover:border-edge-lime/50 hover:bg-edge-lime/5"
            >
                <span class="block text-xs font-semibold text-edge-text">{{ $preset['label'] }}</span>
                <span class="block text-[11px] text-edge-mute">{{ $preset['hint'] }}</span>
            </button>
        @endforeach
    </div>

    <div class="grid gap-px bg-edge-line lg:grid-cols-[minmax(0,1.3fr)_minmax(0,1fr)]">
        <div class="space-y-px bg-edge-line">
            @foreach ([
                ['model' => 'staticSites', 'label' => __('Static / hybrid sites'), 'hint' => __('$:price each past your plan', ['price' => number_format($sitePrice, 2)]), 'step' => 1, 'suffix' => __('sites')],
                ['model' => 'ssrSites', 'label' => __('Worker SSR sites'), 'hint' => __('$:price each', ['price' => number_format($ssrPrice, 2)]), 'step' => 1, 'suffix' => __('sites')],
                ['model' => 'seats', 'label' => __('Seats'), 'hint' => __('People in the organization'), 'step' => 1, 'suffix' => __('people')],
                ['model' => 'minutes', 'label' => __('Build minutes'), 'hint' => __('Per month, rounded up per build'), 'step' => 100, 'suffix' => __('min / mo')],
                ['model' => 'requestsM', 'label' => __('Requests'), 'hint' => __('All sites together'), 'step' => 1, 'suffix' => __('million / mo')],
                ['model' => 'egressGb', 'label' => __('Egress'), 'hint' => __('All sites together'), 'step' => 10, 'suffix' => __('GB / mo')],
                ['model' => 'containerHours', 'label' => __('Container hours'), 'hint' => __('PHP / Rails / Node apps, basic size, busy — idle containers sleep'), 'step' => 50, 'suffix' => __('hours / mo')],
            ] as $field)
                <div class="flex flex-wrap items-center justify-between gap-4 bg-edge-panel px-5 py-3.5">
                    <div class="min-w-0">
                        <p class="text-sm font-medium text-edge-text">{{ $field['label'] }}</p>
                        <p class="mt-0.5 text-xs text-edge-mute">{{ $field['hint'] }}</p>
                    </div>
                    <div class="flex shrink-0 items-center gap-2">
                        <button type="button" x-on:click="{{ $field['model'] }} = Math.max(0, Number({{ $field['model'] }}) - {{ $field['step'] }})" class="font-terminal flex h-8 w-8 items-center justify-center border border-edge-line text-edge-mute transition-colors hover:border-edge-lime/50 hover:text-edge-text" aria-label="{{ __('Decrease') }}">−</button>
                        <input type="number" min="0" step="{{ $field['step'] }}" x-model.number="{{ $field['model'] }}"
                               class="font-terminal h-8 w-20 border border-edge-line bg-edge-void px-2 text-center text-sm text-edge-text focus:border-edge-lime focus:outline-none focus:ring-0"
                               aria-label="{{ $field['label'] }}" />
                        <button type="button" x-on:click="{{ $field['model'] }} = Number({{ $field['model'] }}) + {{ $field['step'] }}" class="font-terminal flex h-8 w-8 items-center justify-center border border-edge-line text-edge-mute transition-colors hover:border-edge-lime/50 hover:text-edge-text" aria-label="{{ __('Increase') }}">+</button>
                        <span class="w-24 shrink-0 text-xs text-edge-mute">{{ $field['suffix'] }}</span>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="bg-edge-panel px-5 py-5">
            <p class="font-terminal text-[11px] uppercase tracking-[0.16em] text-edge-faint">{{ __('Best fit') }} · <span x-text="c.plans[best].label"></span></p>
            <p class="font-terminal mt-3 text-4xl font-bold text-edge-lime" x-text="money(pick.total)">$0.00</p>

            <dl class="mt-6 space-y-2 text-sm">
                <div class="flex items-baseline justify-between gap-3">
                    <dt class="text-edge-mute"><span x-text="c.plans[best].label"></span> {{ __('plan') }}</dt>
                    <dd class="font-terminal text-edge-text" x-text="money(pick.fee)"></dd>
                </div>
                <div class="flex items-baseline justify-between gap-3">
                    <dt class="text-edge-mute">{{ __('Extra and SSR sites') }}</dt>
                    <dd class="font-terminal text-edge-text" x-text="money(pick.sites)"></dd>
                </div>
                <div class="flex items-baseline justify-between gap-3">
                    <dt class="text-edge-mute">{{ __('Extra seats') }}</dt>
                    <dd class="font-terminal text-edge-text" x-text="money(pick.seats)"></dd>
                </div>
                <div class="flex items-baseline justify-between gap-3 border-b border-edge-line pb-2">
                    <dt class="text-edge-mute">{{ __('Usage and compute past the plan') }}</dt>
                    <dd class="font-terminal text-edge-text" x-text="money(pick.usage)"></dd>
                </div>
                <div class="flex items-baseline justify-between gap-3 pt-1">
                    <dt class="font-semibold text-edge-text">{{ __('Total') }}</dt>
                    <dd class="font-terminal font-bold text-edge-lime" x-text="money(pick.total) + '/mo'"></dd>
                </div>
            </dl>

            <p class="mt-5 text-xs leading-relaxed text-edge-mute">
                <template x-if="pro && team"><span>{{ __('Pro') }} <span x-text="money(pro.total)"></span> · {{ __('Team') }} <span x-text="money(team.total)"></span>. </span></template>
                <template x-if="pro === null"><span>{{ __('Pro includes :n seats, so this needs Team.', ['n' => $tiers['pro']['seats']]) }} </span></template>
                {{ __('Free covers 1 site and 1 seat with no card. Previews are free and not counted.') }}
            </p>
        </div>
    </div>
</div>
