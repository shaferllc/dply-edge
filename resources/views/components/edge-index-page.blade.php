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
    'emptyState' => 'local',
    'createUrl' => null,
    'usageUrl' => null,
    'templatesUrl' => null,
    'importUrl' => null,
    'orgName' => null,
])

@php
    $isProductionSurface = $emptyState === 'production';
    $allTotal = (int) ($totals['all'] ?? 0);
    // Deployers and viewers can't create apps; don't offer the button.
    $showCreateAction = $showCreateAction && (auth()->user()?->can('create', \App\Models\Site::class) ?? false);
    $showShellCreate = $edgeEnabled && $showCreateAction && $hasSitesInScope && $allTotal > 0;
    $showShellSecondary = $edgeEnabled && $showSecondaryActions && $hasSitesInScope && $allTotal > 0;
    $createUrl ??= route('edge.create');
    $usageUrl ??= route('edge.usage');
    $templatesUrl ??= route('edge.templates');
    $importUrl ??= route('edge.import');
@endphp

<div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
    @unless ($edgeEnabled)
        <div class="dply-card relative p-8 text-center">
            <span class="absolute end-6 top-6 inline-flex rounded-full bg-brand-sand/60 px-2.5 py-1 text-xs font-semibold uppercase tracking-wide text-brand-moss">
                {{ __('Coming soon') }}
            </span>
            <span class="mx-auto inline-flex h-14 w-14 items-center justify-center rounded-xl border border-brand-ink/10 bg-white text-brand-ink shadow-sm">
                <x-heroicon-o-globe-alt class="h-8 w-8 shrink-0" aria-hidden="true" />
            </span>
            <p class="mt-5 text-lg font-semibold text-brand-ink">{{ __('Dashboard') }}</p>
            <p class="mx-auto mt-2 max-w-xl text-sm leading-6 text-brand-moss">
                {{ __('JavaScript frameworks, static sites, previews, and CDN-style delivery.') }}
            </p>
            <p class="mt-5 text-sm font-medium text-brand-mist">{{ __('Not available yet') }}</p>
        </div>
    @else
        @unless ($hasSitesInScope)
        <x-profile-shell
            :title="__('Dashboard')"
            :description="$isProductionSurface
                ? __('Live Edge apps from the connected control plane.')
                : __('Your Edge apps, with this month’s traffic.')"
            icon="heroicon-o-globe-alt"
        >
            @if ($showShellCreate || $showShellSecondary || isset($actions))
                <x-slot:actions>
                    @if ($showShellSecondary)
                        <x-outline-link :href="route('edge.resources')" size="xxs" wire:navigate>
                            {{ __('Resources') }}
                        </x-outline-link>
                        <x-outline-link :href="$usageUrl" size="xxs" wire:navigate>
                            {{ __('Usage') }}
                        </x-outline-link>
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

            @if (isset($alert) && filled(trim((string) $alert)))
                <div class="border-b border-brand-ink/10 px-5 py-4 sm:px-6">
                    {{ $alert }}
                </div>
            @endif

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
                                            class="inline-flex items-center gap-1.5 rounded-xl border border-brand-ink/15 bg-white px-3 py-2.5 font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40 dark:border-brand-mist/25 dark:bg-zinc-800 dark:hover:bg-raw-zinc-700"
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
        </x-profile-shell>
        @else
            <header class="relative mb-6 overflow-hidden rounded-2xl border border-brand-ink/10 bg-white shadow-sm dark:border-brand-mist/20 dark:bg-zinc-900">
                <span class="absolute inset-y-0 left-0 w-1 bg-brand-forest" aria-hidden="true"></span>
                <div class="pointer-events-none absolute -right-6 -top-10 h-28 w-28 rounded-full bg-brand-forest/15 blur-2xl" aria-hidden="true"></div>
                <div class="flex flex-wrap items-end justify-between gap-x-6 gap-y-3 px-5 py-6 sm:px-7 sm:py-7">
                    <div class="min-w-0 pl-1">
                        <p class="text-xs font-semibold uppercase tracking-[0.2em] text-brand-forest">{{ __('Dashboard') }}</p>
                        <h1 class="mt-1.5 truncate text-2xl font-semibold tracking-tight text-brand-ink sm:text-3xl">{{ $orgName ?: __('Apps') }}</h1>
                        <p class="mt-1 text-sm text-brand-moss">
                            {{ trans_choice(':count app|:count apps', $allTotal - (int) ($totals['previews'] ?? 0), ['count' => $allTotal - (int) ($totals['previews'] ?? 0)]) }}
                            @if (($totals['previews'] ?? 0) > 0)
                                · {{ trans_choice(':count open preview|:count open previews', $totals['previews'], ['count' => $totals['previews']]) }}
                            @endif
                        </p>
                    </div>
                    @if ($showSecondaryActions || $showCreateAction)
                        <div class="flex flex-wrap items-center gap-2">
                            @if ($showSecondaryActions)
                                <x-outline-link :href="route('edge.resources')" size="xxs" wire:navigate>{{ __('Resources') }}</x-outline-link>
                                <x-outline-link :href="$usageUrl" size="xxs" wire:navigate>{{ __('Usage') }}</x-outline-link>
                            @endif
                            @if ($showCreateAction)
                                <a href="{{ $createUrl }}" wire:navigate class="inline-flex items-center justify-center gap-2 rounded-xl bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream shadow-md transition-colors hover:bg-brand-forest">
                                    <x-heroicon-o-sparkles class="h-4 w-4 shrink-0" aria-hidden="true" />
                                    {{ __('Deploy an app') }}
                                </a>
                            @endif
                        </div>
                    @endif
                </div>
            </header>
            @if (isset($alert) && filled(trim((string) $alert)))
                <div class="mb-4">{{ $alert }}</div>
            @endif
            @if ($showFilters)
                <div class="mb-4 flex flex-wrap gap-1.5" role="group" aria-label="{{ __('Filter apps') }}">
                    @foreach (['all' => __('All'), 'active' => __('Active'), 'provisioning' => __('Building'), 'failed' => __('Failed'), 'previews' => __('Previews')] as $key => $label)
                        @php $count = (int) ($totals[$key] ?? 0); @endphp
                        @continue($key !== 'all' && $key !== $filter && $count === 0)
                        <button
                            type="button"
                            wire:click="$set('filter', '{{ $key }}')"
                            aria-pressed="{{ $filter === $key ? 'true' : 'false' }}"
                            @class([
                                'inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-semibold transition',
                                'border-brand-ink bg-brand-ink text-brand-cream' => $filter === $key,
                                'border-brand-ink/10 text-brand-moss hover:border-brand-ink/30 hover:text-brand-ink dark:border-brand-mist/20' => $filter !== $key,
                            ])
                        >
                            {{ $label }}
                            <span @class(['font-mono font-normal', 'text-rose-600 dark:text-rose-300' => $key === 'failed' && $filter !== $key, 'opacity-70' => $key !== 'failed' || $filter === $key])>{{ $count }}</span>
                        </button>
                    @endforeach
                </div>
            @endif
                @if ($rows->isEmpty())
                    <div class="flex flex-col items-center justify-center px-5 py-16 text-center sm:px-6">
                        <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-brand-sand/45 text-brand-mist ring-1 ring-brand-ink/10">
                            <x-heroicon-o-magnifying-glass class="h-5 w-5" aria-hidden="true" />
                        </span>
                        <p class="mt-3 text-sm font-semibold text-brand-ink">{{ __('No Edge sites match this filter') }}</p>
                        <p class="mt-1 max-w-md text-sm leading-relaxed text-brand-moss">
                            {{ __('Nothing matches this view.') }}
                        </p>
                        @if ($showFilters)
                            <button type="button" wire:click="$set('filter', 'all')" class="mt-4 text-xs font-semibold text-brand-sage hover:text-brand-ink">
                                {{ __('Show all') }}
                            </button>
                        @endif
                    </div>
                @else
                    @php
                        // Rows arrive with each parent's previews directly after it
                        // (Index::render); fold those onto the parent's card.
                        $cards = [];
                        foreach ($rows as $row) {
                            if ($row->isPreviewChild && $cards !== []) {
                                $cards[array_key_last($cards)]['previews'][] = $row;

                                continue;
                            }
                            $cards[] = ['site' => $row, 'previews' => []];
                        }
                    @endphp
                    <ul class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                        @foreach ($cards as $card)
                            @include('components.partials.edge-index-card', $card)
                        @endforeach
                        @if ($showCreateAction)
                            <li>
                                <a href="{{ $createUrl }}" wire:navigate class="flex h-full min-h-56 flex-col items-center justify-center gap-2 rounded-2xl border-[1.5px] border-dashed border-brand-ink/20 p-7 text-center text-brand-moss transition hover:border-brand-ink hover:text-brand-ink dark:border-brand-mist/25">
                                    <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-brand-ink text-brand-cream">
                                        <x-heroicon-o-plus class="h-5 w-5" aria-hidden="true" />
                                    </span>
                                    <span class="text-sm font-semibold text-brand-ink">{{ __('Deploy an app') }}</span>
                                    <span class="text-xs">{{ __('From a repo, a template, or an import') }}</span>
                                </a>
                            </li>
                        @endif
                    </ul>
                @endif
        @endunless
    @endunless

    {{ $modals ?? '' }}
</div>
