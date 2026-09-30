@php
    /*
     | Pricing model (docs/adr/pricing-model-2026-09.md, ruling r-2zxevg4sj675qn1m).
     | Everything is read from the config the invoice is built from, and every
     | usage price goes through App\Modules\Billing\Support\UsagePrice (provider
     | cost + one margin, never shown), so the page cannot drift from the bill:
     |   subscription.standard.tiers.*   plans, seats, included credit, limits
     |   subscription.standard.trial.*   trial length, plan, data grace
     |   UsagePrice::rates() / sizes()   every usage rate and the size ladder
     */
    $tiers = collect(config('subscription.standard.tiers'))->only(\App\Modules\Billing\Services\SubscriptionPlanResolver::PAID_TIERS)->all();
    $taglines = ['starter' => __('For side projects'), 'pro' => __('For real projects'), 'team' => __('For your whole company')];

    $trialDays = (int) config('subscription.standard.trial.days', 5);
    $trialCap = number_format(((int) config('subscription.standard.trial.spending_limit_cents', 500)) / 100, 0);
    $keepDays = (int) config('subscription.standard.trial.keep_data_days', 30);

    $num = static fn (?int $n): string => $n === null ? __('Unlimited') : number_format($n);
    $money = static fn (?int $cents): string => '$'.number_format(((int) $cents) / 100, 0);
    $yesNo = static fn (bool $v): string => $v ? __('Yes') : '—';

    // Plan comparison: prices and limits. Sites are unlimited on every plan.
    $compare = [
        [__('Price'), fn ($t) => $money($t['price_cents']).__('/mo')],
        [__('Sites'), fn () => __('Unlimited')],
        [__('Included usage'), fn ($t) => __(':credit / mo', ['credit' => $money($t['usage_credit_cents'])])],
        [__('Seats'), fn ($t) => $num($t['seats']).($t['extra_seat_cents'] ? __(' · then $:p each', ['p' => number_format($t['extra_seat_cents'] / 100, 0)]) : '')],
        [__('Concurrent builds'), fn ($t) => (string) $t['concurrent_builds']],
        [__('Build timeout'), fn ($t) => __(':m min', ['m' => $t['build_timeout_minutes']])],
        [__('Custom domains'), fn ($t) => $num($t['custom_domains'])],
        [__('Container apps (PHP, Rails, Node)'), fn ($t) => ! $t['containers'] ? '—' : ($t['app_instances'] === null ? __('Autoscaling') : trans_choice(':count instance per app|:count instances per app', (int) $t['app_instances']))],
        [__('Queue workers per app'), fn ($t) => ! $t['containers'] ? '—' : trim(($t['worker_instances'] === null ? __('Unlimited') : trans_choice(':count worker|:count workers', (int) $t['worker_instances'])).($t['worker_autoscale'] ? ($t['worker_instances'] === 1 ? __(' · starts when jobs arrive') : __(' · autoscaling')) : ''))],
        [__('Edge SQL databases (D1)'), fn ($t) => $num($t['databases'])],
        [__('Managed queues'), fn ($t) => $num($t['queues'])],
        [__('Realtime connections per app'), fn ($t) => $num($t['realtime_max_connections'])],
        [__('Request logs'), fn () => __(':d days', ['d' => (int) config('edge.analytics.access_logs_days', 7)])],
        [__('Audit log'), fn ($t) => $yesNo((bool) $t['audit_log'])],
        [__('Preview deployments'), fn () => __('Unlimited · usage counts')],
    ];

    $rateGroups = collect(\App\Modules\Billing\Support\UsagePrice::rates())->groupBy('group');
    $sizes = \App\Modules\Billing\Support\UsagePrice::sizes();

    $faqs = [
        [
            'q' => __('How does the trial work?'),
            'a' => __(':days days of the plan you choose, with a card on file. You are billed on day :next unless you cancel first. Trial usage is capped at $:cap, so a busy trial cannot run up a bill. If a trial ends without payment, sites stop serving and apps sleep; your data is kept :keep days, then deleted.', ['days' => $trialDays, 'next' => $trialDays + 1, 'cap' => $trialCap, 'keep' => $keepDays]),
        ],
        [
            'q' => __('What exactly am I paying for?'),
            'a' => __('Your plan’s monthly fee, extra seats on Team, and usage past the credit your plan includes. Usage is what runs and what is served: the seconds your apps, workers, databases and Valkey are awake, and requests, bandwidth, storage and operations by the unit.'),
        ],
        [
            'q' => __('How does the included usage credit work?'),
            'a' => __('Each plan includes an amount of usage every month. Your invoice lists usage by category, then takes the credit off, down to $0. Credit that you don’t use does not roll over.'),
        ],
        [
            'q' => __('Are sites really unlimited?'),
            'a' => __('Yes. There is no per-site fee on any plan, static or server-rendered. You pay for what the sites use.'),
        ],
        [
            'q' => __('Do preview deployments cost anything?'),
            'a' => __('Previews have no fee, but everything they use counts as usage: build time, requests and bandwidth, and compute for PHP, Rails and Node previews.'),
        ],
        [
            'q' => __('What happens if I go past my plan?'),
            'a' => __('Sites keep serving and builds keep running. Usage past the included credit lands on your next invoice. Nothing is throttled, and you can set a usage alert on the billing page.'),
        ],
        [
            'q' => __('Do apps and databases sleep?'),
            'a' => __('Container apps, databases and the smaller Valkey sizes sleep after an idle period you choose and wake on the next request or connection. While asleep they cost nothing; database storage still bills.'),
        ],
        [
            'q' => __('Can I pay yearly?'),
            'a' => __('Not yet. Plans are billed monthly.'),
        ],
        [
            'q' => __('Where do I see what I am accruing?'),
            'a' => __('The organization billing page shows usage this period, the credit it uses, and the estimated charge, before the invoice lands.'),
        ],
    ];

    // Read by @head below, so this block sits above the document.
    $fromPrice = $money(collect($tiers)->min('price_cents'));
    \Laravel\Head\Facades\Head::title(__('Pricing: plans from :from/mo', ['from' => $fromPrice]))
        ->description(__('Plans from :from/mo: Starter, Pro and Team, each with unlimited sites and included usage. Apps, databases and Valkey bill by the second they run.', ['from' => $fromPrice]))
        ->schema(\Laravel\Head\Facades\Schema::product()
            ->name('dply')
            ->description(__('Git-push hosting for static sites, server-rendered apps and PHP, Rails or Node servers.'))
            ->offers(collect($tiers)->map(fn ($t) => \Laravel\Head\Facades\Schema::offer()
                ->name($t['label'])
                ->price(number_format($t['price_cents'] / 100, 2, '.', ''))
                ->currency('USD')
                ->availability(\Laravel\Head\Enums\OfferAvailability::InStock))->values()->all()))
        ->schema(\Laravel\Head\Facades\Schema::faq()->questions(collect($faqs)->pluck('a', 'q')->all()));
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    @include('partials.theme-head')

    @head
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
    <style>
        [x-cloak] { display: none !important; }
    </style>
</head>
<body class="bg-edge-void font-display text-edge-text antialiased">
@include('partials.skip-link')

    <x-edge-marketing-header active="pricing" />

    <main id="main-content" tabindex="-1">
        {{-- ============================== HERO ============================== --}}
        <section class="border-b border-edge-line">
            <div class="mx-auto max-w-6xl px-6 py-16 lg:px-10 lg:py-20">
                <p class="font-terminal text-[11px] uppercase tracking-[0.2em] text-edge-lime">{{ __('Pricing') }}</p>
                <h1 class="mt-4 max-w-3xl text-4xl font-bold leading-[1.05] tracking-[-0.03em] sm:text-5xl">
                    {{ __('Unlimited sites. Pay for what runs.') }}
                </h1>
                <p class="mt-5 max-w-2xl text-base leading-7 text-edge-mute">
                    {{ __('Three plans, each with usage included. Apps, workers, databases and Valkey bill by the second they are awake; requests, bandwidth and storage by the unit. No per-site fees, previews included.') }}
                </p>

                <div class="mt-8 flex flex-wrap items-center gap-4">
                    <a href="{{ route('register') }}" class="font-terminal inline-flex items-center gap-2 bg-edge-lime px-5 py-3 text-sm font-bold text-edge-void transition-colors hover:bg-edge-lime-bright">
                        {{ __('Start a :d-day trial', ['d' => $trialDays]) }} <span aria-hidden="true">→</span>
                    </a>
                    <a href="#estimate" class="font-terminal border-b border-edge-lime pb-0.5 text-sm text-edge-text transition-colors hover:text-edge-lime">
                        {{ __('estimate a bill') }}
                    </a>
                </div>
            </div>
        </section>

        {{-- ============================== PLANS ============================= --}}
        <section class="border-b border-edge-line">
            <div class="mx-auto grid max-w-6xl grid-cols-1 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($tiers as $key => $tier)
                    <div class="border-b border-edge-line px-6 py-8 sm:border-r lg:border-b-0 lg:px-8">
                        <p class="text-lg font-bold tracking-[-0.02em]">{{ $tier['label'] }}</p>
                        <p class="mt-1 text-sm text-edge-mute">{{ $taglines[$key] ?? '' }}</p>
                        <p class="font-terminal mt-4 text-3xl font-bold text-edge-lime">
                            {{ $money($tier['price_cents']) }}<span class="text-sm font-normal text-edge-mute">{{ __('/mo') }}</span>
                        </p>
                        <ul class="mt-4 space-y-1.5 text-sm text-edge-mute">
                            <li>{{ __('Unlimited sites') }}</li>
                            <li>{{ __(':credit of usage included', ['credit' => $money($tier['usage_credit_cents'])]) }}</li>
                            <li>{{ trans_choice(':count seat|:count seats', (int) $tier['seats']) }}@if ($tier['extra_seat_cents']){{ __(', then $:p each', ['p' => number_format($tier['extra_seat_cents'] / 100, 0)]) }}@endif</li>
                        </ul>
                    </div>
                @endforeach
                <div class="px-6 py-8 lg:px-8">
                    <p class="text-lg font-bold tracking-[-0.02em]">{{ __('Enterprise') }}</p>
                    <p class="mt-1 text-sm text-edge-mute">{{ __('For procurement-led rollouts') }}</p>
                    <p class="font-terminal mt-4 text-3xl font-bold text-edge-text">{{ __('Custom') }}</p>
                    <ul class="mt-4 space-y-1.5 text-sm text-edge-mute">
                        <li>{{ __('Volume usage pricing') }}</li>
                        <li>{{ __('SSO, custom MSA, dedicated support') }}</li>
                        <li><a href="mailto:{{ config('dply.support_email') }}" class="border-b border-edge-lime/50 pb-0.5 text-edge-text transition-colors hover:border-edge-lime hover:text-edge-lime">{{ __('Contact us') }}</a></li>
                    </ul>
                </div>
            </div>
            <div class="border-t border-edge-line">
                <p class="mx-auto max-w-6xl px-6 py-4 text-sm text-edge-mute lg:px-10">
                    {{ __('Try any plan free for :d days — card required, billed on day :next unless you cancel. Billed monthly after that.', ['d' => $trialDays, 'next' => $trialDays + 1]) }}
                </p>
            </div>
        </section>

        {{-- ============================ COMPARE ============================= --}}
        <section class="border-b border-edge-line">
            <div class="mx-auto max-w-6xl px-6 py-14 lg:px-10">
                <h2 class="text-2xl font-bold tracking-[-0.02em]">{{ __('What each plan includes') }}</h2>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-edge-mute">{{ __('Limits are per organization. The included usage comes off each monthly invoice.') }}</p>

                <div class="mt-8 overflow-x-auto border border-edge-line">
                    <table class="min-w-full text-left text-sm">
                        <thead class="font-terminal border-b border-edge-line bg-edge-panel text-[11px] uppercase tracking-[0.16em] text-edge-faint">
                            <tr>
                                <th class="px-5 py-3 font-normal"></th>
                                @foreach ($tiers as $tier)
                                    <th class="px-5 py-3 font-normal">{{ $tier['label'] }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-edge-line">
                            @foreach ($compare as [$label, $cell])
                                <tr>
                                    <td class="px-5 py-3 font-medium text-edge-text">{{ $label }}</td>
                                    @foreach ($tiers as $key => $tier)
                                        <td class="px-5 py-3 text-edge-mute">{{ $cell($tier, $key) }}</td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        {{-- ============================ USAGE RATES ========================= --}}
        <section id="rates" class="border-b border-edge-line">
            <div class="mx-auto max-w-6xl px-6 py-14 lg:px-10">
                <h2 class="text-2xl font-bold tracking-[-0.02em]">{{ __('Usage rates') }}</h2>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-edge-mute">
                    {{ __('The same rates on every plan. Your plan’s included usage pays for them first; the rest is billed monthly, after the period ends. Nothing is throttled.') }}
                </p>

                <div class="mt-8 overflow-x-auto border border-edge-line">
                    <table class="min-w-full text-left text-sm">
                        <thead class="font-terminal border-b border-edge-line bg-edge-panel text-[11px] uppercase tracking-[0.16em] text-edge-faint">
                            <tr>
                                <th class="px-5 py-3 font-normal">{{ __('Meter') }}</th>
                                <th class="px-5 py-3 font-normal">{{ __('Rate') }}</th>
                                <th class="px-5 py-3 font-normal">{{ __('Unit') }}</th>
                            </tr>
                        </thead>
                        @foreach ($rateGroups as $group => $rows)
                            <tbody class="divide-y divide-edge-line border-t border-edge-line">
                                <tr class="bg-edge-panel/60">
                                    <th colspan="3" scope="colgroup" class="font-terminal px-5 py-2 text-[11px] font-normal uppercase tracking-[0.16em] text-edge-faint">{{ __($group) }}</th>
                                </tr>
                                @foreach ($rows as $row)
                                    <tr>
                                        <td class="px-5 py-3 font-medium text-edge-text">{{ __($row['label']) }}</td>
                                        <td class="font-terminal px-5 py-3 text-edge-lime">{{ $row['price'] }}</td>
                                        <td class="px-5 py-3 text-edge-mute">{{ __($row['unit']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        @endforeach
                    </table>
                </div>
            </div>
        </section>

        {{-- =============================== SIZES ============================ --}}
        <section class="border-b border-edge-line">
            <div class="mx-auto max-w-6xl px-6 py-14 lg:px-10">
                <h2 class="text-2xl font-bold tracking-[-0.02em]">{{ __('Sizes, by the second') }}</h2>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-edge-mute">
                    {{ __('One size ladder for apps, databases and Valkey. You pay for each second a size is awake; asleep is free. App per-second prices assume every vCPU is busy; you are billed for the CPU you use. An always-on app at a typical 25% CPU costs the ~monthly figure, and never more than its monthly cap.') }}
                </p>

                <div class="mt-8 overflow-x-auto border border-edge-line">
                    <table class="min-w-full text-left text-sm">
                        <thead class="font-terminal border-b border-edge-line bg-edge-panel text-[11px] uppercase tracking-[0.16em] text-edge-faint">
                            <tr>
                                <th class="px-5 py-3 font-normal">{{ __('Size') }}</th>
                                <th class="px-5 py-3 font-normal">{{ __('Container app') }}</th>
                                <th class="px-5 py-3 font-normal">{{ __('Database') }}</th>
                                <th class="px-5 py-3 font-normal">{{ __('Valkey') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-edge-line">
                            @foreach ($sizes as $size)
                                <tr>
                                    <td class="font-terminal px-5 py-3.5 text-edge-text">{{ $size['label'] }}</td>
                                    @foreach (['app', 'database', 'valkey'] as $product)
                                        <td class="px-5 py-3.5 text-edge-mute">
                                            @if ($size[$product])
                                                <span class="font-terminal text-edge-lime">{{ $size[$product]['second'] }}</span>/s
                                                <span class="block text-xs">{{ $size[$product]['memory'] }} · {{ $size[$product]['hour'] }}/hr @if ($product === 'app') <span class="block">{{ __('~:typical/mo typical · max :cap/mo', ['typical' => $size[$product]['typical'], 'cap' => $size[$product]['cap']]) }}</span>@endif @if ($product === 'valkey') · {{ __('max :cap/mo', ['cap' => $size[$product]['cap']]) }}@if (! $size[$product]['sleeps']) · {{ __('stays on') }}@endif @endif</span>
                                            @else
                                                —
                                            @endif
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <p class="mt-3 text-xs text-edge-mute">{{ __('Database storage bills per GB-month whether awake or asleep. Some larger sizes are offered on request.') }}</p>
            </div>
        </section>

        {{-- ============================ ESTIMATOR =========================== --}}
        <section id="estimate" class="border-b border-edge-line scroll-mt-16">
            <div class="mx-auto max-w-6xl px-6 py-14 lg:px-10">
                <h2 class="text-2xl font-bold tracking-[-0.02em]">{{ __('Estimate a month') }}</h2>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-edge-mute">
                    {{ __('Move the numbers for an estimate at the rates the invoice uses. Storage, databases and other meters are not included.') }}
                </p>

                @include('partials.pricing-calculator', ['tiers' => $tiers])
            </div>
        </section>

        {{-- =============================== FAQ ============================== --}}
        <section class="border-b border-edge-line">
            <div class="mx-auto max-w-3xl px-6 py-14 lg:px-10">
                <h2 class="text-2xl font-bold tracking-[-0.02em]">{{ __('Frequently asked') }}</h2>

                <div class="mt-8 divide-y divide-edge-line border-y border-edge-line">
                    @foreach ($faqs as $faq)
                        <details class="group py-4" name="pricing-faq">
                            <summary class="flex cursor-pointer list-none items-center justify-between gap-4 text-sm font-semibold text-edge-text marker:content-none [&::-webkit-details-marker]:hidden">
                                {{ $faq['q'] }}
                                <span class="font-terminal shrink-0 text-edge-lime transition-transform group-open:rotate-45" aria-hidden="true">+</span>
                            </summary>
                            <p class="mt-3 text-sm leading-6 text-edge-mute">{{ $faq['a'] }}</p>
                        </details>
                    @endforeach
                </div>

                <p class="mt-8 text-sm text-edge-mute">
                    {{ __('Still curious?') }}
                    <a href="mailto:{{ config('dply.support_email') }}" class="border-b border-edge-lime/50 pb-0.5 text-edge-text transition-colors hover:border-edge-lime hover:text-edge-lime">{{ __('Email us') }}</a>.
                </p>
            </div>
        </section>

        {{-- =============================== CTA ============================== --}}
        <section>
            <div class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-6 px-6 py-14 lg:px-10">
                <div>
                    <h2 class="text-2xl font-bold tracking-[-0.02em]">{{ __('Push a repo, get the whole app running.') }}</h2>
                    <p class="mt-2 text-sm text-edge-mute">{{ __(':d days of any plan to try it. Cancel before day :next and you pay nothing.', ['d' => $trialDays, 'next' => $trialDays + 1]) }}</p>
                </div>
                <a href="{{ route('register') }}" class="font-terminal inline-flex items-center gap-2 bg-edge-lime px-5 py-3 text-sm font-bold text-edge-void transition-colors hover:bg-edge-lime-bright">
                    {{ __('Start a :d-day trial', ['d' => $trialDays]) }} <span aria-hidden="true">→</span>
                </a>
            </div>
        </section>
    </main>

    <x-edge-marketing-footer />
    @livewireScripts
</body>
</html>
