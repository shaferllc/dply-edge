<div class="contents">
    <x-workspace-nav />

    <x-edge-index-page
        :rows="$rows"
        :totals="$totals"
        :has-sites-in-scope="$hasSitesInScope"
        :edge-enabled="$edgeEnabled"
        :filter="$filter"
        :show-filters="true"
        :show-create-action="true"
        :show-secondary-actions="true"
        empty-state="local"
        :breadcrumbs="[
            ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
            ['label' => __('Apps'), 'icon' => 'globe-alt'],
        ]"
    >
        <x-slot:modals>
            <x-modal
                name="edge-index-delete-site-confirmation"
                :show="false"
                maxWidth="lg"
                overlayClass="bg-brand-ink/30"
                panelClass="dply-modal-panel"
                focusable
            >
                @php
                    $deleteModes = [
                        'now' => ['label' => __('Delete now'), 'help' => __('Immediate teardown')],
                        'in_30' => ['label' => __('In 30 minutes'), 'help' => __('30-minute grace window')],
                        'scheduled' => ['label' => __('Schedule date/time'), 'help' => __('Pick a future date')],
                    ];
                @endphp
                <div class="border-b border-brand-ink/10 px-6 py-5">
                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Danger zone') }}</p>
                    <h2 class="mt-2 text-xl font-semibold text-brand-ink">{{ __('Delete this Edge site?') }}</h2>
                    <p class="mt-2 text-sm leading-6 text-brand-moss">
                        @if ($deleteCandidate)
                            {{ __(':name will be removed from dply. Active deployments and preview deployments stop serving traffic after teardown.', ['name' => $deleteCandidate->name]) }}
                        @else
                            {{ __('This Edge site will be removed from dply. Active deployments and preview deployments stop serving traffic after teardown.') }}
                        @endif
                    </p>
                    <ul class="mt-3 list-disc space-y-1 pl-5 text-xs text-brand-moss">
                        <li>{{ __('Site configuration and edge deployment records are deleted.') }}</li>
                        <li>{{ __('Routing and published assets for this site are torn down.') }}</li>
                        <li>{{ __('This action cannot be undone.') }}</li>
                    </ul>
                </div>
                <div class="space-y-5 px-6 py-5">
                    <fieldset class="space-y-2">
                        <legend class="text-xs font-semibold uppercase tracking-[0.16em] text-zinc-500">{{ __('When to delete') }}</legend>
                        <div class="grid gap-2 sm:grid-cols-3">
                            @foreach ($deleteModes as $mode => $meta)
                                <label class="cursor-pointer">
                                    <input type="radio" wire:model.live="deleteMode" value="{{ $mode }}" class="peer sr-only" />
                                    <div class="rounded-xl border-2 border-zinc-200 bg-white px-3 py-2.5 text-sm transition peer-checked:border-red-500 peer-checked:bg-red-50 peer-focus-visible:ring-2 peer-focus-visible:ring-red-500/40 hover:border-zinc-300">
                                        <p class="font-semibold text-brand-ink">{{ $meta['label'] }}</p>
                                        <p class="mt-0.5 text-xs text-brand-moss">{{ $meta['help'] }}</p>
                                    </div>
                                </label>
                            @endforeach
                        </div>
                    </fieldset>

                    @if ($deleteMode === 'scheduled')
                        <div class="space-y-2">
                            <label for="edge-scheduled-delete-at" class="text-xs font-semibold uppercase tracking-[0.16em] text-zinc-500">{{ __('Deletion date/time') }}</label>
                            <input
                                id="edge-scheduled-delete-at"
                                type="datetime-local"
                                wire:model.live="scheduledDeleteAt"
                                min="{{ now()->addMinute()->format('Y-m-d\TH:i') }}"
                                class="block w-full rounded-xl border-zinc-200 bg-white shadow-sm focus:border-red-500 focus:ring-red-500"
                            />
                            <p class="text-xs text-brand-mist">{{ __('Uses your app timezone and must be in the future.') }}</p>
                            @error('scheduledDeleteAt')
                                <p class="text-xs text-red-700">{{ $message }}</p>
                            @enderror
                        </div>
                    @elseif ($deleteMode === 'in_30')
                        <div class="rounded-xl border border-amber-200 bg-amber-50/60 px-4 py-3 text-sm text-amber-900">
                            {{ __('The site will be deleted in 30 minutes.') }}
                        </div>
                    @endif
                </div>
                <div class="flex flex-wrap justify-end gap-3 border-t border-brand-ink/10 px-6 py-4">
                    <x-secondary-button type="button" wire:click="closeDeleteSiteModal">
                        {{ __('Cancel') }}
                    </x-secondary-button>
                    <x-danger-button type="button" wire:click="deleteSite" wire:loading.attr="disabled" wire:target="deleteSite">
                        <span wire:loading.remove wire:target="deleteSite">
                            @if ($deleteMode === 'scheduled')
                                {{ __('Schedule deletion') }}
                            @elseif ($deleteMode === 'in_30')
                                {{ __('Delete in 30 minutes') }}
                            @else
                                {{ __('Delete Edge site') }}
                            @endif
                        </span>
                        <span wire:loading wire:target="deleteSite">{{ __('Deleting…') }}</span>
                    </x-danger-button>
                </div>
            </x-modal>

            {{-- Quick-look modal — peek at the live BuildJourney for any site in the
                 list without bouncing into its workspace. Polls itself via the
                 nested BuildJourney component (1s log tail), and the link in the
                 footer takes you to the full workspace if you want to go deeper. --}}
            <x-modal name="quick-look-edge-site" :show="false" maxWidth="3xl" overlayClass="bg-brand-ink/30" panelClass="dply-modal-panel" focusable>
                <div class="flex items-start justify-between gap-3 border-b border-brand-ink/10 px-6 py-4">
                    <div class="min-w-0">
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Quick look') }}</p>
                        <h2 class="mt-1 truncate text-base font-semibold text-brand-ink">
                            {{ $quickLookSite?->name ?? __('App') }}
                        </h2>
                        @if ($quickLookSite && $quickLookSite->edgeLiveUrl())
                            <a
                                href="{{ $quickLookSite->edgeLiveUrl() }}"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="mt-0.5 inline-flex items-center gap-1 font-mono text-xs text-brand-moss hover:text-brand-ink"
                            >
                                {{ preg_replace('#^https?://#', '', $quickLookSite->edgeLiveUrl()) }}
                                <x-heroicon-o-arrow-top-right-on-square class="h-3 w-3 opacity-70" />
                            </a>
                        @endif
                    </div>
                    <button type="button" wire:click="closeQuickLookModal" class="rounded-md p-1 text-brand-mist hover:bg-brand-sand/40 hover:text-brand-ink" title="{{ __('Close') }}">
                        <x-heroicon-o-x-mark class="h-5 w-5" />
                    </button>
                </div>
                <div class="px-6 py-5">
                    @if ($quickLookSite === null)
                        <div class="rounded-xl border border-dashed border-brand-ink/15 bg-brand-sand/15 px-5 py-8 text-center text-sm text-brand-moss">
                            {{ __('Site not found.') }}
                        </div>
                    @elseif ($quickLookDeploymentId === null)
                        <div class="rounded-xl border border-dashed border-brand-ink/15 bg-brand-sand/15 px-5 py-8 text-center text-sm text-brand-moss">
                            {{ __('No deployments yet for this site.') }}
                        </div>
                    @elseif ($quickLookStats !== null)
                        @php
                            $latest = $quickLookStats['latest'];
                            $latestStatusTone = match ($latest?->status ?? null) {
                                \App\Models\EdgeDeployment::STATUS_LIVE => 'bg-emerald-100 text-emerald-800',
                                \App\Models\EdgeDeployment::STATUS_FAILED => 'bg-rose-100 text-rose-800',
                                \App\Models\EdgeDeployment::STATUS_SUPERSEDED => 'bg-brand-sand/60 text-brand-moss',
                                default => 'bg-sky-100 text-sky-800',
                            };
                        @endphp
                        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                            <div class="rounded-xl border border-brand-ink/10 bg-white/60 px-4 py-3">
                                <p class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Status') }}</p>
                                <p class="mt-1 text-sm font-semibold capitalize text-brand-ink">{{ str_replace('_', ' ', (string) $quickLookSite->status) }}</p>
                            </div>
                            <div class="rounded-xl border border-brand-ink/10 bg-white/60 px-4 py-3">
                                <p class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Total deploys') }}</p>
                                <p class="mt-1 text-sm font-semibold tabular-nums text-brand-ink">{{ number_format($quickLookStats['total_deploys']) }}</p>
                            </div>
                            <div class="rounded-xl border border-brand-ink/10 bg-white/60 px-4 py-3">
                                <p class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Live') }}</p>
                                <p class="mt-1 text-sm font-semibold tabular-nums text-emerald-700">{{ number_format($quickLookStats['live_deploys']) }}</p>
                            </div>
                            <div class="rounded-xl border border-brand-ink/10 bg-white/60 px-4 py-3">
                                <p class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Failed') }}</p>
                                <p class="mt-1 text-sm font-semibold tabular-nums {{ $quickLookStats['failed_deploys'] > 0 ? 'text-rose-700' : 'text-brand-mist' }}">{{ number_format($quickLookStats['failed_deploys']) }}</p>
                            </div>
                        </div>

                        @if ($latest !== null)
                            <section class="dply-card mt-4 overflow-hidden">
                                <div class="flex items-baseline justify-between gap-3 border-b border-brand-ink/10 px-5 py-3">
                                    <h3 class="text-sm font-semibold text-brand-ink">{{ __('Latest deployment') }}</h3>
                                    <span class="rounded-full px-2 py-0.5 text-2xs font-semibold uppercase tracking-wide {{ $latestStatusTone }}">
                                        {{ str_replace('_', ' ', (string) $latest->status) }}
                                    </span>
                                </div>
                                <dl class="grid grid-cols-1 gap-3 px-5 py-3 text-sm sm:grid-cols-2">
                                    <div>
                                        <dt class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Commit') }}</dt>
                                        <dd class="mt-0.5 break-all font-mono text-xs text-brand-ink">{{ $latest->git_commit ? substr($latest->git_commit, 0, 12) : '—' }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Branch') }}</dt>
                                        <dd class="mt-0.5 font-mono text-xs text-brand-ink">{{ $latest->git_branch ?? '—' }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Published') }}</dt>
                                        <dd class="mt-0.5 text-xs text-brand-ink">{{ $latest->published_at ? $latest->published_at->diffForHumans() : '—' }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Created') }}</dt>
                                        <dd class="mt-0.5 text-xs text-brand-ink">{{ $latest->created_at?->diffForHumans() }}</dd>
                                    </div>
                                </dl>
                                @if ($latest->status === \App\Models\EdgeDeployment::STATUS_FAILED && $latest->failure_reason)
                                    <div class="border-t border-brand-ink/10 bg-rose-50/60 px-5 py-3">
                                        <p class="text-2xs font-semibold uppercase tracking-wide text-rose-700">{{ __('Failure') }}</p>
                                        <p class="mt-1 break-words font-mono text-xs leading-5 text-rose-900">{{ $latest->failure_reason }}</p>
                                    </div>
                                @endif
                            </section>
                        @endif
                    @else
                        @livewire('edge.build-journey', ['deploymentId' => $quickLookDeploymentId], key('quick-look-journey-'.$quickLookDeploymentId))
                    @endif
                </div>
                <div class="flex flex-wrap items-center justify-between gap-2 border-t border-brand-ink/10 bg-brand-sand/15 px-6 py-3">
                    <span class="text-xs text-brand-mist">{{ __('Live — updates every second while the build is running.') }}</span>
                    @if ($quickLookSite)
                        <a
                            href="{{ route('sites.show', ['server' => $quickLookSite->server, 'site' => $quickLookSite]) }}"
                            wire:navigate
                            class="text-xs font-semibold text-brand-forest hover:underline dark:text-brand-sage"
                        >
                            {{ __('Open workspace →') }}
                        </a>
                    @endif
                </div>
            </x-modal>
        </x-slot:modals>
    </x-edge-index-page>
</div>
