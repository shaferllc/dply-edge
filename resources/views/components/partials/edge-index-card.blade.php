@props([
    /** @var \App\Support\Edge\EdgeIndexRow $site */
    'site',
])

<li
    wire:key="edge-{{ $site->id }}"
    @class([
        'flex flex-col gap-4 rounded-xl border border-brand-ink/10 p-4 shadow-sm dark:border-brand-mist/20',
        'bg-brand-sand/40 dark:bg-zinc-900' => $site->isPreviewChild,
        'bg-white dark:bg-zinc-900' => ! $site->isPreviewChild,
    ])
>
    <div class="flex min-w-0 flex-1 items-start gap-3 {{ $site->isPreviewChild ? 'sm:pl-6' : '' }}">
        @if ($site->isPreviewChild)
            <span class="mt-1 hidden select-none text-brand-mist sm:inline" aria-hidden="true">↳</span>
        @endif
        <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                @if ($site->manageEnabled && $site->manageHref)
                    <a href="{{ $site->manageHref }}" wire:navigate class="truncate text-sm font-semibold text-brand-ink hover:text-brand-sage">{{ $site->name }}</a>
                @else
                    <span class="truncate text-sm font-semibold text-brand-ink">{{ $site->name }}</span>
                @endif
                @if ($site->previewBranch)
                    <span class="inline-flex items-center rounded-full bg-brand-sage/15 px-2 py-0.5 text-xs font-semibold text-brand-forest">
                        @if ($site->previewPrNumber)
                            PR #{{ $site->previewPrNumber }}
                        @else
                            {{ __('Preview') }}
                        @endif
                    </span>
                @endif
                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold {{ $site->statusBadgeClass }}">
                    {{ $site->statusLabel }}
                </span>
            </div>
            <p class="mt-1 truncate text-xs text-brand-moss">{{ $site->sourceLabel ?? '—' }}</p>
            @if ($site->liveUrl)
                <a href="{{ $site->liveUrl }}" target="_blank" rel="noopener noreferrer" class="mt-0.5 block truncate text-xs font-medium text-brand-sage hover:underline">{{ $site->hostname ?: $site->liveUrl }}</a>
            @else
                <p class="mt-0.5 text-xs text-brand-mist">{{ __('No URL yet') }}</p>
            @endif
        </div>
    </div>

    <dl class="grid grid-cols-3 gap-4 sm:gap-8">
        <div>
            <dt class="text-xs text-brand-mist">{{ __('Requests') }}</dt>
            <dd class="font-mono text-sm font-semibold tabular-nums text-brand-ink">{{ $site->requestsLabel }}</dd>
        </div>
        <div>
            <dt class="text-xs text-brand-mist">{{ __('Bandwidth') }}</dt>
            <dd class="font-mono text-sm font-semibold tabular-nums text-brand-ink">{{ $site->bandwidthLabel }}</dd>
        </div>
        <div>
            <dt class="text-xs text-brand-mist">{{ __('Last deploy') }}</dt>
            <dd class="text-sm font-semibold text-brand-ink">{{ $site->lastDeployLabel }}</dd>
        </div>
    </dl>

    @if ($site->manageEnabled || $site->canDelete)
        <div class="flex shrink-0 items-center gap-2">
            @if ($site->manageEnabled && $site->manageHref)
                <a href="{{ $site->manageHref }}" wire:navigate class="inline-flex items-center gap-1.5 rounded-lg bg-brand-ink px-3 py-1.5 text-xs font-semibold text-brand-cream hover:bg-brand-forest">
                    {{ __('Open') }}
                </a>
            @endif
            @if ($site->canDelete)
                <button type="button" wire:click="openDeleteSiteModal('{{ $site->id }}')" class="inline-flex items-center rounded-lg px-2 py-1.5 text-xs font-semibold text-brand-mist hover:text-rose-700" title="{{ __('Delete') }}">
                    {{ __('Delete') }}
                </button>
            @endif
        </div>
    @endif
</li>
