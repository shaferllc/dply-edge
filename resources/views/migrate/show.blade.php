<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <x-seo-meta
        full-title="Migrate from {{ $source['name'] }} – {{ config('app.name') }}"
        :description="$source['meta']" />
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
        {{-- Hero --}}
        <section class="pt-16 pb-12 sm:pt-20 sm:pb-16 px-4 sm:px-6 lg:px-8 border-b border-edge-line">
            <div class="max-w-4xl mx-auto">
                <nav class="text-xs font-semibold uppercase tracking-wider text-edge-mute" aria-label="Breadcrumb">
                    <a href="{{ route('migrate.index') }}" class="hover:text-edge-text">Migrate</a>
                    <span class="mx-2 text-edge-mute">/</span>
                    <span class="text-edge-lime">{{ $source['name'] }}</span>
                </nav>
                <h1 class="mt-6 text-4xl font-bold tracking-tight text-edge-text sm:text-5xl">{{ $source['headline'] }}</h1>
                <p class="mt-5 text-lg text-edge-mute leading-relaxed max-w-3xl">
                    {{ $source['hero'] }}
                </p>

                <div class="mt-10 flex flex-col sm:flex-row gap-3 sm:items-center">
                    <a href="{{ $source['cta_href'] }}" class="inline-flex items-center justify-center gap-2 px-6 py-3 bg-edge-lime text-edge-void text-sm font-semibold hover:bg-edge-lime/10 transition-colors">
                        <x-heroicon-o-arrow-down-tray class="h-4 w-4" aria-hidden="true" />
                        {{ $source['cta_label'] }}
                    </a>
                    <a href="{{ route('register') }}" class="inline-flex items-center justify-center gap-2 px-6 py-3 border-2 border-edge-line bg-edge-panel text-edge-text text-sm font-semibold hover:border-edge-line transition-colors">
                        <x-heroicon-o-rocket-launch class="h-4 w-4" aria-hidden="true" />
                        Start trial first
                    </a>
                </div>
                <p class="mt-3 text-xs text-edge-mute">Already signed in? You'll go straight to the import wizard. Otherwise, log in and we'll bring you back here.</p>
            </div>
        </section>

        {{-- What we move / what stays --}}
        <section class="py-16 sm:py-20 px-4 sm:px-6 lg:px-8">
            <div class="mx-auto max-w-6xl grid gap-8 lg:grid-cols-2">
                <div class="border border-edge-line bg-edge-panel p-8">
                    <h2 class="text-xl font-semibold text-edge-text flex items-center gap-2">
                        <x-heroicon-o-check-circle class="h-5 w-5 text-edge-lime" aria-hidden="true" />
                        What the wizard brings across
                    </h2>
                    <ul class="mt-6 space-y-3 text-sm text-edge-mute">
                        @foreach ($source['moves'] as $item)
                            <li class="flex items-start gap-3">
                                <span class="mt-1 h-1.5 w-1.5 shrink-0 rounded-full bg-edge-lime/10" aria-hidden="true"></span>
                                <span>{!! $item !!}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
                <div class="border border-edge-line bg-edge-panel p-8">
                    <h2 class="text-xl font-semibold text-edge-text flex items-center gap-2">
                        <x-heroicon-o-information-circle class="h-5 w-5 text-edge-lime" aria-hidden="true" />
                        What you keep doing yourself
                    </h2>
                    <ul class="mt-6 space-y-3 text-sm text-edge-mute">
                        @foreach ($source['stays'] as $item)
                            <li class="flex items-start gap-3">
                                <span class="mt-1 h-1.5 w-1.5 shrink-0 rounded-full bg-edge-lime/10" aria-hidden="true"></span>
                                <span>{!! $item !!}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </section>

        {{-- Three-step flow --}}
        <section class="py-16 sm:py-20 px-4 sm:px-6 lg:px-8 border-t border-edge-line bg-edge-panel">
            <div class="mx-auto max-w-6xl">
                <h2 class="text-3xl font-bold tracking-tight text-edge-text sm:text-4xl text-center">The afternoon plan</h2>
                <p class="mt-4 text-center text-edge-mute max-w-2xl mx-auto">Three steps. The wizard does the heavy lifting; you confirm and cut over when the parity view is green.</p>

                <ol class="mt-12 grid gap-6 lg:grid-cols-3">
                    @foreach ($source['steps'] as $i => $step)
                        <li class="border border-edge-line bg-edge-panel p-6">
                            <span class="inline-flex h-9 w-9 items-center justify-center rounded-full bg-edge-lime text-edge-void text-sm font-bold">{{ $i + 1 }}</span>
                            <h3 class="mt-4 text-lg font-semibold text-edge-text">{{ $step['title'] }}</h3>
                            <p class="mt-2 text-sm text-edge-mute leading-relaxed">{{ $step['body'] }}</p>
                        </li>
                    @endforeach
                </ol>
            </div>
        </section>

        {{-- Parity hook --}}
        <section class="py-16 sm:py-20 px-4 sm:px-6 lg:px-8 border-t border-edge-line">
            <div class="mx-auto max-w-4xl border border-edge-line bg-edge-lime text-edge-void px-8 py-12 sm:px-12">
                <p class="text-xs font-semibold uppercase tracking-wider text-edge-lime">Not a one-shot import</p>
                <h2 class="mt-3 text-2xl sm:text-3xl font-bold tracking-tight">{{ $source['parity_title'] }}</h2>
                <p class="mt-4 text-edge-dim leading-relaxed max-w-2xl">
                    {{ $source['parity_body'] }}
                </p>
            </div>
        </section>

        {{-- CTA footer --}}
        <section class="py-20 px-4 sm:px-6 lg:px-8">
            <div class="max-w-3xl mx-auto text-center">
                <h2 class="text-2xl font-bold tracking-tight text-edge-text sm:text-3xl">Ready when you are</h2>
                <p class="mt-3 text-edge-mute">No card to start. Run the wizard against a single staging server first if you want to see the shape.</p>
                <div class="mt-8 flex flex-col sm:flex-row items-center justify-center gap-4">
                    <a href="{{ $source['cta_href'] }}" class="inline-flex items-center px-6 py-3 bg-edge-lime/10 text-edge-text text-sm font-semibold hover:bg-[#d4b24d] transition-colors">{{ $source['cta_label'] }}</a>
                    <a href="{{ route('migrate.index') }}" class="inline-flex items-center px-6 py-3 border-2 border-edge-line bg-edge-panel text-edge-text text-sm font-semibold hover:border-edge-line transition-colors">Other platforms</a>
                </div>
            </div>
        </section>
    </main>

    <x-edge-marketing-footer />
    @livewireScripts
</body>
</html>
