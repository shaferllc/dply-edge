<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    @include('partials.theme-head')

    <title>{{ ($title ?? null) ? $title . ' – ' : '' }}{{ config('app.name', 'Laravel') }}</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
    <style>[x-cloak]{display:none!important}</style>
</head>
<body class="edge-auth flex min-h-dvh flex-col bg-edge-void font-display text-edge-text antialiased">
@include('partials.skip-link')
    @include('partials.auth-aside-variant')

    <x-edge-marketing-header />

    <main id="main-content" tabindex="-1" class="w-full flex-1 px-6 py-12 lg:px-10 lg:py-16">
        <div class="mx-auto w-full max-w-5xl">
            <div class="grid items-start gap-8 lg:grid-cols-12 lg:gap-12">
                <div class="order-1 lg:order-2 lg:col-span-7">
                    <div class="border border-edge-line bg-edge-panel">
                        @if (isset($title) && $title)
                            <div class="border-b border-edge-line px-6 py-4 sm:px-8">
                                <h1 class="text-xl font-bold tracking-[-0.025em] text-edge-text">{{ $title }}</h1>
                            </div>
                        @endif
                        <div class="auth-form px-6 py-7 sm:px-8 sm:py-8">
                            {{ $slot }}
                        </div>
                    </div>
                </div>

                <div class="order-2 lg:order-1 lg:col-span-5">
                    <x-edge-auth-aside :variant="$authAsideVariant" class="lg:sticky lg:top-28" />
                </div>
            </div>
        </div>
    </main>

    <x-edge-marketing-footer />
    @livewireScripts
</body>
</html>
