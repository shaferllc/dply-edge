@props([
    /** @var \App\Support\Edge\EdgeIndexRow $site */
    'site',
    /** @var list<\App\Support\Edge\EdgeIndexRow> $previews  preview children folded onto this card */
    'previews' => [],
])

@php
    $isFailed = $site->status === \App\Models\Site::STATUS_EDGE_FAILED;
    $isProvisioning = $site->status === \App\Models\Site::STATUS_EDGE_PROVISIONING;
    $isActive = $site->status === \App\Models\Site::STATUS_EDGE_ACTIVE;
    $area = $site->sparkPath(320, 64, closed: true);
    $line = $site->sparkPath(320, 64);
    // Production is the trunk; each preview forks off it.
    $lanes = array_merge([$site], $previews);
    $nodeTone = fn ($row) => match ($row->status) {
        \App\Models\Site::STATUS_EDGE_FAILED => 'border-rose-500',
        \App\Models\Site::STATUS_EDGE_PROVISIONING => 'border-sky-500 animate-pulse',
        default => 'border-brand-sage',
    };
@endphp

<li
    wire:key="edge-{{ $site->id }}"
    @class([
        'group flex flex-col overflow-hidden rounded-2xl border bg-white shadow-sm transition hover:-translate-y-0.5 hover:shadow-md dark:bg-zinc-900',
        'border-rose-300 dark:border-rose-900' => $isFailed,
        'border-brand-ink/10 hover:border-brand-ink/20 dark:border-brand-mist/20' => ! $isFailed,
    ])
>
    <div class="flex items-start justify-between gap-2 px-4 pt-4">
        <div class="flex flex-wrap gap-1.5">
            @if ($site->frameworkLabel)
                <span class="rounded-md bg-brand-sand/40 px-2 py-0.5 text-xs font-semibold text-brand-moss">{{ $site->frameworkLabel }}</span>
            @endif
            <span class="rounded-md bg-brand-sand/40 px-2 py-0.5 text-xs font-semibold text-brand-moss">{{ $site->runtimeLabel }}</span>
            @if ($site->previewBranch)
                <span class="rounded-md bg-brand-sage/15 px-2 py-0.5 text-xs font-semibold text-brand-forest">
                    {{ $site->previewPrNumber ? 'PR #'.$site->previewPrNumber : __('Preview') }}
                </span>
            @endif
        </div>
        <div class="flex shrink-0 items-center gap-1">
            @unless ($isActive)
                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold {{ $site->statusBadgeClass }}">{{ $site->statusLabel }}</span>
            @endunless
            @if ($site->manageEnabled || $site->canDelete)
                <x-dropdown align="right" width="48">
                    <x-slot name="trigger">
                        <button type="button" class="rounded-lg p-1 text-brand-mist hover:bg-brand-sand/40 hover:text-brand-ink" aria-label="{{ __('More actions for :name', ['name' => $site->name]) }}">
                            <x-heroicon-o-ellipsis-horizontal class="h-5 w-5" aria-hidden="true" />
                        </button>
                    </x-slot>
                    <x-slot name="content">
                        @if ($site->manageEnabled && $site->manageHref)
                            <x-dropdown-link :href="$site->manageHref" wire:navigate>{{ __('Open workspace') }}</x-dropdown-link>
                        @endif
                        @if ($site->liveUrl)
                            <x-dropdown-link :href="$site->liveUrl" target="_blank" rel="noopener noreferrer">{{ __('Visit site') }}</x-dropdown-link>
                        @endif
                        @if ($site->canDelete)
                            <button type="button" wire:click="openDeleteSiteModal('{{ $site->id }}')" class="flex w-full items-center rounded-xl px-3 py-2.5 text-start text-sm font-medium text-rose-700 hover:bg-rose-50 dark:text-rose-300 dark:hover:bg-rose-950/40">
                                {{ __('Delete…') }}
                            </button>
                        @endif
                    </x-slot>
                </x-dropdown>
            @endif
        </div>
    </div>

    <div class="px-4 pt-2.5">
        <h3 class="break-words text-2xl font-semibold leading-tight tracking-tight text-brand-ink">
            @if ($site->manageEnabled && $site->manageHref)
                <a href="{{ $site->manageHref }}" wire:navigate class="hover:text-brand-forest">{{ $site->name }}</a>
            @else
                {{ $site->name }}
            @endif
        </h3>
        @if ($site->liveUrl)
            <a href="{{ $site->liveUrl }}" target="_blank" rel="noopener noreferrer" class="mt-0.5 inline-flex max-w-full items-center gap-1 truncate font-mono text-xs text-brand-sage hover:underline">
                {{ $site->hostname ?: $site->liveUrl }}
                <x-heroicon-o-arrow-top-right-on-square class="h-3 w-3 shrink-0 opacity-70" aria-hidden="true" />
            </a>
        @else
            <p class="mt-0.5 text-xs text-brand-mist">{{ __('No URL yet') }}</p>
        @endif
        @if ($site->sourceRepo)
            <p class="mt-1 truncate font-mono text-xs text-brand-mist">{{ $site->sourceRepo }}</p>
        @endif
    </div>

    @if ($site->failureReason)
        <p class="mx-4 mt-3 break-words rounded-lg bg-rose-50 px-3 py-2 font-mono text-xs leading-5 text-rose-800 dark:bg-rose-950/40 dark:text-rose-300">{{ \Illuminate\Support\Str::limit($site->failureReason, 160) }}</p>
    @endif

    @unless ($isProvisioning)
        <div class="flex gap-6 px-4 pt-3.5">
            <div>
                <p class="font-mono text-base font-bold tabular-nums text-brand-ink">{{ $site->requestsLabel }}</p>
                <p class="text-xs text-brand-mist">{{ __('Requests') }}</p>
            </div>
            <div>
                <p class="font-mono text-base font-bold tabular-nums text-brand-ink">{{ $site->bandwidthLabel }}</p>
                <p class="text-xs text-brand-mist">{{ __('Bandwidth') }}</p>
            </div>
        </div>
    @endunless

    <div class="mt-auto pt-3">
        @if ($line)
            <svg viewBox="0 0 320 64" preserveAspectRatio="none" class="block h-16 w-full" role="img" aria-label="{{ __('Requests per day, last 30 days') }}">
                <path d="{{ $area }}" class="fill-brand-forest/10"></path>
                <path d="{{ $line }}" fill="none" class="stroke-brand-forest" stroke-width="1.5" vector-effect="non-scaling-stroke" stroke-linejoin="round"></path>
            </svg>
        @else
            <div class="flex h-16 items-center justify-center bg-[repeating-linear-gradient(-45deg,transparent_0_8px,rgb(225_216_172/0.25)_8px_16px)] text-xs text-brand-moss">
                {{ $isProvisioning ? __('First build running…') : __('No traffic in the last 30 days') }}
            </div>
        @endif
    </div>

    <ul class="border-t border-brand-ink/10 py-1 dark:border-brand-mist/15" aria-label="{{ __('Deployments') }}">
        @foreach ($lanes as $i => $lane)
            @php
                $isTrunk = $i === 0;
                $isFirst = $i === 0;
                $isLast = $i === count($lanes) - 1;
            @endphp
            <li class="grid min-h-11 grid-cols-[2.5rem_minmax(0,1fr)_auto] items-center gap-2 py-1.5 pe-4">
                <span class="relative self-stretch" aria-hidden="true">
                    @unless ($isFirst && $isLast)
                        <span @class([
                            'absolute left-[22px] w-0.5 bg-brand-forest',
                            'top-1/2' => $isFirst, 'top-0' => ! $isFirst,
                            'bottom-1/2' => $isLast, 'bottom-0' => ! $isLast,
                        ])></span>
                    @endunless
                    @if ($isTrunk)
                        <span class="absolute left-4 top-1/2 h-3.5 w-3.5 -translate-y-1/2 rounded-full bg-brand-forest ring-4 ring-brand-forest/20"></span>
                    @else
                        <span class="absolute bottom-1/2 left-[23px] h-5 w-3.5 rounded-bl-[10px] border-b-2 border-l-2 border-brand-sage"></span>
                        <span class="absolute left-8 top-1/2 h-3 w-3 -translate-y-1/2 rounded-full border-[2.5px] bg-white dark:bg-zinc-900 {{ $nodeTone($lane) }}"></span>
                    @endif
                </span>
                <div @class(['min-w-0', 'ps-2' => ! $isTrunk])>
                    <div class="flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs">
                        <span class="font-mono font-bold text-brand-ink">{{ $isTrunk ? ($lane->commitBranch ?? $lane->sourceBranch ?? 'main') : ($lane->previewBranch ?? $lane->name) }}</span>
                        @unless ($isTrunk)
                            @if ($lane->previewPrNumber)
                                <span class="rounded-md bg-brand-sand/40 px-1.5 font-semibold text-brand-moss">PR #{{ $lane->previewPrNumber }}</span>
                            @endif
                            @if ($lane->status !== \App\Models\Site::STATUS_EDGE_ACTIVE)
                                <span class="rounded-full px-1.5 font-semibold {{ $lane->statusBadgeClass }}">{{ $lane->statusLabel }}</span>
                            @endif
                        @endunless
                    </div>
                    <div class="truncate text-xs text-brand-moss">
                        @if ($lane->commitSha)
                            <span class="font-mono text-brand-mist">{{ $lane->commitSha }}</span>
                        @endif
                        @if (! $isTrunk && $lane->liveUrl)
                            <a href="{{ $lane->liveUrl }}" target="_blank" rel="noopener noreferrer" class="font-mono text-brand-sage hover:underline">{{ $lane->hostname }}</a>
                        @elseif ($isTrunk)
                            {{ __('production') }}
                        @endif
                    </div>
                </div>
                <span class="whitespace-nowrap text-xs text-brand-mist">{{ $lane->lastDeployLabel }}</span>
            </li>
        @endforeach
    </ul>

    <div class="flex items-center justify-between gap-2 border-t border-brand-ink/10 px-4 py-2.5 text-xs text-brand-moss dark:border-brand-mist/15">
        <span>{{ trans_choice(':count deployment|:count deployments', count($lanes), ['count' => count($lanes)]) }}</span>
        <div class="flex items-center gap-2">
            @if ($site->canQuickLook)
                <button type="button" wire:click="openQuickLookModal('{{ $site->id }}')" class="rounded-lg border border-brand-ink/15 px-3 py-1.5 font-semibold text-brand-ink hover:border-brand-ink/40 dark:border-brand-mist/25">
                    {{ __('Quick look') }}
                </button>
            @endif
            @if ($site->manageEnabled && $site->manageHref)
                <a href="{{ $site->manageHref }}" wire:navigate class="rounded-lg bg-brand-ink px-3 py-1.5 font-semibold text-brand-cream hover:bg-brand-forest">
                    {{ __('Open') }}
                </a>
            @endif
        </div>
    </div>
</li>
