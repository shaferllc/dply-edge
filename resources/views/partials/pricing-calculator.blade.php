@props([])

@php
    /*
     | Edge-only estimator. Inputs are the two things that can move a bill: how
     | many live sites of each kind you run, and how much delivery they do.
     | Allowances are per site, so they scale with the site count — the same way
     | EdgeUsageCostCalculator applies them when the invoice is built.
     */
    $calc = [
        'sitePrice' => $sitePrice,
        'ssrPrice' => $ssrPrice,
        'annualPct' => $annualPct,
        'requestsPerMillion' => (float) $rates['requests_per_million'],
        'egressPerGb' => (float) $rates['egress_per_gb'],
        'storagePerGb' => (float) $rates['storage_per_gb'],
        'includedRequestsM' => $includedRequests / 1_000_000,
        'includedEgressGb' => $includedEgress,
        'includedStorageGb' => $includedStorage,
    ];

    $presets = [
        ['label' => __('Personal site'), 'hint' => __('1 static site, light traffic'), 'static' => 1, 'ssr' => 0, 'requests' => 1, 'egress' => 20, 'storage' => 1],
        ['label' => __('Agency'), 'hint' => __('8 client sites'), 'static' => 8, 'ssr' => 0, 'requests' => 20, 'egress' => 400, 'storage' => 12],
        ['label' => __('SaaS marketing'), 'hint' => __('2 static + 1 SSR app'), 'static' => 2, 'ssr' => 1, 'requests' => 25, 'egress' => 500, 'storage' => 10],
        ['label' => __('High traffic'), 'hint' => __('1 SSR app, 60M requests'), 'static' => 0, 'ssr' => 1, 'requests' => 60, 'egress' => 1200, 'storage' => 20],
    ];
@endphp

<div
    x-data="{
        annual: false,
        staticSites: 2,
        ssrSites: 0,
        requestsM: 8,
        egressGb: 150,
        storageGb: 6,
        c: @js($calc),

        clamp(v, min) { const n = Number(v); return Number.isFinite(n) && n > min ? n : min; },
        get sites() { return this.clamp(this.staticSites, 0) + this.clamp(this.ssrSites, 0); },
        get platform() {
            return this.clamp(this.staticSites, 0) * this.c.sitePrice
                + this.clamp(this.ssrSites, 0) * this.c.ssrPrice;
        },
        get platformBilled() {
            return this.annual ? this.platform * (1 - this.c.annualPct / 100) : this.platform;
        },
        over(used, includedPerSite) {
            const allowance = includedPerSite * this.sites;
            return Math.max(0, this.clamp(used, 0) - allowance);
        },
        get requestsCost() { return this.over(this.requestsM, this.c.includedRequestsM) * this.c.requestsPerMillion; },
        get egressCost() { return this.over(this.egressGb, this.c.includedEgressGb) * this.c.egressPerGb; },
        get storageCost() { return this.over(this.storageGb, this.c.includedStorageGb) * this.c.storagePerGb; },
        get usage() { return this.requestsCost + this.egressCost + this.storageCost; },
        get total() { return this.platformBilled + this.usage; },
        money(v) { return '$' + (Math.round(v * 100) / 100).toFixed(2); },
        apply(p) {
            this.staticSites = p.static; this.ssrSites = p.ssr;
            this.requestsM = p.requests; this.egressGb = p.egress; this.storageGb = p.storage;
        },
    }"
    class="mt-8 border border-edge-line bg-edge-panel"
>
    {{-- Presets --}}
    <div class="flex flex-wrap items-center gap-2 border-b border-edge-line px-5 py-3">
        <span class="font-terminal text-[11px] uppercase tracking-[0.16em] text-edge-faint">{{ __('Start from') }}</span>
        @foreach ($presets as $preset)
            <button
                type="button"
                x-on:click="apply(@js(['static' => $preset['static'], 'ssr' => $preset['ssr'], 'requests' => $preset['requests'], 'egress' => $preset['egress'], 'storage' => $preset['storage']]))"
                class="border border-edge-line px-3 py-1.5 text-left transition-colors hover:border-edge-lime/50 hover:bg-edge-lime/5"
            >
                <span class="block text-xs font-semibold text-edge-text">{{ $preset['label'] }}</span>
                <span class="block text-[11px] text-edge-mute">{{ $preset['hint'] }}</span>
            </button>
        @endforeach

        <div class="ms-auto inline-flex border border-edge-line">
            <button type="button" x-on:click="annual = false" class="font-terminal px-3 py-1.5 text-xs transition-colors" :class="!annual ? 'bg-edge-lime text-edge-void font-bold' : 'text-edge-mute hover:text-edge-text'">{{ __('Monthly') }}</button>
            <button type="button" x-on:click="annual = true" class="font-terminal px-3 py-1.5 text-xs transition-colors" :class="annual ? 'bg-edge-lime text-edge-void font-bold' : 'text-edge-mute hover:text-edge-text'">{{ __('Annual −:pct%', ['pct' => $annualPct]) }}</button>
        </div>
    </div>

    <div class="grid gap-px bg-edge-line lg:grid-cols-[minmax(0,1.3fr)_minmax(0,1fr)]">
        {{-- Inputs --}}
        <div class="space-y-px bg-edge-line">
            @foreach ([
                ['model' => 'staticSites', 'label' => __('Static / hybrid sites'), 'hint' => __('$:price per live site / mo', ['price' => number_format($sitePrice, 2)]), 'step' => 1, 'suffix' => __('sites')],
                ['model' => 'ssrSites', 'label' => __('Worker SSR sites'), 'hint' => __('$:price per live site / mo', ['price' => number_format($ssrPrice, 2)]), 'step' => 1, 'suffix' => __('sites')],
                ['model' => 'requestsM', 'label' => __('Requests'), 'hint' => __(':n M included per site', ['n' => (int) ($includedRequests / 1_000_000)]), 'step' => 1, 'suffix' => __('million / mo')],
                ['model' => 'egressGb', 'label' => __('Egress'), 'hint' => __(':n GB included per site', ['n' => $includedEgress]), 'step' => 10, 'suffix' => __('GB / mo')],
                ['model' => 'storageGb', 'label' => __('Stored output'), 'hint' => __(':n GB included per site', ['n' => $includedStorage]), 'step' => 1, 'suffix' => __('GB')],
            ] as $field)
                <div class="flex flex-wrap items-center justify-between gap-4 bg-edge-panel px-5 py-3.5">
                    <div class="min-w-0">
                        <p class="text-sm font-medium text-edge-text">{{ $field['label'] }}</p>
                        <p class="mt-0.5 text-xs text-edge-mute">{{ $field['hint'] }}</p>
                    </div>
                    <div class="flex shrink-0 items-center gap-2">
                        <button type="button" x-on:click="{{ $field['model'] }} = Math.max(0, Number({{ $field['model'] }}) - {{ $field['step'] }})" class="font-terminal flex h-8 w-8 items-center justify-center border border-edge-line text-edge-mute transition-colors hover:border-edge-lime/50 hover:text-edge-text" aria-label="{{ __('Decrease') }}">−</button>
                        <input
                            type="number"
                            min="0"
                            step="{{ $field['step'] }}"
                            x-model.number="{{ $field['model'] }}"
                            class="font-terminal h-8 w-20 border border-edge-line bg-edge-void px-2 text-center text-sm text-edge-text focus:border-edge-lime focus:outline-none focus:ring-0"
                            aria-label="{{ $field['label'] }}"
                        />
                        <button type="button" x-on:click="{{ $field['model'] }} = Number({{ $field['model'] }}) + {{ $field['step'] }}" class="font-terminal flex h-8 w-8 items-center justify-center border border-edge-line text-edge-mute transition-colors hover:border-edge-lime/50 hover:text-edge-text" aria-label="{{ __('Increase') }}">+</button>
                        <span class="w-24 shrink-0 text-xs text-edge-mute">{{ $field['suffix'] }}</span>
                    </div>
                </div>
            @endforeach
        </div>

        {{-- Total --}}
        <div class="bg-edge-panel px-5 py-5">
            <p class="font-terminal text-[11px] uppercase tracking-[0.16em] text-edge-faint">{{ __('Estimated month') }}</p>
            <p class="font-terminal mt-3 text-4xl font-bold text-edge-lime" x-text="money(total)">$0.00</p>
            <p class="mt-1 text-xs text-edge-mute" x-show="annual" x-cloak>
                {{ __('Platform fee shown with the annual discount applied; delivery is always billed monthly.') }}
            </p>

            <dl class="mt-6 space-y-2 text-sm">
                <div class="flex items-baseline justify-between gap-3 border-b border-edge-line pb-2">
                    <dt class="text-edge-mute"><span x-text="sites"></span> {{ __('live sites — platform fee') }}</dt>
                    <dd class="font-terminal text-edge-text" x-text="money(platformBilled)"></dd>
                </div>
                <div class="flex items-baseline justify-between gap-3">
                    <dt class="text-edge-mute">{{ __('Requests over allowance') }}</dt>
                    <dd class="font-terminal text-edge-text" x-text="money(requestsCost)"></dd>
                </div>
                <div class="flex items-baseline justify-between gap-3">
                    <dt class="text-edge-mute">{{ __('Egress over allowance') }}</dt>
                    <dd class="font-terminal text-edge-text" x-text="money(egressCost)"></dd>
                </div>
                <div class="flex items-baseline justify-between gap-3 border-b border-edge-line pb-2">
                    <dt class="text-edge-mute">{{ __('Storage over allowance') }}</dt>
                    <dd class="font-terminal text-edge-text" x-text="money(storageCost)"></dd>
                </div>
                <div class="flex items-baseline justify-between gap-3 pt-1">
                    <dt class="font-semibold text-edge-text">{{ __('Total') }}</dt>
                    <dd class="font-terminal font-bold text-edge-lime" x-text="money(total) + '/mo'"></dd>
                </div>
            </dl>

            <p class="mt-5 text-xs leading-relaxed text-edge-mute">
                {{ __('Allowances are per site, so they grow as you add sites. Preview deployments are free and are not counted here.') }}
            </p>
        </div>
    </div>
</div>
