<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <x-seo-meta
        full-title="{{ config('app.name') }} – Your whole app, deployed from Git"
        description="dply edge deploys static sites, server-rendered apps and PHP, Rails or Node servers from a Git push — with managed Postgres, MySQL, Valkey and queue workers alongside. One bill, no servers to run." />
    <meta name="csrf-token" content="{{ csrf_token() }}">

    @include('partials.theme-head')

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
    <style>
        [x-cloak] { display: none !important; }

        /* Scroll reveal — one transition, no per-element choreography. */
        .reveal { opacity: 0; transform: translateY(20px); transition: opacity .6s cubic-bezier(.2,.7,.2,1), transform .6s cubic-bezier(.2,.7,.2,1); }
        .reveal.reveal-in { opacity: 1; transform: none; }

        /* The one moving part on the page: the cursor at the end of the deploy
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
                    <p class="reveal font-terminal text-xs tracking-[0.12em] text-edge-lime">SITES · SERVER_APPS · DATABASES · WORKERS</p>

                    <h1 class="reveal mt-6 text-[2.75rem] font-bold leading-[1.02] tracking-[-0.045em] sm:text-6xl lg:text-[3.9rem]" style="transition-delay:.06s">
                        Push a repo.<br>Get the whole app<br>running.
                    </h1>

                    <p class="reveal mt-7 max-w-xl text-base leading-7 text-edge-dim" style="transition-delay:.12s">
                        Your Laravel, Rails or Node app, your static and server-rendered sites, deployed straight from Git. The database, cache and queue workers they need live right next to them. No servers to patch, nothing to configure first, and one bill for all of it.
                    </p>

                    <div class="reveal mt-9 flex flex-col items-start gap-4 sm:flex-row sm:items-center" style="transition-delay:.18s">
                        <a href="{{ route('register') }}" class="font-terminal inline-flex items-center gap-2.5 bg-edge-lime px-6 py-3.5 text-sm font-bold text-edge-void transition-colors hover:bg-edge-lime-bright">
                            start a 5-day trial →
                        </a>
                        <p class="font-terminal text-[13px] text-edge-mute">then $20/mo · cancel anytime</p>
                    </div>

                    <p class="reveal mt-4 text-sm text-edge-mute" style="transition-delay:.21s">
                        Not ready to add a card?
                        <a href="{{ route('coming-soon') }}" class="border-b border-edge-lime/50 pb-0.5 text-edge-text transition-colors hover:border-edge-lime hover:text-edge-lime">Join the waitlist</a>
                        and we’ll keep you posted.
                    </p>

                    <p class="reveal mt-6 text-sm text-edge-mute" style="transition-delay:.24s">
                        Coming from Forge, Heroku, Vercel or Netlify?
                        <a href="{{ route('register') }}" class="border-b border-edge-lime/50 pb-0.5 text-edge-text transition-colors hover:border-edge-lime hover:text-edge-lime">Point us at the repo</a>
                        and we’ll detect the framework and bring your build settings with you.
                    </p>
                </div>

                {{-- One deploy of a real-shaped app: site, server, data, workers. --}}
                <div class="reveal mt-12 lg:col-span-5 lg:mt-0" style="transition-delay:.3s">
                    <div class="border border-edge-line bg-edge-panel">
                        <div class="flex items-center justify-between border-b border-edge-line px-4 py-2.5">
                            <span class="font-terminal truncate text-[11px] text-edge-mute">acme/storefront@main</span>
                            <span class="font-terminal shrink-0 text-[11px] text-edge-lime">● LIVE</span>
                        </div>
                        <div class="font-terminal space-y-1 px-4 py-4 text-xs leading-6 text-edge-dim">
                            <p><span class="text-edge-faint">detected</span> laravel 12 · php 8.4</p>
                            <p><span class="text-edge-lime">✓</span> build image <span class="text-edge-faint">41.3s</span></p>
                            <p><span class="text-edge-lime">✓</span> assets → edge cache <span class="text-edge-faint">2.1s</span></p>
                            <p><span class="text-edge-lime">✓</span> app container · autoscale 1–5 <span class="text-edge-faint">9.8s</span></p>
                            <p><span class="text-edge-lime">✓</span> postgres · valkey attached <span class="text-edge-faint">0.4s</span></p>
                            <p><span class="text-edge-lime">✓</span> queue workers × 2 <span class="text-edge-faint">3.6s</span></p>
                            <p class="mt-3 border-t border-edge-line pt-3 text-edge-text">storefront.on-dply.app<span class="edge-cursor ml-1 text-edge-lime">▊</span></p>
                            <p class="text-edge-faint">https ready · migrations ran · 0 failed jobs</p>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        {{-- ========================== WHAT RUNS HERE ========================= --}}
        <section id="what-runs-here" class="border-b border-edge-line">
            <div class="mx-auto max-w-6xl px-6 py-20 lg:px-10">
                <p class="reveal font-terminal text-xs tracking-[0.12em] text-edge-lime">WHAT_RUNS_HERE</p>
                <h2 class="reveal mt-4 max-w-2xl text-3xl font-bold tracking-[-0.035em] sm:text-[2.5rem] sm:leading-[1.1]" style="transition-delay:.06s">
                    Everything an app needs, in one project
                </h2>
                <p class="reveal mt-4 max-w-2xl text-base leading-7 text-edge-dim" style="transition-delay:.1s">
                    Start with a marketing site. Add the API, the database and the workers when you need them — same repo, same dashboard, same invoice.
                </p>

                <div class="mt-12 grid gap-px bg-edge-line sm:grid-cols-2 lg:grid-cols-4">
                    @foreach ([
                        ['SITES', 'Static & SSR', 'Astro, Next.js, Nuxt, SvelteKit, Hugo or plain HTML. Files served from the edge; server routes render there too.', ['Preview per branch', 'Instant rollback', 'Custom domains + TLS']],
                        ['SERVER_APPS', 'PHP, Rails & Node', 'Your framework, unchanged, in a container. It scales out on traffic and sleeps when idle, so the meter stops too.', ['Laravel, Rails, Express', 'Scale to zero', 'Logs + live tail']],
                        ['DATA', 'Databases & cache', 'Managed Postgres, MySQL, MongoDB and Valkey beside your app, plus SQLite at the edge for lighter work.', ['Daily backups', 'Point-in-time restore', 'Query console']],
                        ['WORKERS', 'Queues & jobs', 'Always-on queue workers that autoscale on backlog, with the scheduler built in and failed jobs one click from retry.', ['Autoscaling', 'Worker groups', 'Failed-job alerts']],
                    ] as $i => [$tag, $title, $body, $points])
                        <div class="reveal flex flex-col bg-edge-void p-7" style="transition-delay:{{ .06 * $i + .14 }}s">
                            <p class="font-terminal text-[11px] text-edge-lime">{{ $tag }}</p>
                            <h3 class="mt-3 text-lg font-bold tracking-[-0.02em]">{{ $title }}</h3>
                            <p class="mt-2 text-sm leading-6 text-edge-mute">{{ $body }}</p>
                            <ul class="font-terminal mt-5 space-y-1.5 border-t border-edge-line pt-4 text-xs text-edge-dim">
                                @foreach ($points as $point)
                                    <li><span class="text-edge-lime">+</span> {{ $point }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>

        {{-- ========================== HOW IT WORKS ========================== --}}
        <section id="how-it-works" class="border-b border-edge-line">
            <div class="mx-auto max-w-6xl px-6 py-20 lg:grid lg:grid-cols-12 lg:gap-14 lg:px-10">
                <div class="lg:col-span-5">
                    <p class="reveal font-terminal text-xs tracking-[0.12em] text-edge-lime">HOW_IT_WORKS</p>
                    <h2 class="reveal mt-4 text-3xl font-bold tracking-[-0.035em] sm:text-[2.5rem] sm:leading-[1.1]" style="transition-delay:.06s">
                        No pipeline to assemble
                    </h2>
                    <p class="reveal mt-4 text-base leading-7 text-edge-dim" style="transition-delay:.1s">
                        We read the repo and fill in the rest. Nothing to write before the first deploy — a <span class="font-terminal text-[13px] text-edge-text">dply.yaml</span> is there if you want one later.
                    </p>
                </div>

                <ol class="mt-12 space-y-px bg-edge-line lg:col-span-7 lg:mt-0">
                    @foreach ([
                        ['01', 'Connect Git', 'GitHub, GitLab or Bitbucket. Deploy a branch on every push, or pin a tag.'],
                        ['02', 'Confirm what we found', 'Framework, build command, runtime, and whether it needs a server — monorepo packages included. Override anything.'],
                        ['03', 'Attach what it needs', 'A database, a cache, a queue. Credentials land in the environment; you don’t copy connection strings around.'],
                        ['04', 'Ship', 'A timed, streaming build log and an HTTPS hostname before it finishes. Every later push does it again.'],
                    ] as $i => [$num, $title, $body])
                        <li class="reveal flex gap-6 bg-edge-void p-6" style="transition-delay:{{ .06 * $i + .14 }}s">
                            <p class="font-terminal pt-0.5 text-[11px] text-edge-lime">{{ $num }}</p>
                            <div>
                                <h3 class="text-base font-bold tracking-[-0.02em]">{{ $title }}</h3>
                                <p class="mt-1.5 text-sm leading-6 text-edge-mute">{{ $body }}</p>
                            </div>
                        </li>
                    @endforeach
                </ol>
            </div>
        </section>

        {{-- ============================= DAY TWO ============================ --}}
        <section class="border-b border-edge-line">
            <div class="mx-auto max-w-6xl px-6 py-20 lg:px-10">
                <div class="flex flex-col items-start justify-between gap-6 md:flex-row md:items-end">
                    <div>
                        <p class="reveal font-terminal text-xs tracking-[0.12em] text-edge-lime">DAY_TWO</p>
                        <h2 class="reveal mt-4 text-3xl font-bold tracking-[-0.035em] sm:text-[2.5rem] sm:leading-[1.1]" style="transition-delay:.06s">
                            The parts you only notice later
                        </h2>
                    </div>
                    <a href="{{ route('features') }}" class="reveal font-terminal inline-flex shrink-0 items-center gap-2 border border-edge-line px-5 py-3 text-sm text-edge-text transition-colors hover:border-edge-lime hover:text-edge-lime" style="transition-delay:.1s">
                        see everything included →
                    </a>
                </div>

                <div class="mt-12 grid gap-px bg-edge-line sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ([
                        ['Preview branches', 'Every branch gets its own URL, with review comments pinned to the page.'],
                        ['Instant rollback', 'Every deploy is kept. Promote an old one back in a click — no rebuild.'],
                        ['Access rules', 'Password-gate staging, allow-list an office, rate-limit a path.'],
                        ['Real request logs', 'Live tail, CSV export, and Core Web Vitals from actual visitors.'],
                        ['Restore to a minute', 'Point-in-time recovery on managed databases, not just nightly dumps.'],
                        ['Alerts that matter', 'Failed jobs, crashing workers and downtime reach Slack, email or PagerDuty.'],
                    ] as $i => [$title, $body])
                        <div class="reveal bg-edge-void p-6" style="transition-delay:{{ .04 * $i + .14 }}s">
                            <h3 class="text-sm font-bold tracking-[-0.01em]">{{ $title }}</h3>
                            <p class="mt-1.5 text-sm leading-6 text-edge-mute">{{ $body }}</p>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>

        {{-- ============================= PRICING ============================ --}}
        <section>
            <div class="mx-auto max-w-6xl px-6 py-20 lg:grid lg:grid-cols-12 lg:gap-14 lg:px-10">
                <div class="lg:col-span-5">
                    <p class="reveal font-terminal text-xs tracking-[0.12em] text-edge-lime">PRICING</p>
                    <h2 class="reveal mt-4 text-3xl font-bold tracking-[-0.035em] sm:text-[2.5rem] sm:leading-[1.1]" style="transition-delay:.06s">
                        Two plans. Usage past them is metered.
                    </h2>
                    <p class="reveal mt-4 text-base leading-7 text-edge-dim" style="transition-delay:.1s">
                        Try Pro free for 5 days. Past a plan’s allowance, extra sites are $2/mo, Worker SSR sites $7/mo, and compute is billed by the second it runs — nothing is throttled.
                    </p>
                    <a href="{{ route('pricing') }}" class="reveal mt-6 inline-block text-sm text-edge-mute transition-colors hover:text-edge-text" style="transition-delay:.14s">See full pricing →</a>
                </div>

                <div class="mt-12 grid gap-px bg-edge-line sm:grid-cols-2 lg:col-span-7 lg:mt-0">
                    @foreach ([
                        ['Pro', '$20', 'For real projects', ['10 sites · 3 seats', '1,000 build minutes', '10 databases · 10 queues', '$5 compute included', 'Autoscaling workers']],
                        ['Team', '$49', 'For your whole company', ['50 sites · 5 seats', '3,000 build minutes', '50 databases · 50 queues', '$20 compute included', 'Audit log']],
                    ] as $i => [$name, $price, $tagline, $points])
                        <div class="reveal flex flex-col bg-edge-void p-7" style="transition-delay:{{ .06 * $i + .14 }}s">
                            <p class="text-lg font-bold tracking-[-0.02em]">{{ $name }}</p>
                            <p class="mt-1 text-sm text-edge-mute">{{ $tagline }}</p>
                            <p class="mt-5"><span class="text-4xl font-bold tracking-[-0.04em]">{{ $price }}</span><span class="font-terminal text-[13px] text-edge-mute"> /mo</span></p>
                            <ul class="font-terminal mt-6 space-y-1.5 text-xs text-edge-dim">
                                @foreach ($points as $point)
                                    <li><span class="text-edge-lime">+</span> {{ $point }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>

        {{-- ============================== CLOSE ============================= --}}
        <section class="border-t border-edge-line">
            <div class="mx-auto flex max-w-6xl flex-col items-start justify-between gap-8 px-6 py-16 lg:flex-row lg:items-center lg:px-10">
                <h2 class="reveal text-2xl font-bold tracking-[-0.03em] sm:text-3xl">Your next deploy could be the last one you configure.</h2>
                <div class="reveal flex shrink-0 flex-col items-start gap-3 lg:items-end" style="transition-delay:.08s">
                    <a href="{{ route('register') }}" class="font-terminal inline-flex items-center bg-edge-lime px-7 py-3.5 text-sm font-bold text-edge-void transition-colors hover:bg-edge-lime-bright">
                        start a 5-day trial →
                    </a>
                    <a href="{{ route('coming-soon') }}" class="text-sm text-edge-mute transition-colors hover:text-edge-lime">or join the waitlist</a>
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
