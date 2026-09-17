<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    @include('partials.theme-head')

    <x-seo-meta
        title="Pricing"
        description="Free, Pro and Team plans for Edge sites, with metered usage only past what your plan includes. Preview deployments are free." />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
    <style>
        [x-cloak] { display: none !important; }
    </style>
</head>
<body class="bg-edge-void font-display text-edge-text antialiased">
@include('partials.skip-link')
    @php
        /*
         | Plan tiers + usage (ruling r-zdescb7y05vp1bxx). Everything below is
         | read from the billing config the invoice is built from, so the page
         | cannot drift from it:
         |   subscription.standard.tiers.*          plans and allowances
         |   subscription.standard.edge_cents       extra site, SSR site prices
         |   dply.edge.usage_billing.*              overage rates + storage allowances
         */
        $estimator = app(\App\Modules\Billing\Services\ManagedProductCostEstimator::class);
        $rates = $estimator->edgeUsageRates();

        $sitePrice = ((int) config('subscription.standard.edge_cents', 200)) / 100;
        $ssrPrice = ((int) config('subscription.standard.edge_ssr_cents', 700)) / 100;
        $tiers = collect(config('subscription.standard.tiers'))->only(['free', 'pro', 'team'])->all();

        $includedStorage = (int) $rates['included_r2_storage_gb_per_site'];
        $includedClassA = (int) config('dply.edge.usage_billing.included_r2_class_a_ops_per_site', 100_000);
        $includedClassB = (int) config('dply.edge.usage_billing.included_r2_class_b_ops_per_site', 1_000_000);

        $markup = (int) $rates['markup_percent'];
        $classARate = round(((int) config('dply.edge.usage_billing.r2_class_a_cents_per_million', 450)) / 100 * (100 + $markup) / 100, 2);
        $classBRate = round(((int) config('dply.edge.usage_billing.r2_class_b_cents_per_million', 36)) / 100 * (100 + $markup) / 100, 2);

        $unitLabel = static fn (?int $n): string => $n === null ? __('Unlimited') : ($n >= 1_000_000
            ? number_format($n / 1_000_000, 0).'M'
            : ($n >= 1000 ? number_format($n / 1000, 0).'k' : (string) $n));
        $yesNo = static fn (bool $v): string => $v ? __('Yes') : '—';

        // Plan comparison rows: label => per-tier cell.
        $compare = [
            [__('Sites included'), fn ($t, $k) => $unitLabel($t['sites']).($k !== 'free' ? __(' · then $:p each', ['p' => number_format($sitePrice, 0)]) : '')],
            [__('Worker SSR sites'), fn ($t) => $t['ssr'] ? __('$:p each', ['p' => number_format($ssrPrice, 0)]) : '—'],
            [__('Seats'), fn ($t) => $unitLabel($t['seats']).($t['extra_seat_cents'] ? __(' · then $:p each', ['p' => number_format($t['extra_seat_cents'] / 100, 0)]) : '')],
            [__('Build minutes / mo'), fn ($t) => number_format((int) $t['build_minutes']).($t['build_minute_overage_millicents'] ? __(' · then $:p/min', ['p' => rtrim(rtrim(number_format($t['build_minute_overage_millicents'] / 100_000, 3), '0'), '.')]) : __(' · then builds pause'))],
            [__('Concurrent builds'), fn ($t) => (string) $t['concurrent_builds']],
            [__('Build timeout'), fn ($t) => __(':m min', ['m' => $t['build_timeout_minutes']])],
            [__('Requests / mo'), fn ($t) => $unitLabel($t['requests'])],
            [__('Egress / mo'), fn ($t) => number_format((int) $t['egress_gb']).' GB'],
            [__('Custom domains per site'), fn ($t) => $unitLabel($t['custom_domains_per_site'])],
            [__('Container apps (PHP, Rails, Node)'), fn ($t) => $t['containers'] ? __(':credit compute included', ['credit' => '$'.number_format(($t['compute_credit_cents'] ?? 0) / 100, 0)]) : '—'],
            [__('Load balancing ($8/endpoint)'), fn ($t) => $yesNo((bool) $t['addons'])],
            [__('Audit log'), fn ($t) => $yesNo((bool) $t['audit_log'])],
            [__('Preview deployments'), fn () => __('Free')],
        ];

        $overage = [
            ['unit' => __('Requests'), 'rate' => '$'.number_format($rates['requests_per_million'], 2), 'per' => __('per million, past your plan'), 'note' => __('Every hit the Worker answers.')],
            ['unit' => __('Egress'), 'rate' => '$'.number_format($rates['egress_per_gb'], 2), 'per' => __('per GB, past your plan'), 'note' => __('Bytes delivered to visitors.')],
            ['unit' => __('Build minutes'), 'rate' => '$0.006 / $0.005', 'per' => __('per minute past your plan (Pro / Team)'), 'note' => __('Time in the build container, rounded up per build.')],
            ['unit' => __('R2 storage'), 'rate' => '$'.number_format($rates['storage_per_gb'], 2), 'per' => __('per GB / month, past :n GB per site', ['n' => $includedStorage]), 'note' => __('Published build output at rest.')],
            ['unit' => __('Class A ops'), 'rate' => '$'.number_format($classARate, 2), 'per' => __('per million writes, past :n per site', ['n' => $unitLabel($includedClassA)]), 'note' => __('Publishing a deploy writes objects.')],
            ['unit' => __('Class B ops'), 'rate' => '$'.number_format($classBRate, 2), 'per' => __('per million reads, past :n per site', ['n' => $unitLabel($includedClassB)]), 'note' => __('Cache misses read from R2.')],
        ];

        // Container compute is billed per second; shown per minute for each
        // Cloudflare instance type with every vCPU busy (idle CPU costs less).
        $computeCost = app(\App\Modules\Billing\Services\EdgeContainerComputeCost::class);
        $instanceTypes = [
            ['lite', 1 / 16, 0.25, 2], ['basic', 0.25, 1, 4], ['standard-1', 0.5, 4, 8],
            ['standard-2', 1, 6, 12], ['standard-3', 2, 8, 16], ['standard-4', 4, 12, 20],
        ];
        $computeRows = array_map(static fn (array $t): array => [
            'type' => $t[0],
            'spec' => sprintf('%s vCPU · %s GiB · %d GB disk', $t[1] < 1 ? '1/'.(int) round(1 / $t[1]) : $t[1], $t[2], $t[3]),
            'minute' => '$'.number_format($computeCost->perMinuteMillicents($t[1], $t[2], $t[3]) / 100_000, 5),
            'month' => '$'.number_format($computeCost->perMinuteMillicents($t[1], $t[2], $t[3]) * 60 * 730 / 100_000, 2),
        ], $instanceTypes);

        $faqs = [
            [
                'q' => __('What exactly am I paying for?'),
                'a' => __('Your plan’s monthly fee, plus anything past its allowance: extra sites, Worker SSR sites, extra seats on Team, and metered delivery or build minutes. Free needs no card.'),
            ],
            [
                'q' => __('Do preview deployments cost anything?'),
                'a' => __('Previews don’t count as sites and their traffic isn’t billed. Previews of container apps (PHP, Rails, Node) run their own container, so the seconds they run are billed as compute like production.'),
            ],
            [
                'q' => __('What happens if I go past my plan?'),
                'a' => __('On Pro and Team, sites keep serving and builds keep running; the extra is metered at the rates above and lands on your next invoice. On Free, builds pause until the next month once the build minutes run out.'),
            ],
            [
                'q' => __('How is Worker SSR different?'),
                'a' => __('Worker SSR renders on Cloudflare Workers instead of shipping prebuilt files, so each SSR site carries its own monthly fee on Pro and Team.'),
            ],
            [
                'q' => __('Can I pay yearly?'),
                'a' => __('Not yet — plans are billed monthly for now.'),
            ],
            [
                'q' => __('Where do I see what I am accruing?'),
                'a' => __('The organization billing page shows your plan, what is included, and usage so far this month before the invoice lands.'),
            ],
        ];
    @endphp

    <x-edge-marketing-header active="pricing" />

    <main id="main-content" tabindex="-1">
        {{-- ============================== HERO ============================== --}}
        <section class="border-b border-edge-line">
            <div class="mx-auto max-w-6xl px-6 py-16 lg:px-10 lg:py-20">
                <p class="font-terminal text-[11px] uppercase tracking-[0.2em] text-edge-lime">{{ __('Pricing') }}</p>
                <h1 class="mt-4 max-w-3xl text-4xl font-bold leading-[1.05] tracking-[-0.03em] sm:text-5xl">
                    {{ __('Pick a plan. Pay for what you outgrow.') }}
                </h1>
                <p class="mt-5 max-w-2xl text-base leading-7 text-edge-mute">
                    {{ __('Free to start, Pro for real projects, Team for your whole company. Each plan includes sites, seats, build minutes and traffic; anything past that is metered. Previews are always free.') }}
                </p>

                <div class="mt-8 flex flex-wrap items-center gap-4">
                    <a href="{{ route('register') }}" class="font-terminal inline-flex items-center gap-2 bg-edge-lime px-5 py-3 text-sm font-bold text-edge-void transition-colors hover:bg-edge-lime-bright">
                        {{ __('Deploy a site') }} <span aria-hidden="true">→</span>
                    </a>
                    <a href="#estimate" class="font-terminal border-b border-edge-lime pb-0.5 text-sm text-edge-text transition-colors hover:text-edge-lime">
                        {{ __('estimate a bill') }}
                    </a>
                </div>
            </div>
        </section>

        {{-- ============================== PLANS ============================= --}}
        <section class="border-b border-edge-line">
            <div class="mx-auto grid max-w-6xl grid-cols-1 md:grid-cols-3">
                @foreach ($tiers as $key => $tier)
                    <div @class([
                        'px-6 py-8 lg:px-10',
                        'border-b border-edge-line md:border-b-0 md:border-r' => ! $loop->last,
                    ])>
                        <p class="text-lg font-bold tracking-[-0.02em]">{{ $tier['label'] }}</p>
                        <p class="font-terminal mt-3 text-3xl font-bold text-edge-lime">
                            ${{ number_format($tier['price_cents'] / 100, 0) }}<span class="text-sm font-normal text-edge-mute">{{ __('/mo') }}</span>
                        </p>
                        <p class="mt-3 text-sm leading-6 text-edge-mute">
                            {{ trans_choice(':count site|:count sites', $tier['sites']) }} · {{ trans_choice(':count seat|:count seats', $tier['seats']) }} · {{ __(':m build minutes', ['m' => number_format($tier['build_minutes'])]) }}
                        </p>
                    </div>
                @endforeach
            </div>
            <div class="border-t border-edge-line">
                <p class="mx-auto max-w-6xl px-6 py-4 text-sm text-edge-mute lg:px-10">
                    {{ __('Billed monthly. Preview deployments are free. Enterprise pricing on request.') }}
                </p>
            </div>
        </section>

        {{-- ============================ COMPARE ============================= --}}
        <section class="border-b border-edge-line">
            <div class="mx-auto max-w-6xl px-6 py-14 lg:px-10">
                <h2 class="text-2xl font-bold tracking-[-0.02em]">{{ __('What each plan includes') }}</h2>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-edge-mute">{{ __('Allowances are per organization, per calendar month.') }}</p>

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

        {{-- ============================= OVERAGE ============================ --}}
        <section class="border-b border-edge-line">
            <div class="mx-auto max-w-6xl px-6 py-14 lg:px-10">
                <h2 class="text-2xl font-bold tracking-[-0.02em]">{{ __('If you outgrow your plan') }}</h2>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-edge-mute">
                    {{ __('On Pro and Team, usage past your plan is metered at these rates and billed monthly in arrears. Nothing is throttled; the meter simply runs.') }}
                </p>

                <div class="mt-8 overflow-x-auto border border-edge-line">
                    <table class="min-w-full text-left text-sm">
                        <thead class="font-terminal border-b border-edge-line bg-edge-panel text-[11px] uppercase tracking-[0.16em] text-edge-faint">
                            <tr>
                                <th class="px-5 py-3 font-normal">{{ __('Unit') }}</th>
                                <th class="px-5 py-3 font-normal">{{ __('Rate') }}</th>
                                <th class="px-5 py-3 font-normal">{{ __('Charged') }}</th>
                                <th class="hidden px-5 py-3 font-normal sm:table-cell">{{ __('What it is') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-edge-line">
                            @foreach ($overage as $row)
                                <tr>
                                    <td class="px-5 py-3.5 font-medium text-edge-text">{{ $row['unit'] }}</td>
                                    <td class="font-terminal px-5 py-3.5 text-edge-lime">{{ $row['rate'] }}</td>
                                    <td class="px-5 py-3.5 text-edge-mute">{{ $row['per'] }}</td>
                                    <td class="hidden px-5 py-3.5 text-edge-mute sm:table-cell">{{ $row['note'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        {{-- ========================= CONTAINER COMPUTE ====================== --}}
        <section class="border-b border-edge-line">
            <div class="mx-auto max-w-6xl px-6 py-14 lg:px-10">
                <h2 class="text-2xl font-bold tracking-[-0.02em]">{{ __('Container compute, by the minute') }}</h2>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-edge-mute">
                    {{ __('PHP, Rails and Node server apps run on Cloudflare Containers. You pay for the seconds they run — containers sleep when idle and the meter stops. Pro includes $5 and Team $20 of compute each month.') }}
                </p>

                <div class="mt-8 overflow-x-auto border border-edge-line">
                    <table class="min-w-full text-left text-sm">
                        <thead class="font-terminal border-b border-edge-line bg-edge-panel text-[11px] uppercase tracking-[0.16em] text-edge-faint">
                            <tr>
                                <th class="px-5 py-3 font-normal">{{ __('Instance') }}</th>
                                <th class="px-5 py-3 font-normal">{{ __('Size') }}</th>
                                <th class="px-5 py-3 font-normal">{{ __('Per minute') }}</th>
                                <th class="px-5 py-3 font-normal">{{ __('Always on, per month') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-edge-line">
                            @foreach ($computeRows as $row)
                                <tr>
                                    <td class="font-terminal px-5 py-3.5 text-edge-text">{{ $row['type'] }}</td>
                                    <td class="px-5 py-3.5 text-edge-mute">{{ $row['spec'] }}</td>
                                    <td class="font-terminal px-5 py-3.5 text-edge-lime">{{ $row['minute'] }}</td>
                                    <td class="px-5 py-3.5 text-edge-mute">{{ $row['month'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <p class="mt-3 text-xs text-edge-mute">{{ __('Maximum price with every vCPU busy; CPU is billed only while it works. Container egress is billed per GB.') }}</p>
            </div>
        </section>

        {{-- ============================ ESTIMATOR =========================== --}}
        <section id="estimate" class="border-b border-edge-line scroll-mt-16">
            <div class="mx-auto max-w-6xl px-6 py-14 lg:px-10">
                <h2 class="text-2xl font-bold tracking-[-0.02em]">{{ __('Estimate a month') }}</h2>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-edge-mute">
                    {{ __('Move the numbers. The total is the same arithmetic the invoice runs.') }}
                </p>

                @include('partials.pricing-calculator', [
                    'sitePrice' => $sitePrice,
                    'ssrPrice' => $ssrPrice,
                    'tiers' => $tiers,
                    'rates' => $rates,
                ])
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
                    <a href="mailto:{{ config('mail.from.address') }}" class="border-b border-edge-lime/50 pb-0.5 text-edge-text transition-colors hover:border-edge-lime hover:text-edge-lime">{{ __('Email us') }}</a>.
                </p>
            </div>
        </section>

        {{-- =============================== CTA ============================== --}}
        <section>
            <div class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-6 px-6 py-14 lg:px-10">
                <div>
                    <h2 class="text-2xl font-bold tracking-[-0.02em]">{{ __('Push a repo, get a site on the edge.') }}</h2>
                    <p class="mt-2 text-sm text-edge-mute">{{ __('First deploy takes about a minute. No card to start.') }}</p>
                </div>
                <a href="{{ route('register') }}" class="font-terminal inline-flex items-center gap-2 bg-edge-lime px-5 py-3 text-sm font-bold text-edge-void transition-colors hover:bg-edge-lime-bright">
                    {{ __('Deploy a site') }} <span aria-hidden="true">→</span>
                </a>
            </div>
        </section>
    </main>

    <x-edge-marketing-footer />
    @livewireScripts
</body>
</html>
