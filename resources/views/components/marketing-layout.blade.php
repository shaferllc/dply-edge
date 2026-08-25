@props([
    'title' => null,
    'description' => null,
    'active' => null,
])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    @include('partials.theme-head')

    <x-seo-meta :title="$title" :description="$description" />
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=space-grotesk:400,500,700|space-mono:400,700&display=swap" rel="stylesheet" />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
    <style>
        [x-cloak] { display: none !important; }
    </style>
</head>
<body class="flex min-h-dvh flex-col font-sans antialiased bg-brand-cream text-brand-ink">
    <x-site-header :active="$active" />
    <main class="flex-1">
        {{ $slot }}
    </main>
    <x-marketing-footer />
    @livewireScripts
</body>
</html>
