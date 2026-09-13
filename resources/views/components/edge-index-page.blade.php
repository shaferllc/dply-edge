@props([
    /** @var \Illuminate\Support\Collection<int, \App\Support\Edge\EdgeIndexRow> */
    'rows',
    /** @var array{all: int, active: int, provisioning: int, previews: int, failed: int} */
    'totals' => ['all' => 0, 'active' => 0, 'provisioning' => 0, 'previews' => 0, 'failed' => 0],
    'hasSitesInScope' => true,
    'edgeEnabled' => true,
    'filter' => 'all',
    'showFilters' => true,
    'showCreateAction' => false,
    'showSecondaryActions' => false,
    /** @var list<array{label: string, href?: string, icon?: string}> */
    'breadcrumbs' => [],
    'emptyState' => 'local',
    'createUrl' => null,
    'usageUrl' => null,
    'templatesUrl' => null,
    'importUrl' => null,
])

@php
    $isProductionSurface = $emptyState === 'production';
    $allTotal = (int) ($totals['all'] ?? 0);
    $showStats = $edgeEnabled && $hasSitesInScope && $allTotal > 0;
    $showShellCreate = $edgeEnabled && $showCreateAction && $hasSitesInScope && $allTotal > 0;
    $showShellSecondary = $edgeEnabled && $showSecondaryActions && $hasSitesInScope && $allTotal > 0;
    $createUrl ??= route('edge.create');
    $usageUrl ??= route('edge.usage');
    $templatesUrl ??= route('edge.templates');
    $importUrl ??= route('edge.import');
    $summaryStats = [
        [
            'icon' => 'heroicon-o-globe-alt',
            'label' => __('All sites'),
            'value' => $allTotal,
            'tone' => 'text-brand-sage',
        ],
        [
            'icon' => 'heroicon-o-check-badge',
            'label' => __('Active'),
            'value' => (int) ($totals['active'] ?? 0),
            'tone' => 'text-brand-forest',
        ],
        [
            'icon' => 'heroicon-o-arrow-path',
            'label' => __('Provisioning'),
            'value' => (int) ($totals['provisioning'] ?? 0),
            'tone' => ((int) ($totals['provisioning'] ?? 0)) > 0 ? 'text-brand-sage' : 'text-brand-mist',
        ],
        [
            'icon' => 'heroicon-o-sparkles',
            'label' => __('Previews'),
            'value' => (int) ($totals['previews'] ?? 0),
            'tone' => 'text-brand-sage',
        ],
        [
            'icon' => 'heroicon-o-exclamation-triangle',
            'label' => __('Failed'),
            'value' => (int) ($totals['failed'] ?? 0),
            'tone' => ((int) ($totals['failed'] ?? 0)) > 0 ? 'text-rose-600' : 'text-brand-mist',
        ],
    ];
    $filterTabs = [
        ['key' => 'all', 'label' => __('All'), 'count' => (int) ($totals['all'] ?? 0)],
        ['key' => 'previews', 'label' => __('Previews'), 'count' => (int) ($totals['previews'] ?? 0)],
        ['key' => 'provisioning', 'label' => __('Provisioning'), 'count' => (int) ($totals['provisioning'] ?? 0)],
        ['key' => 'failed', 'label' => __('Failed'), 'count' => (int) ($totals['failed'] ?? 0)],
    ];
@endphp

<div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
    <x-breadcrumb-trail :items="$breadcrumbs" />

    @unless ($edgeEnabled)
        <div class="dply-card relative p-8 text-center">
            <span class="absolute end-6 top-6 inline-flex rounded-full bg-brand-sand/60 px-2.5 py-1 text-xs font-semibold uppercase tracking-wide text-brand-moss">
                {{ __('Coming soon') }}
            </span>
            <span class="mx-auto inline-flex h-14 w-14 items-center justify-center rounded-xl border border-brand-ink/10 bg-white text-brand-ink shadow-sm">
                <x-heroicon-o-globe-alt class="h-8 w-8 shrink-0" aria-hidden="true" />
            </span>
            <p class="mt-5 text-lg font-semibold text-brand-ink">{{ __('Projects') }}</p>
            <p class="mx-auto mt-2 max-w-xl text-sm leading-6 text-brand-moss">
                {{ __('JavaScript frameworks, static sites, previews, and CDN-style delivery.') }}
            </p>
            <p class="mt-5 text-sm font-medium text-brand-mist">{{ __('Not available yet') }}</p>
        </div>
    @else
        <x-profile-shell
            :title="__('Projects')"
            :description="$isProductionSurface
                ? __('Live Edge sites from the connected control plane — Open materializes into the real workspace with Production data.')
                : __('Static and SSG apps on the dply Edge platform — git-connected builds, previews, and delivery.')"
            icon="heroicon-o-globe-alt"
        >
            @if ($showShellCreate || $showShellSecondary || isset($actions))
                <x-slot:actions>
                    @if ($showShellSecondary)
                        <a href="{{ $usageUrl }}" wire:navigate class="inline-flex items-center gap-1.5 rounded-xl border border-brand-ink/15 bg-white px-3 py-2 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40">
                            <x-heroicon-o-chart-bar class="h-4 w-4" aria-hidden="true" />
                            {{ __('Usage') }}
                        </a>
                        <a href="{{ $templatesUrl }}" wire:navigate class="inline-flex items-center gap-1.5 rounded-xl border border-brand-ink/15 bg-white px-3 py-2 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40">
                            <x-heroicon-o-rectangle-stack class="h-4 w-4" aria-hidden="true" />
                            {{ __('Templates') }}
                        </a>
                        <a href="{{ $importUrl }}" wire:navigate class="inline-flex items-center gap-1.5 rounded-xl border border-brand-ink/15 bg-white px-3 py-2 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40">
                            <x-heroicon-o-arrow-down-tray class="h-4 w-4" aria-hidden="true" />
                            {{ __('Import') }}
                        </a>
                    @endif
                    @if ($showShellCreate)
                        <a
                            href="{{ $createUrl }}"
                            wire:navigate
                            class="inline-flex items-center justify-center gap-2 rounded-xl bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream shadow-md transition-colors hover:bg-brand-forest"
                        >
                            <x-heroicon-o-sparkles class="h-4 w-4 shrink-0" aria-hidden="true" />
                            {{ __('Deploy an edge app') }}
                        </a>
                    @endif
                    @isset($actions)
                        {{ $actions }}
                    @endisset
                </x-slot:actions>
            @endif

            @if ($showStats)
                <x-slot:stats>
                    <dl class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-5">
                        @foreach ($summaryStats as $stat)
                            <div class="rounded-xl border border-brand-ink/10 bg-white/80 px-3 py-2">
                                <dt class="flex items-center gap-1.5 text-2xs font-semibold uppercase tracking-wide text-brand-mist">
                                    <x-dynamic-component :component="$stat['icon']" class="h-3.5 w-3.5 shrink-0 {{ $stat['tone'] }}" aria-hidden="true" />
                                    <span class="truncate">{{ $stat['label'] }}</span>
                                </dt>
                                <dd class="mt-0.5 font-mono text-lg font-semibold tabular-nums leading-none text-brand-ink">{{ $stat['value'] }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </x-slot:stats>
            @endif

            @if (isset($alert) && filled(trim((string) $alert)))
                <div class="border-b border-brand-ink/10 px-5 py-4 sm:px-6">
                    {{ $alert }}
                </div>
            @endif

            @unless ($hasSitesInScope)
                @if (isset($empty) && ! $empty->isEmpty())
                    {{ $empty }}
                @elseif ($isProductionSurface)
                    <div class="flex flex-col items-center justify-center px-5 py-16 text-center sm:px-6" aria-labelledby="edge-empty-heading">
                        <span class="flex h-12 w-12 items-center justify-center rounded-2xl bg-brand-sand/45 text-brand-mist ring-1 ring-brand-ink/10">
                            <x-heroicon-o-globe-alt class="h-6 w-6" aria-hidden="true" />
                        </span>
                        <h2 id="edge-empty-heading" class="mt-4 text-sm font-semibold text-brand-ink">
                            {{ __('No production Edge sites') }}
                        </h2>
                        <p class="mt-1 max-w-md text-sm leading-relaxed text-brand-moss">
                            {{ __('The connected control plane returned no Edge sites for this organization.') }}
                        </p>
                    </div>
                @else
                    @php
                        $emptyCapabilities = [
                            [
                                'icon' => 'heroicon-o-bolt',
                                'title' => __('Global delivery'),
                                'body' => __('Static and SSG assets ship to the edge — fast first paint without managing a CDN yourself.'),
                            ],
                            [
                                'icon' => 'heroicon-o-eye',
                                'title' => __('Preview every push'),
                                'body' => __('Branch and PR previews with shareable URLs so review happens before production.'),
                            ],
                            [
                                'icon' => 'heroicon-o-code-bracket',
                                'title' => __('Git-connected builds'),
                                'body' => __('Connect a repo, set build + output, and deploy — frameworks and plain static both welcome.'),
                            ],
                        ];
                    @endphp

                    <section class="relative overflow-hidden border-b border-brand-ink/10" aria-labelledby="edge-empty-heading">
                        <div class="pointer-events-none absolute inset-0 bg-[radial-gradient(ellipse_at_top_right,_rgba(122,154,122,0.16),_transparent_55%),radial-gradient(ellipse_at_bottom_left,_rgba(212,175,122,0.14),_transparent_50%)]" aria-hidden="true"></div>
                        <div class="relative flex flex-col gap-6 px-5 py-10 sm:px-6 lg:flex-row lg:items-end lg:justify-between lg:py-12">
                            <div class="min-w-0 max-w-2xl">
                                <div class="inline-flex items-center gap-2 rounded-full border border-brand-ink/10 bg-white/70 px-3 py-1 text-xs font-semibold uppercase tracking-[0.14em] text-brand-sage shadow-sm backdrop-blur-sm dark:border-brand-mist/20 dark:bg-zinc-900/70">
                                    <x-heroicon-o-globe-alt class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                    {{ __('Get started') }}
                                </div>
                                <h2 id="edge-empty-heading" class="mt-3 text-xl font-semibold tracking-tight text-brand-ink sm:text-2xl">
                                    {{ __('Launch your first Edge site') }}
                                </h2>
                                <p class="mt-2 text-sm leading-relaxed text-brand-moss">
                                    {{ __('dply Edge is for JavaScript frameworks and static sites — git builds, preview URLs, and global delivery. Your first live site is free; add a card when you ship a second. Long-running PHP and Rails apps belong on Cloud.') }}
                                </p>
                            </div>
                            <div class="flex shrink-0 flex-col gap-3 sm:flex-row sm:items-center">
                                @if ($showCreateAction)
                                    <a
                                        href="{{ $createUrl }}"
                                        wire:navigate
                                        class="inline-flex items-center justify-center gap-2 rounded-xl bg-brand-ink px-4 py-2.5 text-sm font-semibold text-brand-cream shadow-md transition-colors hover:bg-brand-forest"
                                    >
                                        <x-heroicon-o-sparkles class="h-4 w-4 shrink-0" aria-hidden="true" />
                                        {{ __('Deploy an edge app') }}
                                    </a>
                                @endif
                                @if ($showSecondaryActions)
                                    <div class="flex flex-wrap items-center gap-2 text-xs">
                                        <a
                                            href="{{ $templatesUrl }}"
                                            wire:navigate
                                            class="inline-flex items-center gap-1.5 rounded-xl border border-brand-ink/15 bg-white px-3 py-2.5 font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40 dark:border-brand-mist/25 dark:bg-zinc-800 dark:hover:bg-zinc-700"
                                        >
                                            <x-heroicon-o-rectangle-stack class="h-4 w-4 shrink-0" aria-hidden="true" />
                                            {{ __('Browse templates') }}
                                        </a>
                                        <a
                                            href="{{ $importUrl }}"
                                            wire:navigate
                                            class="inline-flex items-center gap-1.5 px-2 py-2.5 font-medium text-brand-moss transition hover:text-brand-ink"
                                        >
                                            {{ __('Import a site') }}
                                        </a>
                                    </div>
                                @endif
                            </div>
                        </div>
                    </section>

                    <section aria-labelledby="edge-empty-capabilities-heading">
                        <div class="border-b border-brand-ink/10 bg-brand-sand/20 px-5 py-4 sm:px-6 dark:bg-brand-sand/10">
                            <h3 id="edge-empty-capabilities-heading" class="text-sm font-semibold text-brand-ink">{{ __('What Edge gives you') }}</h3>
                            <p class="mt-0.5 text-sm text-brand-moss">{{ __('A Netlify-style path for frontends — not containers, not VMs.') }}</p>
                        </div>
                        <ul class="grid gap-0 sm:grid-cols-3">
                            @foreach ($emptyCapabilities as $i => $capability)
                                <li @class([
                                    'flex gap-3 px-5 py-5 sm:px-6',
                                    'border-b border-brand-ink/10 sm:border-b-0 sm:border-e dark:border-brand-mist/15' => $i < count($emptyCapabilities) - 1,
                                ])>
                                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-white text-brand-forest shadow-sm ring-1 ring-brand-ink/10 dark:bg-zinc-800 dark:text-brand-sage dark:ring-brand-mist/25">
                                        <x-dynamic-component :component="$capability['icon']" class="h-4 w-4" aria-hidden="true" />
                                    </span>
                                    <div class="min-w-0">
                                        <p class="text-sm font-semibold text-brand-ink">{{ $capability['title'] }}</p>
                                        <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ $capability['body'] }}</p>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                        @if ($showCreateAction || $showSecondaryActions)
                            <div class="border-t border-brand-ink/10 bg-brand-sand/25 px-5 py-4 sm:px-6 dark:border-brand-mist/15 dark:bg-brand-sand/10">
                                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                    <div class="min-w-0">
                                        <p class="text-sm font-semibold text-brand-ink">{{ __('Ready when you are') }}</p>
                                        <p class="mt-0.5 text-sm text-brand-moss">
                                            {{ __('Point Edge at a repo, pick a template, or import an existing static site.') }}
                                        </p>
                                    </div>
                                    <div class="flex shrink-0 flex-wrap items-center gap-2 text-xs">
                                        @if ($showCreateAction)
                                            <a
                                                href="{{ $createUrl }}"
                                                wire:navigate
                                                class="inline-flex items-center gap-1.5 rounded-lg bg-brand-ink px-3 py-2 font-semibold text-brand-cream hover:bg-brand-ink/90"
                                            >
                                                {{ __('Open deploy wizard') }}
                                                <x-heroicon-m-arrow-right class="h-4 w-4 shrink-0" aria-hidden="true" />
                                            </a>
                                        @endif
                                        @if ($showSecondaryActions)
                                            <a href="{{ $templatesUrl }}" wire:navigate class="font-medium text-brand-moss hover:text-brand-ink">{{ __('Templates') }}</a>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        @endif
                    </section>
                @endif
            @else
                @if ($showFilters)
                    <nav class="flex flex-wrap gap-2 border-b border-brand-ink/10 px-3 py-3 sm:px-5" aria-label="{{ __('Edge filters') }}">
                        @foreach ($filterTabs as $tab)
                            <button
                                type="button"
                                wire:click="$set('filter', '{{ $tab['key'] }}')"
                                class="rounded-full border px-3 py-1.5 text-xs font-semibold transition {{ $filter === $tab['key'] ? 'border-brand-ink bg-brand-ink text-brand-cream' : 'border-brand-ink/15 bg-white text-brand-ink hover:bg-brand-sand/40' }}"
                            >
                                {{ $tab['label'] }}
                                <span class="ml-1 font-mono opacity-80">{{ $tab['count'] }}</span>
                            </button>
                        @endforeach
                    </nav>
                @endif

                @if ($rows->isEmpty())
                    <div class="flex flex-col items-center justify-center px-5 py-16 text-center sm:px-6">
                        <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-brand-sand/45 text-brand-mist ring-1 ring-brand-ink/10">
                            <x-heroicon-o-magnifying-glass class="h-5 w-5" aria-hidden="true" />
                        </span>
                        <p class="mt-3 text-sm font-semibold text-brand-ink">{{ __('No Edge sites match this filter') }}</p>
                        <p class="mt-1 max-w-md text-sm leading-relaxed text-brand-moss">
                            {{ __('Try another status tab, or switch back to All to see every Edge site.') }}
                        </p>
                        @if ($showFilters)
                            <button type="button" wire:click="$set('filter', 'all')" class="mt-4 text-xs font-semibold text-brand-sage hover:text-brand-ink">
                                {{ __('Show all') }}
                            </button>
                        @endif
                    </div>
                @else
                    <ul>
                        @foreach ($rows as $site)
                            @include('components.partials.edge-index-card', ['site' => $site])
                        @endforeach
                    </ul>
                @endif
            @endunless
        </x-profile-shell>
    @endunless

    {{ $modals ?? '' }}
</div>
