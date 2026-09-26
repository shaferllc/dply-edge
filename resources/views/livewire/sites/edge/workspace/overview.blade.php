{{-- Overview: status, live URL, key settings at a glance, recent deploys, then traffic and usage (lazy). --}}
@php
    $recentDeployments = $edgeDeployments->take(5);
@endphp

<div @if ($isInProgress ?? false) wire:poll.2s @endif>
    @if (! empty($edgeDeliveryBanner))
        <div class="border-b border-brand-ink/10 px-5 py-3 sm:px-6">
            @include('livewire.sites.partials.edge.delivery-banner')
        </div>
    @endif

    @include('livewire.sites.partials.edge.hero')

    @if (($deploymentJourney ?? null) !== null && ($inProgressDeployment ?? null) !== null)
        <div class="border-b border-brand-ink/10">
            @include('livewire.sites.partials.edge.deployment-journey-card', [
                'journey' => $deploymentJourney,
                'deployment' => $inProgressDeployment,
            ])
        </div>
    @endif

    @php
        $meta = $site->edgeMeta();
        $runtime = (string) ($meta['runtime_mode'] ?? 'static');
        $framework = (string) ($meta['build']['framework'] ?? '');
        $source = is_array($meta['source'] ?? null) ? $meta['source'] : [];
        $dbEngine = (string) ($meta['database']['engine'] ?? '');
        $link = fn (string $section) => route('sites.show', ['server' => $server, 'site' => $site, 'section' => $section]);
        $facts = [
            [__('Runtime'), ['container' => __('Container'), 'ssr' => __('Server-rendered'), 'hybrid' => __('Hybrid')][$runtime] ?? __('Static'), $link('build')],
            [__('Framework'), $framework !== '' ? ucfirst($framework) : __('Auto-detected'), $link('build')],
            [__('Branch'), ($source['branch'] ?? 'main').(($source['deploy_on_push'] ?? false) ? ' · '.__('deploys on push') : ''), $link('deploy-triggers')],
            [__('Database'), ['postgres' => 'Postgres', 'mysql' => 'MySQL', 'mongodb' => 'MongoDB', 'sql' => 'SQLite'][$dbEngine] ?? __('None'), $link('resources')],
        ];
        $statusStyles = [
            'live' => ['bg-emerald-500/10 text-emerald-700 ring-emerald-500/30 dark:text-emerald-300', 'bg-emerald-500'],
            'building' => ['bg-sky-500/10 text-sky-700 ring-sky-500/30 dark:text-sky-300', 'bg-sky-500 animate-pulse'],
            'publishing' => ['bg-sky-500/10 text-sky-700 ring-sky-500/30 dark:text-sky-300', 'bg-sky-500 animate-pulse'],
            'failed' => ['bg-red-500/10 text-red-700 ring-red-500/30 dark:text-red-300', 'bg-red-500'],
        ];
        $activeId = $meta['active_deployment_id'] ?? null;
    @endphp

    <dl class="grid grid-cols-2 border-b border-brand-ink/10 lg:grid-cols-4">
        @foreach ($facts as [$label, $value, $href])
            <a href="{{ $href }}" wire:navigate class="group border-brand-ink/10 px-5 py-4 hover:bg-brand-sand/20 sm:px-6 [&:not(:last-child)]:border-e max-lg:[&:nth-child(2)]:border-e-0 max-lg:[&:nth-child(-n+2)]:border-b">
                <dt class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ $label }}</dt>
                <dd class="mt-1 truncate text-sm font-semibold text-brand-ink group-hover:underline">{{ $value }}</dd>
            </a>
        @endforeach
    </dl>

    @if ($recentDeployments->isNotEmpty())
        <section class="border-b border-brand-ink/10">
            <div class="flex items-center justify-between gap-3 border-b border-brand-ink/10 px-5 py-3 sm:px-6">
                <p class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Recent deploys') }}</p>
                <a href="{{ $link('deploys') }}" wire:navigate class="text-xs font-semibold text-brand-sage hover:underline">
                    {{ __('View all') }}
                </a>
            </div>
            <ul class="divide-y divide-brand-ink/10">
                @foreach ($recentDeployments as $deployment)
                    @php
                        $sha = $deployment->git_commit ? substr((string) $deployment->git_commit, 0, 7) : null;
                        $subject = (string) ($deployment->meta['commit']['subject'] ?? '');
                        $author = (string) ($deployment->meta['commit']['author'] ?? '');
                        $when = $deployment->published_at ?? $deployment->failed_at ?? $deployment->created_at;
                        $seconds = (int) $deployment->build_seconds;
                        [$pill, $dot] = $statusStyles[$deployment->status] ?? ['bg-brand-sand/50 text-brand-moss ring-brand-ink/10', 'bg-brand-mist'];
                    @endphp
                    <li>
                        <a
                            href="{{ route('sites.edge.deployments.show', ['server' => $server, 'site' => $site, 'deployment' => $deployment]) }}"
                            wire:navigate
                            class="flex items-start gap-4 px-5 py-3.5 hover:bg-brand-sand/20 sm:px-6"
                        >
                            <span class="mt-0.5 inline-flex w-24 shrink-0 items-center gap-1.5 rounded-full px-2 py-0.5 text-2xs font-semibold uppercase tracking-wide ring-1 {{ $pill }}">
                                <span class="h-1.5 w-1.5 rounded-full {{ $dot }}"></span>
                                {{ str_replace('_', ' ', (string) $deployment->status) }}
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm font-semibold text-brand-ink">
                                    {{ $subject !== '' ? $subject : ($deployment->status === 'failed' ? __('Deploy failed') : __('Deploy')) }}
                                </span>
                                <span class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs text-brand-moss">
                                    @if ($sha)<span class="font-mono text-brand-ink">{{ $sha }}</span>@endif
                                    @if (filled($deployment->git_branch))<span>{{ $deployment->git_branch }}</span>@endif
                                    @if ($author !== '')<span>{{ __('by :name', ['name' => $author]) }}</span>@endif
                                    @if ($seconds > 0)<span>{{ __('built in :time', ['time' => $seconds >= 60 ? intdiv($seconds, 60).'m '.($seconds % 60).'s' : $seconds.'s']) }}</span>@endif
                                    @if ($deployment->id === $activeId)<span class="font-semibold text-emerald-700 dark:text-emerald-300">{{ __('Serving now') }}</span>@endif
                                </span>
                                @if ($deployment->status === 'failed' && filled($deployment->failure_reason))
                                    <span class="mt-1 block truncate text-xs text-red-700 dark:text-red-300">{{ $deployment->failure_reason }}</span>
                                @endif
                            </span>
                            <time class="shrink-0 text-xs text-brand-mist" datetime="{{ $when?->toIso8601String() }}" title="{{ $when?->toDayDateTimeString() }}">{{ $when?->diffForHumans() }}</time>
                        </a>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    @livewire('sites.edge.workspace.overview-observability', ['server' => $server, 'site' => $site], key('edge-overview-observability-'.$site->id))
</div>
