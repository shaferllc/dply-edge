@include('livewire.sites.partials.edge.delivery-banner')


@include('livewire.sites.partials.edge.hero')

<div class="grid gap-6 lg:grid-cols-2">
    <section class="dply-card overflow-hidden">
        <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
            <x-icon-badge>
                <x-heroicon-o-paper-airplane class="h-5 w-5" aria-hidden="true" />
            </x-icon-badge>
            <div class="min-w-0">
                <p class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Delivery') }}</p>
                <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Delivery') }}</h3>
                <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Where this site is published and how traffic is routed.') }}</p>
            </div>
        </div>
        <dl class="divide-y divide-brand-ink/8 px-6 py-2 text-sm sm:px-8">
            <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 py-3">
                <dt class="w-28 shrink-0 text-xs uppercase tracking-wide text-brand-mist">{{ __('Backend') }}</dt>
                <dd class="min-w-0 flex-1 text-brand-ink">{{ $edgeDeliveryBackendLabel }}</dd>
            </div>
            <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 py-3">
                <dt class="w-28 shrink-0 text-xs uppercase tracking-wide text-brand-mist">{{ __('Hostname') }}</dt>
                <dd class="min-w-0 flex-1 font-mono text-xs text-brand-ink break-all">{{ $edgeDeliveryHostname }}</dd>
            </div>
            @if ($edgeWorkerScriptName !== '')
                <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 py-3">
                    <dt class="w-28 shrink-0 text-xs uppercase tracking-wide text-brand-mist">{{ __('Worker') }}</dt>
                    <dd class="min-w-0 flex-1 font-mono text-xs text-brand-ink">{{ $edgeWorkerScriptName }}</dd>
                </div>
            @endif
            @if ($edgeWorkerZoneName !== '')
                <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 py-3">
                    <dt class="w-28 shrink-0 text-xs uppercase tracking-wide text-brand-mist">{{ __('Zone') }}</dt>
                    <dd class="min-w-0 flex-1 font-mono text-xs text-brand-ink">{{ $edgeWorkerZoneName }}</dd>
                </div>
            @endif
            @if ($edgeWorkerRoutes !== [])
                <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 py-3">
                    <dt class="w-28 shrink-0 text-xs uppercase tracking-wide text-brand-mist">{{ __('Routes') }}</dt>
                    <dd class="min-w-0 flex-1 font-mono text-xs text-brand-ink break-all">{{ implode(', ', $edgeWorkerRoutes) }}</dd>
                </div>
            @endif
        </dl>
        @if ($edgeUsesManagedBackend && ! $edgeFakeMode)
            <div class="border-t border-brand-ink/10 bg-brand-sand/15 px-6 py-3 text-xs text-brand-moss sm:px-8">
                {{ __('Operator checklist: worker deployed (`dply:edge:worker-deploy`), routes match your testing domain, DNS proxied through Dply Edge, and `dply:edge:doctor --probe` passes.') }}
            </div>
        @endif
    </section>

    <section class="dply-card overflow-hidden">
        <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
            <x-icon-badge>
                <x-heroicon-o-folder class="h-5 w-5" aria-hidden="true" />
            </x-icon-badge>
            <div class="min-w-0">
                <p class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Source') }}</p>
                <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Source') }}</h3>
                <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Git repository connected to this Edge site.') }}</p>
            </div>
        </div>
        <dl class="divide-y divide-brand-ink/8 px-6 py-2 text-sm sm:px-8">
            <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 py-3">
                <dt class="w-28 shrink-0 text-xs uppercase tracking-wide text-brand-mist">{{ __('Repository') }}</dt>
                <dd class="min-w-0 flex-1 font-mono text-xs text-brand-ink break-all">
                    @if ($edgeGithubRepoUrl)
                        <a href="{{ $edgeGithubRepoUrl }}" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1 text-brand-forest hover:underline dark:text-brand-sage">
                            {{ $edgeRepo }}
                            <x-heroicon-o-arrow-top-right-on-square class="h-3.5 w-3.5 shrink-0 opacity-70" />
                        </a>
                    @else
                        {{ $edgeRepo ?: '—' }}
                    @endif
                </dd>
            </div>
            <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 py-3">
                <dt class="w-28 shrink-0 text-xs uppercase tracking-wide text-brand-mist">{{ __('Branch') }}</dt>
                <dd class="min-w-0 flex-1 font-mono text-xs text-brand-ink">{{ $edgeBranch }}</dd>
            </div>
            <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 py-3">
                <dt class="w-28 shrink-0 text-xs uppercase tracking-wide text-brand-mist">{{ __('Framework') }}</dt>
                <dd class="min-w-0 flex-1 text-brand-ink">{{ __('Static / SSG') }}</dd>
            </div>
        </dl>
        @if (! $edgeIsPreviewChild)
            <div class="border-t border-brand-ink/10 bg-brand-sand/15 px-6 py-3 sm:px-8">
                <a href="{{ route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'build']) }}" wire:navigate class="text-xs font-medium text-brand-forest hover:underline dark:text-brand-sage">
                    {{ __('View build settings & webhook →') }}
                </a>
            </div>
        @endif
    </section>

    <section class="dply-card overflow-hidden">
        <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
            <x-icon-badge>
                <x-heroicon-o-globe-alt class="h-5 w-5" aria-hidden="true" />
            </x-icon-badge>
            <div class="min-w-0">
                <p class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Domains') }}</p>
                <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Custom domains') }}</h3>
                <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Hostnames routed to this Edge site.') }}</p>
            </div>
            <a href="{{ route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'routing', 'tab' => 'domains']) }}" wire:navigate class="shrink-0 text-xs font-medium text-brand-sage hover:underline">
                {{ __('Manage') }}
            </a>
        </div>
        <div class="px-6 py-4 sm:px-8">
            @if ($edgeAttachedDomains !== [])
                <ul class="space-y-2">
                    @foreach (array_keys($edgeAttachedDomains) as $hostname)
                        <li class="flex items-center gap-2 font-mono text-xs text-brand-ink">
                            <x-heroicon-o-link class="h-4 w-4 text-brand-mist" />
                            {{ $hostname }}
                        </li>
                    @endforeach
                </ul>
            @else
                <x-empty-state
                    borderless
                    compact
                    icon="heroicon-o-squares-2x2"
                    :title="__('No custom domains attached — your Edge hostname is used by default')"
                />
            @endif
        </div>
    </section>
</div>

@livewire('sites.edge.workspace.overview-observability', ['server' => $server, 'site' => $site], key('edge-overview-observability-'.$site->id))

@if ($edgeDeployments->isNotEmpty())
    @include('livewire.sites.partials.edge.deploys-table', ['compact' => true])
@endif
