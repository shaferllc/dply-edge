<div>
    {{-- ?sheet=database|app (links in notifications, e.g. a suggested resize): open that sheet on load. --}}
    @php $linkedSheet = ['database' => 'resources-database', 'app' => 'resources-app'][(string) request()->query('sheet')] ?? null; @endphp
    @if ($linkedSheet)
        <span class="hidden" x-data x-init="$nextTick(() => { $wire.$island(@js($linkedSheet)).$refresh(); $dispatch('open-modal', @js($linkedSheet)) })"></span>
    @elseif (preg_match('/^db-([0-9A-Za-z]{26})$/', (string) request()->query('sheet'), $linkedDatabase) === 1)
        {{-- ?sheet=db-{id}: one of the app's other databases (a resize suggestion for it). --}}
        <span class="hidden" x-data x-init="$nextTick(() => $wire.$island('resources-database-extra').openExtraDatabase(@js($linkedDatabase[1])).then(() => $dispatch('open-modal', 'resources-database-extra')))"></span>
    @endif
    {{-- Islands: an action inside a sheet re-renders that sheet's island and
         the map (Resources::renderIsland), not the whole page. A sheet body is
         teleported out of its island, so app.js routes its actions back.
         always: a plain request still renders everything. with: an island
         render skips render(), so it takes the page's data from here.
         skip: a sheet's body is not in the first render; its placeholder is
         the empty sheet, which loads the island when it first opens. Sheets
         opened from inside another (cache, sleep, logs…) share its island. --}}
    @island(name: 'map', always: true, with: $this->viewData())
    @php
        // The chain follows the jobs: workers hang under the store their queue
        // lives in. Redis: that Valkey/Redis box. Database: the database box.
        // Neither yet: under the app.
        $queueStore = $isContainer && ($workers['enabled'] ?? false) ? \App\Modules\Edge\Support\EdgeQueueWorkers::connection($site) : null;
        $workersUnderDatabase = $queueStore === 'database' && $databaseVisible;
        $queueRedisHost = $queueStore === 'redis' ? (collect($connections)->firstWhere('kind', 'redis')['host'] ?? null) : null;
        $orderedConnections = collect($connections)->sortBy(fn ($c) => $c['host'] === $queueRedisHost ? 0 : 1)->values()->all();
        // The scheduler alone lives in the Scheduled tasks box (partials/crons-node), not a box of its own.
        $workersNode = $isContainer && ($workers['enabled'] ?? false);

        $node = 'group block w-full rounded-2xl border border-brand-ink/15 bg-white p-3.5 text-left transition hover:-translate-y-px hover:border-brand-ink/40 dark:border-brand-mist/20 dark:bg-zinc-900 dark:hover:border-brand-mist/50';
        $eyebrow = 'text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist';
        $more = '<span class="mt-2 block text-2xs font-semibold text-brand-sage opacity-0 transition group-hover:opacity-100 group-focus-visible:opacity-100">'.e(__('Settings')).' ›</span>';
        $pill = fn (string $text, string $tone) => '<span class="inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-2xs font-semibold '.match ($tone) {
            'ok' => 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300',
            'sleep' => 'bg-violet-500/10 text-violet-700 dark:text-violet-300',
            'warn' => 'bg-amber-500/10 text-amber-700 dark:text-amber-300',
            'busy' => 'bg-brand-sage/15 text-brand-forest dark:text-brand-sage',
            default => 'bg-brand-sand/40 text-brand-moss',
        }.'"><span class="h-1.5 w-1.5 rounded-full bg-current'.(in_array($tone, ['ok', 'busy'], true) ? ' animate-pulse' : '').'"></span>'.e($text).'</span>';
        // The resource cards' "zzz" (app.css .resource-snore), for anything sleeping on the map.
        $snore = '<span class="resource-snore" aria-hidden="true"><span>z</span><span>z</span><span>z</span></span>';
        // A deploy in flight outranks asleep/running on the App box (the deploy pill has the steps).
        $deploying = $site->edgeDeployments()->whereIn('status', \App\Modules\Edge\Support\EdgeDeployProgress::IN_FLIGHT)->exists();
        $mini = function (array $values, ?float $ceiling = null) {
            $line = \App\Support\Sites\EdgeServiceMap::path($values, 120, 28, ceiling: $ceiling);

            return $line === null ? '' : '<svg viewBox="0 0 120 28" preserveAspectRatio="none" class="mt-2.5 block h-7 w-full" aria-hidden="true"><path d="'.\App\Support\Sites\EdgeServiceMap::path($values, 120, 28, true, $ceiling).'" class="fill-brand-forest/10"></path><path d="'.$line.'" fill="none" class="stroke-brand-forest" stroke-width="1.5" vector-effect="non-scaling-stroke"></path></svg>';
        };
        // A long resource list would push the centred Edge and App boxes far
        // down; then everything aligns to the top and the links meet the first box.
        $tallMap = count($connections) + ($databaseVisible ? 1 : 0) + ($workersNode ? 1 : 0) > 3;
        $hFlow = '<svg viewBox="0 0 40 100" preserveAspectRatio="none" class="hidden '.($tallMap ? 'h-28' : 'h-full min-h-10').' w-full lg:block" aria-hidden="true"><path d="M0 50 H40" class="dply-flow stroke-brand-forest"></path></svg>';
        $vFlow = '<svg viewBox="0 0 20 28" class="mx-auto block h-7 w-5 lg:hidden" aria-hidden="true"><path d="M10 0 V28" class="dply-flow stroke-brand-forest"></path></svg>';
        // Joins a box stacked under the App/Worker box (queue workers, scheduler, scheduled tasks) to it.
        $stackFlow = '<svg viewBox="0 0 20 20" class="mx-auto block h-5 w-5" aria-hidden="true"><path d="M10 0 V20" class="dply-flow stroke-brand-forest"></path></svg>';
        $sleepNote = fn (string $text) => '<p class="mt-2 flex items-center gap-1.5 text-2xs text-brand-moss"><svg class="h-3.5 w-3.5 text-violet-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21.752 15.002A9.72 9.72 0 0 1 18 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 0 0 3 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 0 0 9.002-5.998Z"/></svg>'.e($text).'</p>';
        $w = \App\Modules\Edge\Support\EdgeQueueWorkers::normalize($workers);
    @endphp

    @if ($needsRedeploy)
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-amber-500/30 bg-amber-500/10 px-5 py-2.5 text-sm sm:px-6">
            <p class="text-brand-ink">{{ __('Saved. Redeploy to apply these settings to the running app.') }}</p>
            @can('deploy', $site)
                <button type="button" wire:click="redeployEdge" wire:loading.attr="disabled" wire:target="redeployEdge" class="rounded-lg bg-brand-ink px-3 py-1.5 text-xs font-semibold text-brand-cream hover:bg-brand-forest disabled:opacity-60">
                    <span wire:loading.remove wire:target="redeployEdge">{{ __('Redeploy') }}</span>
                    <span wire:loading wire:target="redeployEdge">{{ __('Queuing…') }}</span>
                </button>
            @endcan
        </div>
    @endif

    {{-- The map: the app the way a request travels. Each box opens its settings in a sheet. --}}
    {{-- data-skip-busy-within: boxes open sheets with their own loading state (app.js). --}}
    <section aria-label="{{ __('Service map') }}" data-skip-busy-within @class([
        'lg:items-start' => $tallMap,
        'grid grid-cols-1 items-center border-b border-brand-ink/10 bg-[radial-gradient(circle_at_1px_1px,rgb(23_26_14/0.12)_1px,transparent_0)] bg-[length:18px_18px] px-5 py-6 sm:px-6 lg:py-8 dark:border-brand-mist/15 dark:bg-[radial-gradient(circle_at_1px_1px,rgb(232_236_227/0.10)_1px,transparent_0)]',
        'lg:grid-cols-[minmax(0,0.6fr)_36px_minmax(0,1fr)_36px_minmax(0,1.2fr)_44px_minmax(0,1.2fr)]' => $hasCode,
        'lg:grid-cols-[minmax(0,0.6fr)_36px_minmax(0,1.1fr)_36px_minmax(0,1.3fr)]' => ! $hasCode,
    ])>
        {{-- Visitors --}}
        <div class="text-center text-xs text-brand-moss">
            <div class="relative mx-auto mb-2 grid h-14 w-14 place-items-center">
                <span class="absolute inset-0 animate-ping rounded-full bg-brand-forest/15 [animation-duration:3s]" aria-hidden="true"></span>
                <span class="relative grid h-14 w-14 place-items-center rounded-full border border-brand-forest/40 bg-brand-forest/10 text-brand-forest dark:border-brand-sage/40 dark:text-brand-sage">
                    <x-heroicon-o-globe-americas class="h-7 w-7" aria-hidden="true" />
                </span>
            </div>
            <span class="{{ $eyebrow }} block">{{ __('Visitors') }}@if ($map['placement']['region'] ?? null) · <span class="font-mono normal-case tracking-normal">{{ $map['placement']['region'] }}</span>@endif</span>
            <span class="block font-mono text-lg font-bold tabular-nums text-brand-ink">{{ \Illuminate\Support\Number::abbreviate($map['requests30d'], maxPrecision: 1) }}</span>
            {{ __('requests, 30 days') }}
        </div>

        {!! $hFlow !!}{!! $vFlow !!}

        {{-- Edge. The open-site link sits over the box (a link can't live inside the button). --}}
        @php
            $customDomains = (array) ($site->edgeMeta()['routing']['custom_domains'] ?? []);
            $readyDomains = collect($customDomains)->filter(fn ($d) => is_array($d) && ($d['dns_status'] ?? null) === 'ready')->count();
            $avgPerDay = (int) round($map['requests30d'] / 30);
        @endphp
        <div class="relative">
            <button type="button" wire:click="$refresh" wire:island="resources-edge" x-on:click="$dispatch('open-modal', 'resources-edge')" class="{{ $node }}">
                <span class="flex items-center justify-between gap-2"><span class="{{ $eyebrow }}">{{ __('Edge network') }}</span>{!! $pill(__('Active'), 'ok') !!}</span>
                <span class="mt-1.5 block truncate pe-7 font-mono text-xs font-semibold text-brand-ink" title="{{ $hostname }}">{{ $hostname ?: __('No URL yet') }}</span>
                <span class="mt-0.5 block text-2xs text-brand-moss">
                    {{ __('HTTPS') }} ·
                    @if ($customDomains === [])
                        {{ __('no custom domain') }}
                    @else
                        {{ trans_choice(':count custom domain|:count custom domains', count($customDomains)) }}@if ($readyDomains < count($customDomains)) <span class="text-amber-600 dark:text-amber-300">({{ __(':count pending DNS', ['count' => count($customDomains) - $readyDomains]) }})</span>@endif
                    @endif
                </span>
                <span class="mt-2 flex flex-wrap gap-x-3 gap-y-0.5 font-mono text-2xs text-brand-mist">
                    @if ($map['placement'] && $map['placement']['rtt'] !== null)<span><b class="text-brand-ink">{{ $map['placement']['rtt'] }}</b> ms RTT</span>@endif
                    <span>{{ __('cache') }} <b class="text-brand-ink">{{ $cacheModes[$cacheMode] ?? $cacheMode }}</b></span>
                    <span><b class="text-brand-ink">{{ number_format($map['requestsToday']) }}</b> {{ __('req today') }}</span>
                    <span>~<b class="text-brand-ink">{{ number_format($avgPerDay) }}</b>/{{ __('day avg') }}</span>
                </span>
                {!! $mini($map['requests']) !!}
                {!! $more !!}
            </button>
            @if ($hostname)
                <a href="https://{{ $hostname }}" target="_blank" rel="noopener" class="absolute end-3 top-[2.35rem] grid h-6 w-6 place-items-center rounded-md text-brand-mist transition hover:bg-brand-sand/40 hover:text-brand-ink" title="{{ __('Open :host', ['host' => $hostname]) }}" aria-label="{{ __('Open :host in a new tab', ['host' => $hostname]) }}">
                    <x-heroicon-m-arrow-top-right-on-square class="h-3.5 w-3.5" aria-hidden="true" />
                </a>
            @endif
        </div>

        {!! $hFlow !!}{!! $vFlow !!}

        {{-- Runtime: the app, with what runs beside it joined underneath by $stackFlow. --}}
        <div class="grid content-start">
            @if ($isContainer && is_array($settings))
                @php
                    $c = $map['container']; $live = $appInstances; $liveUrl = filled($site->edgeLiveUrl());
                    // Sleep countdown: the app sleeps sleep_after past the last request into its
                    // last running instance (lastActivity comes from the site Worker, /_dply/instances).
                    $awake = collect($live['instances'] ?? [])->filter(fn ($i) => in_array($i['status'], \App\Modules\Edge\Support\EdgeContainerInstances::RUNNING, true));
                    $asleep = $live !== null && $live['instances'] !== null && $awake->isEmpty();
                    $sleepSeconds = preg_match('/^(\d+)([smh])$/', $sleepAfter, $sm) === 1 ? (int) $sm[1] * ['s' => 1, 'm' => 60, 'h' => 3600][$sm[2]] : null;
                    $lastActivity = $awake->pluck('lastActivity')->filter()->max();
                    $sleepsIn = $lastActivity && $sleepSeconds ? max(0, $lastActivity + $sleepSeconds - now()->timestamp) : null;
                    $awakeSince = $awake->pluck('since')->filter()->min();
                    $alwaysOn = (int) ($settings['min_instances'] ?? 0) > 0;
                    // Read by the boxes that live inside the app (scheduler, SQLite).
                    $appAsleep = $asleep && ! $deploying;
                @endphp
                {{-- Live instance state loads after the page (loadAppInstances, ~15s cache), so the map never waits on the app. --}}
                <button type="button" wire:click="$refresh" wire:island="resources-app" x-on:click="$dispatch('open-modal', 'resources-app')" @if ($live === null && $liveUrl) x-init="$wire.$island('map').loadAppInstances()" @endif @class([$node, 'border-brand-forest ring-4 ring-brand-forest/10 dark:border-brand-forest' => ! $asleep || $deploying, 'resource-asleep border-dashed border-brand-forest/60' => $asleep && ! $deploying])>
                    <span class="flex items-center justify-between gap-2"><span class="{{ $eyebrow }}">{{ __('App') }}</span>{!! $deploying ? $pill(__('Deploying'), 'busy') : (! ($site->edgeMeta()['active_deployment_id'] ?? null) ? $pill(__('Not deployed'), 'off') : ($asleep ? '<span class="flex items-center">'.$pill(__('Asleep'), 'off').$snore.'</span>' : $pill(__('Running'), 'ok'))) !!}</span>
                    <span class="mt-1.5 block text-sm font-bold text-brand-ink">{{ $map['framework'] ?? __('App') }} · {{ collect($sizes)->firstWhere('key', $settings['instance_type'])['label'] ?? __('Custom') }}</span>
                    <span class="mt-1 flex flex-wrap gap-x-3 gap-y-0.5 font-mono text-2xs text-brand-mist">
                        @if ($c && $c['memMb'] !== null)<span><b class="text-brand-ink">{{ $c['memMb'] }}</b> MB {{ __('of') }} {{ rtrim(rtrim(number_format($c['memGib'], 2), '0'), '.') }} GiB</span>@endif
                        @if ($live !== null && $live['instances'] !== null)
                            <span class="inline-flex items-center gap-1"><span @class(['h-1.5 w-1.5 rounded-full', 'bg-emerald-500' => $live['running'] > 0, 'bg-violet-500' => $live['running'] === 0])></span><b class="text-brand-ink">{{ $live['running'] }}</b> {{ __('of :max running', ['max' => max(count($live['instances']), 1)]) }}</span>
                        @elseif ($live === null && $liveUrl)
                            <span class="inline-block h-3 w-20 animate-pulse rounded bg-brand-ink/10 dark:bg-brand-mist/15" aria-label="{{ __('Loading instances') }}"></span>
                        @else
                            <span>{{ trans_choice(':count instance|:count instances', $settings['max_instances'], ['count' => $settings['max_instances']]) }}</span>
                        @endif
                        @if ($map['placement'])<span>{{ $map['placement']['location'] }}@if ($map['placement']['rtt'] !== null) · <b class="text-brand-ink">{{ $map['placement']['rtt'] }}</b> ms {{ __('to DB') }}@endif</span>@endif
                        @if (is_array($quote))<span>~<b class="text-brand-ink">{{ $quote['awakeMonth'] }}</b>/mo</span>@endif
                        @if ($awakeSince)<span>{{ __('awake') }} <b class="text-brand-ink">{{ \Illuminate\Support\Carbon::createFromTimestamp($awakeSince)->diffForHumans(syntax: \Carbon\CarbonInterface::DIFF_ABSOLUTE, short: true) }}</b></span>@endif
                        @if ($lastActivity)<span>{{ __('last request') }} <b class="text-brand-ink">{{ \Illuminate\Support\Carbon::createFromTimestamp($lastActivity)->diffForHumans(short: true) }}</b></span>@endif
                    </span>
                    @if ($c){!! $mini($c['memSeries'], $c['memGib'] * 1024) !!}@endif
                    @if ($alwaysOn)
                        {!! $sleepNote(trans_choice('Always awake: :count instance never sleeps|Always awake: :count instances never sleep', (int) $settings['min_instances'])) !!}
                    @elseif ($asleep)
                        {!! $sleepNote(__('Asleep · wakes on the next request')) !!}
                    @elseif ($sleepsIn !== null)
                        {{-- Counts down in the browser; once it runs out, re-reads the instances to show the app asleep. --}}
                        <p class="mt-2 flex items-center gap-1.5 text-2xs text-brand-moss" wire:key="sleep-countdown-{{ $lastActivity }}"
                           x-data="{ left: {{ $sleepsIn }}, t: null, destroy() { clearInterval(this.t) } }"
                           x-init="t = setInterval(() => { if (--left <= 0) { clearInterval(t); setTimeout(() => $wire.$island('map').loadAppInstances(), 15000) } }, 1000)">
                            <x-heroicon-o-moon class="h-3.5 w-3.5 text-violet-500" aria-hidden="true" />
                            <span x-show="left > 0">{{ __('Sleeps in') }} <b class="font-mono tabular-nums text-brand-ink" x-text="Math.floor(left / 60) + ':' + String(left % 60).padStart(2, '0')">{{ intdiv($sleepsIn, 60) }}:{{ str_pad((string) ($sleepsIn % 60), 2, '0', STR_PAD_LEFT) }}</b> {{ __('without a request') }}</span>
                            <span x-show="left <= 0" x-cloak>{{ __('Going to sleep…') }}</span>
                        </p>
                    @else
                        {!! $sleepNote(__('Sleeps after :time idle', ['time' => \App\Support\Sites\EdgeServiceMap::duration($sleepAfter)])) !!}
                    @endif
                    {!! $more !!}
                </button>
                @if ($workersNode && $queueRedisHost === null && ! $workersUnderDatabase)
                    {!! $stackFlow !!}
                    @include('livewire.sites.edge.workspace.partials.workers-node')
                @endif
                @if (is_array($crons) && ($crons['rows'] !== [] || ($crons['scheduler'] && ! $crons['schedulerInWorker']))){!! $stackFlow !!}@endif
                @include('livewire.sites.edge.workspace.partials.crons-node')
            @else
                <div class="rounded-2xl border border-brand-forest bg-white p-3.5 ring-4 ring-brand-forest/10 dark:bg-zinc-900">
                    <p class="{{ $eyebrow }}">{{ $runtimeMode === 'static' ? __('Static assets') : __('Worker') }}</p>
                    <p class="mt-1.5 text-sm font-bold text-brand-ink">{{ $map['framework'] ?? __('Auto-detected') }}</p>
                    <p class="mt-1 text-xs text-brand-moss">{{ ['hybrid' => __('Static assets plus server routes on Workers'), 'ssr' => __('Server-rendered on Workers')][$runtimeMode] ?? __('Files served from the edge cache') }}</p>
                </div>
                @if (is_array($crons) && ($crons['rows'] !== [] || ($crons['scheduler'] && ! $crons['schedulerInWorker']))){!! $stackFlow !!}@endif
                @include('livewire.sites.edge.workspace.partials.crons-node')
            @endif
        </div>

        @if ($hasCode)
            <svg viewBox="0 0 44 200" preserveAspectRatio="none" @class(['hidden w-full lg:block', 'h-56' => $tallMap, 'h-full min-h-24' => ! $tallMap]) aria-hidden="true">
                {{-- To the trunk; the resources column draws the trunk and a branch to each box (.resource-fan in app.css). --}}
                <path d="M0 100 H22" class="dply-flow stroke-brand-forest"></path>
            </svg>
            {!! $vFlow !!}

            {{-- Everything the app talks to --}}
            <div class="resource-fan grid gap-3 [&_.resource-flow]:hidden">
                @foreach ($orderedConnections as $connection)
                    @include('livewire.sites.edge.workspace.partials.connection-card', ['connectionIndex' => $loop->index])
                    @if ($connection['host'] === $queueRedisHost && $workersNode)
                        @include('livewire.sites.edge.workspace.partials.workers-node')
                    @endif
                @endforeach

                @if ($databaseVisible)
                    @php $d = $map['database']; $dplyEngine = in_array($databaseEngine, ['postgres', 'mongodb', 'mysql'], true); @endphp
                    {{-- SQLite is a file inside the app, so it sleeps when the app does. --}}
                    @php $dbAsleep = $databaseEngine === 'sql' && ($appAsleep ?? false); @endphp
                    <button type="button" wire:click="$refresh" wire:island="resources-database" x-on:click="$dispatch('open-modal', 'resources-database')" @class([$node, 'resource-asleep border-dashed' => $dbAsleep])>
                        <span class="flex items-center justify-between gap-2">
                            <span class="{{ $eyebrow }}">{{ __('Database') }}</span>
                            @if ($dbAsleep)
                                <span class="flex items-center">{!! $pill(__('Asleep with the app'), 'off') !!}{!! $snore !!}</span>
                            @elseif ($databaseEngine === 'sql')
                                {!! $pill(__('In the app'), 'ok') !!}
                            @elseif (($d['status'] ?? '') !== '')
                                {!! $pill($d['status'] === 'ready' ? __('Ready') : (string) str($d['status'])->headline(), $d['status'] === 'ready' ? 'ok' : 'warn') !!}
                            @endif
                        </span>
                        @php $primaryName = $appDatabases->first(fn ($x) => (bool) $x->attached_primary)?->name; @endphp
                        <span class="mt-1.5 block text-sm font-bold text-brand-ink">{{ ['postgres' => 'Postgres', 'mongodb' => 'MongoDB', 'mysql' => 'MySQL', 'sql' => 'SQLite'][$databaseEngine] ?? __('None') }}@if ($dplyEngine) · <span class="font-normal text-brand-moss">{{ $databaseName }}</span>@endif</span>
                        @if ($primaryName && $databaseEngine !== 'sql')
                            <span class="block font-mono text-2xs text-brand-mist">{{ $primaryName }} · {{ __('primary') }}</span>
                        @endif
                        @if ($databaseEngine === 'sql')
                            <span class="mt-0.5 block text-xs text-brand-moss">{{ __('A file inside the app, saved while it runs and restored when it wakes.') }}</span>
                        @elseif ($dplyEngine && $d)
                            @php
                                // Nothing written since the last full backup (a sleeping database):
                                // that backup holds everything, so its age is not a warning.
                                $current = $d['backupAt'] && $d['logAt'] && $d['logAt']->lte($d['backupAt']);
                            @endphp
                            <span class="mt-2 flex flex-wrap gap-x-3 gap-y-0.5 font-mono text-2xs text-brand-mist" @if ($d['checkedAt']) title="{{ __('Checked :time', ['time' => $d['checkedAt']->diffForHumans()]) }}" @endif>
                                @if ($current)
                                    <span>{{ __('backed up') }} · {{ __('no changes since') }} <b class="text-brand-ink">{{ $d['logAt']->diffForHumans(short: true) }}</b></span>
                                @else
                                    <span>{{ __('backup') }} <b class="text-brand-ink">{{ $d['backupAt']?->diffForHumans(short: true) ?? __('none') }}</b></span>
                                    @if ($d['logAt'])<span>PITR <b class="text-brand-ink">{{ $d['logAt']->diffForHumans(short: true) }}</b></span>@endif
                                @endif
                                @if ($d['checkedAt'] && $d['checkedAt']->lt(now()->subHours(3)))
                                    <span class="text-amber-600">{{ __('status from :time', ['time' => $d['checkedAt']->diffForHumans(short: true)]) }}</span>
                                @endif
                            </span>
                            @if ($d['suspend'] && $d['suspend'] > 0)
                                {!! $sleepNote(__('Suspends after :time idle', ['time' => \App\Support\Sites\EdgeServiceMap::duration(intdiv($d['suspend'], 60).'m')])) !!}
                            @endif
                        @endif
                        {!! $more !!}
                    </button>
                    @if ($workersUnderDatabase)
                        @include('livewire.sites.edge.workspace.partials.workers-node')
                    @endif
                @endif
                {{-- The app's other databases (DplyDatabases): each its own box and sheet. --}}
                @foreach ($appDatabases->reject(fn ($d) => (bool) $d->attached_primary) as $extra)
                    <button type="button" wire:click="openExtraDatabase('{{ $extra->id }}')" wire:island="resources-database-extra" x-on:click="$dispatch('open-modal', 'resources-database-extra')" class="{{ $node }}" wire:key="extra-db-{{ $extra->id }}">
                        <span class="flex items-center justify-between gap-2">
                            <span class="{{ $eyebrow }}">{{ __('Database') }}</span>
                            <span class="font-mono text-2xs text-brand-mist">{{ $extra->attached_env_name }}_*</span>
                        </span>
                        <span class="mt-1.5 block text-sm font-bold text-brand-ink">{{ $extra->name }}</span>
                        <span class="mt-0.5 block text-xs text-brand-moss">{{ ['postgres' => 'Postgres', 'mongodb' => 'MongoDB', 'mysql' => 'MySQL'][$extra->engine] ?? $extra->engine }} · {{ \App\Modules\Edge\Services\EdgeAppDatabase::POSTGRES_SIZES[$extra->size]['cpu'] ?? $extra->size }}</span>
                        {!! $more !!}
                    </button>
                @endforeach

                @if ($showBrowser && $browserOn)
                    <button type="button" wire:click="openPanel('browser')" wire:island="resources-browser" x-on:click="$dispatch('open-modal', 'resources-browser')" class="{{ $node }}">
                        <span class="flex items-center justify-between gap-2"><span class="{{ $eyebrow }}">{{ __('Browser') }}</span>{!! $pill(__('On'), 'ok') !!}</span>
                        <span class="mt-1.5 block font-mono text-xs font-semibold text-brand-ink">{{ $isWorker ? 'env.BROWSER' : $browserHost }}</span>
                        {!! $more !!}
                    </button>
                @endif

                <button type="button" wire:click="openConnectionBuilder" wire:island="resources-connection" x-on:click="$dispatch('open-modal', 'resources-connection')" class="resource-fan-end rounded-2xl border border-dashed border-brand-ink/25 bg-white/60 px-3.5 py-3 text-left text-sm font-semibold text-brand-ink transition hover:border-brand-ink dark:border-brand-mist/30 dark:bg-zinc-900/60">
                    ＋ {{ __('Add resource') }}
                    <span class="mt-0.5 block text-xs font-normal text-brand-moss">{{ __('Database, cache, storage, queue, workers, scheduled tasks, browser') }}</span>
                </button>
                @if ($showBrowser && ! $browserOn)
                    <button type="button" wire:click="openPanel('browser')" wire:island="resources-browser" x-on:click="$dispatch('open-modal', 'resources-browser')" class="resource-fan-skip -mt-1 text-left text-xs font-semibold text-brand-sage hover:underline">{{ __('Add a browser') }}</button>
                @endif
            </div>
        @endif
    </section>
    @unless ($hasCode)
        <p class="border-b border-brand-ink/10 px-5 py-3 text-xs text-brand-moss sm:px-6 dark:border-brand-mist/15">{{ __('Resources need server code. Switch to SSR or a container app to attach a database, cache or storage.') }}</p>
    @endunless
    @endisland

    {{-- Sheets for the map boxes. Deeper settings (sleep, estimate, cache
         rules, database tools) open as further sheets on top of these. --}}
    @island(name: 'resources-edge', always: true, skip: true, with: $this->viewData())
        @placeholder
            <x-sheet.pending name="resources-edge" maxWidth="lg" />
        @endplaceholder
        @include('livewire.sites.edge.workspace.partials.sheets.edge')
        @include('livewire.sites.edge.workspace.partials.sheets.cache')
    @endisland
    @island(name: 'resources-app', always: true, skip: true, with: $this->viewData())
        @placeholder
            @if ($isContainer && is_array($settings))
                <x-sheet.pending name="resources-app" maxWidth="lg" />
            @endif
        @endplaceholder
        @include('livewire.sites.edge.workspace.partials.sheets.app')
        @include('livewire.sites.edge.workspace.partials.sheets.estimate')
        @include('livewire.sites.edge.workspace.partials.sheets.sleep')
    @endisland
    @island(name: 'resources-database', always: true, skip: true, with: $this->viewData())
        @placeholder
            @if ($databaseVisible)
                <x-sheet.pending name="resources-database" maxWidth="lg" />
            @endif
        @endplaceholder
        @include('livewire.sites.edge.workspace.partials.sheets.database')
        @include('livewire.sites.edge.workspace.partials.database-panel')
        @include('livewire.sites.edge.workspace.partials.sheets.databases')
    @endisland
    @island(name: 'resources-workers', always: true, skip: true, with: $this->viewData())
        @placeholder
            @if ($isContainer && (($workers['enabled'] ?? false) || $scheduler))
                <x-sheet.pending name="resources-workers" maxWidth="xl" />
            @endif
        @endplaceholder
        @include('livewire.sites.edge.workspace.partials.sheets.workers')
        @include('livewire.sites.edge.workspace.partials.sheets.worker-logs')
        @include('livewire.sites.edge.workspace.partials.sheets.failed-jobs')
    @endisland
    @island(name: 'resources-database-add', always: true, skip: true, with: $this->viewData())
        @placeholder
            <x-sheet.pending name="resources-database-add" maxWidth="lg" />
        @endplaceholder
        @include('livewire.sites.edge.workspace.partials.sheets.database-add')
    @endisland
    @island(name: 'resources-database-extra', always: true, skip: true, with: $this->viewData())
        @placeholder
            <x-sheet.pending name="resources-database-extra" maxWidth="lg" />
        @endplaceholder
        @include('livewire.sites.edge.workspace.partials.sheets.database-extra')
    @endisland
    @island(name: 'resources-worker-setup', always: true, skip: true, with: $this->viewData())
        @placeholder
            <x-sheet.pending name="resources-worker-setup" maxWidth="lg" />
        @endplaceholder
        @include('livewire.sites.edge.workspace.partials.sheets.worker-setup')
    @endisland
    @island(name: 'resources-service', always: true, skip: true, with: $this->viewData())
        @placeholder
            <x-sheet.pending name="resources-service" maxWidth="3xl" />
        @endplaceholder
        @include('livewire.sites.edge.workspace.partials.sheets.service')
    @endisland
    @island(name: 'resources-kv', always: true, skip: true, with: $this->viewData())
        @placeholder
            <x-sheet.pending name="resources-kv" maxWidth="3xl" />
        @endplaceholder
        @include('livewire.sites.edge.workspace.partials.sheets.kv')
    @endisland
    @island(name: 'resources-object', always: true, skip: true, with: $this->viewData())
        @placeholder
            <x-sheet.pending name="resources-object" maxWidth="3xl" />
        @endplaceholder
        @include('livewire.sites.edge.workspace.partials.sheets.object')
    @endisland
    @island(name: 'resources-images', always: true, skip: true, with: $this->viewData())
        @placeholder
            <x-sheet.pending name="resources-images" maxWidth="3xl" />
        @endplaceholder
        @include('livewire.sites.edge.workspace.partials.sheets.images')
    @endisland
    @island(name: 'resources-messages', always: true, skip: true, with: $this->viewData())
        @placeholder
            <x-sheet.pending name="resources-messages" maxWidth="3xl" />
        @endplaceholder
        @include('livewire.sites.edge.workspace.partials.sheets.messages')
    @endisland
    @island(name: 'resources-valkey', always: true, skip: true, with: $this->viewData())
        @placeholder
            <x-sheet.pending name="resources-valkey" maxWidth="3xl" />
        @endplaceholder
        @include('livewire.sites.edge.workspace.partials.sheets.valkey')
    @endisland
    @island(name: 'resources-browser', always: true, skip: true, with: $this->viewData())
        @placeholder
            @if ($showBrowser)
                <x-sheet.pending name="resources-browser" maxWidth="3xl" />
            @endif
        @endplaceholder
        @include('livewire.sites.edge.workspace.partials.sheets.browser')
        @include('livewire.sites.edge.workspace.partials.sheets.remove-browser')
    @endisland
    @island(name: 'resources-delete-connection', always: true, skip: true, with: $this->viewData())
        @placeholder
            <x-sheet.pending name="resources-delete-connection" maxWidth="md" />
        @endplaceholder
        @include('livewire.sites.edge.workspace.partials.sheets.delete-connection')
    @endisland
    @island(name: 'resources-connection', always: true, skip: true, with: $this->viewData())
        @placeholder
            <x-sheet.pending name="resources-connection" maxWidth="lg" />
        @endplaceholder
        @include('livewire.sites.edge.workspace.partials.sheets.connection')
    @endisland
    @island(name: 'resources-sql', always: true, skip: true, with: $this->viewData())
        @placeholder
            <x-sheet.pending name="resources-sql" maxWidth="4xl" />
        @endplaceholder
        @include('livewire.sites.edge.workspace.partials.sheets.sql')
    @endisland
    @island(name: 'resources-queue', always: true, skip: true, with: $this->viewData())
        @placeholder
            <x-sheet.pending name="resources-queue" maxWidth="3xl" />
        @endplaceholder
        @include('livewire.sites.edge.workspace.partials.sheets.queue')
    @endisland
    @island(name: 'resources-durable-object', always: true, skip: true, with: $this->viewData())
        @placeholder
            <x-sheet.pending name="resources-durable-object" maxWidth="3xl" />
        @endplaceholder
        @include('livewire.sites.edge.workspace.partials.sheets.state')
    @endisland
    @island(name: 'resources-ai', always: true, skip: true, with: $this->viewData())
        @placeholder
            <x-sheet.pending name="resources-ai" maxWidth="3xl" />
        @endplaceholder
        @include('livewire.sites.edge.workspace.partials.sheets.ai')
    @endisland
    @island(name: 'resources-redis-external', always: true, skip: true, with: $this->viewData())
        @placeholder
            <x-sheet.pending name="resources-redis-external" maxWidth="3xl" />
        @endplaceholder
        @include('livewire.sites.edge.workspace.partials.sheets.redis-external')
    @endisland
    @island(name: 'resources-vectors', always: true, skip: true, with: $this->viewData())
        @placeholder
            <x-sheet.pending name="resources-vectors" maxWidth="3xl" />
        @endplaceholder
        @include('livewire.sites.edge.workspace.partials.sheets.vectors')
    @endisland
    @island(name: 'resources-database-pool', always: true, skip: true, with: $this->viewData())
        @placeholder
            <x-sheet.pending name="resources-database-pool" maxWidth="3xl" />
        @endplaceholder
        @include('livewire.sites.edge.workspace.partials.sheets.pool')
    @endisland
    @island(name: 'resources-workflow', always: true, skip: true, with: $this->viewData())
        @placeholder
            <x-sheet.pending name="resources-workflow" maxWidth="lg" />
        @endplaceholder
        @include('livewire.sites.edge.workspace.partials.sheets.workflow')
    @endisland
    @island(name: 'resources-realtime', always: true, skip: true, with: $this->viewData())
        @placeholder
            <x-sheet.pending name="resources-realtime" maxWidth="3xl" />
        @endplaceholder
        @include('livewire.sites.edge.workspace.partials.sheets.realtime')
    @endisland

</div>
