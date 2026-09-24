@php
    $items = [
        // The old Projects grouping product is gone. This row is Compute.
        // The Edge list (nav label: Projects) is the first entry — do not
        // add a second Projects item here. Cross-product ops views live
        // under /infrastructure.
        // Backups moved to the Services row — it is a managed capability,
        // not compute (docs/adr/managed-services-tier.md, decision 1).
        // These carry a `feature` key because their routes stay registered
        // while the surface is parked — only the feature middleware rejects
        // — so Route::has() alone would keep linking them into a 400.
        ['route' => 'dashboard', 'match' => ['dashboard', 'edge.create', 'edge.import', 'edge.templates', 'edge.usage'], 'label' => __('Dashboard'), 'icon' => 'bolt', 'feature' => 'surface.edge'],
        ['route' => 'edge.databases', 'match' => 'edge.databases', 'label' => __('Databases'), 'icon' => 'circle-stack', 'feature' => 'surface.edge'],
        ['route' => 'edge.queues', 'match' => 'edge.queues', 'label' => __('Queues'), 'icon' => 'queue-list', 'feature' => 'surface.edge'],
        ['route' => 'serverless.index', 'match' => 'serverless.*', 'label' => __('Serverless'), 'icon' => 'cpu-chip', 'feature' => 'surface.serverless'],
    ];
@endphp
@php
    $visible = array_values(array_filter($items, function (array $item): bool {
        return \Illuminate\Support\Facades\Route::has($item['route'])
            && (empty($item['feature']) || feature($item['feature']));
    }));
@endphp

@if ($visible !== [])

{{--
    The Compute row: the machines your code runs on. Carries the same eyebrow
    label + tab-strip shape as <x-services-index-nav> so the two rows read as
    one navigation in two registers rather than two unrelated bars. The label
    widths are pinned equal (sm:w-20) so both tab strips start on the same
    column. Differentiation is carried by weight, not by shape: white ground
    against the services row's sand tint, larger type, larger icons, and an
    ink-toned label against the services row's muted moss.
--}}

<nav class="border-b border-brand-ink/10 bg-white" aria-label="{{ __('Workspace') }}">
    <div class="mx-auto flex max-w-7xl items-center gap-3 px-4 sm:px-6 lg:px-8">
        <span class="hidden shrink-0 text-[11px] font-semibold uppercase tracking-wider text-brand-ink/60 sm:inline sm:w-20">
            {{ __('Compute') }}
        </span>
        <div class="flex min-w-0 flex-1 gap-0.5 overflow-x-auto sm:gap-1" style="-webkit-overflow-scrolling: touch;">
            @foreach ($visible as $item)
                @php
                    $routeExists = \Illuminate\Support\Facades\Route::has($item['route']);
                    $featureOk = empty($item['feature']) || feature($item['feature']);
                    $active = $routeExists && request()->routeIs($item['match']);
                @endphp
                @if ($routeExists && $featureOk)
                    <a
                        href="{{ route($item['route']) }}"
                        wire:navigate
                        @class([
                            'group inline-flex shrink-0 items-center gap-2 whitespace-nowrap border-b-2 px-2.5 py-2.5 text-sm font-medium leading-5 transition duration-150 ease-in-out sm:px-3',
                            'border-brand-ink text-brand-ink' => $active,
                            'border-transparent text-brand-moss hover:border-brand-sage/40 hover:text-brand-ink' => ! $active,
                        ])
                    >
                        <x-dynamic-component
                            :component="'heroicon-o-'.$item['icon']"
                            @class([
                                'h-4 w-4 shrink-0',
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
