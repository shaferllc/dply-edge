<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    @include('partials.theme-head')

    <x-seo-meta
        title="Pricing"
        description="A flat fee per live Edge site and metered delivery only past a generous included allowance. Preview deployments are free." />
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
         | One product, so one price list. Everything below is read from the
         | billing config and the same estimator the app bills from, so the
         | page cannot drift from the invoice:
         |   subscription.standard.edge_cents      flat fee, static/SSG + hybrid
         |   subscription.standard.edge_ssr_cents  flat fee, Worker SSR
         |   dply.edge.usage_billing.*             allowances + overage rates
         */
        $estimator = app(\App\Modules\Billing\Services\ManagedProductCostEstimator::class);
        $rates = $estimator->edgeUsageRates();

        $sitePrice = ((int) config('subscription.standard.edge_cents', 200)) / 100;
        $ssrPrice = ((int) config('subscription.standard.edge_ssr_cents', 700)) / 100;
        $annualPct = (int) config('subscription.standard.annual_discount_pct', 20);

        $includedRequests = (int) $rates['included_requests_per_site'];
        $includedEgress = (int) $rates['included_egress_gb_per_site'];
        $includedStorage = (int) $rates['included_r2_storage_gb_per_site'];
        $includedClassA = (int) config('dply.edge.usage_billing.included_r2_class_a_ops_per_site', 100_000);
        $includedClassB = (int) config('dply.edge.usage_billing.included_r2_class_b_ops_per_site', 1_000_000);

        $markup = (int) $rates['markup_percent'];
        $classARate = round(((int) config('dply.edge.usage_billing.r2_class_a_cents_per_million', 450)) / 100 * (100 + $markup) / 100, 2);
        $classBRate = round(((int) config('dply.edge.usage_billing.r2_class_b_cents_per_million', 36)) / 100 * (100 + $markup) / 100, 2);

        $modes = [
            [
                'num' => '01',
                'name' => __('Static / SSG'),
                'price' => $sitePrice,
                'body' => __('CDN-only. Astro, Eleventy, Hugo, Vite, Next export, plain HTML. Most sites belong here.'),
            ],
            [
                'num' => '02',
                'name' => __('Hybrid'),
                'price' => $sitePrice,
                'body' => __('Static assets on the edge, your own HTTPS origin behind the routes that need a server.'),
            ],
            [
                'num' => '03',
                'name' => __('Worker SSR'),
                'price' => $ssrPrice,
                'body' => __('Server rendering on the edge itself. No origin to run — this fee replaces the static one.'),
            ],
        ];

        $unitLabel = static fn (int $n): string => $n >= 1_000_000
            ? number_format($n / 1_000_000, 0).'M'
            : number_format($n / 1000, 0).'k';

        $included = [
            ['label' => __('Requests'), 'value' => $unitLabel($includedRequests), 'unit' => __('per site / month')],
            ['label' => __('Egress'), 'value' => $includedEgress.' GB', 'unit' => __('per site / month')],
            ['label' => __('R2 storage'), 'value' => $includedStorage.' GB', 'unit' => __('per site')],
            ['label' => __('Writes (Class A)'), 'value' => $unitLabel($includedClassA), 'unit' => __('per site / month')],
            ['label' => __('Reads (Class B)'), 'value' => $unitLabel($includedClassB), 'unit' => __('per site / month')],
        ];

        $requestsAllowanceLabel = $unitLabel($includedRequests);
        $classAAllowanceLabel = $unitLabel($includedClassA);
        $classBAllowanceLabel = $unitLabel($includedClassB);

        $overage = [
            ['unit' => __('Requests'), 'rate' => '$'.number_format($rates['requests_per_million'], 2), 'per' => __('per million, past :n', ['n' => $requestsAllowanceLabel]), 'note' => __('Every hit the Worker answers.')],
            ['unit' => __('Egress'), 'rate' => '$'.number_format($rates['egress_per_gb'], 2), 'per' => __('per GB, past :n GB', ['n' => $includedEgress]), 'note' => __('Bytes delivered to visitors.')],
            ['unit' => __('R2 storage'), 'rate' => '$'.number_format($rates['storage_per_gb'], 2), 'per' => __('per GB / month, past :n GB', ['n' => $includedStorage]), 'note' => __('Published build output at rest.')],
            ['unit' => __('Class A ops'), 'rate' => '$'.number_format($classARate, 2), 'per' => __('per million writes, past :n', ['n' => $classAAllowanceLabel]), 'note' => __('Publishing a deploy writes objects.')],
            ['unit' => __('Class B ops'), 'rate' => '$'.number_format($classBRate, 2), 'per' => __('per million reads, past :n', ['n' => $classBAllowanceLabel]), 'note' => __('Cache misses read from R2.')],
        ];

        $faqs = [
            [
                'q' => __('What exactly am I paying for?'),
                'a' => __('A flat platform fee per live site, and metered delivery only if that site goes past its included allowance. There is no seat price, no build-minute price, and no plan tier to outgrow.'),
            ],
            [
                'q' => __('Do preview deployments cost anything?'),
                'a' => __('No. Branch and PR previews are free — they do not carry a platform fee, and their traffic is not billed. Only sites serving a production hostname are billable.'),
            ],
            [
                'q' => __('What happens if a site gets a traffic spike?'),
                'a' => __('It keeps serving. Delivery past the included allowance is metered at the rates above and appears on your next invoice; the site is never throttled or taken offline for going over.'),
            ],
            [
                'q' => __('How is Worker SSR different?'),
                'a' => __('Worker SSR renders on Cloudflare Workers instead of shipping prebuilt files, so it carries the higher platform fee — and it replaces the static fee rather than stacking on top of it.'),
            ],
            [
                'q' => __('Can I pay yearly?'),
                'a' => __('Yes — annual billing takes :pct% off the platform fee. Metered delivery is always billed monthly in arrears, because that is when it happens.', ['pct' => $annualPct]),
            ],
            [
                'q' => __('Where do I see what I am accruing?'),
                'a' => __('Edge → Usage shows requests, egress, storage and estimated cost for the current calendar month, per site and org-wide, before the invoice lands.'),
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
                    {{ __('One product. Two numbers.') }}
                </h1>
                <p class="mt-5 max-w-2xl text-base leading-7 text-edge-mute">
                    {{ __('A flat fee per live site, and metered delivery only if that site outgrows what is included. Previews are free, builds are free, seats are free.') }}
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

        {{-- ========================= PLATFORM FEE =========================== --}}
        <section class="border-b border-edge-line">
            <div class="mx-auto grid max-w-6xl grid-cols-1 md:grid-cols-3">
                @foreach ($modes as $i => $mode)
                    <div @class([
                        'px-6 py-8 lg:px-10',
                        'border-b border-edge-line md:border-b-0 md:border-r' => $i < 2,
                    ])>
                        <p class="font-terminal text-[11px] text-edge-faint">{{ $mode['num'] }}</p>
                        <p class="mt-2 text-lg font-bold tracking-[-0.02em]">{{ $mode['name'] }}</p>
                        <p class="font-terminal mt-3 text-3xl font-bold text-edge-lime">
                            ${{ number_format($mode['price'], 2) }}<span class="text-sm font-normal text-edge-mute">{{ __('/site/mo') }}</span>
                        </p>
                        <p class="mt-3 text-sm leading-6 text-edge-mute">{{ $mode['body'] }}</p>
                    </div>
                @endforeach
            </div>
            <div class="border-t border-edge-line">
                <p class="mx-auto max-w-6xl px-6 py-4 text-sm text-edge-mute lg:px-10">
                    {{ __('Billed per live site. Preview deployments are free. Annual billing takes :pct% off.', ['pct' => $annualPct]) }}
                </p>
            </div>
        </section>

        {{-- ========================== WHAT'S INCLUDED ======================= --}}
        <section class="border-b border-edge-line">
            <div class="mx-auto max-w-6xl px-6 py-14 lg:px-10">
                <h2 class="text-2xl font-bold tracking-[-0.02em]">{{ __('Included with every site') }}</h2>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-edge-mute">
                    {{ __('Per site, per calendar month. A typical marketing site or docs site never leaves this envelope, and pays only the platform fee.') }}
                </p>

                <dl class="mt-8 grid grid-cols-2 gap-px border border-edge-line bg-edge-line sm:grid-cols-3 lg:grid-cols-5">
                    @foreach ($included as $item)
                        <div class="bg-edge-panel px-5 py-5">
                            <dt class="font-terminal text-[11px] uppercase tracking-[0.16em] text-edge-faint">{{ $item['label'] }}</dt>
                            <dd class="font-terminal mt-2 text-2xl font-bold text-edge-text">{{ $item['value'] }}</dd>
                            <p class="mt-1 text-xs text-edge-mute">{{ $item['unit'] }}</p>
                        </div>
                    @endforeach
                </dl>
            </div>
        </section>

        {{-- ============================= OVERAGE ============================ --}}
        <section class="border-b border-edge-line">
            <div class="mx-auto max-w-6xl px-6 py-14 lg:px-10">
                <h2 class="text-2xl font-bold tracking-[-0.02em]">{{ __('If a site outgrows it') }}</h2>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-edge-mute">
                    {{ __('Delivery past the allowance is metered at these rates and billed monthly in arrears. Nothing is throttled; the meter simply runs.') }}
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
                    'annualPct' => $annualPct,
                    'rates' => $rates,
                    'includedRequests' => $includedRequests,
                    'includedEgress' => $includedEgress,
                    'includedStorage' => $includedStorage,
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
