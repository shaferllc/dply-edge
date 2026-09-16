@php
    $depBadge = match ($deployment->status) {
        \App\Models\EdgeDeployment::STATUS_LIVE => 'bg-emerald-100 text-emerald-800 dark:bg-raw-emerald-950/40 dark:text-emerald-300',
        \App\Models\EdgeDeployment::STATUS_FAILED => 'bg-rose-100 text-rose-800 dark:bg-rose-950/40 dark:text-rose-300',
        \App\Models\EdgeDeployment::STATUS_BUILDING, \App\Models\EdgeDeployment::STATUS_PUBLISHING => 'bg-sky-100 text-sky-800 dark:bg-sky-950/40 dark:text-sky-300',
        default => 'bg-brand-sand/60 text-brand-moss',
    };
@endphp

<div
    class="max-w-7xl mx-auto px-4 py-8 sm:px-6 lg:px-8"
    @if ($isInProgress) wire:poll.2s @endif
>
    <div class="lg:grid lg:grid-cols-12 lg:gap-10">
        @include('livewire.sites.settings.partials.sidebar')

        <div class="min-w-0 lg:col-span-9">
            <x-breadcrumb-trail :site="$site" :items="[
                ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
                ['label' => __('Projects'), 'href' => route('edge.index'), 'icon' => 'globe-alt'],
                ['label' => $site->name, 'href' => route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'edge-deploys'])],
                ['label' => __('Deployment'), 'icon' => 'code-bracket-square'],
            ]" class="mb-6" />

            <section class="dply-card min-w-0 overflow-hidden p-0">
                <x-workspace-panel-head
                    class="border-b border-brand-ink/10"
                    icon="heroicon-o-rocket-launch"
                    :title="__('Edge deployment')"
                    :note="__('Build log, stable aliases, and deploy-specific detail.')"
                >
                    <x-slot:actions>
                        <span class="max-w-[10rem] truncate font-mono text-2xs text-brand-mist" title="{{ $deployment->id }}">{{ $deployment->id }}</span>
                        <span class="inline-flex rounded-full px-2 py-0.5 text-2xs font-semibold uppercase tracking-wide {{ $depBadge }}">
                            {{ str_replace('_', ' ', (string) $deployment->status) }}
                        </span>
                        @if ($isActiveDeployment)
                            <span class="inline-flex rounded-full bg-brand-sand/70 px-2 py-0.5 text-2xs font-semibold uppercase tracking-wide text-brand-moss dark:bg-brand-sand/20">
                                {{ __('Production') }}
                            </span>
                        @endif
                        @if ($deployment->pruned_at)
                            <span class="inline-flex rounded-full bg-brand-sand/60 px-2 py-0.5 text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Pruned') }}</span>
                        @endif
                        <a
                            href="{{ route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'edge-deploys']) }}"
                            wire:navigate
                            class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white/80 px-2.5 py-1.5 text-xs font-semibold text-brand-ink hover:bg-white dark:border-brand-mist/25 dark:bg-zinc-800"
                        >
                            <x-heroicon-o-arrow-left class="h-3.5 w-3.5 opacity-70" />
                            {{ __('All deploys') }}
                        </a>
                        @if (! $isActiveDeployment && in_array($deployment->status, [\App\Models\EdgeDeployment::STATUS_LIVE, \App\Models\EdgeDeployment::STATUS_SUPERSEDED], true) && $deployment->storage_prefix !== null)
                            @can('update', $site)
                                <button
                                    type="button"
                                    wire:click="confirmRollbackEdgeDeployment('{{ $deployment->id }}')"
                                    class="inline-flex items-center gap-1.5 rounded-lg bg-brand-ink px-2.5 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-brand-ink/90"
                                >
                                    <x-heroicon-o-arrow-uturn-left class="h-3.5 w-3.5" />
                                    {{ __('Roll back') }}
                                </button>
                            @endcan
                        @endif
                    </x-slot:actions>
                </x-workspace-panel-head>

                @if ($deployment->status === \App\Models\EdgeDeployment::STATUS_FAILED)
                    <div class="border-b border-brand-ink/10 px-5 py-4 sm:px-6">
                    </div>
                @endif

                <x-server-workspace-tablist
                    :aria-label="__('Deployment sections')"
                    :scroll="true"
                    class="mb-0 rounded-none border-0 border-b border-brand-ink/10 bg-transparent p-2 shadow-none sm:px-4"
                >
                    @foreach ([
                        'overview' => __('Overview'),
                        'aliases' => __('Aliases'),
                        'log' => __('Build log'),
                    ] as $tabKey => $tabLabel)
                        <x-server-workspace-tab
                            as="a"
                            :href="route('sites.edge.deployments.show', ['server' => $server, 'site' => $site, 'deployment' => $deployment, 'tab' => $tabKey])"
                            wire:navigate
                            :active="$tab === $tabKey"
                        >
                            {{ $tabLabel }}
                        </x-server-workspace-tab>
                    @endforeach
                </x-server-workspace-tablist>

                @if ($tab === 'overview')
                    @if ($deploymentJourney !== null)
                        <div class="border-b border-brand-ink/10">
                            @include('livewire.sites.partials.edge.deployment-journey-card', [
                                'journey' => $deploymentJourney,
                                'deployment' => $deployment,
                            ])
                        </div>
                    @endif

                    {{-- Only fields the deploys list does not already show. --}}
                    <section class="border-b border-brand-ink/10">
                        <div class="px-5 py-3 sm:px-6">
                            <p class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('More detail') }}</p>
                        </div>
                        <dl class="divide-y divide-brand-ink/8 border-t border-brand-ink/8 px-5 text-sm sm:px-6">
                            @if (! empty($commitMeta['subject']))
                                <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 py-3">
                                    <dt class="w-36 shrink-0 text-xs uppercase tracking-wide text-brand-mist">{{ __('Commit message') }}</dt>
                                    <dd class="min-w-0 flex-1 text-brand-ink">{{ $commitMeta['subject'] }}</dd>
                                </div>
                            @endif
                            @if (filled($deployment->git_commit))
                                <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 py-3">
                                    <dt class="w-36 shrink-0 text-xs uppercase tracking-wide text-brand-mist">{{ __('Full SHA') }}</dt>
                                    <dd class="min-w-0 flex-1 font-mono text-xs text-brand-ink break-all">{{ $deployment->git_commit }}</dd>
                                </div>
                            @endif
                            @if (filled($deployment->storage_prefix))
                                <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 py-3">
                                    <dt class="w-36 shrink-0 text-xs uppercase tracking-wide text-brand-mist">{{ __('Storage prefix') }}</dt>
                                    <dd class="min-w-0 flex-1 font-mono text-xs text-brand-ink break-all">{{ $deployment->storage_prefix }}</dd>
                                </div>
                            @endif
                        </dl>
                        <div class="flex flex-wrap gap-x-4 gap-y-1 border-t border-brand-ink/8 px-5 py-3 text-sm sm:px-6">
                            <a
                                href="{{ route('sites.edge.deployments.show', ['server' => $server, 'site' => $site, 'deployment' => $deployment, 'tab' => 'aliases']) }}"
                                wire:navigate
                                class="font-medium text-brand-sage hover:underline"
                            >
                                {{ __('Aliases') }}
                                @if ($deploymentAliases !== [])
                                    <span class="text-brand-mist">({{ count($deploymentAliases) }})</span>
                                @endif
                            </a>
                            <a
                                href="{{ route('sites.edge.deployments.show', ['server' => $server, 'site' => $site, 'deployment' => $deployment, 'tab' => 'log']) }}"
                                wire:navigate
                                class="font-medium text-brand-sage hover:underline"
                            >
                                {{ __('Build log') }}
                            </a>
                        </div>
                    </section>

                    @if ($deployment->status === \App\Models\EdgeDeployment::STATUS_FAILED)
                        <div class="px-5 py-4 sm:px-6">
                            @include('livewire.sites.partials.edge.build-log-lint-callout', [
                                'buildLog' => $buildLogForLint,
                                'failureReason' => $deployment->failure_reason,
                                'site' => $site,
                                'server' => $server,
                                'deployment' => $deployment,
                            ])
                        </div>
                    @endif
                @elseif ($tab === 'aliases')
                    <section>
                        <div class="border-b border-brand-ink/10 px-5 py-3 sm:px-6">
                            <p class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Stable per-deploy aliases') }}</p>
                            <p class="mt-0.5 text-xs text-brand-moss">{{ __('These hostnames always route to this deployment — even after production moves on.') }}</p>
                        </div>
                        @if ($deploymentAliases === [])
                            <p class="px-5 py-10 text-center text-sm text-brand-moss sm:px-6">
                                {{ __('No aliases yet. Aliases are generated when a deployment publishes successfully.') }}
                            </p>
                        @else
                            <ul class="divide-y divide-brand-ink/8">
                                @foreach ($deploymentAliases as $alias)
                                    <li class="px-5 py-4 sm:px-6" wire:key="edge-alias-{{ $alias }}">
                                        <div class="flex flex-wrap items-center justify-between gap-3">
                                            <div class="min-w-0">
                                                <p class="font-mono text-sm text-brand-ink break-all">{{ $alias }}</p>
                                                <a href="https://{{ $alias }}" target="_blank" rel="noopener noreferrer" class="mt-1 inline-flex items-center gap-1 text-xs font-medium text-brand-forest hover:underline dark:text-brand-sage">
                                                    {{ __('Open') }}
                                                    <x-heroicon-o-arrow-top-right-on-square class="h-3 w-3" />
                                                </a>
                                            </div>
                                            <div class="flex shrink-0 items-center gap-2" x-data="{ copied: false }">
                                                <button
                                                    type="button"
                                                    class="inline-flex items-center gap-1 rounded-lg border border-brand-ink/10 bg-white px-2.5 py-1.5 text-xs font-medium text-brand-moss hover:bg-brand-sand/40"
                                                    @click="navigator.clipboard.writeText(@js('https://'.$alias)); copied = true; setTimeout(() => copied = false, 2000)"
                                                >
                                                    <x-heroicon-o-clipboard class="h-4 w-4" />
                                                    <span x-show="!copied">{{ __('Copy URL') }}</span>
                                                    <span x-show="copied" x-cloak>{{ __('Copied') }}</span>
                                                </button>
                                                <button
                                                    type="button"
                                                    class="inline-flex items-center gap-1 rounded-lg border border-brand-ink/10 bg-white px-2.5 py-1.5 text-xs font-medium text-brand-moss hover:bg-brand-sand/40"
                                                    @click="navigator.clipboard.writeText(@js($alias)); copied = true; setTimeout(() => copied = false, 2000)"
                                                >
                                                    <span x-show="!copied">{{ __('Copy host') }}</span>
                                                    <span x-show="copied" x-cloak>{{ __('Copied') }}</span>
                                                </button>
                                            </div>
                                        </div>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </section>
                @elseif ($tab === 'log')
                    @if ($deployment->status === \App\Models\EdgeDeployment::STATUS_FAILED && filled($deployment->failure_reason))
                        <div class="border-b border-brand-ink/10 px-5 py-4 sm:px-6">
                            @include('livewire.sites.partials.edge.build-log-lint-callout', [
                                'buildLog' => $buildLog,
                                'failureReason' => $deployment->failure_reason,
                                'site' => $site,
                                'server' => $server,
                                'deployment' => $deployment,
                            ])
                            @if (! str_contains((string) $deployment->failure_reason, 'dply config lint failed'))
                                <div class="mt-3 rounded-lg border border-rose-200/60 bg-rose-50/50 px-3 py-2.5 dark:border-raw-rose-900/30 dark:bg-rose-950/20">
                                    <p class="text-2xs font-semibold uppercase tracking-[0.14em] text-rose-700 dark:text-rose-300">{{ __('Failure') }}</p>
                                    <p class="mt-1 break-words font-mono text-xs leading-relaxed text-rose-900 dark:text-raw-rose-200">{{ $deployment->failure_reason }}</p>
                                </div>
                            @endif
                        </div>
                    @endif

                    @if ($isInProgress)
                        <div class="border-b border-brand-ink/10">
                            @livewire('edge.build-journey', ['deploymentId' => $deployment->id], key('edge-detail-log-tab-journey-'.$deployment->id))
                        </div>
                    @endif

                    <section class="border-b border-brand-ink/10">
                        <div class="flex flex-wrap items-center justify-between gap-2 px-5 py-3 sm:px-6">
                            <p class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">
                                {{ $isInProgress ? __('Archived build log') : __('Build log') }}
                            </p>
                            @if (! $isInProgress && $buildLog !== null && $buildLog !== '')
                                <span class="text-2xs uppercase tracking-wide text-brand-mist">{{ number_format(strlen($buildLog)) }} bytes</span>
                            @endif
                        </div>
                        @if ($buildLog === null || $buildLog === '')
                            <p class="px-5 pb-8 text-center text-sm text-brand-moss sm:px-6">
                                {{ $isInProgress
                                    ? __('The archived log appears here once publish finishes — for now, follow the live stream above.')
                                    : __('No build log stored for this deployment. Redeploy to capture a fresh log — failed builds now persist output automatically.') }}
                            </p>
                        @else
                            <pre class="max-h-[32rem] overflow-auto border-t border-brand-ink/8 bg-brand-ink px-5 py-4 font-mono text-xs leading-relaxed text-brand-cream sm:px-6">{{ $buildLog }}</pre>
                        @endif
                    </section>
                @endif
            </section>
        </div>
    </div>

    @include('livewire.partials.confirm-action-modal')
</div>
