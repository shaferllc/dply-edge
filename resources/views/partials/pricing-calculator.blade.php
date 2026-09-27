@props([])

@php
    /*
     | Plan estimator. Prices the same inputs on every plan and points at the
     | cheapest — the arithmetic OrganizationBillingStateComputer runs:
     | plan fee + extra seats + max(0, usage at customer price − included credit).
     | Rates come from UsagePrice (cost + margin), the same as the invoice.
     */
    $plan = static fn (array $t): array => [
        'label' => $t['label'],
        'price' => $t['price_cents'] / 100,
        'seats' => $t['seats'],
        'seatPrice' => $t['extra_seat_cents'] === null ? null : $t['extra_seat_cents'] / 100,
        'credit' => ((int) $t['usage_credit_cents']) / 100,
    ];
    $calc = [
        // Dollars per unit, customer price.
        'requestsPerMillion' => \App\Modules\Billing\Support\UsagePrice::rate('requests_millicents_per_million') / 100_000,
        'egressPerGb' => \App\Modules\Billing\Support\UsagePrice::rate('egress_millicents_per_gb') / 100_000,
        'buildPerMinute' => \App\Modules\Billing\Support\UsagePrice::rate('build_millicents_per_minute') / 100_000,
        // A 0.25 vCPU app (1 GiB, 4 GB disk) awake for an hour with the CPU busy.
        'appHour' => \App\Modules\Billing\Support\UsagePrice::containerPerSecond(0.25, 1, 4) * 3600 / 100_000,
        'plans' => array_map($plan, $tiers),
    ];

    $presets = [
        ['label' => __('Side project'), 'hint' => __('A few sites, just you'), 'seats' => 1, 'minutes' => 100, 'requests' => 2, 'egress' => 20, 'hours' => 0],
        ['label' => __('Agency'), 'hint' => __('20 client sites, 3 people'), 'seats' => 3, 'minutes' => 900, 'requests' => 30, 'egress' => 400, 'hours' => 0],
        ['label' => __('SaaS'), 'hint' => __('Sites and SSR apps, 8 people'), 'seats' => 8, 'minutes' => 2500, 'requests' => 40, 'egress' => 900, 'hours' => 200],
        ['label' => __('Laravel app'), 'hint' => __('1 container app, busy 8h a day'), 'seats' => 2, 'minutes' => 400, 'requests' => 5, 'egress' => 60, 'hours' => 240],
    ];
@endphp

<div
    x-data="{
        seats: 1,
        minutes: 100,
        requestsM: 2,
        egressGb: 20,
        appHours: 0,
        c: @js($calc),

        n(v) { const x = Number(v); return Number.isFinite(x) && x > 0 ? x : 0; },
        get usage() {
            return this.n(this.minutes) * this.c.buildPerMinute
                + this.n(this.requestsM) * this.c.requestsPerMillion
                + this.n(this.egressGb) * this.c.egressPerGb
                + this.n(this.appHours) * this.c.appHour;
        },
        cost(p) {
            if (p.seatPrice === null && this.n(this.seats) > p.seats) return null;
            const seats = p.seatPrice === null ? 0 : Math.max(0, this.n(this.seats) - p.seats) * p.seatPrice;
            const credit = Math.min(p.credit, this.usage);
            return { fee: p.price, seats, usage: this.usage, credit, total: p.price + seats + this.usage - credit };
        },
        get best() {
            let best = null;
            for (const key of Object.keys(this.c.plans)) {
                const cost = this.cost(this.c.plans[key]);
                if (cost !== null && (best === null || cost.total < this.cost(this.c.plans[best]).total)) best = key;
            }
            return best ?? Object.keys(this.c.plans).pop();
        },
        get pick() { return this.cost(this.c.plans[this.best]) ?? { fee: 0, seats: 0, usage: 0, credit: 0, total: 0 }; },
        money(v) { return '$' + (Math.round(v * 100) / 100).toFixed(2); },
        apply(p) { Object.assign(this, { seats: p.seats, minutes: p.minutes, requestsM: p.requests, egressGb: p.egress, appHours: p.hours }); },
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
                ['model' => 'seats', 'label' => __('Seats'), 'hint' => __('People in the organization'), 'step' => 1, 'suffix' => __('people')],
                ['model' => 'minutes', 'label' => __('Build time'), 'hint' => __('All builds, previews included; billed per second'), 'step' => 100, 'suffix' => __('min / mo')],
                ['model' => 'requestsM', 'label' => __('Requests'), 'hint' => __('All sites together'), 'step' => 1, 'suffix' => __('million / mo')],
                ['model' => 'egressGb', 'label' => __('Bandwidth'), 'hint' => __('All sites together'), 'step' => 10, 'suffix' => __('GB / mo')],
                ['model' => 'appHours', 'label' => __('App hours'), 'hint' => __('PHP / Rails / Node apps at 0.25 vCPU, busy — idle apps sleep'), 'step' => 50, 'suffix' => __('hours / mo')],
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
                    <dt class="text-edge-mute">{{ __('Extra seats') }}</dt>
                    <dd class="font-terminal text-edge-text" x-text="money(pick.seats)"></dd>
                </div>
                <div class="flex items-baseline justify-between gap-3">
                    <dt class="text-edge-mute">{{ __('Usage') }}</dt>
                    <dd class="font-terminal text-edge-text" x-text="money(pick.usage)"></dd>
                </div>
                <div class="flex items-baseline justify-between gap-3 border-b border-edge-line pb-2">
                    <dt class="text-edge-mute">{{ __('Included usage credit') }}</dt>
                    <dd class="font-terminal text-edge-text" x-text="'−' + money(pick.credit)"></dd>
                </div>
                <div class="flex items-baseline justify-between gap-3 pt-1">
                    <dt class="font-semibold text-edge-text">{{ __('Total') }}</dt>
                    <dd class="font-terminal font-bold text-edge-lime" x-text="money(pick.total) + '/mo'"></dd>
                </div>
            </dl>

            <p class="mt-5 text-xs leading-relaxed text-edge-mute">
                <template x-for="(p, key) in c.plans" :key="key">
                    <span x-show="cost(p)"><span x-text="p.label"></span> <span x-text="cost(p) ? money(cost(p).total) : ''"></span> · </span>
                </template>
                {{ __('Sites are unlimited. Databases, Valkey and other resources bill on top at the usage rates, by the second they are awake.') }}
            </p>
        </div>
    </div>
</div>
