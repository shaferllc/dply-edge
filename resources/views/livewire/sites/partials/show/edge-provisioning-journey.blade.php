<div class="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(17rem,20rem)] lg:gap-8" wire:poll.5s="pollProvisioningStatus">
    <section class="dply-card overflow-hidden min-w-0 lg:col-start-1 lg:row-start-1">
        <div class="flex flex-col gap-6 border-b border-brand-ink/10 px-5 pb-6 pt-6 sm:px-8 sm:pb-8 sm:pt-8">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <p class="text-xs font-semibold uppercase tracking-[0.22em] text-brand-forest">{{ __('Edge deployment') }}</p>
                        @if ($edgeJourneyHasFailed)
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-red-100 px-2.5 py-1 text-2xs font-semibold uppercase tracking-wide text-red-800 ring-1 ring-red-200">
                                <x-heroicon-s-x-mark class="h-3 w-3" />
                                {{ __('Failed') }}
                            </span>
                        @elseif ($edgeJourneyIsDone)
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-100 px-2.5 py-1 text-2xs font-semibold uppercase tracking-wide text-emerald-800 ring-1 ring-emerald-200">
                                <x-heroicon-s-check class="h-3 w-3" />
                                {{ __('Live') }}
                            </span>
                        @else
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-sky-100 px-2.5 py-1 text-2xs font-semibold uppercase tracking-wide text-sky-800 ring-1 ring-sky-200">
                                <x-heroicon-o-arrow-path class="h-3 w-3 animate-spin" />
                                {{ __('Building') }}
                            </span>
                        @endif
                    </div>
                    <h2 class="mt-2 text-xl font-semibold tracking-tight text-brand-ink sm:text-2xl">
                        {{ __('Edge build') }}
                    </h2>
                    <p class="mt-2 max-w-prose text-sm leading-relaxed text-brand-moss">
                        @if ($edgeJourneyHasFailed)
                            {{ __('The Edge build hit an error. Review the failure details below, then retry — Dply will clone, build, and publish again.') }}
                        @else
                            {{ __('Dply is cloning your repository, running the build, and publishing static assets to the Edge CDN.') }}
                        @endif
                    </p>
                </div>
                <div class="flex shrink-0 flex-wrap gap-2 sm:justify-end">
                    @if ($edgeJourneyHasFailed)
                        <button
                            type="button"
                            wire:click="retryProvisioning"
                            wire:loading.attr="disabled"
                            wire:target="retryProvisioning"
                            class="inline-flex items-center justify-center gap-2 rounded-xl border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-semibold text-brand-ink shadow-sm transition-colors hover:border-brand-sage hover:text-brand-sage disabled:opacity-60"
                        >
                            <x-heroicon-o-arrow-path class="h-4 w-4" />
                            <span wire:loading.remove wire:target="retryProvisioning">{{ __('Retry build') }}</span>
                            <span wire:loading wire:target="retryProvisioning">{{ __('Retrying…') }}</span>
                        </button>
                    @endif
                    <button
                        type="button"
                        wire:click="openCancelProvisioningModal"
                        wire:loading.attr="disabled"
                        wire:target="openCancelProvisioningModal"
                        class="inline-flex items-center justify-center gap-2 rounded-xl border border-red-200 bg-red-50 px-4 py-2.5 text-sm font-semibold text-red-800 shadow-sm transition-colors hover:border-red-300 hover:bg-red-100 disabled:opacity-60"
                    >
                        <x-heroicon-o-x-circle class="h-4 w-4" />
                        {{ __('Cancel build') }}
                    </button>
                </div>
            </div>

            {{-- Progress bar + current-step display are owned by the
                 BuildJourney component below — keeping them here too
                 produced two different "X/4" readings because the outer
                 view reads from $site->status (lags behind the deployment
                 row by one tick) while BuildJourney reads from the
                 deployment directly. Single source of truth wins. --}}
        </div>

        <div class="flex flex-col gap-6 px-5 py-6 sm:px-8 sm:py-8">
            @if ($edgeJourneyHasFailed && $edgeProvisioningError)
                {{-- Failure reason callout — BuildJourney also shows the
                     deployment-level reason, but the site-level last_error
                     here can surface infra-level failures (e.g. R2 perms)
                     that never made it into the deployment row. --}}
                <div class="rounded-2xl border border-red-300 bg-white/80 px-4 py-3 text-xs">
                    <div class="flex items-start justify-between gap-3">
                        <p class="text-xs font-semibold uppercase tracking-wide text-red-700">{{ __('Last error') }}</p>
                        <button
                            type="button"
                            x-data="{ copied: false }"
                            x-on:click="navigator.clipboard.writeText(@js($edgeProvisioningError)); copied = true; setTimeout(() => copied = false, 1500)"
                            class="shrink-0 rounded-md border border-red-200 bg-white px-2 py-0.5 text-2xs font-semibold uppercase tracking-wide text-red-700 hover:border-red-300 hover:bg-red-50"
                        >
                            <span x-show="!copied">{{ __('Copy') }}</span>
                            <span x-show="copied" x-cloak>{{ __('Copied') }}</span>
                        </button>
                    </div>
                    <p class="mt-1 break-words font-mono leading-5 text-red-900">{{ $edgeProvisioningError }}</p>
                </div>
            @endif

            @include('livewire.sites.partials.edge.recovery-callout')

            {{-- The first deploy's progress is in the deploy pill, opened
                 for it: a first deploy is when people watch most. --}}
            @if ($edgeLatestDeployment !== null)
                <div class="rounded-2xl border border-brand-ink/10 bg-white/40 dark:bg-zinc-900/40">
                    @include('livewire.sites.partials.edge.deployment-journey-card', ['deployment' => $edgeLatestDeployment, 'autoOpen' => true])
                </div>
            @else
                <div class="rounded-2xl border border-dashed border-brand-ink/15 bg-white/40 px-5 py-6 text-center text-xs text-brand-moss">
                    {{ __('Waiting for the first deploy to start…') }}
                </div>
            @endif
        </div>
    </section>

    <aside class="w-full space-y-6 self-start lg:col-start-2 lg:row-start-1 lg:sticky lg:top-24 lg:max-w-none">
        <section class="dply-card overflow-hidden">
            <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                <x-icon-badge>
                    <x-heroicon-o-clipboard-document-list class="h-5 w-5" aria-hidden="true" />
                </x-icon-badge>
                <div class="min-w-0">
                    <p class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Summary') }}</p>
                    <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Edge summary') }}</h3>
                </div>
            </div>
            <div class="px-6 py-6 sm:px-7">
            <dl class="flex flex-col gap-3 text-sm">
                <div class="min-w-0">
                    <dt class="text-xs font-medium uppercase tracking-wide text-brand-mist">{{ __('Status') }}</dt>
                    <dd class="mt-0.5 font-semibold capitalize text-brand-ink">{{ $site->statusLabel() }}</dd>
                </div>
                <div class="min-w-0">
                    <dt class="text-xs font-medium uppercase tracking-wide text-brand-mist">{{ __('Repository') }}</dt>
                    <dd class="mt-0.5 break-all font-mono text-xs text-brand-ink">{{ $edgeRepoLabel }}</dd>
                </div>
                <div class="min-w-0">
                    <dt class="text-xs font-medium uppercase tracking-wide text-brand-mist">{{ __('Build command') }}</dt>
                    <dd class="mt-0.5 break-all font-mono text-xs text-brand-ink">{{ $edgeBuildCommand }}</dd>
                </div>
                <div class="min-w-0">
                    <dt class="text-xs font-medium uppercase tracking-wide text-brand-mist">{{ __('Output directory') }}</dt>
                    <dd class="mt-0.5 font-mono text-xs text-brand-ink">{{ $edgeOutputDir }}</dd>
                </div>
                <div class="min-w-0">
                    <dt class="text-xs font-medium uppercase tracking-wide text-brand-mist">{{ __('Current step') }}</dt>
                    <dd class="mt-0.5 font-medium text-brand-ink">{{ $edgeCurrentLabel }}</dd>
                </div>
                @if ($edgeLatestDeployment)
                    <div class="min-w-0">
                        <dt class="text-xs font-medium uppercase tracking-wide text-brand-mist">{{ __('Deployment') }}</dt>
                        <dd class="mt-0.5 font-mono text-xs capitalize text-brand-ink">{{ $edgeLatestDeployment->status }}</dd>
                    </div>
                @endif
            </dl>
            {{-- Only surface the URL once the site is actually serving on
                 it. Showing it during build is misleading — operators read
                 the green card as "ready to click" even with the
                 "available once publish completes" hint. --}}
            @if ($edgeLiveUrl && $site->status === \App\Models\Site::STATUS_EDGE_ACTIVE)
                <div
                    x-data="{ copied: false, copy() { navigator.clipboard.writeText(@js($edgeLiveUrl)); this.copied = true; setTimeout(() => { this.copied = false; }, 1500); } }"
                    class="mt-5 rounded-2xl border border-emerald-200 bg-gradient-to-b from-emerald-50 to-white px-4 py-4"
                >
                    <p class="text-xs font-semibold uppercase tracking-[0.24em] text-emerald-700">{{ __('Live URL') }}</p>
                    <div class="mt-2 flex min-w-0 items-center gap-1.5 font-mono text-xs text-emerald-950">
                        <a
                            href="{{ $edgeLiveUrl }}"
                            target="_blank"
                            rel="noopener noreferrer"
                            title="{{ $edgeLiveUrl }}"
                            class="flex min-w-0 flex-1 items-center gap-1.5 hover:text-emerald-700"
                        >
                            <span class="block min-w-0 flex-1 truncate">{{ preg_replace('#^https?://#', '', $edgeLiveUrl) }}</span>
                            <x-heroicon-o-arrow-top-right-on-square class="h-3.5 w-3.5 shrink-0 opacity-70" />
                        </a>
                        <button
                            type="button"
                            x-on:click.stop="copy()"
                            :title="copied ? '{{ __('Copied') }}' : '{{ __('Copy URL') }}'"
                            class="shrink-0 text-emerald-950/70 hover:text-emerald-700"
                        >
                            <x-heroicon-o-clipboard x-show="!copied" class="h-4 w-4" />
                            <x-heroicon-s-check x-show="copied" x-cloak class="h-3.5 w-3.5 text-emerald-600" />
                        </button>
                    </div>
                    <p class="mt-2 text-xs leading-5 text-emerald-800/80">{{ __('Available once publish completes.') }}</p>
                </div>
            @endif
            </div>
        </section>

        @can('delete', $site)
            <section class="dply-card overflow-hidden p-5 sm:p-6">
                <p class="text-xs leading-relaxed text-brand-moss">
                    {{ __('If the build is stuck or you want to abandon it, cancel to remove partial deployments and delete the Edge site.') }}
                </p>
            </section>
        @endcan
    </aside>
</div>
