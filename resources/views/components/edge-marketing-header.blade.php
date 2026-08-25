@props(['active' => null])

{{--
    Guest-facing header for the dply-edge marketing pages ("Terminal" identity).

    Deliberately NOT <x-site-header>: that one dresses the authenticated app in
    the brand-* palette and carries the org switcher, notification bell and
    every product surface flag. Out here there is one product and no session to
    speak of, so this is a flat bar — and it can be dark without fighting the
    workspace chrome.
--}}
@php
    $links = [
        ['label' => __('How it works'), 'href' => url('/#how-it-works')],
        ['label' => __('Pricing'), 'href' => route('pricing'), 'key' => 'pricing'],
        ['label' => __('Features'), 'href' => route('features'), 'key' => 'features'],
        ['label' => __('Changelog'), 'href' => route('changelog'), 'key' => 'changelog'],
    ];
@endphp

<header class="sticky top-0 z-40 border-b border-edge-line bg-edge-void/95 backdrop-blur supports-[backdrop-filter]:bg-edge-void/80">
    <div class="mx-auto flex max-w-6xl items-center justify-between gap-6 px-6 py-4 lg:px-10">
        <a href="{{ url('/') }}" class="font-terminal text-[15px] font-bold tracking-[-0.02em] text-edge-text">
            dply<span class="text-edge-lime">/</span>edge
        </a>

        <nav class="hidden items-center gap-7 md:flex" aria-label="{{ __('Marketing') }}">
            @foreach ($links as $link)
                <a
                    href="{{ $link['href'] }}"
                    @class([
                        'text-sm transition-colors hover:text-edge-text',
                        'text-edge-text' => $active !== null && ($link['key'] ?? null) === $active,
                        'text-edge-mute' => $active === null || ($link['key'] ?? null) !== $active,
                    ])
                >{{ $link['label'] }}</a>
            @endforeach
        </nav>

        <div class="flex items-center gap-4">
            @auth
                <a href="{{ route('dashboard') }}" class="font-terminal border-b border-edge-lime pb-0.5 text-sm text-edge-text transition-colors hover:text-edge-lime">
                    dashboard →
                </a>
            @else
                <a href="{{ route('login') }}" class="hidden text-sm text-edge-mute transition-colors hover:text-edge-text sm:inline">{{ __('Sign in') }}</a>
                <a href="{{ route('register') }}" class="font-terminal border-b border-edge-lime pb-0.5 text-sm text-edge-text transition-colors hover:text-edge-lime">
                    deploy →
                </a>
            @endauth
        </div>
    </div>
</header>
