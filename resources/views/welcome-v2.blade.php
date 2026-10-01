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

        /* the product window: the app's own Overview, recreated */
        .wl .win { border: 1px solid var(--line); background: var(--bg); box-shadow: 0 40px 80px -40px rgba(0,0,0,.8); overflow: hidden; }
        .wl .win-bar { display: flex; align-items: center; gap: 1rem; padding: .7rem 1rem; border-bottom: 1px solid var(--line); font: .72rem var(--mono); color: var(--muted); }
        .wl .win-bar .brand { color: var(--ink); font-weight: 700; }
        .wl .win-bar .brand b { color: var(--accent); }
        .wl .win-bar .crumb { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .wl .win-bar .go { background: var(--accent); color: var(--bg); padding: .25rem .7rem; font-weight: 700; }
        .wl .win-body { display: grid; grid-template-columns: 12rem 1fr; min-height: 34rem; }
        .wl .win-side { border-right: 1px solid var(--line); padding: 1rem .75rem; display: grid; align-content: start; gap: .15rem; font-size: .78rem; color: var(--dim); }
        .wl .win-side .grp { font: .62rem var(--mono); letter-spacing: .12em; color: var(--faint); margin: .8rem .5rem .25rem; }
        .wl .win-side span { padding: .3rem .5rem; }
        .wl .win-side .on { background: var(--panel); color: var(--ink); }
        .wl .win-side .site { display: flex; align-items: center; gap: .5rem; padding: .3rem .5rem .7rem; color: var(--ink); font-weight: 600; }
        .wl .win-side .site i { width: 1.6rem; height: 1.6rem; display: grid; place-items: center; font: 700 .6rem var(--mono); font-style: normal; color: var(--bg); background: linear-gradient(135deg, var(--accent), #6fae4f); }
        .wl .win-main { min-width: 0; display: grid; grid-template-rows: auto 1fr auto; }
        .wl .win-head { display: flex; align-items: center; gap: .7rem; padding: .9rem 1.2rem; border-bottom: 1px solid var(--line); }
        .wl .win-head b { font-size: .95rem; }
        .wl .win-head .muted { font: .7rem var(--mono); }
        .wl .tag-live { margin-left: auto; font: .65rem var(--mono); color: var(--accent); border: 1px solid color-mix(in srgb, var(--accent) 40%, transparent); padding: .1rem .45rem; }
        .wl .topo { position: relative; display: grid; grid-template-columns: 6.5rem minmax(0, 1fr) minmax(0, 1fr) minmax(0, 1fr); gap: 2.2rem; align-items: center; padding: 1.6rem 1.4rem; background-image: radial-gradient(var(--line) 1px, transparent 1px); background-size: 16px 16px; }
        .wl .topo > canvas { position: absolute; inset: 0; pointer-events: none; }
        .wl .node { position: relative; display: grid; justify-items: center; gap: .15rem; text-align: center; font-family: var(--mono); }
        .wl .node .globe { width: 3.2rem; height: 3.2rem; border-radius: 50%; display: grid; place-items: center; border: 1px solid color-mix(in srgb, var(--accent) 50%, transparent); background: color-mix(in srgb, var(--accent) 12%, transparent); color: var(--accent); font-size: 1.2rem; box-shadow: 0 0 0 8px color-mix(in srgb, var(--accent) 5%, transparent); }
        .wl .node small { font-size: .6rem; color: var(--muted); letter-spacing: .08em; }
        .wl .node b { font-size: 1.15rem; color: var(--ink); font-variant-numeric: tabular-nums; }
        .wl .tcard { position: relative; background: var(--panel); border: 1px solid var(--line); padding: .75rem .85rem; display: grid; gap: .3rem; font-size: .7rem; color: var(--dim); min-width: 0; transition: border-color .4s, box-shadow .4s; }
        .wl .tcard.hot { border-color: color-mix(in srgb, var(--accent) 55%, transparent); box-shadow: 0 0 0 3px color-mix(in srgb, var(--accent) 10%, transparent); }
        .wl .tcard .th { display: flex; align-items: center; justify-content: space-between; gap: .5rem; font: .6rem var(--mono); letter-spacing: .1em; color: var(--muted); }
        .wl .tcard .tt { color: var(--ink); font-weight: 600; font-size: .82rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .wl .tcard .mono { font-family: var(--mono); font-size: .66rem; }
        .wl .tcard b { color: var(--ink); font-weight: 600; font-variant-numeric: tabular-nums; }
        .wl .st { font: .58rem var(--mono); letter-spacing: 0; padding: .05rem .4rem; border-radius: 999px; white-space: nowrap; transition: color .3s, background .3s; }
        .wl .st.on { color: #7fd99a; background: rgba(127,217,154,.12); }
        .wl .st.sleep { color: var(--faint); background: rgba(255,255,255,.05); }
        .wl .st.wake { color: var(--warn); background: rgba(226,160,106,.12); }
        .wl .meter { height: 4px; background: var(--line); }
        .wl .meter i { display: block; height: 100%; width: 0; background: var(--accent); transition: width 1s ease; }
        .wl .spark { height: 2.2rem; }
        .wl .res { display: grid; gap: .6rem; }
        .wl .res .tcard { padding: .55rem .75rem; }
        .wl .win-split { display: grid; grid-template-columns: 1fr 1fr; border-top: 1px solid var(--line); }
        .wl .win-split > div { padding: 1rem 1.2rem; min-width: 0; }
        .wl .win-split > div + div { border-left: 1px solid var(--line); }
        .wl .win-split .lbl { font: .62rem var(--mono); letter-spacing: .12em; color: var(--muted); display: flex; justify-content: space-between; }
        .wl .win-split .big { font-size: 1.6rem; font-weight: 700; color: var(--ink); font-variant-numeric: tabular-nums; margin: .3rem 0 .4rem; }
        .wl .bars { display: flex; align-items: end; gap: 2px; height: 3.4rem; }
        .wl .bars i { flex: 1; background: color-mix(in srgb, var(--accent) 70%, transparent); min-height: 2px; transition: height .6s ease; }
        .wl .feed { display: grid; gap: .25rem; margin-top: .5rem; font: .66rem var(--mono); color: var(--dim); height: 5.6rem; overflow: hidden; align-content: start; }
        .wl .feed div { display: grid; grid-template-columns: 2.6rem 1fr 2.2rem 3rem 2.4rem; gap: .4rem; animation: wl-in .3s ease-out; white-space: nowrap; }
        .wl .feed span { overflow: hidden; text-overflow: ellipsis; }
        .wl .feed .ok { color: #7fd99a; } .wl .feed .warn { color: var(--warn); }

        /* traffic tab recreation */
        .wl .traffic { display: grid; grid-template-columns: 1fr 1fr; gap: 1px; background: var(--line); border: 1px solid var(--line); }
        .wl .traffic > div { background: var(--bg); padding: 1.6rem; min-width: 0; }
        .wl .say { font-size: clamp(1.25rem, 2.2vw, 1.6rem); line-height: 1.35; letter-spacing: -0.02em; font-weight: 500; color: var(--ink); margin: .8rem 0 1.2rem; }
        .wl .say .n { color: var(--accent); font-variant-numeric: tabular-nums; }
        .wl .say .w { color: var(--warn); }
        .wl .rows { border-top: 1px solid var(--line); font-size: .85rem; }
        .wl .rows div { display: flex; justify-content: space-between; gap: 1rem; padding: .6rem 0; border-bottom: 1px solid var(--line); color: var(--ink); }
        .wl .rows small { font: .7rem var(--mono); color: var(--muted); white-space: nowrap; }
        .wl .map { aspect-ratio: 2 / 1; width: 100%; max-width: 100%; border: 1px solid var(--line); background: var(--panel); margin: .9rem 0; }
        .wl .colos { display: grid; gap: .1rem; font-size: .8rem; }
        .wl .colos div { display: grid; grid-template-columns: 2.6rem 1fr 4rem 3rem; gap: .6rem; align-items: center; padding: .3rem 0; border-bottom: 1px solid var(--line); }
        .wl .colos code { font: .7rem var(--mono); color: var(--accent); }
        .wl .colos .bar { height: 3px; background: var(--line); }
        .wl .colos .bar i { display: block; height: 100%; background: var(--accent); }
        .wl .colos small { font: .7rem var(--mono); color: var(--muted); text-align: right; font-variant-numeric: tabular-nums; }

        @media (max-width: 1000px) {
            .wl .win-body { grid-template-columns: 1fr; }
            .wl .win-side { display: none; }
            .wl .topo { grid-template-columns: 1fr 1fr; gap: 1.2rem; }
            .wl .topo .node { grid-column: 1 / -1; }
            .wl .topo .res { grid-column: 1 / -1; grid-template-columns: repeat(3, 1fr); }
            .wl .traffic { grid-template-columns: 1fr; }
        }
        @media (max-width: 640px) {
            .wl .topo { grid-template-columns: 1fr; }
            .wl .topo .res { grid-template-columns: 1fr; }
            .wl .win-split { grid-template-columns: 1fr; }
            .wl .win-split > div + div { border-left: 0; border-top: 1px solid var(--line); }
        }
        @media (max-width: 900px) {
            .wl .hero { grid-template-columns: 1fr; }
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
                        <p class="tl" data-step="9.8"><span class="mark">✓</span><span>new version up beside production</span><span class="t">9.8s</span></p>
                        <p class="tl" data-step="1.6"><span class="mark">✓</span><span>migrations ran · new version checked</span><span class="t">1.6s</span></p>
                        <p class="tl" data-step="0.4"><span class="mark">✓</span><span>visitors moved to the <span class="hi">new version</span></span><span class="t">0.4s</span></p>
                        <p class="tl" data-step="12.2"><span class="mark">✓</span><span>production updated · visitors moved back</span><span class="t">12.2s</span></p>
                        <p class="tl" data-step="3.6"><span class="mark">✓</span><span>queue workers × 2 restarted</span><span class="t">3.6s</span></p>
                        <p class="live" data-live><span class="dot"></span><span>storefront.on-dply.app</span><span class="muted" style="margin-left:auto">0 requests dropped</span></p>
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

        {{-- ============================ THE APP ============================== --}}
        {{-- The workspace's own Overview tab, recreated: same cards, same words. --}}
        <section id="the-app">
            <div class="wrap">
                <div class="head">
                    <p class="eyebrow">THE_DASHBOARD</p>
                    <h2>Every app gets a live map of itself</h2>
                    <p class="lede">Visitors, the edge in front, your app, and everything attached to it, on one screen. It sleeps when nobody is around, wakes on the next request, and scales out when traffic comes.</p>
                </div>
                <div class="win" aria-label="The dply app Overview for an example storefront">
                    <div class="win-bar"><span class="brand">dply<b>/</b>edge</span><span class="crumb">Dashboard / Projects / storefront / Overview</span><span class="go">↻ Deploy</span></div>
                    <div class="win-body">
                        <aside class="win-side" aria-hidden="true">
                            <span class="site"><i>ST</i>storefront</span>
                            <span class="grp">SHIP</span><span class="on">Overview</span><span>Deploys</span><span>Build</span><span>Environment</span><span>Previews</span><span>Deploy triggers</span>
                            <span class="grp">TRAFFIC</span><span>Routing</span><span>Cache</span><span>Traffic &amp; analytics</span>
                            <span class="grp">PROTECT</span><span>Security</span><span>Firewall</span><span>Rate limits</span>
                            <span class="grp">MANAGE</span><span>Alerts</span><span>Billing &amp; usage</span>
                        </aside>
                        <div class="win-main">
                            <div class="win-head"><b>storefront</b><span class="muted">acme/storefront@main</span><span class="tag-live">LIVE</span></div>
                            <div class="topo" data-topo aria-hidden="true">
                                <canvas data-topo-lines></canvas>
                                <div class="node" data-n="visitors"><span class="globe">◎</span><small>VISITORS</small><b data-k="visitors">2,840</b><small>requests, 30 days</small></div>
                                <div class="tcard" data-n="edge">
                                    <div class="th">EDGE NETWORK <span class="st on">Active</span></div>
                                    <div class="tt">storefront.com</div>
                                    <div class="mono">HTTPS · custom domain</div>
                                    <div class="mono">cache <b>On</b> · <b data-k="today">412</b> req today</div>
                                    <canvas class="spark" data-spark></canvas>
                                </div>
                                <div class="tcard" data-n="app">
                                    <div class="th">APP <span class="st on" data-k="app-state">Running</span></div>
                                    <div class="tt">Laravel · 0.5 vCPU</div>
                                    <div class="mono"><b data-k="mem">212</b> MB of 1 GiB · <b data-k="running">1</b> of 3 running</div>
                                    <div class="meter"><i data-k="mem-bar" style="width:21%"></i></div>
                                    <div class="mono" data-k="app-note">Sleeps after 5 min idle</div>
                                </div>
                                <div class="res">
                                    <div class="tcard" data-n="valkey"><div class="th">DPLY VALKEY <span class="st on">On</span></div><div class="tt">0.25 vCPU</div><div class="mono">sessions · cache · locks</div></div>
                                    <div class="tcard" data-n="workers"><div class="th">QUEUE WORKERS <span class="st on">On</span></div><div class="tt" data-k="workers">2 workers</div><div class="mono">scheduler runs every minute</div></div>
                                    <div class="tcard" data-n="db"><div class="th">DATABASE <span class="st on">Running</span></div><div class="tt">Postgres</div><div class="mono">daily backups · restore to a minute</div></div>
                                </div>
                            </div>
                            <div class="win-split">
                                <div>
                                    <div class="lbl"><span>REQUESTS PER DAY</span><span>last 30 days</span></div>
                                    <div class="big" data-k="month">2,840</div>
                                    <div class="bars" data-bars>@foreach ([3,4,2,5,6,4,7,5,6,8,7,9,6,8,10,9,7,11,9,12,10,13,11,12,14,12,15,13,16,18] as $h)<i style="height: {{ $h * 5 }}%"></i>@endforeach</div>
                                </div>
                                <div>
                                    <div class="lbl"><span>LIVE REQUESTS</span><span style="color: var(--accent)">● live</span></div>
                                    <div class="feed" data-feed>
                                        <div><span>GET</span><span>/products/sneakers</span><span class="ok">200</span><span>41 ms</span><span>IAD</span></div>
                                        <div><span>POST</span><span>/cart</span><span class="ok">200</span><span>63 ms</span><span>LHR</span></div>
                                        <div><span>GET</span><span>/</span><span class="ok">200</span><span>18 ms</span><span>SJC</span></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

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
        {{-- The Traffic tab, recreated, with its Served from map on Cloudflare's real locations. --}}
        @php
            $served = [['IAD', 3120], ['SJC', 2480], ['ORD', 1920], ['LHR', 1650], ['FRA', 1210], ['DFW', 980], ['AMS', 860], ['NRT', 640], ['SIN', 520], ['SYD', 410], ['GRU', 330], ['YYZ', 300], ['BOM', 220], ['CDG', 190]];
            $servedTotal = array_sum(array_column($served, 1));
            $colos = array_map(fn ($c) => ['colo' => $c[0], 'requests' => $c[1]] + (\App\Modules\Edge\Support\EdgeColos::place($c[0]) ?? []), $served);
            $network = array_values(array_map(fn ($p) => [$p[1], $p[2]], \App\Modules\Edge\Support\EdgeColos::PLACES));
        @endphp
        <section id="traffic">
            <div class="wrap">
                <div class="head">
                    <p class="eyebrow">ON_THE_EDGE</p>
                    <h2>See every request, and where in the world it was answered</h2>
                    <p class="lede">Each app's Traffic tab, as it looks for an example store: requests, failures and speed in plain words, and the Cloudflare locations that served its visitors.</p>
                </div>
                <div class="traffic" data-traffic>
                    <div>
                        <p class="eyebrow" style="color: var(--muted)">TRAFFIC · LAST 7 DAYS</p>
                        <p class="say">Your app answered <span class="n" data-c="14810">14,810</span> requests, about <span class="n" data-c="2116">2,116</span> a day, and sent <span class="n" data-c="1.9" data-d="1">1.9</span> GB. Responses took <span class="n" data-c="84">84</span> ms on average. Today so far: <span data-c="1342">1,342</span> requests, <span class="w" data-c="3">3</span> failed.</p>
                        <p class="small" style="color: var(--ink); font-weight: 600; margin-bottom: .4rem">Look closer</p>
                        <div class="rows">
                            <div>Busiest day in the last 30: Sep 27, with 3,912 requests <small>~2,116 / day</small></div>
                            <div>3 requests failed today <small>1,342</small></div>
                            <div>Responses take 84 ms on average <small>91.4% cached</small></div>
                            <div>Pages load in 0.94 s for real visitors <small>LCP p75</small></div>
                            <div>Watch requests as they arrive <small style="color: var(--accent)">● Live</small></div>
                        </div>
                    </div>
                    <div>
                        <p class="eyebrow" style="color: var(--muted)">SERVED FROM · LAST 24 HOURS</p>
                        <p class="say" style="margin-bottom: 0">Answered from {{ count($colos) }} Cloudflare locations, most from <span class="n">{{ $colos[0]['city'] }}</span>.</p>
                        <canvas class="map" data-map data-network='@json($network)' data-served='@json($colos)' aria-label="Map of the Cloudflare locations that served the example store" role="img"></canvas>
                        <div class="colos">
                            @foreach (array_slice($colos, 0, 6) as $c)
                                <div><code>{{ $c['colo'] }}</code><span>{{ $c['city'] }}</span><span class="bar"><i style="width: {{ round($c['requests'] / $colos[0]['requests'] * 100) }}%"></i></span><small>{{ number_format($c['requests']) }}</small></div>
                            @endforeach
                        </div>
                    </div>
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

        { // the Traffic tab, Served from map and Overview topology: own scope
        /* TRAFFIC: count the sentence up once it scrolls into view */
        const trafficTab = $('[data-traffic]');
        new IntersectionObserver((es, obs) => {
            if (! es[0].isIntersecting) return;
            obs.disconnect();
            trafficTab.querySelectorAll('[data-c]').forEach((el) => {
                const end = parseFloat(el.dataset.c), d = parseInt(el.dataset.d || '0', 10), t0 = performance.now();
                const step = (now) => {
                    const k = Math.min(1, (now - t0) / 1600), v = end * (1 - Math.pow(1 - k, 3));
                    el.textContent = v.toLocaleString(undefined, { minimumFractionDigits: d, maximumFractionDigits: d });
                    if (k < 1) requestAnimationFrame(step);
                };
                requestAnimationFrame(step);
            });
        }, { threshold: 0.3 }).observe(trafficTab);

        /* SERVED FROM: Cloudflare's locations (EdgeColos), the example's lit and pulsing */
        const mc = watch($('[data-map]'));
        const network = JSON.parse(mc.dataset.network), served = JSON.parse(mc.dataset.served).filter((c) => c.lat !== undefined);
        const maxServed = Math.max(...served.map((c) => c.requests));
        const geo = (lat, lon, w, h) => [((lon + 180) / 360) * w, ((72 - lat) / 128) * h];
        const map = (now) => {
            requestAnimationFrame(map);
            if (! seen.get(mc)) return;
            const [ctx, w, h] = fit(mc);
            const lime = tok('lime');
            ctx.fillStyle = tok('faint'); ctx.globalAlpha = 0.45;
            for (const [lat, lon] of network) { const [x, y] = geo(lat, lon, w, h); ctx.fillRect(x - 1, y - 1, 2, 2); }
            ctx.globalAlpha = 1;
            for (const c of served) {
                const [x, y] = geo(c.lat, c.lon, w, h), r = 2.5 + Math.sqrt(c.requests / maxServed) * 6;
                const phase = (now / 1700 + (c.lon + 180) / 70) % 1;
                ctx.strokeStyle = lime; ctx.globalAlpha = 1 - phase;
                ctx.beginPath(); ctx.arc(x, y, r + phase * 14, 0, 7); ctx.stroke();
                ctx.globalAlpha = 1; ctx.fillStyle = lime;
                ctx.beginPath(); ctx.arc(x, y, r, 0, 7); ctx.fill();
            }
        };
        requestAnimationFrame(map);

        /* THE_DASHBOARD: the Overview's topology, living the way an app does:
           asleep, woken by a request, scaled out by a burst, asleep again. */
        const topo = watch($('[data-topo]'));
        const lines = $('[data-topo-lines]', topo), k = (name) => $('[data-k="' + name + '"]');
        const node = (name) => $('[data-n="' + name + '"]', topo);
        const feed = $('[data-feed]');
        const box = (el) => { const a = el.getBoundingClientRect(), b = topo.getBoundingClientRect(); return { l: a.left - b.left, r: a.right - b.left, t: a.top - b.top, b: a.bottom - b.top, cx: (a.left + a.right) / 2 - b.left, cy: (a.top + a.bottom) / 2 - b.top }; };
        // A connector from a to b: sideways when b is to the right, else downwards.
        const path = (a, b) => {
            const A = box(node(a)), B = box(node(b));
            if (B.l >= A.r - 4) { const mx = (A.r + B.l) / 2; return [[A.r, A.cy], [mx, A.cy], [mx, B.cy], [B.l, B.cy]]; }
            return [[A.cx, A.b], [A.cx, (A.b + B.t) / 2], [B.cx, (A.b + B.t) / 2], [B.cx, B.t]];
        };
        const along = (pts, t) => {
            const seg = pts.slice(1).map((p, i) => Math.hypot(p[0] - pts[i][0], p[1] - pts[i][1]));
            let d = t * seg.reduce((x, y) => x + y, 0);
            for (let i = 0; i < seg.length; i++) {
                if (d <= seg[i]) { const f = seg[i] ? d / seg[i] : 0; return [pts[i][0] + (pts[i + 1][0] - pts[i][0]) * f, pts[i][1] + (pts[i + 1][1] - pts[i][1]) * f]; }
                d -= seg[i];
            }
            return pts[pts.length - 1];
        };
        const routes = [['visitors', 'edge'], ['edge', 'app'], ['app', 'valkey'], ['app', 'workers'], ['app', 'db']];
        let pulses = [], awake = true, running = 1, mem = 212, quietUntil = 0, wakingUntil = 0;
        let visitors = 2840, today = 412, month = 2840, burst = false;
        const setApp = (state) => {
            const st = k('app-state');
            st.textContent = state;
            st.className = 'st ' + (state === 'Running' ? 'on' : state === 'Waking' ? 'wake' : 'sleep');
            k('app-note').textContent = state === 'Asleep' ? 'Asleep · wakes on the next request' : state === 'Waking' ? 'Starting · the request waits for it' : 'Sleeps after 5 min idle';
            node('app').classList.toggle('hot', state !== 'Asleep');
        };
        const paths = ['/', '/products/sneakers', '/cart', '/checkout', '/api/stock', '/account', '/search?q=boots', '/products/jacket'];
        const pops = ['IAD', 'SJC', 'ORD', 'LHR', 'FRA', 'NRT', 'SIN', 'SYD'];
        const logRequest = () => {
            const row = document.createElement('div'), path = paths[Math.floor(Math.random() * paths.length)];
            const method = path === '/cart' || path === '/checkout' ? 'POST' : 'GET', fail = Math.random() < 0.03;
            [method, path, fail ? '503' : '200', Math.round(rand(14, 95)) + ' ms', pops[Math.floor(Math.random() * pops.length)]].forEach((v, i) => {
                const s = document.createElement('span'); s.textContent = v; if (i === 2) s.className = fail ? 'warn' : 'ok'; row.appendChild(s);
            });
            feed.prepend(row);
            while (feed.children.length > 6) feed.lastChild.remove();
        };
        let last = 0, phaseStart = performance.now();
        const topoFrame = (now) => {
            requestAnimationFrame(topoFrame);
            if (! seen.get(topo)) return;
            const [ctx, w, h] = fit(lines);
            const lime = tok('lime'), line = tok('line');
            // the story: 0-9s busy (burst at 4-8s), 9-14s quiet then asleep, 14s+ a visitor wakes it
            const t = (now - phaseStart) / 1000;
            const busy = t < 9 || t > 14;
            burst = t > 4 && t < 8;
            if (t > 20) phaseStart = now;
            if (busy && now - last > (burst ? 120 : 420)) {
                last = now;
                pulses.push({ route: 0, t: 0 });
                visitors++; today++; month++;
                k('visitors').textContent = visitors.toLocaleString(); k('today').textContent = today.toLocaleString(); k('month').textContent = month.toLocaleString();
            }
            if (! busy && awake && t > 12) { awake = false; running = 0; setApp('Asleep'); }
            // lines
            ctx.strokeStyle = line; ctx.lineWidth = 1; ctx.setLineDash([3, 4]);
            for (const [a, b] of routes) { const p = path(a, b); ctx.beginPath(); p.forEach(([x, y], i) => (i ? ctx.lineTo(x, y) : ctx.moveTo(x, y))); ctx.stroke(); }
            ctx.setLineDash([]);
            // pulses
            for (const p of pulses) {
                const [a, b] = routes[p.route];
                if (p.route === 1 && ! awake && p.t > 0.98) {
                    // the request reaches a sleeping app: it waits while the app wakes
                    if (! wakingUntil) { wakingUntil = now + 1400; setApp('Waking'); }
                    if (now < wakingUntil) { p.hold = true; } else { awake = true; running = 1; wakingUntil = 0; setApp('Running'); p.hold = false; }
                }
                if (! p.hold) p.t += p.route === 0 ? 0.03 : 0.04;
                const [x, y] = along(path(a, b), Math.min(1, p.t));
                ctx.fillStyle = lime; ctx.globalAlpha = p.hold ? 0.5 + 0.5 * Math.sin(now / 80) : 1;
                ctx.beginPath(); ctx.arc(x, y, 3, 0, 7); ctx.fill(); ctx.globalAlpha = 1;
                if (p.t >= 1) {
                    p.done = true;
                    if (p.route === 0) pulses.push({ route: 1, t: 0 });
                    else if (p.route === 1) { pulses.push({ route: 2 + Math.floor(Math.random() * 3), t: 0 }); logRequest(); }
                }
            }
            pulses = pulses.filter((p) => ! p.done);
            // scale: a burst runs more instances, memory follows
            if (awake) {
                const want = burst ? 3 : 1;
                if (now % 900 < 17 && running !== want) running += Math.sign(want - running);
                mem += ((burst ? 640 : 212) - mem) * 0.02;
            } else {
                mem += (0 - mem) * 0.05;
            }
            k('running').textContent = running;
            k('mem').textContent = Math.round(mem);
            k('mem-bar').style.width = Math.round(mem / 1024 * 100) + '%';
            k('workers').textContent = burst ? '4 workers · scaling' : '2 workers';
        };
        requestAnimationFrame(topoFrame);

        /* edge card sparkline */
        const sc = $('[data-spark]'), spark = Array.from({ length: 40 }, (_, i) => 0.3 + 0.25 * Math.sin(i / 4) + rand(0, 0.15));
        setInterval(() => {
            if (! seen.get(topo)) return;
            spark.shift(); spark.push(burst ? rand(0.75, 1) : awake ? rand(0.3, 0.55) : rand(0, 0.08));
            const [ctx, w, h] = fit(sc);
            ctx.beginPath(); spark.forEach((v, i) => (i ? ctx.lineTo : ctx.moveTo).call(ctx, (i / (spark.length - 1)) * w, h - 1 - v * (h - 3)));
            ctx.strokeStyle = tok('lime'); ctx.lineWidth = 1.2; ctx.stroke();
            ctx.lineTo(w, h); ctx.lineTo(0, h); ctx.closePath(); ctx.globalAlpha = 0.15; ctx.fillStyle = tok('lime'); ctx.fill(); ctx.globalAlpha = 1;
        }, 250);

        }

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
