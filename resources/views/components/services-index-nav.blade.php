{{--
    The Services row: managed capabilities your apps lean on, as opposed to the
    compute they run on. Deliberately subordinate to <x-compute-index-nav> —
    smaller type, no bottom border of its own, muted until active — because two
    equal-weight tab bars read as two competing navigations rather than a
    primary and a secondary.

    Grouping only. These four do not share a billing shape: Backups does not
    bill at all, Realtime is flat per-app-per-tier, Queue is per-namespace-per-
    tier and free when it serves Serverless, and Cache is free outright on the
    shared tier with revenue only from a dedicated cluster. See
    docs/adr/managed-services-tier.md decision 2, and docs/adr/dply-cache.md
    decision 7 — which reopens Queue's Serverless-only free tier, since the
    two products sitting side by side under different rules is an incoherence
    to resolve rather than to preserve.
--}}

@php
    $items = [
        ['route' => 'backups.overview', 'match' => 'backups.*', 'label' => __('Backups'), 'icon' => 'archive-box', 'feature' => 'workspace.backups'],
        ['route' => 'realtime.index', 'match' => 'realtime.*', 'label' => __('Realtime'), 'icon' => 'signal'],
        ['route' => 'queues.index', 'match' => 'queues.*', 'label' => __('Queues'), 'icon' => 'queue-list', 'feature' => 'surface.queue'],
        ['route' => 'caches.index', 'match' => 'caches.*', 'label' => __('Caches'), 'icon' => 'bolt', 'feature' => 'surface.cache'],
    ];
@endphp
@php
    $visible = array_values(array_filter($items, function (array $item): bool {
        return \Illuminate\Support\Facades\Route::has($item['route'])
            && (empty($item['feature']) || feature($item['feature']));
    }));
@endphp

@if ($visible !== [])

<nav class="border-b border-brand-ink/10 bg-brand-sand/20" aria-label="{{ __('Services') }}">
    <div class="mx-auto flex max-w-7xl items-center gap-3 px-4 sm:px-6 lg:px-8">
        {{-- sm:w-20 matches the Compute eyebrow so both tab strips start on the
             same column; the muted moss (vs. Compute's ink) keeps this row
             reading as the secondary of the pair. --}}
        <span class="hidden shrink-0 text-[11px] font-semibold uppercase tracking-wider text-brand-moss/70 sm:inline sm:w-20">
            {{ __('Services') }}
        </span>
        <div class="flex min-w-0 flex-1 gap-0.5 overflow-x-auto sm:gap-1" style="-webkit-overflow-scrolling: touch;">
            @foreach ($visible as $item)
                @php
                    // Same two guards as the compute row: a Queues entry can sit
                    // in this array before its pages exist and simply not render.
                    $routeExists = \Illuminate\Support\Facades\Route::has($item['route']);
                    $featureOk = empty($item['feature']) || feature($item['feature']);
                    $active = $routeExists && request()->routeIs($item['match']);
                @endphp
                @if ($routeExists && $featureOk)
                    <a
                        href="{{ route($item['route']) }}"
                        wire:navigate
                        @class([
                            'group inline-flex shrink-0 items-center gap-1.5 whitespace-nowrap border-b-2 px-2 py-1.5 text-xs font-medium leading-5 transition duration-150 ease-in-out sm:px-2.5',
                            'border-brand-ink text-brand-ink' => $active,
                            'border-transparent text-brand-moss hover:border-brand-sage/40 hover:text-brand-ink' => ! $active,
                        ])
                    >
                        <x-dynamic-component
                            :component="'heroicon-o-'.$item['icon']"
                            @class([
                                'h-3.5 w-3.5 shrink-0',
                                'text-brand-ink' => $active,
                                'text-brand-moss group-hover:text-brand-ink' => ! $active,
                            ])
                            aria-hidden="true"
                        />
                        {{ $item['label'] }}
                    </a>
                @endif
            @endforeach
        </div>
    </div>
</nav>
@endif
