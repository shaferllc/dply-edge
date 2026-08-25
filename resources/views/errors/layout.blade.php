@php
    $errorContext = app(\App\View\Components\ErrorContext::class)->parse();
    $hasSmartActions = ! empty($errorContext['suggestions']) || ! empty($errorContext['server']) || ! empty($errorContext['site']);
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    @include('partials.theme-head')

    <title>@yield('title', __('Error')) – {{ config('app.name', 'Laravel') }}</title>

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=space-grotesk:400,500,700|space-mono:400,700&display=swap" rel="stylesheet" />

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
    <style>[x-cloak]{display:none!important}</style>
</head>
<body class="font-sans antialiased bg-brand-cream text-brand-ink min-h-screen flex flex-col">
    <div class="fixed inset-0 -z-20 bg-brand-cream"></div>
    <div class="fixed inset-0 -z-10 bg-mesh-brand"></div>

    <x-site-header />

    <main class="flex-1 w-full px-4 sm:px-6 py-16 sm:py-20 lg:py-24 flex items-center justify-center">
        <div class="w-full @yield('card-width', 'max-w-2xl') mx-auto text-center">
            <div class="rounded-3xl border border-brand-ink/10 bg-white/90 backdrop-blur-sm shadow-xl shadow-brand-forest/10 overflow-hidden ring-1 ring-brand-ink/5 px-8 py-12 sm:px-12 sm:py-16">
                @yield('content')

                {{-- Smart contextual actions based on URL parsing --}}
                @hasSection('smart-actions')
                    @yield('smart-actions')
                @elseif($hasSmartActions)
                    <div class="mt-8 pt-8 border-t border-brand-ink/10">
                        @if(!empty($errorContext['suggestions']))
                            <p class="text-sm font-medium text-brand-ink mb-4">{{ __('Where to next?') }}</p>
                            <div class="flex flex-wrap items-center justify-center gap-3">
                                @foreach($errorContext['suggestions'] as $suggestion)
                                    <a href="{{ $suggestion['url'] }}"
                                       @class([
                                           'inline-flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium transition-colors',
                                           'bg-brand-ink text-brand-cream hover:bg-brand-forest' => $suggestion['primary'] ?? false,
                                           'border border-brand-ink/20 text-brand-ink hover:bg-brand-sand/50' => !($suggestion['primary'] ?? false),
                                       ])
                                    >
                                        @if($suggestion['primary'] ?? false)
                                            <x-heroicon-o-arrow-uturn-left class="w-4 h-4" />
                                        @else
                                            <x-heroicon-o-chevron-right class="w-4 h-4" />
                                        @endif
                                        {{ $suggestion['label'] }}
                                    </a>
                                @endforeach
                            </div>
                        @endif

                        @yield('extra-actions')

                        {{-- Referrer --}}
                        @if(!empty($errorContext['referrer']) && !str_starts_with($errorContext['referrer'], url()->current()))
                            <div class="mt-4">
                                <a href="{{ $errorContext['referrer'] }}" class="text-sm text-brand-moss hover:text-brand-ink transition-colors inline-flex items-center gap-1">
                                    <x-heroicon-o-arrow-uturn-left class="w-3.5 h-3.5" />
                                    {{ __('Go back to previous page') }}
                                </a>
                            </div>
                        @endif
                    </div>
                @endif
            </div>
        </div>
    </main>

    <x-marketing-footer />
    @livewireScripts
</body>
</html>
