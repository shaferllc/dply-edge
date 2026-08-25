@props([
    /** Heroicon component name for the content header, e.g. 'heroicon-o-camera'. */
    'icon' => 'heroicon-o-sparkles',
    'title',
    'description' => null,
    /** Terminal-hero comment line (without the leading "# "). */
    'eyebrow' => null,
    /**
     * Terminal output lines. Each: ['tone' => 'cmd|muted|ok', 'text' => '…'].
     * 'cmd' renders a sky prompt line, 'muted' a dim slate line, 'ok' an emerald summary.
     *
     * @var array<int, array{tone?: string, text: string}>
     */
    'lines' => [],
    /**
     * Feature cards. Each: ['icon' => 'cube', 'title' => '…', 'body' => '…'] where
     * icon is the heroicon-o-* leaf (resolved as a dynamic component).
     *
     * @var array<int, array{icon: string, title: string, body: string}>
     */
    'features' => [],
    'server' => null,
    'footnote' => null,
    'heroNote' => null,
])

@php
    $hostLabel = $server?->name ?: ($server?->ip_address ?: 'your-server');
    $lineTone = static fn (string $tone): string => match ($tone) {
        'cmd' => 'text-sky-300/90',
        'ok' => 'text-emerald-400/90',
        default => 'text-slate-400',
    };
@endphp

<div class="relative overflow-hidden rounded-2xl border border-brand-ink/10 bg-white shadow-sm ring-1 ring-brand-ink/[0.04]">
    {{-- Terminal hero --}}
    <div class="relative overflow-hidden bg-brand-ink/95 px-5 pb-6 pt-5 sm:px-6 sm:pb-7 sm:pt-6">
        <div class="pointer-events-none absolute -end-16 -top-20 h-56 w-56 rounded-full bg-sky-500/10 blur-3xl" aria-hidden="true"></div>
        <div class="pointer-events-none absolute -bottom-24 start-8 h-48 w-48 rounded-full bg-brand-gold/10 blur-3xl" aria-hidden="true"></div>

        <div class="relative flex items-start justify-between gap-3">
            <div class="flex items-center gap-2">
                <x-mac-window-dots />
            </div>
            <span class="inline-flex items-center gap-1.5 rounded-full border border-white/10 bg-white/5 px-2.5 py-1 text-2xs font-semibold uppercase tracking-[0.12em] text-sky-200/90">
                <span class="relative flex h-1.5 w-1.5">
                    <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-sky-400/60 opacity-75"></span>
                    <span class="relative inline-flex h-1.5 w-1.5 rounded-full bg-sky-400"></span>
                </span>
                {{-- Split words so letter-spacing doesn’t collapse the gap between them. --}}
                <span class="inline-flex items-center gap-[0.45em]" aria-label="{{ __('Coming soon') }}">
                    <span>{{ __('Coming') }}</span>
                    <span>{{ __('soon') }}</span>
                </span>
            </span>
        </div>

        <div class="relative mt-4 font-mono text-xs leading-relaxed sm:text-xs">
            @if ($eyebrow)
                <p class="text-slate-500">{{ '# '.$eyebrow.' — '.$hostLabel }}</p>
            @endif
            @foreach ($lines as $line)
                <p @class([$lineTone($line['tone'] ?? 'muted'), 'mt-3' => ($line['tone'] ?? 'muted') !== 'muted'])>{{ $line['text'] }}</p>
            @endforeach
            <p class="mt-3 text-emerald-300">
                <span class="text-slate-300">~ $</span>
                <span class="inline-block h-4 w-2 animate-pulse bg-emerald-300/90 align-middle" aria-hidden="true"></span>
            </p>
        </div>

        @if ($server && $heroNote)
            <div class="relative mt-4 inline-flex items-center gap-2 rounded-lg border border-white/10 bg-white/5 px-3 py-1.5 text-xs text-slate-300">
                <x-heroicon-o-server-stack class="h-3.5 w-3.5 shrink-0 text-sky-300/80" aria-hidden="true" />
                <span>{{ $heroNote }}</span>
            </div>
        @endif
    </div>

    {{-- Content --}}
    <div class="relative bg-gradient-to-b from-brand-cream to-white px-6 py-7 sm:px-8 sm:py-8">
        <div class="max-w-md text-center sm:text-left">
            <div class="inline-flex h-11 w-11 items-center justify-center rounded-xl bg-brand-sage/10 text-brand-forest ring-1 ring-brand-sage/20">
                <x-dynamic-component :component="$icon" class="h-6 w-6 shrink-0" aria-hidden="true" />
            </div>
            <h2 class="mt-4 text-xl font-semibold tracking-tight text-brand-ink sm:text-2xl">{{ $title }}</h2>
            @if ($description)
                <p class="mt-2 text-sm leading-6 text-brand-moss sm:text-[15px]">{{ $description }}</p>
            @endif
        </div>

        @if (! empty($features))
            <ul class="mt-7 grid gap-3 sm:grid-cols-2">
                @foreach ($features as $feature)
                    <li class="flex gap-3 rounded-xl border border-brand-ink/8 bg-white/90 p-3.5 shadow-sm ring-1 ring-brand-ink/[0.03] sm:p-4">
                        <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-brand-sand/35 text-brand-forest ring-1 ring-brand-ink/8">
                            <x-dynamic-component :component="'heroicon-o-'.$feature['icon']" class="h-4 w-4" aria-hidden="true" />
                        </span>
                        <span class="min-w-0 text-left">
                            <span class="block font-semibold text-brand-ink">{{ $feature['title'] }}</span>
                            <span class="mt-0.5 block text-[13px] leading-5 text-brand-moss">{{ $feature['body'] }}</span>
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif

        <div class="mt-7 flex flex-col gap-3 border-t border-brand-ink/8 pt-5 sm:flex-row sm:items-center sm:justify-between">
            <p class="text-sm text-brand-moss">
                {{ $footnote ?? __('We will enable this for your org when it ships.') }}
            </p>
            <span class="inline-flex items-center justify-center gap-1.5 rounded-full bg-brand-ink/[0.04] px-3 py-1.5 text-xs font-medium text-brand-mist">
                <x-heroicon-o-clock class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                {{ __('In development') }}
            </span>
        </div>
    </div>
</div>
