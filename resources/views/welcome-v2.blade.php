<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @head
    <meta name="csrf-token" content="{{ csrf_token() }}">

    @include('partials.theme-head')

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
    <style>
        [x-cloak] { display: none !important; }

        /* The homepage: a replaying deploy console, four live capability panels,
           an edge-traffic field, the deploy steps and the plans. Everything is
           scoped under .wl so it never meets the app's own utility classes.
           The markup is a complete still frame; the script at the bottom only
           animates it, and reduced-motion readers keep the still frame. */
        .wl { --bg: var(--color-edge-void); --panel: var(--color-edge-panel); --tint: var(--color-edge-raise);
              --line: var(--color-edge-line); --ink: var(--color-edge-text); --dim: var(--color-edge-dim);
              --muted: var(--color-edge-mute); --faint: var(--color-edge-faint);
              --accent: var(--color-edge-lime); --accent-hi: var(--color-edge-lime-bright);
              --warn: #e2a06a; --bad: #f07a62;
              --mono: var(--font-terminal); --display: var(--font-display); }
        .wl .wrap { max-width: 72rem; margin: 0 auto; padding-inline: 1.5rem; }
        .wl section { padding-block: 5rem; border-bottom: 1px solid var(--line); }
        .wl h1, .wl h2, .wl h3 { margin: 0; text-wrap: balance; letter-spacing: -0.035em; line-height: 1.05; font-weight: 700; }
        .wl h1 { font-size: clamp(2.6rem, 6vw, 4.2rem); letter-spacing: -0.045em; }
        .wl h2 { font-size: clamp(1.9rem, 3.4vw, 2.5rem); }
        .wl h3 { font-size: 1.15rem; letter-spacing: -0.02em; }
        .wl .eyebrow { font-family: var(--mono); font-size: .75rem; letter-spacing: .12em; color: var(--accent); }
        .wl .lede { color: var(--dim); font-size: 1rem; line-height: 1.75; max-width: 40rem; }
        .wl .muted { color: var(--muted); }
        .wl .head { display: grid; gap: 1rem; margin-bottom: 3rem; }
        .wl .btn { display: inline-flex; align-items: center; gap: .6rem; padding: .9rem 1.5rem; font: 700 .875rem var(--mono); background: var(--accent); color: var(--bg); transition: background .15s, transform .15s; }
        .wl .btn:hover { background: var(--accent-hi); transform: translateY(-1px); }
        .wl .link { color: var(--ink); border-bottom: 1px solid color-mix(in srgb, var(--accent) 50%, transparent); padding-bottom: 2px; transition: color .15s, border-color .15s; }
        .wl .link:hover { color: var(--accent); border-color: var(--accent); }
        .wl a:focus-visible, .wl button:focus-visible { outline: 2px solid var(--accent); outline-offset: 3px; }

        /* hero */
        .wl .hero { display: grid; grid-template-columns: 1fr 1.05fr; gap: 3.5rem; align-items: center; padding-block: 4.5rem 5rem; }
        .wl .hero > * { min-width: 0; }
        .wl .hero-copy { display: grid; gap: 1.6rem; justify-items: start; }
        .wl .swap { color: var(--accent); display: inline-block; }
        .wl .ctas { display: flex; flex-wrap: wrap; align-items: center; gap: 1rem; }
        .wl .small { font-size: .875rem; color: var(--muted); line-height: 1.6; }
        .wl .term { background: #070806; border: 1px solid var(--line); font-family: var(--mono); font-size: .8rem; box-shadow: 0 30px 60px -30px rgba(0,0,0,.7); }
        .wl .term-bar { display: flex; align-items: center; gap: .5rem; padding: .7rem 1rem; border-bottom: 1px solid var(--line); color: var(--faint); font-size: .7rem; }
        .wl .term-bar span { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .wl .term-bar button { color: var(--faint); font: inherit; border: 1px solid var(--line); padding: .1rem .5rem; cursor: pointer; }
        .wl .term-bar button:hover { color: var(--accent); border-color: var(--accent); }
        .wl .term-status { color: var(--accent); font-weight: 400; }
        .wl .term-body { padding: 1rem 1.1rem 1.2rem; min-height: 21rem; display: grid; align-content: start; gap: .3rem; color: var(--dim); }
        .wl .tl { display: flex; gap: .6rem; white-space: nowrap; overflow: hidden; margin: 0; }
        .wl .tl.in { animation: wl-in .25s ease-out; }
        .wl .tl .t { margin-left: auto; color: var(--faint); font-variant-numeric: tabular-nums; }
        .wl .tl .g { color: var(--faint); }
        .wl .hi { color: var(--accent); }
        .wl .mark { display: inline-block; width: 1ch; color: var(--accent); }
        .wl .live { margin: .7rem 0 0; display: flex; align-items: center; gap: .6rem; padding: .6rem .8rem; border: 1px solid color-mix(in srgb, var(--accent) 35%, transparent); background: color-mix(in srgb, var(--accent) 6%, transparent); color: var(--ink); }
        .wl .dot { width: 8px; height: 8px; border-radius: 50%; background: var(--accent); flex: none; animation: wl-ping 1.6s infinite; }
        @keyframes wl-in { from { opacity: 0; transform: translateY(4px); } }
        @keyframes wl-ping { 0% { box-shadow: 0 0 0 0 color-mix(in srgb, var(--accent) 55%, transparent); } 70%, 100% { box-shadow: 0 0 0 8px transparent; } }

        /* ticker */
        .wl .ticker { border-block: 1px solid var(--line); overflow: hidden; padding-block: .9rem; font-family: var(--mono); font-size: .8rem; color: var(--muted); }
        .wl .ticker-track { display: flex; gap: 3rem; width: max-content; animation: wl-slide 40s linear infinite; }
        .wl .ticker-track span::before { content: "▲ "; color: var(--accent); }
        @keyframes wl-slide { to { transform: translateX(-50%); } }

        /* capabilities */
        .wl .caps { display: grid; grid-template-columns: repeat(2, 1fr); gap: 1px; background: var(--line); border: 1px solid var(--line); }
        .wl .cap { background: var(--bg); padding: 1.75rem; display: grid; gap: .9rem; align-content: start; min-width: 0; }
        .wl .cap .tag { font-family: var(--mono); font-size: .7rem; color: var(--accent); }
        .wl .cap p { color: var(--muted); font-size: .9rem; line-height: 1.6; margin: 0; }
        .wl .stage { background: var(--panel); border: 1px solid var(--line); height: 13rem; position: relative; overflow: hidden; font-family: var(--mono); font-size: .72rem; color: var(--dim); }
        .wl .stage > div, .wl .stage > canvas { position: absolute; inset: .9rem; }
        .wl .stage > canvas { width: calc(100% - 1.8rem); height: calc(100% - 1.8rem); }
        .wl .chips { display: flex; flex-wrap: wrap; gap: .4rem; font-family: var(--mono); font-size: .7rem; color: var(--dim); }
        .wl .chips span { border: 1px solid var(--line); padding: .2rem .6rem; }
        .wl .chips span::before { content: "+ "; color: var(--accent); }
        .wl canvas { display: block; width: 100%; height: 100%; }
        .wl .branches { display: grid; gap: .5rem; align-content: start; }
        .wl .br { display: grid; grid-template-columns: auto 1fr auto; gap: .6rem; align-items: center; border: 1px solid var(--line); padding: .5rem .7rem; background: var(--bg); transition: border-color .4s; min-width: 0; }
        .wl .br.enter { animation: wl-in .4s ease-out; }
        .wl .br.flash { border-color: var(--accent); }
        .wl .br .u { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: var(--faint); }
        .wl .br .who { color: var(--ink); white-space: nowrap; }
        .wl .pill { font-size: .65rem; padding: .1rem .5rem; border: 1px solid currentColor; white-space: nowrap; }
        .wl .ok { color: var(--accent); } .wl .warn { color: var(--warn); } .wl .mut { color: var(--faint); }
        .wl .apps { display: grid; grid-template-rows: auto 1fr auto; gap: .5rem; }
        .wl .apps canvas { min-height: 0; }
        .wl .row-between { display: flex; justify-content: space-between; gap: 1rem; }
        .wl .row-between b { color: var(--ink); font-weight: 400; font-variant-numeric: tabular-nums; }
        .wl .pods { display: flex; gap: .35rem; height: 1.6rem; }
        .wl .pod { flex: 1; border: 1px dashed var(--line); display: grid; place-items: center; font-size: .6rem; color: transparent; transition: background .4s, color .4s, border-color .4s; }
        .wl .pod.on { background: var(--accent); border: 1px solid var(--accent); color: var(--bg); }
        .wl .sql { display: grid; grid-template-rows: auto 1fr auto; gap: .6rem; }
        .wl .sql-in { border: 1px solid var(--line); background: var(--bg); padding: .45rem .6rem; color: var(--ink); min-height: 2.2rem; overflow-wrap: anywhere; }
        .wl .sql-in .p { color: var(--accent); }
        .wl .sql-out { display: grid; align-content: start; gap: .15rem; }
        .wl .sql-out .r { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: .4rem; font-variant-numeric: tabular-nums; animation: wl-in .3s ease-out; }
        .wl .sql-out .r.h { color: var(--faint); border-bottom: 1px solid var(--line); padding-bottom: .2rem; }
        .wl .sql-out .r:not(.h) { color: var(--ink); }
        .wl .pitr-bar { height: 2px; background: var(--line); position: relative; margin-top: .45rem; }
        .wl .pitr-bar i { position: absolute; top: -4px; width: 10px; height: 10px; background: var(--accent); transform: translateX(-50%); transition: left 1.2s cubic-bezier(.6,0,.2,1); }

        /* edge field */
        .wl .edge { display: grid; grid-template-columns: 1.4fr 1fr; gap: 3rem; align-items: end; }
        .wl .edge > * { min-width: 0; }
        .wl .field { height: 20rem; border: 1px solid var(--line); background: var(--panel); }
        .wl .stats { display: grid; gap: 1.1rem; }
        .wl .stat { display: grid; gap: .15rem; border-top: 1px solid var(--line); padding-top: .9rem; }
        .wl .stat small { font-family: var(--mono); font-size: .7rem; color: var(--muted); }
        .wl .stat b { font-size: 2rem; font-weight: 700; letter-spacing: -0.03em; font-variant-numeric: tabular-nums; }
        .wl .note { font-family: var(--mono); font-size: .7rem; color: var(--faint); margin: 0; }

        /* steps */
        .wl .steps { list-style: none; margin: 0; padding: 0; display: grid; grid-template-columns: repeat(4, 1fr); gap: 1.5rem; position: relative; }
        .wl .steps::before { content: ""; position: absolute; top: 1.25rem; left: 1.25rem; right: 1.25rem; height: 1px; background: var(--line); }
        .wl .steps .fill { position: absolute; top: 1.25rem; left: 1.25rem; height: 1px; background: var(--accent); width: 0; transition: width .6s ease; }
        .wl .step { position: relative; display: grid; gap: .6rem; align-content: start; min-width: 0; }
        .wl .step .n { width: 2.5rem; height: 2.5rem; display: grid; place-items: center; background: var(--bg); border: 1px solid var(--line); font: 700 .75rem var(--mono); color: var(--muted); transition: all .4s; }
        .wl .step.on .n { background: var(--accent); border-color: var(--accent); color: var(--bg); }
        .wl .step p { color: var(--muted); font-size: .875rem; line-height: 1.6; margin: 0; }

        /* day two + plans share the hairline grid */
        .wl .grid { display: grid; gap: 1px; background: var(--line); border: 1px solid var(--line); }
        .wl .grid > * { background: var(--bg); padding: 1.6rem; }
        .wl .grid-3 { grid-template-columns: repeat(3, 1fr); }
        .wl .grid h3 { font-size: .95rem; }
        .wl .grid p { color: var(--muted); font-size: .875rem; line-height: 1.6; margin: .4rem 0 0; }
        .wl .split { display: flex; flex-wrap: wrap; align-items: end; justify-content: space-between; gap: 1.5rem; margin-bottom: 3rem; }
        .wl .split .head { margin-bottom: 0; }
        .wl .plan { display: grid; align-content: start; transition: background .25s; }
        .wl .plan:hover { background: var(--panel); }
        .wl .plan.feat { box-shadow: inset 0 2px 0 var(--accent); }
        .wl .price { margin: 1rem 0 0; font-size: 2.6rem; font-weight: 700; letter-spacing: -0.04em; color: var(--ink); }
        .wl .price small { font: 400 .8rem var(--mono); color: var(--muted); letter-spacing: 0; }
        .wl .plan ul { list-style: none; margin: 1.2rem 0 0; padding: 0; display: grid; gap: .4rem; font-family: var(--mono); font-size: .75rem; color: var(--dim); }
        .wl .plan li::before { content: "+ "; color: var(--accent); }
        .wl .close { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 2rem; }
        .wl .close h2 { font-size: clamp(1.5rem, 2.6vw, 1.9rem); max-width: 30rem; }
        .wl .close-links { display: grid; gap: .6rem; justify-items: start; }

        @media (max-width: 900px) {
            .wl .hero, .wl .edge { grid-template-columns: 1fr; }
            .wl .caps, .wl .grid-3 { grid-template-columns: 1fr; }
            .wl .steps { grid-template-columns: 1fr 1fr; row-gap: 2rem; }
            .wl .steps::before, .wl .steps .fill { display: none; }
            .wl section { padding-block: 3.5rem; }
        }
        @media (max-width: 480px) { .wl .steps { grid-template-columns: 1fr; } .wl .term { font-size: .7rem; } }
        @media (prefers-reduced-motion: reduce) {
            .wl *, .wl *::before { animation: none !important; transition: none !important; }
        }
    </style>
</head>
<body class="bg-edge-void font-display text-edge-text antialiased">
@include('partials.skip-link')

    <x-edge-marketing-header />

    <main id="main-content" tabindex="-1" class="wl">
        {{-- ============================== HERO ============================== --}}
        <div class="wrap">
            <header class="hero">
                <div class="hero-copy">
                    <p class="eyebrow">SITES · SERVER_APPS · DATABASES · WORKERS</p>
                    <h1>Push a repo.<br> Get the whole <span class="swap" data-swap>app</span><br> running.</h1>
                    <p class="lede">Your Laravel, Rails or Node app, your static and server-rendered sites, deployed straight from Git. The database, cache and queue workers they need live right next to them. No servers to patch, nothing to configure first, and one bill for all of it.</p>
                    <div class="ctas">
                        <a href="{{ route('register') }}" class="btn">start a 5-day trial →</a>
                        <span class="small" style="font-family: var(--mono)">then $20/mo · cancel anytime</span>
                    </div>
                    <p class="small">Not ready to add a card? <a href="{{ route('coming-soon') }}" class="link">Join the waitlist</a> and we’ll keep you posted.</p>
                    <p class="small">Coming from Forge, Heroku, Vercel or Netlify? <a href="{{ route('register') }}" class="link">Point us at the repo</a> and we’ll detect the framework and bring your build settings with you.</p>
                </div>

                <div class="term" aria-label="A deploy of a Laravel storefront">
                    <div class="term-bar"><span>acme/storefront@main</span><button type="button" data-replay hidden>↻ replay</button><b class="term-status" data-status>● LIVE</b></div>
                    <div class="term-body" data-deploy>
                        <p class="tl"><span class="g">$</span><span>git push origin main</span></p>
                        <p class="tl"><span class="g">→</span><span>detected <span class="hi">laravel 12</span> · php 8.4</span></p>
                        <p class="tl" data-step="41.3"><span class="mark">✓</span><span>build image</span><span class="t">41.3s</span></p>
                        <p class="tl" data-step="2.1"><span class="mark">✓</span><span>assets → edge cache</span><span class="t">2.1s</span></p>
                        <p class="tl" data-step="9.8"><span class="mark">✓</span><span>app container · autoscale 1–5</span><span class="t">9.8s</span></p>
                        <p class="tl" data-step="0.4"><span class="mark">✓</span><span>postgres · valkey attached</span><span class="t">0.4s</span></p>
                        <p class="tl" data-step="3.6"><span class="mark">✓</span><span>queue workers × 2</span><span class="t">3.6s</span></p>
                        <p class="live" data-live><span class="dot"></span><span>storefront.on-dply.app</span><span class="muted" style="margin-left:auto">https · migrations ran</span></p>
                    </div>
                </div>
            </header>
        </div>

        <div class="ticker" aria-hidden="true"><div class="ticker-track">
            {{-- Listed twice so the scroll loops without a seam. --}}
            @foreach (array_merge($stack = ['Astro', 'Next.js', 'Nuxt', 'SvelteKit', 'Hugo', 'Laravel', 'Rails', 'Express', 'Postgres', 'MySQL', 'MongoDB', 'Valkey', 'SQLite at the edge', 'GitHub', 'GitLab', 'Bitbucket'], $stack) as $item)
                <span>{{ $item }}</span>
            @endforeach
        </div></div>

        {{-- ========================== WHAT RUNS HERE ========================= --}}
        <section id="what-runs-here">
            <div class="wrap">
                <div class="head">
                    <p class="eyebrow">WHAT_RUNS_HERE</p>
                    <h2>Everything an app needs, in one project</h2>
                    <p class="lede">Start with a marketing site. Add the API, the database and the workers when you need them — same repo, same dashboard, same invoice.</p>
                </div>
                <div class="caps">
                    <article class="cap">
                        <span class="tag">SITES</span>
                        <h3>Static &amp; SSR</h3>
                        <p>Astro, Next.js, Nuxt, SvelteKit, Hugo or plain HTML. Files served from the edge; server routes render there too.</p>
                        <div class="stage" aria-hidden="true"><div class="branches" data-sites>
                            <div class="br"><span class="who">⎇ main</span><span class="u">storefront.com</span><span class="pill ok">production</span></div>
                            <div class="br"><span class="who">⎇ feat/checkout</span><span class="u">feat-checkout.storefront.on-dply.app</span><span class="pill ok">preview</span></div>
                            <div class="br"><span class="who">↺ deploys</span><span class="u">v42 · v41 · v40</span><span class="pill mut">v42 live</span></div>
                        </div></div>
                        <div class="chips"><span>preview per branch</span><span>instant rollback</span><span>custom domains + TLS</span></div>
                    </article>

                    <article class="cap">
                        <span class="tag">SERVER_APPS</span>
                        <h3>PHP, Rails &amp; Node</h3>
                        <p>Your framework, unchanged, in a container. It scales out on traffic and sleeps when idle, so the meter stops too.</p>
                        <div class="stage" aria-hidden="true"><div class="apps" data-apps>
                            <div class="row-between"><span>storefront · laravel</span><span><b data-rps>612</b> req/s · <b data-inst>3 running</b></span></div>
                            <canvas></canvas>
                            <div class="pods">@foreach (range(1, 5) as $n)<span class="pod {{ $n <= 3 ? 'on' : '' }}">pod {{ $n }}</span>@endforeach</div>
                        </div></div>
                        <div class="chips"><span>Laravel, Rails, Express</span><span>scale to zero</span><span>logs + live tail</span></div>
                    </article>

                    <article class="cap">
                        <span class="tag">DATA</span>
                        <h3>Databases &amp; cache</h3>
                        <p>Managed Postgres, MySQL, MongoDB and Valkey beside your app, plus SQLite at the edge for lighter work.</p>
                        <div class="stage" aria-hidden="true"><div class="sql" data-data>
                            <div class="sql-in"><span class="p">›</span> <span data-query>select status, count(*), sum(total) from orders group by 1;</span></div>
                            <div class="sql-out" data-result>
                                <div class="r h"><span>status</span><span>count</span><span>total</span></div>
                                <div class="r"><span>paid</span><span>18,204</span><span>$1.92M</span></div>
                                <div class="r"><span>refunded</span><span>311</span><span>$28.4k</span></div>
                                <div class="r"><span>pending</span><span>97</span><span>$9.1k</span></div>
                            </div>
                            <div><div class="row-between"><span>point-in-time restore</span><span data-at>now</span></div><div class="pitr-bar"><i data-knob style="left:100%"></i></div></div>
                        </div></div>
                        <div class="chips"><span>daily backups</span><span>point-in-time restore</span><span>query console</span></div>
                    </article>

                    <article class="cap">
                        <span class="tag">WORKERS</span>
                        <h3>Queues &amp; jobs</h3>
                        <p>Always-on queue workers that autoscale on backlog, with the scheduler built in and failed jobs one click from retry.</p>
                        <div class="stage" aria-hidden="true"><canvas data-workers></canvas></div>
                        <div class="chips"><span>autoscaling</span><span>worker groups</span><span>failed-job alerts</span></div>
                    </article>
                </div>
            </div>
        </section>

        {{-- ============================== EDGE =============================== --}}
        <section>
            <div class="wrap edge">
                <div>
                    <div class="head">
                        <p class="eyebrow">ON_THE_EDGE</p>
                        <h2>Requests answered close to the people making them</h2>
                        <p class="lede">Real request logs with Core Web Vitals from actual visitors, access rules that password-gate or rate-limit a path, and alerts to Slack, email or PagerDuty.</p>
                    </div>
                    <div class="field" aria-hidden="true"><canvas data-field></canvas></div>
                </div>
                <div class="stats">
                    <div class="stat"><small>requests, last minute</small><b data-s="req">18,240</b></div>
                    <div class="stat"><small>LCP p75</small><b><span data-s="lcp">0.94</span>s</b></div>
                    <div class="stat"><small>cache hit ratio</small><b><span data-s="hit">96.1</span>%</b></div>
                    <div class="stat"><small>blocked by access rules</small><b data-s="blk">112</b></div>
                    <p class="note">Example figures, the way a site's Analytics tab shows them.</p>
                </div>
            </div>
        </section>

        {{-- ========================== HOW IT WORKS ========================== --}}
        <section id="how-it-works">
            <div class="wrap">
                <div class="head">
                    <p class="eyebrow">HOW_IT_WORKS</p>
                    <h2>No pipeline to assemble</h2>
                    <p class="lede">We read the repo and fill in the rest. Nothing to write before the first deploy — a <span style="font-family: var(--mono); color: var(--ink)">dply.yaml</span> is there if you want one later.</p>
                </div>
                <div class="steps" data-steps>
                    <span class="fill" data-fill></span>
                    @foreach ([
                        ['Connect Git', 'GitHub, GitLab or Bitbucket. Deploy a branch on every push, or pin a tag.'],
                        ['Confirm what we found', 'Framework, build command, runtime, and whether it needs a server — monorepo packages included. Override anything.'],
                        ['Attach what it needs', 'A database, a cache, a queue. Credentials land in the environment; you don’t copy connection strings around.'],
                        ['Ship', 'A timed, streaming build log and an HTTPS hostname before it finishes. Every later push does it again.'],
                    ] as $i => [$title, $body])
                        <div class="step {{ $i === 0 ? 'on' : '' }}"><span class="n">0{{ $i + 1 }}</span><h3>{{ $title }}</h3><p>{{ $body }}</p></div>
                    @endforeach
                </div>
            </div>
        </section>

        {{-- ============================= DAY TWO ============================ --}}
        <section>
            <div class="wrap">
                <div class="split">
                    <div class="head">
                        <p class="eyebrow">DAY_TWO</p>
                        <h2>The parts you only notice later</h2>
                    </div>
                    <a href="{{ route('features') }}" class="link" style="font-family: var(--mono); font-size: .875rem">see everything included →</a>
                </div>
                <div class="grid grid-3">
                    @foreach ([
                        ['Preview branches', 'Every branch gets its own URL, with review comments pinned to the page.'],
                        ['Instant rollback', 'Every deploy is kept. Promote an old one back in a click — no rebuild.'],
                        ['Access rules', 'Password-gate staging, allow-list an office, rate-limit a path.'],
                        ['Real request logs', 'Live tail, CSV export, and Core Web Vitals from actual visitors.'],
                        ['Restore to a minute', 'Point-in-time recovery on managed databases, not just nightly dumps.'],
                        ['Alerts that matter', 'Failed jobs, crashing workers and downtime reach Slack, email or PagerDuty.'],
                    ] as [$title, $body])
                        <div><h3>{{ $title }}</h3><p>{{ $body }}</p></div>
                    @endforeach
                </div>
            </div>
        </section>

        {{-- ============================= PRICING ============================ --}}
        <section id="plans">
            <div class="wrap">
                <div class="head">
                    <p class="eyebrow">PRICING</p>
                    <h2>Three plans. Unlimited sites. Usage included.</h2>
                    <p class="lede">Try Pro free for 5 days. Every plan includes usage credit. Past it, apps, databases and Valkey bill by the second they run, and requests and bandwidth by the unit — nothing is throttled. <a href="{{ route('pricing') }}" class="link">See full pricing →</a></p>
                </div>
                <div class="grid grid-3">
                    @foreach (['starter' => 'For side projects', 'pro' => 'For real projects', 'team' => 'For your whole company'] as $key => $tagline)
                        @php
                            $plan = config('subscription.standard.tiers.'.$key);
                            $points = [
                                'Unlimited sites',
                                trans_choice('{1} 1 seat|[2,*] :count seats', (int) $plan['seats'], ['count' => (int) $plan['seats']]).($plan['extra_seat_cents'] ? ' · $'.number_format($plan['extra_seat_cents'] / 100, 0).'/extra' : ''),
                                '$'.number_format($plan['usage_credit_cents'] / 100, 0).' usage included',
                                $plan['databases'].' databases · '.$plan['queues'].' queues',
                                $plan['audit_log'] ? 'Audit log' : ($plan['worker_autoscale'] && $plan['worker_instances'] !== 1 ? 'Autoscaling workers' : $plan['concurrent_builds'].' build at a time'),
                            ];
                        @endphp
                        <div class="plan {{ $key === 'pro' ? 'feat' : '' }}">
                            <h3 style="font-size:1.15rem">{{ $plan['label'] }}</h3>
                            <p>{{ $tagline }}</p>
                            <p class="price">${{ number_format($plan['price_cents'] / 100, 0) }}<small> /mo</small></p>
                            <ul>@foreach ($points as $point)<li>{{ $point }}</li>@endforeach</ul>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>

        {{-- ============================== CLOSE ============================= --}}
        <section style="border-bottom:0">
            <div class="wrap close">
                <h2>Your next deploy could be the last one you configure.</h2>
                <div class="close-links">
                    <a href="{{ route('register') }}" class="btn">start a 5-day trial →</a>
                    <a href="{{ route('coming-soon') }}" class="small link">or join the waitlist</a>
                    <a href="{{ route('docs.show', 'guides/migrate-from-forge-cloud') }}" class="small link">moving from Forge or Laravel Cloud? →</a>
                </div>
            </div>
        </section>
    </main>

    <x-edge-marketing-footer />
    @livewireScripts

    <script>
    (() => {
        if (matchMedia('(prefers-reduced-motion: reduce)').matches) return;

        const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
        const rand = (a, b) => a + Math.random() * (b - a);
        const tok = (n) => getComputedStyle(document.documentElement).getPropertyValue('--color-edge-' + n).trim();
        const monoFont = getComputedStyle(document.documentElement).getPropertyValue('--font-terminal').trim() || 'monospace';
        const $ = (s, root = document) => root.querySelector(s);

        // Loops only do work while their element is on screen.
        const seen = new WeakMap();
        const io = new IntersectionObserver((es) => es.forEach((e) => seen.set(e.target, e.isIntersecting)));
        const watch = (el) => { seen.set(el, false); io.observe(el); return el; };
        const visible = async (el) => { while (! seen.get(el)) await sleep(400); };
        const fit = (c) => {
            const dpr = devicePixelRatio || 1, w = c.clientWidth, h = c.clientHeight;
            if (c.width !== Math.round(w * dpr) || c.height !== Math.round(h * dpr)) { c.width = Math.round(w * dpr); c.height = Math.round(h * dpr); }
            const ctx = c.getContext('2d');
            ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
            ctx.clearRect(0, 0, w, h);
            return [ctx, w, h];
        };

        /* hero word swap */
        const words = ['app', 'site', 'API', 'queue', 'stack'];
        const swap = $('[data-swap]');
        let wi = 0;
        setInterval(() => {
            wi = (wi + 1) % words.length;
            swap.animate([{ opacity: 1 }, { opacity: 0, transform: 'translateY(-8px)' }], { duration: 180 }).onfinish = () => {
                swap.textContent = words[wi];
                swap.animate([{ opacity: 0, transform: 'translateY(8px)' }, { opacity: 1, transform: 'none' }], { duration: 220 });
            };
        }, 2600);

        /* deploy console: replay the still trace */
        const lines = [...$('[data-deploy]').querySelectorAll('.tl')];
        const live = $('[data-live]'), status = $('[data-status]'), replay = $('[data-replay]');
        const cmd = lines[0].children[1], cmdText = cmd.textContent;
        const frames = '⠋⠙⠹⠸⠼⠴⠦⠧⠇⠏';
        let run = 0;
        const deploy = async () => {
            const id = ++run;
            replay.hidden = true;
            status.textContent = '○ BUILDING';
            [...lines, live].forEach((el) => { el.hidden = true; el.classList.remove('in'); });
            lines[0].hidden = false; cmd.textContent = '';
            for (const ch of cmdText) { if (id !== run) return; cmd.textContent += ch; await sleep(rand(30, 60)); }
            for (const el of lines.slice(1)) {
                if (id !== run) return;
                el.hidden = false; el.classList.add('in');
                const mark = $('.mark', el), t = $('.t', el);
                if (! mark) { await sleep(450); continue; }
                let f = 0;
                const spin = setInterval(() => { mark.textContent = frames[f++ % frames.length]; }, 80);
                t.textContent = '…';
                await sleep(Math.min(1400, 300 + parseFloat(el.dataset.step) * 70));
                clearInterval(spin);
                mark.textContent = '✓'; t.textContent = el.dataset.step + 's';
            }
            if (id !== run) return;
            live.hidden = false;
            status.textContent = '● LIVE';
            replay.hidden = false;
        };
        replay.addEventListener('click', deploy);
        deploy();

        /* SITES: a branch preview builds, a bad deploy rolls back */
        const sites = watch($('[data-sites]'));
        const [main, , deploys] = sites.children;
        const pill = (row, text, tone) => { const p = $('.pill', row); p.className = 'pill ' + tone; p.textContent = text; };
        (async () => {
            const branches = [['⎇ fix/hero-img', 'fix-hero-img.storefront.on-dply.app'], ['⎇ feat/search', 'feat-search.storefront.on-dply.app']];
            for (let n = 0; ; n++) {
                await visible(sites);
                const [who, url] = branches[n % branches.length];
                const row = document.createElement('div');
                row.className = 'br enter';
                row.innerHTML = '<span class="who"></span><span class="u"></span><span class="pill warn">building</span>';
                row.children[0].textContent = who; row.children[1].textContent = url;
                sites.insertBefore(row, deploys);
                await sleep(1800); pill(row, 'preview', 'ok');
                await sleep(1500);
                $('.u', deploys).textContent = 'v43 · v42 · v41';
                pill(deploys, 'v43 live', 'warn'); pill(main, 'errors ↑', 'warn');
                await sleep(1500);
                deploys.classList.add('flash');
                pill(deploys, '↺ v42 live', 'ok'); pill(main, 'production', 'ok');
                await sleep(2600);
                deploys.classList.remove('flash');
                $('.u', deploys).textContent = 'v42 · v41 · v40'; pill(deploys, 'v42 live', 'mut');
                row.remove();
                await sleep(700);
            }
        })();

        /* SERVER_APPS: a day of traffic drives instances, down to zero */
        const apps = watch($('[data-apps]'));
        const tc = $('canvas', apps), pods = [...apps.querySelectorAll('.pod')];
        const N = 90, series = [];
        let t = 0;
        const traffic = (t) => {
            const p = (t % 600) / 600;
            if (p > 0.85 || p < 0.08) return 0;
            const base = Math.max(0, Math.sin(p * Math.PI * 2 - Math.PI / 2) * 0.5 + 0.35);
            return Math.max(0, base + Math.exp(-((p - 0.55) ** 2) / 0.002) * 0.9 + rand(-0.04, 0.04));
        };
        for (t = 200; t < 200 + N; t++) series.push(traffic(t));
        const drawTraffic = () => {
            const [ctx, w, h] = fit(tc);
            const y = (v) => h - 2 - (Math.min(v, 1.3) / 1.3) * (h - 6);
            ctx.strokeStyle = tok('line'); ctx.lineWidth = 1;
            for (let g = 1; g < 4; g++) { ctx.beginPath(); ctx.moveTo(0, (h * g) / 4); ctx.lineTo(w, (h * g) / 4); ctx.stroke(); }
            ctx.beginPath();
            series.forEach((v, i) => (i ? ctx.lineTo : ctx.moveTo).call(ctx, (i / (N - 1)) * w, y(v)));
            ctx.strokeStyle = tok('lime'); ctx.lineWidth = 1.5; ctx.stroke();
            ctx.lineTo(w, h); ctx.lineTo(0, h); ctx.closePath();
            ctx.globalAlpha = 0.12; ctx.fillStyle = tok('lime'); ctx.fill(); ctx.globalAlpha = 1;
            ctx.beginPath(); ctx.arc(w - 3, y(series[N - 1]), 3, 0, 7); ctx.fillStyle = tok('lime'); ctx.fill();
        };
        setInterval(() => {
            if (! seen.get(apps)) return;
            series.shift(); const v = traffic(t++); series.push(v);
            const want = v === 0 ? 0 : Math.min(5, Math.max(1, Math.ceil(v * 4.2)));
            pods.forEach((p, i) => p.classList.toggle('on', i < want));
            $('[data-rps]', apps).textContent = Math.round(v * 840);
            $('[data-inst]', apps).textContent = want ? want + ' running' : 'asleep';
            drawTraffic();
        }, 120);
        drawTraffic();
        addEventListener('resize', drawTraffic);

        /* DATA: point-in-time restore, then a query in the console */
        const data = watch($('[data-data]'));
        const query = $('[data-query]', data), result = $('[data-result]', data), knob = $('[data-knob]', data), at = $('[data-at]', data);
        const queries = [
            ['select plan, count(*) from customers group by 1;', ['plan', 'count', ''], [['monthly', '6,842', ''], ['annual', '2,105', ''], ['trial', '488', '']]],
            ['select status, count(*), sum(total) from orders group by 1;', ['status', 'count', 'total'], [['paid', '18,204', '$1.92M'], ['refunded', '311', '$28.4k'], ['pending', '97', '$9.1k']]],
        ];
        const row = (cells, cls) => { const r = document.createElement('div'); r.className = 'r ' + cls; cells.forEach((c) => { const s = document.createElement('span'); s.textContent = c; r.appendChild(s); }); return r; };
        (async () => {
            for (let n = 0; ; n++) {
                await sleep(2400);
                await visible(data);
                knob.style.left = '38%'; at.textContent = 'yesterday 14:02';
                await sleep(2000);
                knob.style.left = '100%'; at.textContent = 'now';
                await sleep(1400);
                const [sql, head, rows] = queries[n % queries.length];
                result.replaceChildren();
                for (let i = 1; i <= sql.length; i++) { query.textContent = sql.slice(0, i); await sleep(26); }
                await sleep(300);
                result.appendChild(row(head, 'h'));
                for (const r of rows) { await sleep(160); result.appendChild(row(r, '')); }
            }
        })();

        /* WORKERS: backlog in, workers scale, a failed-job alert */
        const qc = watch($('[data-workers]'));
        let jobs = [], active = 2, qt = 0, alert = 0, done = 0;
        const lanes = 5;
        const spawn = () => jobs.push({ x: -8, y: 0, lane: -1, state: 'queued', p: 0, fail: Math.random() < 0.04 });
        for (let i = 0; i < 6; i++) spawn();
        const workers = () => {
            requestAnimationFrame(workers);
            if (! seen.get(qc)) return;
            const [ctx, w, h] = fit(qc);
            const lime = tok('lime'), line = tok('line'), text = tok('dim'), mute = tok('mute');
            qt++;
            if (Math.random() < (Math.sin(qt / 160) > 0.3 ? 0.2 : 0.05)) spawn();
            const queued = jobs.filter((j) => j.state === 'queued');
            if (qt % 40 === 0) active += Math.sign(Math.min(lanes, Math.max(1, Math.ceil(queued.length / 4))) - active);
            const top = 22, bottom = h - 22, qx = 2, qw = w * 0.4, wx = w * 0.52, ww = w * 0.48 - 2, lh = (bottom - top) / lanes;
            ctx.font = '11px ' + monoFont;
            ctx.fillStyle = mute;
            ctx.fillText('queue · ' + queued.length + ' waiting', qx, 12);
            ctx.fillText('workers · ' + active + '/' + lanes + ' · ' + done + ' done', wx, 12);
            ctx.strokeStyle = line; ctx.lineWidth = 1;
            ctx.strokeRect(qx + 0.5, (top + bottom) / 2 - 11.5, qw, 23);
            for (let i = 0; i < lanes; i++) {
                ctx.setLineDash(i < active ? [] : [3, 3]);
                ctx.strokeStyle = i < active ? lime : line;
                ctx.strokeRect(wx + 0.5, top + i * lh + 2.5, ww - 1, lh - 5);
            }
            ctx.setLineDash([]);
            queued.forEach((j, i) => { j.x += (Math.max(qx + qw - 8 - i * 8, qx + 6) - j.x) * 0.15; j.y = (top + bottom) / 2; });
            const busy = new Set(jobs.filter((j) => j.state === 'move' || j.state === 'work').map((j) => j.lane));
            for (let l = 0; l < active; l++) {
                const j = ! busy.has(l) && jobs.find((j) => j.state === 'queued');
                if (j) { j.state = 'move'; j.lane = l; }
            }
            for (const j of jobs) {
                if (j.state === 'move') {
                    const tx = wx + 8, ty = top + j.lane * lh + lh / 2;
                    j.x += (tx - j.x) * 0.2; j.y += (ty - j.y) * 0.2;
                    if (Math.abs(tx - j.x) < 1) j.state = 'work';
                } else if (j.state === 'work') {
                    j.p += 0.02; j.x = wx + 8 + (ww - 16) * j.p;
                    if (j.p >= 1) { j.state = j.fail ? 'failed' : 'done'; if (j.fail) alert = 120; else done++; }
                } else if (j.state !== 'queued') {
                    j.x += 3; j.dead = j.x > w + 8;
                }
                ctx.beginPath(); ctx.arc(j.x, j.y, 3.5, 0, 7);
                ctx.fillStyle = j.state === 'failed' ? '#f07a62' : j.state === 'queued' ? text : lime;
                ctx.fill();
            }
            jobs = jobs.filter((j) => ! j.dead);
            if (alert > 0) alert--;
            ctx.fillStyle = alert ? '#f07a62' : mute;
            ctx.fillText(alert ? '! SendInvoiceEmail failed → #alerts' : 'scheduler · every minute', qx, h - 4);
        };
        workers();

        /* EDGE: requests arriving at edge locations (an abstract layout, not a map) */
        const fc = watch($('[data-field]'));
        const pops = Array.from({ length: 34 }, () => ({ x: rand(0.06, 0.94), y: rand(0.12, 0.88) }));
        const pulses = [];
        let req = 18240, blk = 112;
        const field = () => {
            requestAnimationFrame(field);
            if (! seen.get(fc)) return;
            const [ctx, w, h] = fit(fc);
            const lime = tok('lime'), line = tok('line'), faint = tok('faint');
            ctx.fillStyle = line;
            for (let x = 12; x < w; x += 18) for (let y = 12; y < h; y += 18) ctx.fillRect(x, y, 1.5, 1.5);
            if (Math.random() < 0.5) {
                const p = pops[Math.floor(Math.random() * pops.length)], a = rand(0, 7), d = rand(30, 80);
                pulses.push({ p, sx: p.x * w + Math.cos(a) * d, sy: p.y * h + Math.sin(a) * d, t: 0, blocked: Math.random() < 0.03 });
            }
            pops.forEach((p) => { ctx.beginPath(); ctx.arc(p.x * w, p.y * h, 2.5, 0, 7); ctx.fillStyle = faint; ctx.fill(); });
            for (const q of pulses) {
                q.t += 0.03;
                const px = q.p.x * w, py = q.p.y * h, k = Math.min(1, q.t);
                ctx.strokeStyle = q.blocked ? '#f07a62' : lime;
                ctx.globalAlpha = 0.35 * (1 - Math.max(0, q.t - 1));
                ctx.beginPath(); ctx.moveTo(q.sx, q.sy); ctx.lineTo(q.sx + (px - q.sx) * k, q.sy + (py - q.sy) * k); ctx.stroke();
                ctx.globalAlpha = 1;
                if (q.t >= 1) {
                    ctx.beginPath(); ctx.arc(px, py, (q.t - 1) * 30, 0, 7);
                    ctx.globalAlpha = Math.max(0, 1 - (q.t - 1) * 2); ctx.stroke(); ctx.globalAlpha = 1;
                    if (! q.counted) { q.counted = true; req += Math.floor(rand(3, 14)); if (q.blocked) blk++; }
                }
            }
            while (pulses.length && pulses[0].t > 1.6) pulses.shift();
        };
        field();
        setInterval(() => {
            if (! seen.get(fc)) return;
            $('[data-s="req"]').textContent = req.toLocaleString();
            $('[data-s="lcp"]').textContent = rand(0.82, 1.14).toFixed(2);
            $('[data-s="hit"]').textContent = rand(94.2, 97.8).toFixed(1);
            $('[data-s="blk"]').textContent = blk.toLocaleString();
        }, 900);

        /* HOW_IT_WORKS: walk the steps along the line */
        const steps = [...document.querySelectorAll('[data-steps] .step')], fill = $('[data-fill]');
        let si = 0;
        setInterval(() => {
            si = (si + 1) % steps.length;
            steps.forEach((s, k) => s.classList.toggle('on', k <= si));
            fill.style.width = ($('.n', steps[si]).getBoundingClientRect().left - $('.n', steps[0]).getBoundingClientRect().left) + 'px';
        }, 1400);
    })();
    </script>
</body>
</html>
