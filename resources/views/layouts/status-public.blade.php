<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        @include('partials.theme-head')

        <title>{{ $title ?? config('app.name') }}</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @livewireStyles
    </head>
    <body class="flex min-h-dvh flex-col bg-edge-void font-display text-edge-text antialiased">
        <div class="flex-1 w-full">
            {{ $slot }}
        </div>
        <x-edge-marketing-footer />
        @livewireScripts
    </body>
</html>
