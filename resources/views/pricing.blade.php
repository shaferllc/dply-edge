<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    @include('partials.theme-head')

    <x-seo-meta
        title="Pricing"
        description="Flat, per-organization pricing that covers every server and site you run—no per-app surprises. Start with a free trial on infrastructure you already control." />
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=space-grotesk:400,500,700|space-mono:400,700&display=swap" rel="stylesheet" />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
    <style>
        [x-cloak] { display: none !important; }
    </style>
</head>
<body class="bg-edge-void font-display text-edge-text antialiased">
    @php
        // Flat plans metered by BYO server COUNT. Mirrors SubscriptionPlanResolver.
        $plans = collect(config('subscription.standard.plans', []))
            ->map(fn ($plan, $key) => [
                'key' => $key,
                'label' => $plan['label'] ?? ucfirst($key),
                'price' => (int) ($plan['price_cents'] ?? 0) / 100,
                'max' => $plan['max_servers'] ?? null,
                // Per-surface ceilings — see App\Enums\QuotaSurface. They are
                // independent, so the card lists the managed ones separately
                // rather than folding them into one "sites" number.
                'max_sites' => $plan['max_sites'] ?? null,
                'max_cloud_apps' => $plan['max_cloud_apps'] ?? null,
                'max_edge_apps' => $plan['max_edge_apps'] ?? null,
                'max_functions' => $plan['max_functions'] ?? null,
            ])
            ->values();

        $annualPct = (int) config('subscription.standard.annual_discount_pct', 20);
        $serverless = (int) config('subscription.standard.serverless_cents', 200) / 100;
        $cloud = (int) config('subscription.standard.cloud_cents', 500) / 100;
        $edge = (int) config('subscription.standard.edge_cents', 200) / 100;

        // Managed services are priced per resource by tier, so the headline is
        // the cheapest tier in each table rather than a single configured cent
        // value like the hosting products above.
        $realtimeTiers = collect((array) config('realtime.tiers', []))->pluck('price_cents')->filter();
        $realtime = ($realtimeTiers->min() ?: 1500) / 100;

        $queueTiers = collect((array) config('queue_service.tiers', []))->pluck('price_cents')->filter();
        $queue = ($queueTiers->min() ?: 900) / 100;

        $freePlan = $plans->firstWhere('key', 'free');
        $starterPlan = $plans->firstWhere('key', 'starter');

        // Marketing copy for each plan card.
        $planBlurbs = [
            'free' => 'For your first project. Connect one server and ship.',
            'starter' => 'For indie devs and small side projects.',
            'pro' => 'For teams running a real fleet.',
            'business' => 'For agencies and large fleets — no server cap.',
        ];
        $highlightKey = 'pro';
    @endphp

    <div class="fixed inset-0 -z-20 bg-edge-void"></div>
    <div class="fixed inset-0 -z-10 bg-mesh-brand"></div>

    <x-edge-marketing-header active="pricing" />

    <main class="flex-1"
          x-data="{
              annual: false,
              servers: 3,
              edge: 0,
              cloud: 0,
              serverless: 0,
              plans: {{ Illuminate\Support\Js::from($plans) }},
              edgePrice: {{ $edge }},
              cloudPrice: {{ $cloud }},
              serverlessPrice: {{ $serverless }},
              annualPct: {{ $annualPct }},
              get plan() {
                  return this.plans.find(p => p.max === null || this.servers <= p.max)
                      || this.plans[this.plans.length - 1];
              },
              get managedTotal() {
                  return this.edge * this.edgePrice + this.cloud * this.cloudPrice + this.serverless * this.serverlessPrice;
              },
              // Managed products require a paid plan. If the fleet alone would
              // land on Free but managed units are selected, bill the cheapest
              // paid plan instead. Mirrors the require_paid_plan rule.
              get needsPaidForManaged() {
                  return this.plan.price === 0 && this.managedTotal > 0;
              },
              get effectivePlan() {
                  if (this.needsPaidForManaged) {
                      return this.plans.find(p => p.price > 0) || this.plan;
                  }
                  return this.plan;
              },
              get planPrice() {
                  return this.effectivePlan.price;
              },
              get monthlyTotal() {
                  return this.planPrice + this.managedTotal;
              },
              get billedTotal() {
                  return this.annual
                      ? Math.round(this.monthlyTotal * 12 * (1 - this.annualPct / 100))
                      : this.monthlyTotal;
              },
              fmt(n) { return '$' + (Math.round(n * 100) / 100).toFixed(2); },
              fmt0(n) { return '$' + Math.round(n); }
          }">
        <section class="pt-16 pb-6 px-4 sm:px-6 lg:px-8">
            <div class="max-w-3xl mx-auto text-center">
                <h1 class="text-4xl font-bold tracking-tight text-edge-text sm:text-5xl">Simple plans, priced by server count.</h1>
                <p class="mt-4 text-lg text-edge-mute">Start free with one server. Flat monthly plans as your fleet grows — same fee whether you run on DigitalOcean, Hetzner, or your own SSH box. You always pay your provider for the hardware; dply is just the platform fee.</p>
                <p class="mt-3 inline-flex items-center gap-2 rounded-full bg-edge-panel px-4 py-1.5 text-sm font-semibold text-edge-lime">
                    <x-heroicon-s-sparkles class="h-4 w-4" aria-hidden="true" />
                    Your first server is free, forever. No credit card to start.
                </p>
            </div>
        </section>

        <div class="flex justify-center mb-10 px-4">
            <div class="inline-flex items-center gap-3 p-1 border border-edge-line bg-edge-panel">
                <button type="button" @click="annual = false" :class="!annual ? 'bg-edge-lime text-edge-void ' : 'text-edge-mute'" class="px-4 py-2 text-sm font-semibold transition">Monthly</button>
                <button type="button" @click="annual = true" :class="annual ? 'bg-edge-lime text-edge-void ' : 'text-edge-mute'" class="px-4 py-2 text-sm font-semibold transition">Annual</button>
                <span class="text-xs font-semibold text-edge-lime bg-edge-panel px-2.5 py-1 mr-1">Save {{ $annualPct }}%</span>
            </div>
        </div>

        {{-- Plan cards --}}
        <section class="pb-12 px-4 sm:px-6 lg:px-8">
            <div class="mx-auto grid w-full max-w-6xl gap-6 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($plans as $plan)
                    @php
                        $isHighlight = $plan['key'] === $highlightKey;
                        $ceiling = $plan['max'] === null
                            ? 'Unlimited servers'
                            : ($plan['max'] === 1 ? '1 server' : 'Up to ' . $plan['max'] . ' servers');
                        $siteCeiling = $plan['max_sites'] === null
                            ? 'Unlimited sites on your servers'
                            : ($plan['max_sites'] === 1 ? '1 site on your server' : 'Up to ' . $plan['max_sites'] . ' sites on your servers');

                        // Managed surfaces each carry their own ceiling and are
                        // billed per app on top of the plan, so they read as a
                        // separate allowance rather than eating the site count.
                        $managedCeilings = collect([
                            ['n' => $plan['max_cloud_apps'], 'one' => 'Cloud app', 'many' => 'Cloud apps'],
                            ['n' => $plan['max_edge_apps'], 'one' => 'Edge site', 'many' => 'Edge sites'],
                            ['n' => $plan['max_functions'], 'one' => 'function', 'many' => 'functions'],
                        ]);
                        $managedCeiling = $managedCeilings->every(fn ($c) => $c['n'] === null)
                            ? 'Unlimited Cloud apps, Edge sites &amp; functions'
                            : $managedCeilings
                                ->map(fn ($c) => $c['n'] === null
                                    ? 'unlimited ' . $c['many']
                                    : $c['n'] . ' ' . ($c['n'] === 1 ? $c['one'] : $c['many']))
                                ->join(', ', ' &amp; ');
                    @endphp
                    <div @class([
                        'relative flex flex-col  p-8 transition',
                        'border-2 border-edge-line bg-edge-panel  ring-1 ring-edge-line' => $isHighlight,
                        'border border-edge-line bg-edge-panel  ' => ! $isHighlight,
                    ])>
                        @if ($isHighlight)
                            <div class="absolute -top-3 left-1/2 -translate-x-1/2 rounded-full bg-edge-lime/10 px-3 py-1 text-xs font-bold text-edge-text uppercase tracking-wide">Most popular</div>
                        @endif
                        <h2 class="text-lg font-semibold text-edge-text">{{ $plan['label'] }}</h2>
                        <p class="mt-1 text-sm text-edge-mute min-h-[2.5rem]">{{ $planBlurbs[$plan['key']] ?? '' }}</p>
                        <div class="mt-5 flex items-baseline gap-1">
                            @if ($plan['price'] > 0)
                                <span class="text-4xl font-bold text-edge-text"
                                      x-text="annual ? fmt0({{ $plan['price'] }} * 12 * (1 - {{ $annualPct }} / 100)) : '{{ '$' . number_format($plan['price'], 0) }}'">{{ '$' . number_format($plan['price'], 0) }}</span>
                                <span class="text-edge-mute" x-text="annual ? '/yr' : '/mo'">/mo</span>
                            @else
                                <span class="text-4xl font-bold text-edge-text">$0</span>
                                <span class="text-edge-mute">/mo</span>
                            @endif
                        </div>
                        <p class="mt-4 text-sm font-semibold text-edge-text">{{ $ceiling }}</p>
                        <ul class="mt-5 space-y-3 text-sm text-edge-mute flex-1">
                            <li class="flex items-start gap-2.5">
                                <x-heroicon-s-check class="h-5 w-5 shrink-0 text-edge-lime" aria-hidden="true" />
                                {{ $siteCeiling }}, unlimited deploys &amp; team members
                            </li>
                            <li class="flex items-start gap-2.5">
                                <x-heroicon-s-check class="h-5 w-5 shrink-0 text-edge-lime" aria-hidden="true" />
                                <span>{!! $managedCeiling !!} — each billed per app</span>
                            </li>
                            <li class="flex items-start gap-2.5">
                                <x-heroicon-s-check class="h-5 w-5 shrink-0 text-edge-lime" aria-hidden="true" />
                                Every feature — no tier gating
                            </li>
                            <li class="flex items-start gap-2.5">
                                <x-heroicon-s-check class="h-5 w-5 shrink-0 text-edge-lime" aria-hidden="true" />
                                Public REST API + <code class="text-xs bg-edge-panel px-1.5 py-0.5 rounded">@dply/cli</code>
                            </li>
                            @if ($plan['price'] > 0)
                                <li class="flex items-start gap-2.5">
                                    <x-heroicon-s-check class="h-5 w-5 shrink-0 text-edge-lime" aria-hidden="true" />
                                    Add managed Edge, Cloud &amp; Serverless à la carte
                                </li>
                            @else
                                <li class="flex items-start gap-2.5">
                                    <x-heroicon-s-check class="h-5 w-5 shrink-0 text-edge-lime" aria-hidden="true" />
                                    Upgrade any time as you add servers
                                </li>
                            @endif
                        </ul>
                        <a href="{{ route('register') }}" @class([
                            'mt-8 block w-full  px-4 py-3 text-center text-sm font-semibold transition-colors',
                            'bg-edge-lime text-edge-void  hover:bg-edge-lime/10' => $isHighlight,
                            'border-2 border-edge-line bg-edge-panel text-edge-text hover:border-edge-line' => ! $isHighlight,
                        ])>
                            {{ $plan['price'] > 0 ? 'Start 14-day free trial' : 'Start free' }}
                        </a>
                    </div>
                @endforeach
            </div>
            <p class="mx-auto mt-6 max-w-2xl text-center text-sm text-edge-mute">
                Every paid plan starts with a 14-day free trial — no credit card to begin. Need more than a self-serve plan?
                <a href="mailto:hello@dply.io?subject=Dply%20enterprise%20pricing" class="font-semibold text-edge-text underline underline-offset-2 hover:text-edge-lime">Talk to sales</a>
                about Enterprise: volume pricing, SSO, audit logs, and a custom MSA.
            </p>
        </section>

        {{-- Managed products — à la carte on top of any paid plan --}}
        @php
            $managedProducts = [
                ['name' => 'dply Edge', 'flag' => 'surface.edge', 'prefix' => 'from', 'price' => $edge, 'unit' => 'per site / mo + usage', 'desc' => 'Static & SSG sites on a managed global CDN. Flat platform fee plus metered delivery (requests & bandwidth) for high-traffic sites.', 'icon' => 'heroicon-o-globe-alt'],
                ['name' => 'dply Cloud', 'flag' => 'surface.cloud', 'prefix' => 'from', 'price' => $cloud, 'unit' => 'per app / mo + resources', 'desc' => 'Managed long-running PHP & Rails containers. Platform fee plus metered compute, workers & databases at cost + margin.', 'icon' => 'heroicon-o-cloud'],
                ['name' => 'Serverless', 'flag' => 'surface.serverless', 'prefix' => 'from', 'price' => $serverless, 'unit' => 'per function / mo', 'desc' => 'Deploy functions on your own cloud account (Lambda, Cloudflare Workers & more) for a flat management fee, or let dply host them — a platform fee plus metered usage at cost + margin, no provider account needed.', 'icon' => 'heroicon-o-bolt'],
            ];

            \Laravel\Pennant\Feature::loadMissing(array_column($managedProducts, 'flag'));
        @endphp
        <section class="pb-12 px-4 sm:px-6 lg:px-8">
            <div class="mx-auto max-w-5xl">
                <div class="text-center">
                    <h2 class="text-2xl font-bold text-edge-text">Managed products, à la carte</h2>
                    <p class="mt-2 text-edge-mute">Stack first-party managed hosting on top of any paid plan. Each starts at a flat per-unit fee — heavier apps add metered resources or your own provider's usage on top.</p>
                </div>
                <div class="mt-6 grid gap-4 sm:grid-cols-3">
                    @foreach ($managedProducts as $product)
                        @php $comingSoon = ! \Laravel\Pennant\Feature::active($product['flag']); @endphp
                        <div @class([
                            'relative  border border-edge-line bg-edge-panel p-6',
                            'opacity-75' => $comingSoon,
                        ])>
                            <div class="flex items-start justify-between gap-2">
                                <x-dynamic-component :component="$product['icon']" class="h-7 w-7 text-edge-lime" aria-hidden="true" />
                                @if ($comingSoon)
                                    <span class="inline-flex items-center rounded-full bg-edge-lime/10 px-2.5 py-0.5 text-xs font-medium text-edge-lime ring-1 ring-inset ring-edge-line">Coming soon</span>
                                @endif
                            </div>
                            <h3 class="mt-3 text-base font-semibold text-edge-text">{{ $product['name'] }}</h3>
                            <div class="mt-2 flex items-baseline gap-1">
                                @if (! empty($product['prefix']))
                                    <span class="text-sm font-medium text-edge-mute">{{ $product['prefix'] }}</span>
                                @endif
                                <span class="text-2xl font-bold text-edge-text">${{ number_format($product['price'], 0) }}</span>
                                <span class="text-sm text-edge-mute">{{ $product['unit'] }}</span>
                            </div>
                            <p class="mt-2 text-sm text-edge-mute">{{ $product['desc'] }}</p>
                        </div>
                    @endforeach
                </div>
                <p class="mt-4 text-center text-xs text-edge-mute">Managed products require a paid plan (Starter or higher).</p>
            </div>
        </section>

        {{-- Managed services — the things apps lean on, priced per resource.
             Kept separate from the hosting products above: these are not places
             to run an app, and Queue's Serverless carve-out needs room to be
             stated plainly rather than buried in a card. --}}
        @php
            $managedServices = [
                [
                    'name' => 'Realtime',
                    'flag' => 'surface.realtime',
                    'price' => $realtime,
                    'unit' => 'per app / mo',
                    'desc' => 'A Pusher-compatible WebSocket relay. Drop-in for Laravel Echo or Reverb — no relay to run. Priced by connection tier, capped at the tier so a traffic spike costs connections, never a surprise invoice.',
                    'icon' => 'heroicon-o-signal',
                ],
                [
                    'name' => 'Queues',
                    'flag' => 'surface.queue',
                    'price' => $queue,
                    'unit' => 'per queue / mo',
                    'desc' => 'A managed job queue Laravel talks to with its built-in SQS driver — nothing to install, no Redis to provision. Priced by capacity, never per job: ten jobs or ten million costs the same.',
                    'icon' => 'heroicon-o-queue-list',
                ],
            ];

            \Laravel\Pennant\Feature::loadMissing(array_column($managedServices, 'flag'));
        @endphp
        <section class="pb-12 px-4 sm:px-6 lg:px-8">
            <div class="mx-auto max-w-5xl">
                <div class="text-center">
                    <h2 class="text-2xl font-bold text-edge-text">Services your apps lean on</h2>
                    <p class="mt-2 text-edge-mute">Managed infrastructure that isn't a place to run an app. Add them to any paid plan; each is priced per resource by tier.</p>
                </div>
                <div class="mt-6 grid gap-4 sm:grid-cols-2">
                    @foreach ($managedServices as $service)
                        @php $comingSoon = ! \Laravel\Pennant\Feature::active($service['flag']); @endphp
                        <div @class([
                            'relative  border border-edge-line bg-edge-panel p-6',
                            'opacity-75' => $comingSoon,
                        ])>
                            <div class="flex items-start justify-between gap-2">
                                <x-dynamic-component :component="$service['icon']" class="h-7 w-7 text-edge-lime" aria-hidden="true" />
                                @if ($comingSoon)
                                    <span class="inline-flex items-center rounded-full bg-edge-lime/10 px-2.5 py-0.5 text-xs font-medium text-edge-lime ring-1 ring-inset ring-edge-line">Coming soon</span>
                                @endif
                            </div>
                            <h3 class="mt-3 text-base font-semibold text-edge-text">{{ $service['name'] }}</h3>
                            <div class="mt-2 flex items-baseline gap-1">
                                <span class="text-sm font-medium text-edge-mute">from</span>
                                <span class="text-2xl font-bold text-edge-text">${{ number_format($service['price'], 0) }}</span>
                                <span class="text-sm text-edge-mute">{{ $service['unit'] }}</span>
                            </div>
                            <p class="mt-2 text-sm text-edge-mute">{{ $service['desc'] }}</p>

                            @if ($service['name'] === 'Queues')
                                {{-- The single most important sentence on this page for
                                     Queue. Given its own band because "$9/queue" and
                                     "free with Serverless" on the same card is exactly
                                     where a reader decides the pricing is either
                                     generous or confusing. --}}
                                <p class="mt-3 flex items-start gap-1.5 bg-edge-lime/10 p-2.5 text-sm font-medium text-edge-lime ring-1 ring-inset ring-edge-line">
                                    <x-heroicon-m-check-circle class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
                                    <span>Free on Serverless. Sites you deploy to dply Serverless get a queue automatically at no charge — the per-queue price applies to Cloud, Edge, and your own servers.</span>
                                </p>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        </section>

        @feature('global.billing_enabled')
            @include('partials.pricing-calculator')
        @else
            <section class="border-t border-edge-line bg-edge-panel py-16 px-4 sm:px-6 lg:px-8">
                <div class="max-w-2xl mx-auto text-center">
                    <h2 class="text-2xl font-semibold text-edge-text">Pricing TBA</h2>
                    <p class="mt-3 text-edge-mute leading-relaxed">
                        dply is in invite-only beta. The plans above describe what
                        we plan to charge once billing turns on. While we're in beta there
                        is no charge, and there will be at least 30 days' notice before
                        billing begins for any account.
                    </p>
                </div>
            </section>
        @endfeature

        {{-- FAQ --}}
        @php
            $faqs = [
                [
                    'q' => 'Why per-server count and not per-seat?',
                    'a' => 'dply\'s cost scales with the work it does for each server: agent traffic, metrics ingestion, deploys, scheduler ticks, audit. Counting servers matches that cost honestly and keeps pricing predictable. Team seats are always unlimited, and each plan includes a generous site allowance that grows as you move up.',
                ],
                [
                    'q' => 'What if I run on Hetzner / cheap providers — am I overpaying?',
                    'a' => 'No. dply prices its own work, not your cloud invoice. A Hetzner customer and an AWS customer pay the same dply plan for the same number of servers. If your infra is cheap, that\'s a great deal for you — dply is the platform fee, your provider bill is separate.',
                ],
                [
                    'q' => 'Is there really a free tier?',
                    'a' => 'Yes. Your first server is free forever on the Free plan — full product, real deploys, no credit card. You only move to a paid plan when you add a second server or use a managed product (Cloud, Edge, or Serverless).',
                ],
                [
                    'q' => 'Can I cancel anytime?',
                    'a' => 'Yes. Cancel any time through Stripe\'s billing portal. We don\'t hold your data hostage — your servers keep running on your provider; dply just stops managing them. Re-subscribe and reconnect later anytime.',
                ],
                [
                    'q' => 'What happens when I cross a plan ceiling?',
                    'a' => 'dply moves you to the next plan automatically and Stripe prorates the difference for the rest of your cycle. Servers under one day old don\'t count toward your total, so short-lived test boxes are free.',
                ],
                [
                    'q' => 'How does managed Edge / Cloud / Serverless billing work?',
                    'a' => 'They bill à la carte on top of any paid plan, each starting from a flat per-unit fee: Edge from $' . number_format($edge, 0) . '/site (plus metered CDN delivery on dply\'s managed Cloudflare for high-traffic sites), Cloud from $' . number_format($cloud, 0) . '/app (a platform fee plus the metered DigitalOcean compute, workers, and databases your app runs on, at cost plus margin), and Serverless from $' . number_format($serverless, 0) . '/function. Serverless has two modes: bring your own FaaS account (AWS Lambda, Cloudflare Workers, etc.) and your provider bills the compute directly, or let dply host it — then the flat fee covers the platform and dply meters invocations plus any managed database or cache at cost plus margin. They require a paid plan.',
                ],
                [
                    'q' => 'Why is Queues free on Serverless but paid everywhere else?',
                    'a' => 'Because a serverless app can\'t queue at all without a shared job store — the defaults either drop jobs silently or never enqueue them. Making you buy something before your queue works would be a bad first deploy, so on dply Serverless a queue comes with the site at no charge. On Cloud, Edge, or your own servers you already have a working option (Redis on a box you run), so there dply Queue is a convenience you\'re choosing, and it\'s priced from $' . number_format($queue, 0) . '/queue/mo by capacity tier. If a site later moves off Serverless, its queue moves onto that price — and we tell you when it happens, before it reaches an invoice.',
                ],
                [
                    'q' => 'How do you handle Enterprise / large fleets?',
                    'a' => 'Talk to us. Above the Business plan most teams want a contract, volume pricing, SSO, and an MSA. We do that as a custom Enterprise plan rather than a higher self-serve tier.',
                ],
                [
                    'q' => 'Is there an API or CLI?',
                    'a' => 'Yes — both are included on every plan, no add-on fee. The Edge REST API has a public OpenAPI 3 spec at /openapi/edge.json. The dply CLI ships as @dply/cli on npm and uses OAuth device flow to authenticate. Org-scoped tokens have granular abilities so CI pipelines stay narrowly permissioned.',
                ],
            ];
        @endphp
        <section class="border-t border-edge-line bg-edge-panel py-16 px-4 sm:px-6 lg:px-8">
            <div class="mx-auto max-w-3xl">
                <h2 class="text-2xl font-bold text-center text-edge-text">Frequently asked</h2>
                <div class="mt-8 space-y-2">
                    @foreach ($faqs as $i => $faq)
                        <details class="group border border-edge-line bg-edge-panel px-5 py-4">
                            <summary class="cursor-pointer list-none flex items-center justify-between gap-4">
                                <span class="font-semibold text-edge-text">{{ $faq['q'] }}</span>
                                <svg class="h-5 w-5 shrink-0 text-edge-mute transition-transform group-open:rotate-180" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.06l3.71-3.83a.75.75 0 111.08 1.04l-4.25 4.39a.75.75 0 01-1.08 0L5.21 8.27a.75.75 0 01.02-1.06z" clip-rule="evenodd"/></svg>
                            </summary>
                            <p class="mt-3 text-sm text-edge-mute leading-relaxed">{{ $faq['a'] }}</p>
                        </details>
                    @endforeach
                </div>
                <p class="mt-10 text-sm text-edge-mute text-center">
                    Still curious? <a href="mailto:hello@dply.io" class="font-semibold text-edge-text hover:text-edge-lime underline underline-offset-2">Email us</a>.
                </p>
            </div>
        </section>
    </main>

    <x-edge-marketing-footer />
    @livewireScripts
</body>
</html>
