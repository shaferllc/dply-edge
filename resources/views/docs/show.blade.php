<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark scroll-pt-24">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    @include('partials.theme-head')

    @head
    @if ($page['exists'])
        <link rel="alternate" type="text/markdown" href="{{ route('docs.markdown', $page['slug']) }}">
    @endif
    @vite(['resources/css/app.css', 'resources/css/docs.css', 'resources/js/docs.js'])
</head>
<body class="bg-edge-void font-display text-edge-text antialiased">
@include('partials.skip-link')

    <x-edge-marketing-header active="docs" wide />

    @php
        $searchButton = 'group flex w-full items-center gap-2 border border-edge-line bg-edge-panel px-3 py-2 text-left text-sm text-edge-mute transition-colors hover:border-edge-dim hover:text-edge-text';
    @endphp

    {{-- Mobile bar: menu + search. --}}
    <div class="sticky top-[var(--docs-top)] z-30 border-b border-edge-line bg-edge-void/95 backdrop-blur lg:hidden">
        <div class="flex items-center gap-3 px-4 py-2.5 sm:px-6">
            <button type="button" data-docs-open-menu class="inline-flex items-center gap-2 text-sm text-edge-mute hover:text-edge-text" aria-controls="docs-menu">
                <x-heroicon-o-bars-3 class="h-5 w-5" aria-hidden="true" />
                <span class="max-w-[12rem] truncate">{{ $page['section'] }}</span>
            </button>
            <button type="button" data-docs-open-search class="ml-auto inline-flex items-center gap-2 p-1 text-edge-mute hover:text-edge-text" aria-label="{{ __('Search docs') }}">
                <x-heroicon-o-magnifying-glass class="h-5 w-5" aria-hidden="true" />
            </button>
        </div>
    </div>

    <div class="mx-auto max-w-[90rem] px-4 sm:px-6 lg:grid lg:grid-cols-[16rem_minmax(0,1fr)] lg:gap-12 lg:px-8 xl:grid-cols-[16rem_minmax(0,1fr)_14rem]">
        {{-- Left: search + nav. --}}
        <aside class="docs-scroll sticky top-[var(--docs-top)] hidden h-[calc(100dvh-var(--docs-top))] overflow-y-auto py-8 pr-2 lg:block">
            <button type="button" data-docs-open-search class="{{ $searchButton }} mb-8">
                <x-heroicon-o-magnifying-glass class="h-4 w-4 shrink-0" aria-hidden="true" />
                <span class="flex-1">{{ __('Search docs') }}</span>
                <kbd class="font-terminal border border-edge-line px-1.5 text-[11px] text-edge-faint">⌘K</kbd>
            </button>
            @include('docs.nav')
        </aside>

        <main id="main-content" tabindex="-1" class="min-w-0 py-8 focus:outline-none lg:py-12">
            <div class="mx-auto max-w-3xl">
                <p class="font-terminal text-[11px] uppercase tracking-[0.2em] text-edge-lime">{{ $page['section'] }}</p>
                <h1 class="mt-3 text-3xl font-bold tracking-[-0.02em] text-edge-text sm:text-4xl">{{ $page['title'] }}</h1>
                @if ($page['description'] !== '')
                    <p class="mt-3 text-lg leading-7 text-edge-dim">{{ $page['description'] }}</p>
                @endif

                @if ($page['exists'])
                    <article class="docs-prose prose mt-8 max-w-none">
                        {!! $page['html'] !!}
                    </article>
                @else
                    <div class="mt-10 border border-dashed border-edge-line bg-edge-panel px-6 py-10 text-center">
                        <p class="font-terminal text-[11px] uppercase tracking-[0.2em] text-edge-faint">{{ __('Coming soon') }}</p>
                        <p class="mx-auto mt-3 max-w-md text-edge-dim">{{ __('This page is being written. In the meantime, start with the introduction or search the docs.') }}</p>
                        <div class="mt-6 flex flex-wrap items-center justify-center gap-4">
                            <a href="{{ route('docs.show', 'introduction') }}" class="font-terminal border-b border-edge-lime pb-0.5 text-sm text-edge-text hover:text-edge-lime">{{ __('introduction') }} →</a>
                            <button type="button" data-docs-open-search class="font-terminal text-sm text-edge-mute hover:text-edge-text">{{ __('search') }} ⌘K</button>
                        </div>
                    </div>
                @endif

                {{-- Prev / next. --}}
                @if ($neighbours['prev'] || $neighbours['next'])
                    <nav class="mt-16 grid gap-4 border-t border-edge-line pt-8 sm:grid-cols-2" aria-label="{{ __('Previous and next page') }}">
                        @foreach (['prev' => __('Previous'), 'next' => __('Next')] as $dir => $label)
                            @if ($item = $neighbours[$dir])
                                <a href="{{ route('docs.show', $item['slug']) }}" @class([
                                    'group border border-edge-line px-4 py-3 transition-colors hover:border-edge-lime/60',
                                    'sm:col-start-2 sm:text-right' => $dir === 'next',
                                ])>
                                    <span class="font-terminal block text-[11px] uppercase tracking-[0.14em] text-edge-faint">{{ $dir === 'prev' ? '← ' : '' }}{{ $label }}{{ $dir === 'next' ? ' →' : '' }}</span>
                                    <span class="mt-1 block font-medium text-edge-text group-hover:text-edge-lime">{{ $item['title'] }}</span>
                                </a>
                            @endif
                        @endforeach
                    </nav>
                @endif

                @if ($page['exists'])
                    <p class="font-terminal mt-8 text-xs text-edge-faint">
                        <a href="{{ route('docs.markdown', $page['slug']) }}" class="hover:text-edge-text">{{ __('View as Markdown') }}</a>
                        <span class="mx-2" aria-hidden="true">·</span>
                        <a href="{{ route('docs.llms') }}" class="hover:text-edge-text">llms.txt</a>
                    </p>
                @endif
            </div>
        </main>

        {{-- Right: on this page. --}}
        <aside class="docs-scroll sticky top-[var(--docs-top)] hidden h-[calc(100dvh-var(--docs-top))] overflow-y-auto py-12 xl:block">
            @if (count($page['toc']) > 1)
                <p class="font-terminal text-[11px] uppercase tracking-[0.14em] text-edge-faint">{{ __('On this page') }}</p>
                <ul class="mt-3 space-y-2 text-sm" data-docs-toc>
                    @foreach ($page['toc'] as $heading)
                        <li @class(['pl-3' => $heading['level'] === 3])>
                            <a href="#{{ $heading['id'] }}" class="block leading-5 text-edge-mute transition-colors hover:text-edge-text data-[active]:text-edge-lime">{{ $heading['text'] }}</a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </aside>
    </div>

    <x-edge-marketing-footer />

    {{-- Mobile nav drawer. --}}
    <dialog id="docs-menu" data-docs-dialog class="docs-drawer" aria-label="{{ __('Documentation') }}">
        <div class="flex h-full flex-col bg-edge-void">
            <div class="flex items-center justify-between border-b border-edge-line px-4 py-3">
                <x-dply-wordmark class="text-[15px] text-edge-text" />
                <button type="button" data-docs-close class="p-1 text-edge-mute hover:text-edge-text" aria-label="{{ __('Close menu') }}">
                    <x-heroicon-o-x-mark class="h-5 w-5" aria-hidden="true" />
                </button>
            </div>
            <div class="docs-scroll flex-1 overflow-y-auto px-4 py-6">
                <button type="button" data-docs-open-search class="{{ $searchButton }} mb-8">
                    <x-heroicon-o-magnifying-glass class="h-4 w-4 shrink-0" aria-hidden="true" />
                    <span class="flex-1">{{ __('Search docs') }}</span>
                </button>
                @include('docs.nav')
            </div>
        </div>
    </dialog>

    {{-- Search. --}}
    <dialog id="docs-search" data-docs-dialog data-index="{{ route('docs.search') }}" class="docs-search" aria-label="{{ __('Search docs') }}">
        <div class="border border-edge-line bg-edge-panel shadow-2xl shadow-black/60">
            <div class="flex items-center gap-3 border-b border-edge-line px-4">
                <x-heroicon-o-magnifying-glass class="h-5 w-5 shrink-0 text-edge-faint" aria-hidden="true" />
                <input
                    id="docs-search-input"
                    type="search"
                    role="combobox"
                    aria-expanded="true"
                    aria-controls="docs-search-results"
                    aria-autocomplete="list"
                    autocomplete="off"
                    spellcheck="false"
                    placeholder="{{ __('Search the docs…') }}"
                    class="w-full border-0 bg-transparent py-4 text-base text-edge-text placeholder:text-edge-faint focus:outline-none focus:ring-0"
                >
                <button type="button" data-docs-close class="font-terminal border border-edge-line px-1.5 text-[11px] text-edge-faint hover:text-edge-text">esc</button>
            </div>
            <ul id="docs-search-results" role="listbox" data-hint="{{ __('Search page titles, headings and content.') }}" class="max-h-[60vh] overflow-y-auto p-2"></ul>
        </div>
    </dialog>
</body>
</html>
