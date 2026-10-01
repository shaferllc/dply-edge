{{--
 | Shared chrome for the legal pages (terms, privacy, acceptable-use, dpa).
 | Same skeleton as resources/views/compliance.blade.php.
 | Params: $title, $eyebrow, $intro, $toc (array of [anchor, label]).
 | Closed by legal.partials.layout-end.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    @include('partials.theme-head')

    @head
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="bg-edge-void font-display text-edge-text antialiased">
@include('partials.skip-link')

    <x-edge-marketing-header />

    <main id="main-content" tabindex="-1">
        <section class="border-b border-edge-line">
            <div class="mx-auto max-w-6xl px-6 py-16 lg:px-10 lg:py-20">
                <p class="font-terminal text-[11px] uppercase tracking-[0.2em] text-edge-lime">{{ $eyebrow }}</p>
                <h1 class="mt-4 max-w-3xl text-4xl font-bold leading-[1.05] tracking-[-0.03em] sm:text-5xl">{{ $title }}</h1>
                <p class="font-terminal mt-4 text-xs text-edge-faint">{{ __('Last updated') }} {{ config('legal.version') }}</p>
                <p class="mt-5 max-w-2xl text-base leading-7 text-edge-mute">{{ $intro }}</p>

                <nav aria-label="{{ __('Contents') }}" class="mt-8 max-w-3xl border border-edge-line bg-edge-panel px-5 py-4">
                    <p class="font-terminal text-[11px] uppercase tracking-[0.16em] text-edge-faint">{{ __('Contents') }}</p>
                    <ol class="mt-3 grid gap-x-8 gap-y-1.5 text-sm sm:grid-cols-2">
                        @foreach ($toc as $i => [$anchor, $label])
                            <li><a href="#{{ $anchor }}" class="text-edge-mute transition-colors hover:text-edge-lime">{{ $i + 1 }}. {{ $label }}</a></li>
                        @endforeach
                    </ol>
                </nav>
            </div>
        </section>
