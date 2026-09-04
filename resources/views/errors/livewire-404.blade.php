{{--
    Chrome-less 404 served ONLY to Livewire requests (wire:navigate morphs and
    Livewire update responses). The full-page errors.layout renders <x-site-header />;
    when Livewire morphs/injects that into a page that ALREADY has the header, you
    get a duplicated header and a broken-looking nested 404. This variant carries no
    app chrome — just a self-contained card — so it morphs in cleanly, and it offers
    a Refresh because the usual cause is a stale snapshot the user can recover from.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    @include('partials.theme-head')

    <title>{{ __('Page not found') }} – {{ config('app.name', 'Laravel') }}</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>[x-cloak]{display:none!important}</style>
</head>
<body class="font-sans antialiased bg-brand-cream text-brand-ink min-h-dvh flex flex-col">
@include('partials.skip-link')
    <div class="fixed inset-0 -z-20 bg-brand-cream"></div>
    <div class="fixed inset-0 -z-10 bg-mesh-brand"></div>

    <main id="main-content" tabindex="-1" class="flex-1 w-full px-4 sm:px-6 py-16 flex items-center justify-center">
        <div class="w-full max-w-lg mx-auto text-center">
            <div class="rounded-3xl border border-brand-ink/10 bg-white/90 backdrop-blur-sm shadow-xl shadow-brand-forest/10 ring-1 ring-brand-ink/5 px-8 py-12 sm:px-10 sm:py-14">
                <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-brand-sand/60 text-brand-forest">
                    <x-heroicon-o-arrow-path class="h-6 w-6" />
                </div>

                <h1 class="mt-5 text-2xl font-semibold text-brand-ink">{{ __('This view is out of date') }}</h1>

                <p class="mt-3 text-sm leading-relaxed text-brand-moss">
                    {{ __('The page you were on changed underneath you — the thing you clicked no longer exists here. Refresh to pick up where it moved to.') }}
                </p>

                <div class="mt-7 flex flex-wrap items-center justify-center gap-3">
                    <button type="button"
                            onclick="window.location.reload()"
                            class="inline-flex items-center gap-2 rounded-lg bg-brand-ink px-4 py-2 text-sm font-medium text-brand-cream transition-colors hover:bg-brand-forest">
                        <x-heroicon-o-arrow-path class="h-4 w-4" />
                        {{ __('Refresh') }}
                    </button>

                    <a href="{{ route('dashboard') }}"
                       class="inline-flex items-center gap-2 rounded-lg border border-brand-ink/20 px-4 py-2 text-sm font-medium text-brand-ink transition-colors hover:bg-brand-sand/50">
                        <x-heroicon-o-home class="h-4 w-4" />
                        {{ __('Go home') }}
                    </a>
                </div>
            </div>
        </div>
    </main>
</body>
</html>
