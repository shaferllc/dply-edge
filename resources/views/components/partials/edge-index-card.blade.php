@props([
    /** @var \App\Support\Edge\EdgeIndexRow $site */
    'site',
])

<li
    wire:key="edge-{{ $site->id }}"
    class="flex flex-col gap-3 border-b border-brand-ink/10 px-5 py-4 transition-colors last:border-b-0 hover:bg-brand-sand/15 sm:px-6 lg:flex-row lg:items-center lg:justify-between lg:gap-6 {{ $site->isPreviewChild ? 'bg-brand-sand/10' : '' }}"
>
    <div class="flex min-w-0 flex-1 items-start gap-3 {{ $site->isPreviewChild ? 'sm:pl-6' : '' }}">
        @if ($site->isPreviewChild)
            <span class="mt-1 hidden select-none text-brand-mist sm:inline" aria-hidden="true">↳</span>
        @endif
        <div class="min-w-0 flex-1">
            <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                @if ($site->manageEnabled && $site->manageHref)
                    <a
                        href="{{ $site->manageHref }}"
                        wire:navigate
                        class="truncate text-sm font-semibold {{ $site->isPreviewChild ? 'text-brand-moss' : 'text-brand-ink' }} hover:text-brand-sage"
                    >{{ $site->name }}</a>
                @else
                    <span class="truncate text-sm font-semibold text-brand-ink">{{ $site->name }}</span>
                @endif
                @if ($site->previewBranch)
                    <span class="inline-flex items-center rounded-full bg-brand-sage/15 px-2 py-0.5 text-2xs font-semibold uppercase tracking-[0.12em] text-brand-forest dark:bg-brand-sage/20 dark:text-brand-sage">
                        @if ($site->previewPrNumber)
                            PR #{{ $site->previewPrNumber }}
                        @else
                            {{ __('Preview') }}
                        @endif
                    </span>
                @endif
                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-2xs font-semibold uppercase tracking-[0.14em] {{ $site->statusBadgeClass }}">
                    {{ $site->statusLabel }}
                </span>
            </div>

            <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-brand-moss">
                <span class="inline-flex items-center gap-1 font-mono">
                    <x-heroicon-m-code-bracket class="h-3.5 w-3.5 shrink-0 text-brand-mist" aria-hidden="true" />
                    {{ $site->sourceLabel ?? '—' }}
                </span>
                <span aria-hidden="true" class="text-brand-mist/60">·</span>
                <span class="inline-flex items-center gap-1.5">
                    <x-heroicon-m-cpu-chip class="h-3.5 w-3.5 shrink-0 text-brand-mist" aria-hidden="true" />
                    {{ $site->runtimeLabel }}
                    @if ($site->frameworkLabel)
                        <span class="inline-flex rounded-full border border-brand-ink/15 bg-brand-sand/60 px-2 py-0.5 text-2xs font-semibold uppercase tracking-[0.12em] text-brand-moss">{{ $site->frameworkLabel }}</span>
                    @endif
                </span>
                <span aria-hidden="true" class="text-brand-mist/60">·</span>
                @if ($site->liveUrl)
                    <a
                        href="{{ $site->liveUrl }}"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="inline-flex max-w-full items-center gap-1 truncate font-medium text-brand-sage hover:underline"
                    >
                        <x-heroicon-m-globe-alt class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                        <span class="truncate">{{ $site->hostname ?: $site->liveUrl }}</span>
                    </a>
                @else
                    <span class="inline-flex items-center gap-1 text-brand-mist">
                        <x-heroicon-m-globe-alt class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                        {{ $site->hostname ?: __('Pending') }}
                    </span>
                @endif
            </div>
        </div>
    </div>

    @if ($site->manageEnabled || $site->canQuickLook || $site->canDelete)
        <div class="flex shrink-0 flex-wrap items-center justify-end gap-2">
            @if ($site->canQuickLook)
                <button
                    type="button"
                    wire:click="openQuickLookModal('{{ $site->id }}')"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink transition hover:bg-brand-sand/40"
                    title="{{ __('Peek at the live build/provisioning status without leaving this list.') }}"
                >
                    <x-heroicon-o-eye class="h-4 w-4" aria-hidden="true" />
                    {{ __('Status') }}
                </button>
            @endif
            @if ($site->manageEnabled && $site->manageHref)
                <a
                    href="{{ $site->manageHref }}"
                    wire:navigate
                    class="inline-flex items-center justify-center gap-1.5 rounded-xl bg-brand-ink px-3 py-1.5 text-xs font-semibold text-brand-cream shadow-sm transition hover:bg-brand-forest"
                >
                    {{ __('Open') }}
                    <x-heroicon-m-arrow-up-right class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                </a>
            @endif
            @if ($site->canDelete)
                <button
                    type="button"
                    wire:click="openDeleteSiteModal('{{ $site->id }}')"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-rose-300 bg-rose-50 px-3 py-1.5 text-xs font-semibold text-rose-800 transition hover:bg-rose-100 dark:border-raw-rose-900/50 dark:bg-rose-950/30 dark:text-rose-300 dark:hover:bg-rose-950/50"
                >
                    <x-heroicon-o-trash class="h-4 w-4" aria-hidden="true" />
                    {{ __('Delete') }}
                </button>
            @endif
        </div>
    @endif
</li>
