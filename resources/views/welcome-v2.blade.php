<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <x-seo-meta
        full-title="{{ config('app.name') }} – Push a repo, get a site on the edge"
        description="dply edge builds your Git repository and publishes it on Dply Edge. Static, hybrid or Worker SSR — with an HTTPS hostname before the first build finishes." />
    <meta name="csrf-token" content="{{ csrf_token() }}">

    @include('partials.theme-head')

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
    <style>
        [x-cloak] { display: none !important; }

        /* Scroll reveal — one transition, no per-element choreography. */
        .reveal { opacity: 0; transform: translateY(20px); transition: opacity .6s cubic-bezier(.2,.7,.2,1), transform .6s cubic-bezier(.2,.7,.2,1); }
        .reveal.reveal-in { opacity: 1; transform: none; }

        /* The one moving part on the page: the cursor at the end of the build
           trace. Everything else is still, which is what makes it read. */
        @keyframes edge-blink { 0%, 49% { opacity: 1; } 50%, 100% { opacity: 0; } }
        .edge-cursor { animation: edge-blink 1.1s step-end infinite; }

        @media (prefers-reduced-motion: reduce) {
            .reveal { opacity: 1 !important; transform: none !important; transition: none; }
            .edge-cursor { animation: none; }
        }
    </style>
</head>
<body class="bg-edge-void font-display text-edge-text antialiased">
@include('partials.skip-link')

    <x-edge-marketing-header />

    <main id="main-content" tabindex="-1">
        {{-- ============================== HERO ============================== --}}
        <section class="border-b border-edge-line">
            <div class="mx-auto max-w-6xl px-6 pb-16 pt-16 lg:grid lg:grid-cols-12 lg:gap-14 lg:px-10 lg:pb-20 lg:pt-24">
                <div class="lg:col-span-7">
                    <p class="reveal font-terminal text-xs tracking-[0.12em] text-edge-lime">STATIC · HYBRID · WORKER_SSR</p>

                    <h1 class="reveal mt-6 text-[2.75rem] font-bold leading-[1.02] tracking-[-0.045em] sm:text-6xl lg:text-[3.9rem]" style="transition-delay:.06s">
                        Push a repo.<br>Get a site<br>on the edge.
                    </h1>

                    <p class="reveal mt-7 max-w-xl text-base leading-7 text-edge-dim" style="transition-delay:.12s">
                        dply edge builds your Git repository in a clean container and publishes the output on Dply Edge. You get an HTTPS hostname before the first build finishes.
                    </p>

                    <div class="reveal mt-9 flex flex-col items-start gap-4 sm:flex-row sm:items-center" style="transition-delay:.18s">
                        <a href="{{ route('register') }}" class="font-terminal inline-flex items-center gap-2.5 bg-edge-lime px-6 py-3.5 text-sm font-bold text-edge-void transition-colors hover:bg-edge-lime-bright">
                            deploy your first site →
                        </a>
                        <p class="font-terminal text-[13px] text-edge-mute">$2/site/mo + usage</p>
                    </div>

                    <p class="reveal mt-6 text-sm text-edge-mute" style="transition-delay:.24s">
                        Already on Vercel, Netlify or Pages?
                        <a href="{{ route('register') }}" class="border-b border-edge-lime/50 pb-0.5 text-edge-text transition-colors hover:border-edge-lime hover:text-edge-lime">Import the project</a>
                        and keep your build settings.
                    </p>
                </div>

                {{-- The build, as the machine reported it. --}}
                <div class="reveal mt-12 lg:col-span-5 lg:mt-0" style="transition-delay:.3s">
                    <div class="border border-edge-line bg-edge-panel">
                        <div class="flex items-center justify-between border-b border-edge-line px-4 py-2.5">
                            <span class="font-terminal truncate text-[11px] text-edge-mute">tomshafer/marketing-site@main</span>
                            <span class="font-terminal shrink-0 text-[11px] text-edge-lime">● LIVE</span>
                        </div>
                        <div class="font-terminal space-y-1 px-4 py-4 text-xs leading-6 text-edge-dim">
                            <p><span class="text-edge-lime">✓</span> clone a3f91c2 <span class="text-edge-faint">4.2s</span></p>
                            <p><span class="text-edge-lime">✓</span> npm ci <span class="text-edge-faint">11.0s</span></p>
                            <p><span class="text-edge-lime">✓</span> npm run build <span class="text-edge-faint">38.1s</span></p>
                            <p><span class="text-edge-lime">✓</span> publish → r2 <span class="text-edge-faint">2.4s</span></p>
                            <p class="mt-3 border-t border-edge-line pt-3 text-edge-text">marketing-site.on-dply.app<span class="edge-cursor ml-1 text-edge-lime">▊</span></p>
                            <p class="text-edge-faint">https ready · 42ms p95 · 0 errors</p>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        {{-- ========================= DELIVERY MODES ========================= --}}
        <section class="border-b border-edge-line">
            <div class="mx-auto grid max-w-6xl grid-cols-1 md:grid-cols-3">
                @foreach ([
                    ['01', 'Static / SSG', '$2/mo', 'CDN-only. Astro, Eleventy, Hugo, Vite, plain HTML. Most sites belong here.'],
                    ['02', 'Hybrid', '$2/mo', 'Static assets on the edge, your own HTTPS origin behind the routes that need a server.'],
                    ['03', 'Worker SSR', '$7/mo', 'Server rendering on the edge itself. No origin to run. Replaces the $2 site fee.'],
                ] as $i => [$num, $name, $price, $body])
                    <div @class([
                        'reveal px-6 py-7 lg:px-10',
                        'border-b border-edge-line md:border-b-0 md:border-r' => $i < 2,
                    ]) style="transition-delay:{{ .06 * $i }}s">
                        <p class="font-terminal text-[11px] text-edge-faint">{{ $num }}</p>
                        <p class="mt-2 text-lg font-bold tracking-[-0.02em]">{{ $name }}</p>
                        <p class="mt-1.5 text-sm leading-6 text-edge-mute">{{ $body }}</p>
                        <p class="font-terminal mt-3 text-[13px] text-edge-lime">{{ $price }}</p>
                    </div>
                @endforeach
            </div>
        </section>

        {{-- ========================== HOW IT WORKS ========================== --}}
        <section id="how-it-works" class="border-b border-edge-line">
            <div class="mx-auto max-w-6xl px-6 py-20 lg:px-10">
                <p class="reveal font-terminal text-xs tracking-[0.12em] text-edge-lime">HOW_IT_WORKS</p>
                <h2 class="reveal mt-4 max-w-2xl text-3xl font-bold tracking-[-0.035em] sm:text-[2.5rem] sm:leading-[1.1]" style="transition-delay:.06s">
                    Two screens between a repo and a hostname
                </h2>
                <p class="reveal mt-4 max-w-2xl text-base leading-7 text-edge-dim" style="transition-delay:.1s">
                    There is no pipeline to assemble and no config file required. Connect the repo, confirm what we detected, deploy.
                </p>

                <div class="mt-12 grid gap-px bg-edge-line md:grid-cols-3">
                    @foreach ([
                        ['01', 'Connect Git', 'Pick a repo from a connected account or paste a public remote. Target a branch, a tag, or a specific commit — a tag pins the build, a branch redeploys when you push.'],
                        ['02', 'Confirm the build', 'We read the repo and fill in the framework, build command and output directory — including the right package in a monorepo. Override any of it, or don’t.'],
                        ['03', 'Watch it go live', 'Four steps, timed, with the build log streaming as it happens. The hostname resolves with HTTPS before the build finishes, so you can share the link immediately.'],
                    ] as $i => [$num, $title, $body])
                        <div class="reveal bg-edge-void p-7" style="transition-delay:{{ .06 * $i + .14 }}s">
                            <p class="font-terminal text-[11px] text-edge-lime">{{ $num }}</p>
                            <h3 class="mt-3 text-lg font-bold tracking-[-0.02em]">{{ $title }}</h3>
                            <p class="mt-2 text-sm leading-6 text-edge-mute">{{ $body }}</p>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>

        {{-- ============================= DAY TWO ============================ --}}
        <section class="border-b border-edge-line">
            <div class="mx-auto max-w-6xl px-6 py-20 lg:grid lg:grid-cols-12 lg:gap-14 lg:px-10">
                <div class="lg:col-span-5">
                    <p class="reveal font-terminal text-xs tracking-[0.12em] text-edge-lime">DAY_TWO</p>
                    <h2 class="reveal mt-4 text-3xl font-bold tracking-[-0.035em] sm:text-[2.5rem] sm:leading-[1.1]" style="transition-delay:.06s">
                        The parts you only notice later
                    </h2>
                    <p class="reveal mt-4 text-base leading-7 text-edge-dim" style="transition-delay:.1s">
                        Rollback, preview URLs and request logs ship with every site — no add-ons, no second product.
                    </p>
                    <a href="{{ route('features') }}" class="reveal font-terminal mt-7 inline-flex items-center gap-2 border border-edge-line px-5 py-3 text-sm text-edge-text transition-colors hover:border-edge-lime hover:text-edge-lime" style="transition-delay:.14s">
                        see everything included →
                    </a>
                </div>

                <div class="mt-12 grid gap-px bg-edge-line sm:grid-cols-2 lg:col-span-7 lg:mt-0">
                    @foreach ([
                        ['Preview branches', 'Every branch gets its own hostname, with comments pinned to the page.'],
                        ['Instant rollback', 'Every deploy is kept. Promote an old one back in a click — no rebuild.'],
                        ['Access rules', 'Password-gate a staging site, allow-list an office, rate-limit a path.'],
                        ['Real request logs', 'Live tail, CSV export, and Core Web Vitals from actual visitors.'],
                        ['Your account, optionally', 'Run on our account, or bring a credential and keep the zone yourself.'],
                        ['dply.yaml, if you want it', 'Export what you configured in the UI and check it into the repo.'],
                    ] as $i => [$title, $body])
                        <div class="reveal bg-edge-void p-5" style="transition-delay:{{ .04 * $i + .16 }}s">
                            <h3 class="text-sm font-bold tracking-[-0.01em]">{{ $title }}</h3>
                            <p class="mt-1.5 text-sm leading-6 text-edge-mute">{{ $body }}</p>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>

        {{-- ============================= PRICING ============================ --}}
        <section>
            <div class="mx-auto flex max-w-6xl flex-col items-start justify-between gap-10 px-6 py-20 lg:flex-row lg:items-center lg:px-10">
                <div class="reveal">
                    <h2 class="text-3xl font-bold tracking-[-0.035em] sm:text-[2.5rem] sm:leading-[1.1]">Free, then Pro and Team</h2>
                    <p class="mt-4 max-w-2xl text-base leading-7 text-edge-dim">
                        Free includes unlimited sites, seats, and builds, with $5 of usage credit and container apps that sleep when idle. Pro and Team include more traffic and compute, then $2/mo for each site past the plan. Worker SSR sites are $7/mo. Preview branches count as usage.
                    </p>
                </div>
                <div class="reveal flex shrink-0 flex-col items-stretch gap-3" style="transition-delay:.08s">
                    <a href="{{ route('register') }}" class="font-terminal inline-flex items-center justify-center bg-edge-lime px-7 py-3.5 text-sm font-bold text-edge-void transition-colors hover:bg-edge-lime-bright">
                        deploy your first site →
                    </a>
                    <a href="{{ route('pricing') }}" class="text-center text-sm text-edge-mute transition-colors hover:text-edge-text">See full pricing</a>
                </div>
            </div>
        </section>
    </main>

    <x-edge-marketing-footer />
    @livewireScripts

    <script>
    (() => {
        const els = document.querySelectorAll('.reveal');
        const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        if (reduce || !('IntersectionObserver' in window)) {
            els.forEach((el) => el.classList.add('reveal-in'));
            return;
        }

        const io = new IntersectionObserver((entries) => {
            entries.forEach((entry) => {
                if (! entry.isIntersecting) return;
                entry.target.classList.add('reveal-in');
                io.unobserve(entry.target);
            });
        }, { rootMargin: '0px 0px -10% 0px', threshold: 0.05 });

        els.forEach((el) => io.observe(el));
    })();
    </script>
</body>
</html>
