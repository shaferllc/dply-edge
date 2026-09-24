{{-- Lean Overview identity — status, live URL, primary actions only. --}}
<section class="border-b border-brand-ink/10">
    <x-workspace-panel-head
        icon="heroicon-o-globe-alt"
        :title="$site->name"
        :note="$edgeSourceSpec ? $edgeSourceRef : null"
    >
        <x-slot:actions>
            <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-semibold uppercase tracking-wide ring-1 {{ $edgeStatusBadgeClass }}">
                {{ $edgeStatusLabel }}
            </span>
            @if ($edgeLiveUrl && ! empty($edgeActiveDeploymentId))
                <a href="{{ $edgeLiveUrl }}" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white/80 px-2.5 py-1.5 text-xs font-semibold text-brand-ink hover:bg-white dark:border-brand-mist/25 dark:bg-zinc-800">
                    <x-heroicon-o-arrow-top-right-on-square class="h-4 w-4" />
                    {{ __('Open') }}
                </a>
            @endif
            @can('update', $site)
                <button
                    type="button"
                    wire:click="redeployEdge"
                    wire:loading.attr="disabled"
                    wire:target="redeployEdge"
                    class="inline-flex items-center gap-1.5 rounded-lg bg-brand-ink px-2.5 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-brand-ink/90 disabled:opacity-60"
                >
                    <x-heroicon-o-arrow-path class="h-4 w-4" wire:loading.remove wire:target="redeployEdge" />
                    <span wire:loading wire:target="redeployEdge" class="inline-flex h-4 w-4 items-center justify-center"><x-spinner variant="white" size="sm" /></span>
                    <span wire:loading.remove wire:target="redeployEdge">{{ __('Deploy') }}</span>
                    <span wire:loading wire:target="redeployEdge">{{ __('Queuing…') }}</span>
                </button>
            @endcan
        </x-slot:actions>
    </x-workspace-panel-head>

    <div class="px-5 py-4 sm:px-6">
        @if ($edgeLiveUrl && ! empty($edgeActiveDeploymentId))
            <div
                x-data="{ copied: false }"
                class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-brand-ink/10 bg-white px-4 py-3 dark:bg-zinc-900/40"
            >
                <a href="{{ $edgeLiveUrl }}" target="_blank" rel="noopener noreferrer" class="inline-flex min-w-0 max-w-full items-center gap-1.5 font-mono text-xs text-brand-ink hover:underline">
                    <x-heroicon-m-globe-alt class="h-4 w-4 shrink-0 text-brand-forest" />
                    <span class="truncate">{{ $edgeLiveUrl }}</span>
                </a>
                <button
                    type="button"
                    class="inline-flex shrink-0 items-center gap-1 rounded-lg border border-brand-ink/10 bg-brand-sand/20 px-2.5 py-1 text-xs font-medium text-brand-moss hover:bg-brand-sand/40 dark:border-brand-mist/20 dark:bg-zinc-900 dark:text-brand-sage"
                    @click="navigator.clipboard.writeText(@js($edgeLiveUrl)); copied = true; setTimeout(() => copied = false, 2000)"
                >
                    <x-heroicon-o-clipboard class="h-4 w-4" />
                    <span x-show="!copied">{{ __('Copy') }}</span>
                    <span x-show="copied" x-cloak class="text-brand-forest">{{ __('Copied') }}</span>
                </button>
            </div>
        @elseif ($site->status === \App\Models\Site::STATUS_EDGE_FAILED && empty($edgeActiveDeploymentId))
            <p class="text-sm text-rose-700">{{ __('First deploy did not publish. Once a build succeeds, the live URL appears here.') }}</p>
        @else
            <p class="text-sm text-brand-moss">{{ __('Live URL pending — complete the first build to publish.') }}</p>
        @endif
    </div>

</section>
