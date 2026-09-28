<div>
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
        $workersNode = $isContainer && (($workers['enabled'] ?? false) || $scheduler);

        $node = 'group block w-full rounded-2xl border border-brand-ink/15 bg-white p-3.5 text-left transition hover:-translate-y-px hover:border-brand-ink/40 dark:border-brand-mist/20 dark:bg-zinc-900 dark:hover:border-brand-mist/50';
        $eyebrow = 'text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist';
        $more = '<span class="mt-2 block text-2xs font-semibold text-brand-sage opacity-0 transition group-hover:opacity-100 group-focus-visible:opacity-100">'.e(__('Settings')).' ›</span>';
        $pill = fn (string $text, string $tone) => '<span class="inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-2xs font-semibold '.match ($tone) {
            'ok' => 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300',
            'sleep' => 'bg-violet-500/10 text-violet-700 dark:text-violet-300',
            'warn' => 'bg-amber-500/10 text-amber-700 dark:text-amber-300',
            default => 'bg-brand-sand/40 text-brand-moss',
        }.'"><span class="h-1.5 w-1.5 rounded-full bg-current'.($tone === 'ok' ? ' animate-pulse' : '').'"></span>'.e($text).'</span>';
        $mini = function (array $values, ?float $ceiling = null) {
            $line = \App\Support\Sites\EdgeServiceMap::path($values, 120, 28, ceiling: $ceiling);

            return $line === null ? '' : '<svg viewBox="0 0 120 28" preserveAspectRatio="none" class="mt-2.5 block h-7 w-full" aria-hidden="true"><path d="'.\App\Support\Sites\EdgeServiceMap::path($values, 120, 28, true, $ceiling).'" class="fill-brand-forest/10"></path><path d="'.$line.'" fill="none" class="stroke-brand-forest" stroke-width="1.5" vector-effect="non-scaling-stroke"></path></svg>';
        };
        // A long resource list would push the centred Edge and App boxes far
        // down; then everything aligns to the top and the links meet the first box.
        $tallMap = count($connections) + ($databaseVisible ? 1 : 0) + ($workersNode ? 1 : 0) > 3;
        $hFlow = '<svg viewBox="0 0 40 100" preserveAspectRatio="none" class="hidden '.($tallMap ? 'h-28' : 'h-full min-h-10').' w-full lg:block" aria-hidden="true"><path d="M0 50 H40" class="dply-flow stroke-brand-forest"></path></svg>';
        $vFlow = '<svg viewBox="0 0 20 28" class="mx-auto block h-7 w-5 lg:hidden" aria-hidden="true"><path d="M10 0 V28" class="dply-flow stroke-brand-forest"></path></svg>';
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
    <section aria-label="{{ __('Service map') }}" @class([
        'lg:items-start' => $tallMap,
        'grid grid-cols-1 items-center border-b border-brand-ink/10 bg-[radial-gradient(circle_at_1px_1px,rgb(23_26_14/0.12)_1px,transparent_0)] bg-[length:18px_18px] px-5 py-6 sm:px-6 lg:py-8 dark:border-brand-mist/15 dark:bg-[radial-gradient(circle_at_1px_1px,rgb(232_236_227/0.10)_1px,transparent_0)]',
        'lg:grid-cols-[minmax(0,0.6fr)_36px_minmax(0,1fr)_36px_minmax(0,1.2fr)_44px_minmax(0,1.2fr)]' => $hasCode,
        'lg:grid-cols-[minmax(0,0.6fr)_36px_minmax(0,1.1fr)_36px_minmax(0,1.3fr)]' => ! $hasCode,
    ])>
        {{-- Visitors --}}
        <div class="text-center text-xs text-brand-moss">
            <div class="mx-auto mb-2 grid h-14 w-14 place-items-center rounded-full border border-brand-ink/15 bg-[repeating-radial-gradient(circle,transparent_0_8px,rgb(23_26_14/0.06)_8px_9px)] font-mono text-2xs text-brand-mist dark:border-brand-mist/20">
                {{ $map['placement']['region'] ?? '' }}
            </div>
            <span class="block font-mono text-lg font-bold tabular-nums text-brand-ink">{{ \Illuminate\Support\Number::abbreviate($map['requests30d'], maxPrecision: 1) }}</span>
            {{ __('requests, 30 days') }}
        </div>

        {!! $hFlow !!}{!! $vFlow !!}

        {{-- Edge --}}
        <button type="button" wire:click="$refresh" wire:island="resources-edge" x-on:click="$dispatch('open-modal', 'resources-edge')" class="{{ $node }}">
            <span class="flex items-center justify-between gap-2"><span class="{{ $eyebrow }}">{{ __('Edge network') }}</span>{!! $pill(__('Active'), 'ok') !!}</span>
            <span class="mt-1.5 block break-all font-mono text-xs font-semibold text-brand-ink">{{ $hostname ?: __('No URL yet') }}</span>
            <span class="mt-2 flex flex-wrap gap-x-3 gap-y-0.5 font-mono text-2xs text-brand-mist">
                @if ($map['placement'] && $map['placement']['rtt'] !== null)<span><b class="text-brand-ink">{{ $map['placement']['rtt'] }}</b> ms RTT</span>@endif
                <span>{{ __('cache') }} <b class="text-brand-ink">{{ $cacheModes[$cacheMode] ?? $cacheMode }}</b></span>
                <span><b class="text-brand-ink">{{ number_format($map['requestsToday']) }}</b> {{ __('req today') }}</span>
            </span>
            {!! $mini($map['requests']) !!}
            {!! $more !!}
        </button>

        {!! $hFlow !!}{!! $vFlow !!}

        {{-- Runtime --}}
        <div class="grid gap-3">
            @if ($isContainer && is_array($settings))
                @php $c = $map['container']; $live = $appInstances; $liveUrl = filled($site->edgeLiveUrl()); @endphp
                {{-- Live instance state loads after the page (loadAppInstances, ~15s cache), so the map never waits on the app. --}}
                <button type="button" wire:click="$refresh" wire:island="resources-app" x-on:click="$dispatch('open-modal', 'resources-app')" @if ($live === null && $liveUrl) x-init="$wire.$island('map').loadAppInstances()" @endif class="{{ $node }} border-brand-forest ring-4 ring-brand-forest/10 dark:border-brand-forest">
                    <span class="flex items-center justify-between gap-2"><span class="{{ $eyebrow }}">{{ __('App') }}</span>{!! ($site->edgeMeta()['active_deployment_id'] ?? null) ? $pill(__('Running'), 'ok') : $pill(__('Not deployed'), 'off') !!}</span>
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
                    </span>
                    @if ($c){!! $mini($c['memSeries'], $c['memGib'] * 1024) !!}@endif
                    {!! $sleepNote(__('Sleeps after :time idle', ['time' => \App\Support\Sites\EdgeServiceMap::duration($sleepAfter)])) !!}
                    {!! $more !!}
                </button>
                @if ($workersNode && $queueRedisHost === null && ! $workersUnderDatabase)
                    @include('livewire.sites.edge.workspace.partials.workers-node')
                @endif
            @else
                <div class="rounded-2xl border border-brand-forest bg-white p-3.5 ring-4 ring-brand-forest/10 dark:bg-zinc-900">
                    <p class="{{ $eyebrow }}">{{ $runtimeMode === 'static' ? __('Static assets') : __('Worker') }}</p>
                    <p class="mt-1.5 text-sm font-bold text-brand-ink">{{ $map['framework'] ?? __('Auto-detected') }}</p>
                    <p class="mt-1 text-xs text-brand-moss">{{ ['hybrid' => __('Static assets plus server routes on Workers'), 'ssr' => __('Server-rendered on Workers')][$runtimeMode] ?? __('Files served from the edge cache') }}</p>
                </div>
            @endif
        </div>

        @if ($hasCode)
            <svg viewBox="0 0 44 200" preserveAspectRatio="none" @class(['hidden w-full lg:block', 'h-56' => $tallMap, 'h-full min-h-24' => ! $tallMap]) aria-hidden="true">
                <path d="M0 100 C22 100 22 40 44 40" class="dply-flow stroke-brand-forest"></path>
                <path d="M0 100 C22 100 22 160 44 160" class="dply-flow stroke-brand-mist"></path>
            </svg>
            {!! $vFlow !!}

            {{-- Everything the app talks to --}}
            <div class="grid gap-3 [&_.resource-flow]:hidden">
                @foreach ($orderedConnections as $connection)
                    @include('livewire.sites.edge.workspace.partials.connection-card', ['connectionIndex' => $loop->index])
                    @if ($connection['host'] === $queueRedisHost && $workersNode)
                        @include('livewire.sites.edge.workspace.partials.workers-node')
                    @endif
                @endforeach

                @if ($databaseVisible)
                    @php $d = $map['database']; $dplyEngine = in_array($databaseEngine, ['postgres', 'mongodb', 'mysql'], true); @endphp
                    <button type="button" wire:click="$refresh" wire:island="resources-database" x-on:click="$dispatch('open-modal', 'resources-database')" class="{{ $node }}">
                        <span class="flex items-center justify-between gap-2">
                            <span class="{{ $eyebrow }}">{{ __('Database') }}</span>
                            @if ($databaseEngine === 'sql')
                                {!! $pill(__('In the app'), 'ok') !!}
                            @elseif (($d['status'] ?? '') !== '')
                                {!! $pill($d['status'] === 'ready' ? __('Ready') : (string) str($d['status'])->headline(), $d['status'] === 'ready' ? 'ok' : 'warn') !!}
                            @endif
                        </span>
                        <span class="mt-1.5 block text-sm font-bold text-brand-ink">{{ ['postgres' => 'Postgres', 'mongodb' => 'MongoDB', 'mysql' => 'MySQL', 'sql' => 'SQLite'][$databaseEngine] ?? __('None') }}@if ($dplyEngine) · <span class="font-normal text-brand-moss">{{ $databaseName }}</span>@endif</span>
                        @if ($databaseEngine === 'sql')
                            <span class="mt-0.5 block text-xs text-brand-moss">{{ __('A file inside the app, saved while it runs and restored when it wakes.') }}</span>
                        @elseif ($dplyEngine && $d)
                            <span class="mt-2 flex flex-wrap gap-x-3 gap-y-0.5 font-mono text-2xs text-brand-mist">
                                <span>{{ __('backup') }} <b class="text-brand-ink">{{ $d['backupAt']?->diffForHumans(short: true) ?? __('none') }}</b></span>
                                @if ($d['logAt'])<span>PITR <b class="text-brand-ink">{{ $d['logAt']->diffForHumans(short: true) }}</b></span>@endif
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

                @if ($showBrowser && $browserOn)
                    <button type="button" wire:click="openPanel('browser')" wire:island="resources-browser" x-on:click="$dispatch('open-modal', 'resources-browser')" class="{{ $node }}">
                        <span class="flex items-center justify-between gap-2"><span class="{{ $eyebrow }}">{{ __('Browser') }}</span>{!! $pill(__('On'), 'ok') !!}</span>
                        <span class="mt-1.5 block font-mono text-xs font-semibold text-brand-ink">{{ $isWorker ? 'env.BROWSER' : $browserHost }}</span>
                        {!! $more !!}
                    </button>
                @endif

                <button type="button" wire:click="openConnectionBuilder" wire:island="resources-connection" x-on:click="$dispatch('open-modal', 'resources-connection')" class="rounded-2xl border border-dashed border-brand-ink/25 bg-white/60 px-3.5 py-3 text-left text-sm font-semibold text-brand-ink transition hover:border-brand-ink dark:border-brand-mist/30 dark:bg-zinc-900/60">
                    ＋ {{ __('Add resource') }}
                    <span class="mt-0.5 block text-xs font-normal text-brand-moss">{{ __('Database, cache, storage, queue, workers, browser') }}</span>
                </button>
                @if ($showBrowser && ! $browserOn)
                    <button type="button" wire:click="openPanel('browser')" wire:island="resources-browser" x-on:click="$dispatch('open-modal', 'resources-browser')" class="-mt-1 text-left text-xs font-semibold text-brand-sage hover:underline">{{ __('Add a browser') }}</button>
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
