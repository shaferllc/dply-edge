<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        @include('partials.theme-head')

        <title>@yield('title', __('Platform admin') . ' — ' . config('app.name', 'Laravel'))</title>

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=space-grotesk:400,500,700|space-mono:400,700&display=swap" rel="stylesheet" />

        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @livewireStyles
        <style>[x-cloak]{display:none!important}</style>
        @php
            $toastPosition = \App\Support\NotificationToastPosition::resolvedFor(auth()->user());
        @endphp
    </head>
    <body class="font-sans antialiased bg-brand-cream text-brand-ink min-h-screen flex flex-col" x-data="toastStore({ position: @js($toastPosition) })">
        <x-impersonation-banner />
        <div class="flex flex-col flex-1 min-h-0">
            <x-site-header />

            <main class="flex-1 w-full pb-28 sm:pb-32">
                <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
                    <div class="lg:grid lg:grid-cols-12 lg:gap-10">
                        <aside class="sm:col-span-3 mb-8 lg:mb-0 shrink-0">
                            <x-admin-nav />
                        </aside>
                        <div class="lg:col-span-9 min-w-0">
                            {{ $slot }}
                        </div>
                    </div>
                </div>
            </main>
        </div>

        <x-marketing-footer />

        {{ $modals ?? '' }}

        @include('partials.toast-stack')

        @include('partials.session-flash-toasts')
        @livewireScripts
        @include('partials.livewire-toast-events')
    </body>
</html>
