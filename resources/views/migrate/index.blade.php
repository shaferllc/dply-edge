<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <x-seo-meta
        full-title="Migrate to {{ config('app.name') }} – in an afternoon"
        description="Move from Laravel Forge, Ploi, or Vercel to dply in an afternoon. Import wizards bring servers, sites, env, and deploy hooks across — then you get a continuous parity view, not a one-shot handoff." />
    <meta name="csrf-token" content="{{ csrf_token() }}">

    @include('partials.theme-head')

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=space-grotesk:400,500,700|space-mono:400,700&display=swap" rel="stylesheet" />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
    <style>[x-cloak] { display: none !important; }</style>
</head>
<body class="bg-edge-void font-display text-edge-text antialiased">
    <div class="fixed inset-0 -z-20 bg-edge-void"></div>
    <div class="fixed inset-0 -z-10 bg-mesh-brand"></div>

    <x-edge-marketing-header />

    <main>
        <section class="pt-16 pb-12 sm:pt-20 sm:pb-16 px-4 sm:px-6 lg:px-8 border-b border-edge-line">
            <div class="max-w-3xl mx-auto text-center">
                <p class="text-xs font-semibold uppercase tracking-wider text-edge-lime">Migration</p>
                <h1 class="mt-4 text-4xl font-bold tracking-tight text-edge-text sm:text-5xl">Move to {{ config('app.name') }} in an afternoon</h1>
                <p class="mt-5 text-lg text-edge-mute leading-relaxed">
                    Import wizards for the platforms most teams are leaving. Bring your servers, sites, environment variables, and deploy hooks across — then keep a <strong class="text-edge-text font-semibold">continuous parity view</strong> against the source, not a one-shot handoff that forgets where you came from.
                </p>
            </div>
        </section>

        <section class="py-16 sm:py-20 px-4 sm:px-6 lg:px-8">
            <div class="mx-auto max-w-6xl grid gap-8 md:grid-cols-3">
                @foreach ($sources as $slug => $source)
                    <a href="{{ route('migrate.show', $slug) }}" class="group flex flex-col border border-edge-line bg-edge-panel p-8 hover:border-edge-line hover: transition">
                        <p class="text-xs font-semibold uppercase tracking-wider text-edge-lime">{{ $source['kicker'] }}</p>
                        <h2 class="mt-3 text-2xl font-semibold text-edge-text">From {{ $source['name'] }}</h2>
                        <p class="mt-3 text-sm text-edge-mute leading-relaxed flex-1">{{ $source['tagline'] }}</p>
                        <span class="mt-6 inline-flex items-center gap-1.5 text-sm font-semibold text-edge-lime group-hover:text-edge-text">
                            See the migration plan
                            <x-heroicon-o-arrow-right class="h-4 w-4 transition-transform group-hover:translate-x-0.5" aria-hidden="true" />
                        </span>
                    </a>
                @endforeach
            </div>
        </section>

        <section class="py-16 sm:py-20 px-4 sm:px-6 lg:px-8 border-t border-edge-line bg-edge-panel">
            <div class="mx-auto max-w-3xl text-center">
                <h2 class="text-2xl font-bold text-edge-text sm:text-3xl">Why {{ config('app.name') }} after the import</h2>
                <p class="mt-4 text-edge-mute leading-relaxed">
                    Forge and Ploi own one lane (BYO PHP VMs); Vercel owns another (edge / SSR). {{ config('app.name') }} runs <strong class="text-edge-text font-semibold">BYO + Cloud + Edge + Serverless in one org</strong> with one vault, one billing relationship, and one audit trail. So an afternoon of migration earns you the rest of the year of not stitching three panels together.
                </p>
                <div class="mt-8 flex flex-col sm:flex-row items-center justify-center gap-4">
                    <a href="{{ route('register') }}" class="inline-flex items-center px-6 py-3 bg-edge-lime text-edge-void text-sm font-semibold hover:bg-edge-lime/10 transition-colors">Start 14-day trial</a>
                    <a href="{{ route('features') }}" class="inline-flex items-center px-6 py-3 border-2 border-edge-line bg-edge-panel text-edge-text text-sm font-semibold hover:border-edge-line transition-colors">See all features</a>
                </div>
            </div>
        </section>
    </main>

    <x-edge-marketing-footer />
    @livewireScripts
</body>
</html>
