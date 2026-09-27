{{-- The app's database panel: overview, live stats, backups and restore, how to connect, settings. --}}
@php
    $dbDply = in_array($databaseEngine, ['postgres', 'mysql', 'mongodb'], true);
    $dbName = ['postgres' => 'Postgres', 'mysql' => 'MySQL', 'mongodb' => 'MongoDB', 'sql' => 'SQLite'][$databaseEngine] ?? __('Database');
    $bytes = static function (int|float $n): string {
        foreach (['B', 'KB', 'MB', 'GB', 'TB'] as $unit) {
            if ($n < 1024 || $unit === 'TB') {
                return ($unit === 'B' ? (int) $n : number_format($n, $n < 10 ? 1 : 0)).' '.$unit;
            }
            $n /= 1024;
        }
    };
    $duration = static function (int $s): string {
        return match (true) {
            $s >= 86400 => intdiv($s, 86400).'d '.intdiv($s % 86400, 3600).'h',
            $s >= 3600 => intdiv($s, 3600).'h '.intdiv($s % 3600, 60).'m',
            $s >= 60 => intdiv($s, 60).'m',
            default => $s.'s',
        };
    };
    $dbStatus = is_array($databaseStatus ?? null) ? $databaseStatus : null;
    $dbAwake = $dbStatus['awake'] ?? null;
    $dbIdle = (int) ($dbStatus['idle_seconds'] ?? 0);
    $dbSleepAfter = (int) ($dbStatus['sleep_after'] ?? 0);
    $dbRecord = is_array($dplyDatabase ?? null) ? $dplyDatabase : null;
    $dbHost = (string) ($dbRecord['host'] ?? '');
    $dbPort = ['postgres' => '5432', 'mysql' => '3306', 'mongodb' => '27017'][$databaseEngine] ?? '';
    $dbDiskGb = (int) ($dbRecord['disk_gb'] ?? $postgresDisk);
    $dbInsights = is_array($databaseInsights ?? null) ? $databaseInsights : null;
    $dbSnapshotAt = ($dbInsights['taken_at'] ?? '') !== '' ? \Illuminate\Support\Carbon::parse($dbInsights['taken_at']) : null;
    // Live stats when loaded, else the snapshot the agent took before it last slept (never wakes it).
    $dbStats = is_array($databaseStats ?? null) ? $databaseStats : (isset($dbInsights['size_bytes'], $dbInsights['tables']) ? $dbInsights : null);
    $dbStatsSnapshot = ! is_array($databaseStats ?? null) && $dbStats !== null;
    $dbBackupMeta = (array) ($site->edgeMeta()['database']['backup'] ?? []);
    $dbBackupLive = is_array($databaseBackup ?? null) ? $databaseBackup : [];
    $dbLastFull = ($dbBackupLive['last_ok_at'] ?? $dbBackupMeta['last_ok_at'] ?? '') !== '' ? \Illuminate\Support\Carbon::parse($dbBackupLive['last_ok_at'] ?? $dbBackupMeta['last_ok_at']) : null;
    $dbLastLog = ($dbBackupMeta['log_ok_at'] ?? '') !== '' ? \Illuminate\Support\Carbon::parse($dbBackupMeta['log_ok_at']) : null;
    $dbProblem = \App\Modules\Edge\Support\EdgeDplyDatabase::backupProblem($dbBackupMeta);
    $dbCreated = isset($dbRecord['storage_at']) ? \Illuminate\Support\Carbon::createFromTimestamp((int) $dbRecord['storage_at']) : null;
    $windowStart = now()->subDays(7);
    $coveredFrom = $dbLastFull && $dbCreated && $dbCreated->gt($windowStart) ? $dbCreated : ($dbLastFull ? $windowStart : null);
    $coveragePct = $coveredFrom ? max(0, min(100, 100 - $coveredFrom->diffInSeconds(now()) / (7 * 86400) * 100)) : 0;
    $usage = is_array($databaseUsage ?? null) ? $databaseUsage : null;
    $maxHours = $usage ? max(1, max(array_column($usage['days'], 'hours'))) : 1;
    $tabs = array_filter([
        'overview' => $dbDply ? __('Overview') : null,
        'stats' => $dbDply ? __('Statistics') : null,
        'queries' => $dbDply ? __('Queries') : null,
        'health' => $dbDply ? __('Health') : null,
        'console' => $dbDply ? __('Console') : null,
        'backups' => $dbDply ? __('Backups') : null,
        'connect' => $databaseEngine !== 'none' ? __('Connect') : null,
        'settings' => $databaseEngine !== 'none' ? __('Settings') : null,
        'how' => __('How it works'),
    ]);
    $firstTab = array_key_first($tabs);
@endphp
<x-sheet name="resources-app-database" maxWidth="5xl" focusable>
    <div
        class="contents"
        x-data="{ tab: @js($firstTab), password: '' }"
        x-on:database-tab.window="tab = (Array.isArray($event.detail) ? $event.detail[0] : $event.detail); if (! @js(array_keys($tabs)).includes(tab)) tab = @js($firstTab); @if ($dbDply) $wire.$island('resources-database').loadDatabaseStatus(); $wire.$island('resources-database').loadDatabaseInsights(); @endif"
    >
        <x-sheet.header :eyebrow="$dbHost !== '' ? __('Database').' · '.$dbHost.':'.$dbPort : __('Database')" :title="$dbDply ? __('dply :engine', ['engine' => $dbName]) : $dbName">
            @if ($dbDply)
                <x-slot:actions>
                    @if ($dbAwake === true)
                        <span class="mt-0.5 inline-flex shrink-0 items-center gap-1.5 rounded-full bg-emerald-500/10 px-2 py-0.5 text-2xs font-semibold text-emerald-700 ring-1 ring-emerald-500/30 dark:text-emerald-300"><span class="h-1.5 w-1.5 animate-pulse rounded-full bg-emerald-500"></span>{{ __('Awake') }}</span>
                    @elseif ($dbAwake === false)
                        <span class="mt-0.5 inline-flex shrink-0 items-center gap-1.5 rounded-full bg-brand-sand/50 px-2 py-0.5 text-2xs font-semibold text-brand-moss ring-1 ring-brand-ink/10 dark:bg-zinc-800 dark:ring-brand-mist/15"><span class="h-1.5 w-1.5 rounded-full bg-brand-mist"></span>{{ __('Asleep') }}</span>
                    @else
                        <span wire:loading.delay wire:target="loadDatabaseStatus" class="mt-1 shrink-0 text-2xs text-brand-moss">{{ __('Checking…') }}</span>
                    @endif
                </x-slot:actions>
            @endif
        </x-sheet.header>

        <x-sheet.body>
            <x-sheet.tabs class="-mt-4">
                @foreach ($tabs as $key => $label)
                    <button type="button" role="tab" x-on:click="tab = '{{ $key }}'" :aria-selected="tab === '{{ $key }}' ? 'true' : 'false'">{{ $label }}</button>
                @endforeach
            </x-sheet.tabs>

            @if ($dbDply && ! $cardOnFile)
                <x-sheet.note tone="warn">{{ __('Add a card before starting a database. It is billed to that card.') }}
                    @if ($site->organization)<a href="{{ route('billing.show', $site->organization) }}" class="font-semibold underline">{{ __('Billing') }}</a>@endif
                </x-sheet.note>
            @endif

            @if ($dbDply)
                {{-- Overview --}}
                <div x-show="tab === 'overview'" class="grid gap-5">
                    <x-sheet.metrics :cols="4">
                        @foreach ([
                            [__('Status'), $dbAwake === true ? __('Awake') : ($dbAwake === false ? __('Asleep') : '—'), $dbAwake === true ? ($dbSleepAfter > 0 ? __('Sleeps in :time without a connection', ['time' => $duration(max(0, $dbSleepAfter - $dbIdle))]) : __('Stays on')) : ($dbAwake === false ? __('Wakes on the next connection') : __('Status loads when the panel opens'))],
                            [__('Size'), $postgresSizes[$postgresSize]['memory'] ?? '—', ($postgresSizes[$postgresSize]['cpu'] ?? '').' · '.($postgresSuspend === -1 ? __('stays on') : __('sleeps after :time', ['time' => __($postgresSleeps[$postgresSuspend] ?? '5 minutes')]))],
                            [__('Disk'), $dbStats ? $bytes($dbStats['size_bytes']).' / '.$dbDiskGb.' GB' : $dbDiskGb.' GB', $dbStats ? __(':pct% used', ['pct' => number_format(min(100, $dbStats['size_bytes'] / max(1, $dbDiskGb * 1024 ** 3) * 100), 1)]) : __('Load statistics to see what is used')],
                            [__('Awake this month'), $usage ? $duration($usage['awake_seconds']) : '—', $usage ? __(':gb GB-hours of storage', ['gb' => number_format($usage['storage_gb_hours'], 1)]) : ''],
                        ] as [$label, $value, $hint])
                            <x-sheet.metric :label="$label" :note="$hint">{{ $value }}</x-sheet.metric>
                        @endforeach
                    </x-sheet.metrics>

                    @php $placement = $site->edgeMeta()['placement'] ?? null; @endphp
                    @if (is_array($placement) && ($placement['rtt_ms'] ?? 0) > 0)
                        @php $far = $placement['rtt_ms'] > \App\Modules\Edge\Services\Containers\EdgeContainerDeployer::FAR_FROM_DATABASE_MS; @endphp
                        <x-sheet.metric :label="__('From the app')" :tone="$far ? 'warn' : null">
                            {{ __(':ms ms per round trip', ['ms' => rtrim(rtrim(number_format((float) $placement['rtt_ms'], 1), '0'), '.')]) }}
                            <x-slot:extra>
                            <p class="mt-1.5 text-xs leading-5 text-brand-moss">
                                {{ __('Measured inside the app at its last deploy, running in :location (:region). Every query pays this; a queue job makes several.', ['location' => $placement['location'] ?: '?', 'region' => $placement['region'] ?: '?']) }}
                                @if ($far) <span class="font-semibold text-amber-800 dark:text-amber-300">{{ __('That is far: redeploy to be placed again.') }}</span> @endif
                                @if (($site->edgeMeta()['database']['engine'] ?? '') === 'mysql' && ! $site->edgeEnvVars()->where('key', 'DPLY_MYSQL_ONE_ROUND_TRIP')->exists())
                                    {{ __('MySQL queries take two round trips. Set DPLY_MYSQL_ONE_ROUND_TRIP=true in Environment and redeploy to send each in one: parameters are then escaped into the query by PHP instead of bound by MySQL.') }}
                                @endif
                            </p>
                            </x-slot:extra>
                        </x-sheet.metric>
                    @endif

                    @if ($usage)
                        <x-sheet.section :title="__('Awake hours, last 14 days')">
                            <div class="rounded-xl border border-brand-ink/10 px-3.5 py-3 dark:border-brand-mist/15">
                                <p class="text-2xs text-brand-moss">{{ __('Compute bills only while awake') }}</p>
                                <div class="mt-3 flex h-28 items-end gap-1.5">
                                    @foreach ($usage['days'] as $day)
                                        <div class="group relative flex h-full flex-1 flex-col justify-end">
                                            <div class="rounded-t bg-brand-sage/70 group-hover:bg-brand-sage" style="height: {{ max($day['hours'] > 0 ? 3 : 0, $day['hours'] / $maxHours * 100) }}%"></div>
                                            <span class="pointer-events-none absolute -top-6 left-1/2 hidden -translate-x-1/2 whitespace-nowrap rounded bg-brand-ink px-1.5 py-0.5 text-2xs text-white group-hover:block">{{ \Illuminate\Support\Carbon::parse($day['date'])->format('M j') }} · {{ $day['hours'] }}h</span>
                                        </div>
                                    @endforeach
                                </div>
                                <div class="mt-1 flex justify-between text-2xs text-brand-mist">
                                    <span>{{ \Illuminate\Support\Carbon::parse($usage['days'][0]['date'])->format('M j') }}</span>
                                    <span>{{ __('Today') }}</span>
                                </div>
                            </div>
                        </x-sheet.section>
                    @endif

                    @php $history = array_values(array_filter((array) ($dbRecord['history'] ?? []), 'is_array')); @endphp
                    @if (count($history) >= 2)
                        @php
                            $histMax = max(1, max(array_map(fn ($h) => max((int) ($h['disk_used'] ?? 0), (int) ($h['size'] ?? 0)), $history)));
                            $histLast = end($history);
                        @endphp
                        <x-sheet.section :title="__('Size, last :n days', ['n' => count($history)])">
                            <div class="rounded-xl border border-brand-ink/10 px-3.5 py-3 dark:border-brand-mist/15">
                                <p class="text-2xs text-brand-moss">{{ __('Data :size · disk used :used of :disk', ['size' => $bytes((int) ($histLast['size'] ?? 0)), 'used' => $bytes((int) ($histLast['disk_used'] ?? 0)), 'disk' => $bytes((int) ($histLast['disk'] ?? 0))]) }}</p>
                                <div class="mt-3 flex h-24 items-end gap-1">
                                    @foreach ($history as $h)
                                        <div class="group relative flex h-full flex-1 flex-col justify-end">
                                            <div class="rounded-t bg-brand-sage/70 group-hover:bg-brand-sage" style="height: {{ max(2, (int) ($h['disk_used'] ?? $h['size'] ?? 0) / $histMax * 100) }}%"></div>
                                            <span class="pointer-events-none absolute -top-6 left-1/2 hidden -translate-x-1/2 whitespace-nowrap rounded bg-brand-ink px-1.5 py-0.5 text-2xs text-white group-hover:block">{{ \Illuminate\Support\Carbon::parse($h['date'])->format('M j') }} · {{ $bytes((int) ($h['disk_used'] ?? 0)) }} · {{ trans_choice(':count connection|:count connections', (int) ($h['connections'] ?? 0), ['count' => (int) ($h['connections'] ?? 0)]) }}</span>
                                        </div>
                                    @endforeach
                                </div>
                                <div class="mt-1 flex justify-between text-2xs text-brand-mist">
                                    <span>{{ \Illuminate\Support\Carbon::parse($history[0]['date'])->format('M j') }}</span>
                                    <span>{{ __('Today') }}</span>
                                </div>
                            </div>
                        </x-sheet.section>
                    @endif

                    <div class="grid gap-2 sm:grid-cols-2">
                        <x-sheet.row :title="__('Backups')" :hint="$dbProblem ?? ($dbLastFull ? __('Last full backup :ago. Restore to any second in the last 7 days.', ['ago' => $dbLastFull->diffForHumans()]) : __('The first backup runs a minute or two after the database first starts.'))" x-on:click="tab = 'backups'" class="{{ $dbProblem ? '[&_.truncate]:whitespace-normal [&_.truncate]:font-semibold [&_.truncate]:text-rose-700 dark:[&_.truncate]:text-rose-300' : '' }}" />
                        <x-sheet.row :title="__('Connect')" :hint="__('Address, login, and a command to open a shell. TLS only.')" x-on:click="tab = 'connect'" />
                    </div>
                </div>

                {{-- Statistics --}}
                <div x-show="tab === 'stats'" x-cloak class="grid gap-5">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <p class="max-w-xl text-xs leading-5 text-brand-moss">
                            @if ($dbStatsSnapshot)
                                {{ __('From the snapshot taken :ago, before the database last slept. Loading live stats wakes it, which counts as awake time.', ['ago' => $dbSnapshotAt?->diffForHumans() ?? __('earlier')]) }}
                            @else
                                {{ __('Read live from the database with the app\'s own login. Loading them wakes it if it is asleep, which counts as awake time.') }}
                            @endif
                        </p>
                        <x-sheet.button wire:click="loadDatabaseStats" wire:loading.attr="disabled" wire:target="loadDatabaseStats">
                            <span wire:loading.remove wire:target="loadDatabaseStats">{{ $dbStats ? __('Refresh') : __('Load live stats') }}</span>
                            <span wire:loading wire:target="loadDatabaseStats">{{ __('Waking and reading…') }}</span>
                        </x-sheet.button>
                    </div>
                    @if ($databaseStatsError)
                        <x-sheet.note tone="danger">{{ $databaseStatsError }}</x-sheet.note>
                    @endif
                    @if ($dbStats)
                        <x-sheet.metrics :cols="4">
                            @foreach ([
                                [__('Data'), $bytes($dbStats['size_bytes']), __('of :gb GB disk', ['gb' => $dbDiskGb])],
                                [__('Tables'), number_format($dbStats['tables']), __(':rows rows (estimate)', ['rows' => number_format($dbStats['rows'])])],
                                [__('Connections'), $dbStats['connections'].' / '.$dbStats['max_connections'], __('open now')],
                                [__('Cache hit rate'), $dbStats['cache_hit_ratio'] !== null ? $dbStats['cache_hit_ratio'].'%' : '—', __('reads served from memory')],
                                [__('Commits'), number_format($dbStats['commits']), __(':n rolled back', ['n' => number_format($dbStats['rollbacks'])])],
                                [__('Up for'), $duration($dbStats['uptime_seconds']), __('since this wake or restart')],
                                [__('Version'), $dbStats['version'], ''],
                            ] as [$label, $value, $hint])
                                <x-sheet.metric :label="$label" :note="$hint" title="{{ $value }}">{{ $value }}</x-sheet.metric>
                            @endforeach
                        </x-sheet.metrics>
                        <x-sheet.section :title="__('Largest tables')">
                            <ul class="divide-y divide-brand-ink/10 rounded-xl border border-brand-ink/10 dark:divide-brand-mist/15 dark:border-brand-mist/15">
                                @forelse ($dbStats['largest'] as $table)
                                    <li class="flex items-center gap-3 px-3.5 py-2.5 text-xs">
                                        <span class="min-w-0 flex-1 truncate font-mono text-brand-ink">{{ $table['name'] }}</span>
                                        <span class="w-24 text-right tabular-nums text-brand-moss">{{ number_format($table['rows']) }} {{ __('rows') }}</span>
                                        <span class="w-20 text-right font-semibold tabular-nums text-brand-ink">{{ $bytes($table['bytes']) }}</span>
                                        <span class="hidden h-1.5 w-32 overflow-hidden rounded-full bg-brand-ink/10 sm:block"><span class="block h-full rounded-full bg-brand-sage" style="width: {{ $dbStats['largest'][0]['bytes'] > 0 ? $table['bytes'] / $dbStats['largest'][0]['bytes'] * 100 : 0 }}%"></span></span>
                                    </li>
                                @empty
                                    <li class="px-3.5 py-2.5 text-xs text-brand-moss">{{ __('No tables yet. Migrations create them on the next deploy or with Migrate under Settings.') }}</li>
                                @endforelse
                            </ul>
                        </x-sheet.section>
                    @elseif (! $databaseStatsError)
                        <x-sheet.empty :message="$databaseEngine === 'mongodb' ? __('Size, collections, documents, connections, cache hit rate, and the largest collections. Load them when you need them.') : __('Size, tables, connections, cache hit rate, and the largest tables. Load them when you need them.')" />
                    @endif
                </div>

                {{-- Queries --}}
                @php
                    $dbQueries = is_array($dbInsights['queries'] ?? null) ? $dbInsights['queries'] : null;
                    $dbRunning = is_array($dbInsights['running'] ?? null) ? $dbInsights['running'] : null;
                    $dbQueryMax = $dbQueries ? max(1, max(array_map(fn ($q) => (float) ($q['total_ms'] ?? 0), $dbQueries) ?: [1])) : 1;
                @endphp
                <div x-show="tab === 'queries'" x-cloak class="grid gap-5">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <p class="max-w-xl text-xs leading-5 text-brand-moss">
                            {{ $dbSnapshotAt ? __('As of :ago:', ['ago' => $dbSnapshotAt->diffForHumans()]).' ' : '' }}{{ __('queries grouped by shape, with values stripped out. Refresh reads the database now, and wakes it if it is asleep.') }}
                        </p>
                        <div class="flex gap-2">
                            @if ($dbQueries)
                                <x-sheet.button wire:click="resetDatabaseQueries" wire:confirm="{{ __('Start the query counts fresh? Useful right after a fix.') }}" wire:loading.attr="disabled" wire:target="resetDatabaseQueries">{{ __('Reset counts') }}</x-sheet.button>
                            @endif
                            <x-sheet.button wire:click="loadDatabaseInsights(true)" wire:loading.attr="disabled" wire:target="loadDatabaseInsights">
                                <span wire:loading.remove wire:target="loadDatabaseInsights">{{ __('Refresh') }}</span>
                                <span wire:loading wire:target="loadDatabaseInsights">{{ __('Reading…') }}</span>
                            </x-sheet.button>
                        </div>
                    </div>
                    @if ($databaseInsightsError)
                        <x-sheet.note>{{ $databaseInsightsError }}</x-sheet.note>
                    @endif

                    <x-sheet.section :title="__('Running now')">
                        @if (is_array($dbInsights['by_state'] ?? null) && $dbInsights['by_state'] !== [])
                            <p class="text-2xs text-brand-moss">{{ collect($dbInsights['by_state'])->map(fn ($n, $state) => $n.' '.$state)->implode(' · ') }}</p>
                        @endif
                        <ul class="divide-y divide-brand-ink/10 rounded-xl border border-brand-ink/10 dark:divide-brand-mist/15 dark:border-brand-mist/15">
                            @forelse ($dbRunning ?? [] as $run)
                                @php $stuck = ($run['state'] ?? '') === 'idle in transaction'; @endphp
                                <li class="flex items-start gap-3 px-3.5 py-2.5 text-xs">
                                    <span @class(['w-14 shrink-0 text-right font-semibold tabular-nums', 'text-rose-700 dark:text-rose-300' => ($run['seconds'] ?? 0) >= 30, 'text-brand-ink' => ($run['seconds'] ?? 0) < 30])>{{ $duration((int) ($run['seconds'] ?? 0)) }}</span>
                                    <div class="min-w-0 flex-1">
                                        <p class="truncate font-mono text-brand-ink" title="{{ $run['query'] ?? '' }}">{{ $run['query'] ?? '' }}</p>
                                        <p class="mt-0.5 text-brand-moss">
                                            {{ $run['state'] ?? '' }}
                                            @if (! empty($run['wait'])) · {{ __('waiting on :what', ['what' => $run['wait']]) }} @endif
                                            @if (! empty($run['blocked_by'])) · <span class="font-semibold text-amber-800 dark:text-amber-300">{{ __('blocked by :pids', ['pids' => implode(', ', (array) $run['blocked_by'])]) }}</span> @endif
                                            @if ($stuck) · <span class="font-semibold text-amber-800 dark:text-amber-300">{{ __('a transaction left open: usually a missing commit, or a job that died mid-transaction') }}</span> @endif
                                        </p>
                                    </div>
                                    @if (($dbInsights['awake'] ?? false) && isset($run['pid']))
                                        <x-sheet.button variant="danger" class="shrink-0" wire:click="cancelDatabaseQuery({{ (int) $run['pid'] }})" wire:confirm="{{ __('Cancel this query? The app gets an error for it.') }}">{{ __('Cancel') }}</x-sheet.button>
                                    @endif
                                </li>
                            @empty
                                <li class="px-3.5 py-2.5 text-xs text-brand-moss">{{ $dbInsights ? __('Nothing running.') : __('Shown once the database has reported in.') }}</li>
                            @endforelse
                        </ul>
                    </x-sheet.section>

                    <x-sheet.section :title="__('Top queries by total time')">
                        @if (($dbInsights['queries_since'] ?? '') !== '')
                            <p class="text-2xs text-brand-moss">{{ __('since :when', ['when' => \Illuminate\Support\Carbon::parse($dbInsights['queries_since'])->diffForHumans()]) }}</p>
                        @endif
                        @if ($dbQueries)
                            <div class="divide-y divide-brand-ink/10 rounded-xl border border-brand-ink/10 dark:divide-brand-mist/15 dark:border-brand-mist/15">
                                <div class="hidden grid-cols-[1fr_5rem_6rem_6rem_5rem] gap-3 rounded-t-xl bg-brand-sand/40 px-3.5 py-2 text-2xs font-semibold uppercase tracking-[0.12em] text-brand-mist sm:grid dark:bg-zinc-800">
                                    <span>{{ __('Query') }}</span><span class="text-right">{{ __('Calls') }}</span><span class="text-right">{{ __('Total') }}</span><span class="text-right">{{ __('Average') }}</span><span class="text-right">{{ __('Rows') }}</span>
                                </div>
                                @forelse ($dbQueries as $q)
                                    <div x-data="{ open: false }" class="px-3.5 py-2.5 text-xs">
                                        <div class="grid grid-cols-2 gap-3 sm:grid-cols-[1fr_5rem_6rem_6rem_5rem]">
                                            <button type="button" x-on:click="open = ! open" class="col-span-2 min-w-0 text-left sm:col-span-1">
                                                <span class="block truncate font-mono text-brand-ink" x-show="! open">{{ $q['query'] ?? '' }}</span>
                                                <span class="block h-1 overflow-hidden rounded-full bg-brand-ink/10" x-show="! open"><span class="block h-full rounded-full bg-brand-sage" style="width: {{ (float) ($q['total_ms'] ?? 0) / $dbQueryMax * 100 }}%"></span></span>
                                            </button>
                                            <span class="text-right tabular-nums text-brand-moss">{{ number_format((int) ($q['calls'] ?? 0)) }}</span>
                                            <span class="text-right font-semibold tabular-nums text-brand-ink">{{ number_format((float) ($q['total_ms'] ?? 0) / 1000, 1) }} s</span>
                                            <span @class(['text-right tabular-nums', 'font-semibold text-amber-800 dark:text-amber-300' => (float) ($q['mean_ms'] ?? 0) >= 100, 'text-brand-moss' => (float) ($q['mean_ms'] ?? 0) < 100])>{{ number_format((float) ($q['mean_ms'] ?? 0), 1) }} ms</span>
                                            <span class="text-right tabular-nums text-brand-moss">{{ number_format((int) ($q['rows'] ?? 0)) }}</span>
                                        </div>
                                        <pre x-show="open" x-cloak x-on:click="open = false" class="mt-2 cursor-pointer overflow-x-auto whitespace-pre-wrap rounded-xl bg-brand-sand/40 px-3.5 py-3 font-mono text-xs text-brand-ink dark:bg-zinc-950">{{ $q['query'] ?? '' }}</pre>
                                    </div>
                                @empty
                                @endforelse
                                @if ($dbQueries === [])
                                    <p class="px-3.5 py-2.5 text-xs text-brand-moss">{{ __('No queries recorded since the counts were reset.') }}</p>
                                @endif
                            </div>
                        @elseif ($databaseEngine === 'mongodb')
                            <x-sheet.empty :message="__('MongoDB 7 keeps no per-query totals. Running operations are above.')" />
                        @else
                            <x-sheet.empty :message="($dbInsights['queries_error'] ?? '') !== '' ? $dbInsights['queries_error'] : __('Recorded from the database\'s next wake on the updated image.')" />
                        @endif
                    </x-sheet.section>
                </div>

                {{-- Health --}}
                @php
                    $dbDiskUsed = (int) ($dbInsights['disk_used_bytes'] ?? 0);
                    $dbDiskAll = (int) ($dbInsights['disk_bytes'] ?? 0);
                    $dbChecks = array_values(array_filter([
                        $dbDiskAll > 0 ? [__('Disk'), $dbDiskUsed / $dbDiskAll >= 0.8 ? 'warn' : 'ok', __(':used of :all used', ['used' => $bytes($dbDiskUsed), 'all' => $bytes($dbDiskAll)]).($dbDiskUsed / $dbDiskAll >= 0.8 ? ' · '.__('a disk only grows: add more under Resources before it fills') : '')] : null,
                        isset($dbStats['connections'], $dbStats['max_connections']) ? [__('Connections'), $dbStats['connections'] >= 0.8 * max(1, $dbStats['max_connections']) ? 'warn' : 'ok', __(':n of :max', ['n' => $dbStats['connections'], 'max' => $dbStats['max_connections']])] : null,
                        [__('Backups'), $dbProblem ? 'warn' : ($dbLastFull ? 'ok' : 'wait'), $dbProblem ?? ($dbLastFull ? __('last full :ago', ['ago' => $dbLastFull->diffForHumans()]) : __('the first runs soon after the first start'))],
                        isset($dbStats['cache_hit_ratio']) && $dbStats['cache_hit_ratio'] !== null ? [__('Cache hit rate'), $dbStats['cache_hit_ratio'] < 90 ? 'warn' : 'ok', $dbStats['cache_hit_ratio'].'%'.($dbStats['cache_hit_ratio'] < 90 ? ' · '.__('reads miss memory often: a larger size helps') : '')] : null,
                    ]));
                    $dbUnused = is_array($dbInsights['unused_indexes'] ?? null) ? $dbInsights['unused_indexes'] : [];
                    $dbScans = is_array($dbInsights['full_scans'] ?? null) ? $dbInsights['full_scans'] : [];
                    $dbVacuum = is_array($dbInsights['vacuum'] ?? null) ? $dbInsights['vacuum'] : [];
                    $dbExtensions = is_array($dbInsights['extensions'] ?? null) ? $dbInsights['extensions'] : [];
                @endphp
                <div x-show="tab === 'health'" x-cloak class="grid gap-5">
                    <div class="grid gap-2 sm:grid-cols-2">
                        @foreach ($dbChecks as [$label, $state, $detail])
                            <div @class(['flex items-start gap-3 rounded-xl border px-3.5 py-2.5', 'border-amber-500/40 bg-amber-500/10' => $state === 'warn', 'border-brand-ink/10 dark:border-brand-mist/15' => $state !== 'warn'])>
                                <span @class(['mt-1.5 h-2 w-2 shrink-0 rounded-full', 'bg-amber-500' => $state === 'warn', 'bg-emerald-500' => $state === 'ok', 'bg-brand-mist' => $state === 'wait'])></span>
                                <div class="min-w-0">
                                    <p class="text-sm font-semibold text-brand-ink">{{ $label }}</p>
                                    <p class="mt-0.5 text-2xs text-brand-moss">{{ $detail }}</p>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    @if (! $dbInsights)
                        <x-sheet.note>{{ $databaseInsightsError ?? __('Index and table health appear once the database has reported in.') }}</x-sheet.note>
                    @endif

                    @if ($dbScans !== [])
                        <x-sheet.section :title="__('Probably needs an index')">
                            <p class="text-2xs leading-4 text-brand-mist">{{ __('Large tables read mostly by scanning every row. Find the query that filters them under Queries and index its WHERE columns.') }}</p>
                            <ul class="divide-y divide-brand-ink/10 rounded-xl border border-brand-ink/10 dark:divide-brand-mist/15 dark:border-brand-mist/15">
                                @foreach ($dbScans as $scan)
                                    <li class="flex items-center gap-3 px-3.5 py-2.5 text-xs">
                                        <span class="min-w-0 flex-1 truncate font-mono text-brand-ink">{{ $scan['table'] ?? '' }}</span>
                                        @if (isset($scan['scans']))
                                            <span class="tabular-nums text-brand-moss">{{ __(':n full scans, :i by index', ['n' => number_format((int) $scan['scans']), 'i' => number_format((int) ($scan['index_scans'] ?? 0))]) }}</span>
                                        @endif
                                        @if (isset($scan['rows_scanned']))
                                            <span class="tabular-nums text-brand-moss">{{ __(':n rows read by full scans', ['n' => number_format((int) $scan['rows_scanned'])]) }}</span>
                                        @endif
                                        @if (isset($scan['rows']))
                                            <span class="w-24 text-right tabular-nums text-brand-ink">{{ number_format((int) $scan['rows']) }} {{ __('rows') }}</span>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        </x-sheet.section>
                    @endif

                    @if ($dbUnused !== [])
                        <x-sheet.section :title="__('Indexes never used')">
                            <p class="text-2xs leading-4 text-brand-mist">{{ __('Not read since the counts started. Each slows every write and takes disk; drop it in a migration if nothing needs it.') }}</p>
                            <ul class="divide-y divide-brand-ink/10 rounded-xl border border-brand-ink/10 dark:divide-brand-mist/15 dark:border-brand-mist/15">
                                @foreach ($dbUnused as $index)
                                    <li class="flex items-center gap-3 px-3.5 py-2.5 text-xs">
                                        <span class="min-w-0 flex-1 truncate font-mono text-brand-ink">{{ $index['index'] ?? '' }}</span>
                                        <span class="truncate font-mono text-brand-moss">{{ $index['table'] ?? '' }}</span>
                                        @if (isset($index['bytes']))
                                            <span class="w-20 text-right tabular-nums text-brand-ink">{{ $bytes((int) $index['bytes']) }}</span>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        </x-sheet.section>
                    @endif

                    @if ($dbVacuum !== [])
                        <x-sheet.section :title="__('Dead rows')">
                            <p class="text-2xs leading-4 text-brand-mist">{{ __('Rows updated or deleted but not yet cleaned up. Postgres does this on its own; a big number that stays is worth a look.') }}</p>
                            <ul class="divide-y divide-brand-ink/10 rounded-xl border border-brand-ink/10 dark:divide-brand-mist/15 dark:border-brand-mist/15">
                                @foreach ($dbVacuum as $v)
                                    <li class="flex items-center gap-3 px-3.5 py-2.5 text-xs">
                                        <span class="min-w-0 flex-1 truncate font-mono text-brand-ink">{{ $v['table'] ?? '' }}</span>
                                        <span class="tabular-nums text-brand-ink">{{ __(':n dead of :rows', ['n' => number_format((int) ($v['dead_rows'] ?? 0)), 'rows' => number_format((int) ($v['rows'] ?? 0))]) }}</span>
                                        <span class="w-36 text-right text-brand-moss">{{ ($v['last_vacuum'] ?? null) ? __('cleaned :ago', ['ago' => \Illuminate\Support\Carbon::parse($v['last_vacuum'])->diffForHumans()]) : __('never cleaned') }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        </x-sheet.section>
                    @endif

                    @if ($dbExtensions !== [])
                        <x-sheet.section :title="__('Extensions')">
                            <p class="text-2xs leading-4 text-brand-mist">{{ __('Turning one on wakes the database. Turning one off is a migration (DROP EXTENSION).') }}</p>
                            <ul class="divide-y divide-brand-ink/10 rounded-xl border border-brand-ink/10 dark:divide-brand-mist/15 dark:border-brand-mist/15">
                                @foreach ($dbExtensions as $ext)
                                    <li class="flex items-start gap-3 px-3.5 py-2.5 text-xs">
                                        <div class="min-w-0 flex-1">
                                            <p class="font-mono font-semibold text-brand-ink">{{ $ext['name'] }}</p>
                                            <p class="mt-0.5 text-brand-moss">{{ $ext['comment'] ?? '' }}</p>
                                        </div>
                                        @if (! empty($ext['installed']))
                                            <span class="shrink-0 rounded-full bg-emerald-500/10 px-2 py-0.5 text-2xs font-semibold text-emerald-700 dark:text-emerald-300">{{ __('On') }} · {{ $ext['installed'] }}</span>
                                        @else
                                            <x-sheet.button class="shrink-0" wire:click="enableDatabaseExtension({{ \Illuminate\Support\Js::from($ext['name']) }})" wire:loading.attr="disabled" wire:target="enableDatabaseExtension">{{ __('Turn on') }}</x-sheet.button>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        </x-sheet.section>
                    @endif
                </div>

                {{-- Console --}}
                <div x-show="tab === 'console'" x-cloak class="grid gap-4">
                    <x-sheet.note>
                        @if ($databaseEngine === 'mongodb')
                            {{ __('Find documents in a collection, read-only, up to 200. Running a query wakes the database.') }}
                        @else
                            {{ __('One read-only statement as a login that can only SELECT, up to 200 rows and 15 seconds. Writes, several statements, and role changes are refused. Running a query wakes the database.') }}
                        @endif
                    </x-sheet.note>
                    <form wire:submit="runDatabaseConsole" class="grid gap-2">
                        @if ($databaseEngine === 'mongodb')
                            <div class="grid gap-2 sm:grid-cols-[12rem_1fr]">
                                <input type="text" wire:model="databaseConsoleCollection" placeholder="{{ __('collection') }}" class="dply-input mt-0 font-mono" />
                                <input type="text" wire:model="databaseConsoleFilter" placeholder='{"status": "open"}' class="dply-input mt-0 font-mono" />
                            </div>
                        @else
                            <textarea wire:model="databaseConsoleSql" rows="4" spellcheck="false" placeholder="select id, email from users order by id desc limit 20" x-on:keydown.meta.enter.prevent="$wire.$island('resources-database').runDatabaseConsole()" x-on:keydown.ctrl.enter.prevent="$wire.$island('resources-database').runDatabaseConsole()" class="dply-input mt-0 font-mono"></textarea>
                        @endif
                        <div class="flex flex-wrap items-center gap-3">
                            <x-sheet.button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="runDatabaseConsole">
                                <span wire:loading.remove wire:target="runDatabaseConsole">{{ __('Run') }}</span>
                                <span wire:loading wire:target="runDatabaseConsole">{{ __('Running…') }}</span>
                            </x-sheet.button>
                            @if ($databaseEngine !== 'mongodb')<span class="text-2xs text-brand-mist">⌘/Ctrl + Enter</span>@endif
                            @if ($databaseConsoleResult)
                                <span class="text-xs text-brand-moss">{{ trans_choice(':count row|:count rows', count($databaseConsoleResult['rows'] ?? []), ['count' => count($databaseConsoleResult['rows'] ?? [])]) }}{{ ($databaseConsoleResult['truncated'] ?? false) ? ' '.__('(first 200)') : '' }} · {{ $databaseConsoleResult['ms'] ?? 0 }} ms</span>
                            @endif
                        </div>
                    </form>
                    @if ($databaseConsoleError)
                        <pre class="whitespace-pre-wrap rounded-xl border border-rose-500/30 bg-rose-500/10 px-3.5 py-3 font-mono text-xs text-rose-800 dark:text-rose-200">{{ $databaseConsoleError }}</pre>
                    @endif
                    @if ($databaseConsoleResult)
                        <x-sheet.table class="max-h-[45vh] overflow-y-auto">
                            <table>
                                <thead>
                                    <tr>
                                        @foreach ($databaseConsoleResult['columns'] ?? [] as $column)
                                            <th>{{ $column }}</th>
                                        @endforeach
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($databaseConsoleResult['rows'] ?? [] as $row)
                                        <tr>
                                            @foreach ((array) $row as $value)
                                                <td class="max-w-xs truncate whitespace-nowrap font-mono {{ $value === null ? 'italic !text-brand-mist' : '' }}" title="{{ is_scalar($value) ? $value : json_encode($value) }}">{{ $value === null ? 'null' : (is_scalar($value) ? $value : json_encode($value)) }}</td>
                                            @endforeach
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </x-sheet.table>
                    @endif
                </div>

                {{-- Backups --}}
                <div x-show="tab === 'backups'" x-cloak class="grid gap-5">
                    <x-sheet.metrics :cols="3">
                        <x-sheet.metric :label="__('Last full backup')" :note="$dbLastFull ? $dbLastFull->utc()->format('M j, H:i').' UTC' : __('The first runs a minute or two after the database starts')">{{ $dbLastFull?->diffForHumans() ?? __('Not yet') }}</x-sheet.metric>
                        <x-sheet.metric :label="__('Changes saved')" :note="__('Every change is streamed to storage')">{{ $dbLastLog?->diffForHumans() ?? '—' }}</x-sheet.metric>
                        <x-sheet.metric :label="__('Health')" :tone="$dbProblem ? 'danger' : ($dbLastFull ? 'ok' : null)">
                            {{ $dbProblem ? __('Needs attention') : ($dbLastFull ? __('Healthy') : __('Waiting')) }}
                            {{-- Not :note — a backup problem is a sentence and must not be truncated. --}}
                            <x-slot:extra><p class="mt-0.5 text-2xs text-brand-moss">{{ $dbProblem ?? (($dbBackupLive['last_error'] ?? '') !== '' ? $dbBackupLive['last_error'] : __('Kept for 7 days')) }}</p></x-slot:extra>
                        </x-sheet.metric>
                    </x-sheet.metrics>

                    <x-sheet.section :title="__('Restore window')">
                        <div class="rounded-xl border border-brand-ink/10 px-3.5 py-3 dark:border-brand-mist/15">
                            <p class="text-2xs text-brand-moss">{{ $coveredFrom ? __('Any second from :from to now', ['from' => $coveredFrom->utc()->format('M j, H:i').' UTC']) : __('Opens after the first full backup') }}</p>
                            <div class="relative mt-2 h-3 overflow-hidden rounded-full bg-brand-ink/10 dark:bg-brand-mist/15">
                                <div class="absolute inset-y-0 right-0 rounded-full bg-brand-sage" style="width: {{ $coveragePct }}%"></div>
                            </div>
                            <div class="mt-1 flex justify-between text-2xs text-brand-mist"><span>{{ __('7 days ago') }}</span><span>{{ __('Now') }}</span></div>
                            @if (($dbBackupMeta['lost'] ?? '') !== '')
                                <p class="mt-2 text-xs text-brand-ink">{{ ucfirst($dbBackupMeta['lost']) }}. {{ __('Restoring to a time after that works as usual.') }}</p>
                            @endif
                        </div>
                    </x-sheet.section>

                    @if ($coveredFrom)
                        <x-sheet.field :label="__('Quick pick')">
                            <div class="flex flex-wrap gap-2">
                                @foreach (['1 hour ago' => now()->utc()->subHour(), '6 hours ago' => now()->utc()->subHours(6), 'Yesterday, same time' => now()->utc()->subDay()] as $label => $at)
                                    @if ($at->gte($coveredFrom))
                                        <x-sheet.button wire:click="$set('postgresRestoreAt', '{{ $at->format('Y-m-d\TH:i:s') }}')">{{ __($label) }}</x-sheet.button>
                                    @endif
                                @endforeach
                            </div>
                        </x-sheet.field>
                    @endif

                    @if (($site->edgeMeta()['database']['provider'] ?? '') === 'dply' && ($site->edgeMeta()['database']['engine'] ?? '') === $databaseEngine)
                        <x-sheet.section :title="__('Restore to a point in time')">
                            <div class="grid gap-3 rounded-xl border border-brand-ink/10 px-3.5 py-3 dark:border-brand-mist/15">
                                @if ($databaseEngine === 'postgres')
                                    <p class="text-xs leading-5 text-brand-moss">{{ __('Changes are backed up continuously for 7 days. Restoring replaces the data with how it was at that moment (UTC); the data from before the restore is kept aside until the next one.') }}</p>
                                @else
                                    <p class="text-xs leading-5 text-brand-moss">{{ __('Changes are backed up continuously for 7 days. Restoring replaces the data with how it was at that moment (UTC); the data from before the restore is saved as a backup first.') }}</p>
                                @endif
                                @php
                                    $backup = (array) ($site->edgeMeta()['database']['backup'] ?? []);
                                    $backupOk = ($backup['last_ok_at'] ?? '') !== '' ? \Illuminate\Support\Carbon::parse($backup['last_ok_at']) : null;
                                    $changesOk = ($backup['log_ok_at'] ?? '') !== '' ? \Illuminate\Support\Carbon::parse($backup['log_ok_at']) : null;
                                    $backupProblem = \App\Modules\Edge\Support\EdgeDplyDatabase::backupProblem($backup);
                                @endphp
                                @if ($backupProblem)
                                    <x-sheet.note tone="danger">{{ $backupProblem }}@if ($backupOk) {{ __('The last good full backup was :ago.', ['ago' => $backupOk->diffForHumans()]) }}@endif</x-sheet.note>
                                @elseif ($backupOk)
                                    <p class="text-xs text-brand-moss">{{ __('Last full backup :ago.', ['ago' => $backupOk->diffForHumans()]) }}@if ($changesOk) {{ __('Changes saved :ago.', ['ago' => $changesOk->diffForHumans()]) }}@endif</p>
                                @else
                                    <p class="text-xs text-brand-moss">{{ __('No backup yet. The first one runs a minute or two after the database first starts.') }}</p>
                                @endif
                                @if (($backup['lost'] ?? '') !== '')
                                    <p class="text-xs text-brand-ink">{{ ucfirst($backup['lost']) }}. {{ __('Restoring to a time after that works as usual.') }}</p>
                                @endif
                                <div class="flex flex-wrap items-end gap-2">
                                    <x-sheet.field :label="__('Time (UTC)')" for="database-restore-at">
                                        <input id="database-restore-at" type="datetime-local" step="1" wire:model="postgresRestoreAt" min="{{ now()->utc()->subDays(7)->format('Y-m-d\TH:i') }}" max="{{ now()->utc()->format('Y-m-d\TH:i:s') }}" class="dply-input mt-0" />
                                    </x-sheet.field>
                                    <x-sheet.button variant="danger" wire:click="restorePostgres" wire:confirm="{{ __('Replace this database with how it was at that time? Changes after it are set aside.') }}" wire:loading.attr="disabled" wire:target="restorePostgres">
                                        <span wire:loading.remove wire:target="restorePostgres">{{ __('Restore') }}</span>
                                        <span wire:loading wire:target="restorePostgres">{{ __('Restoring… this can take a few minutes') }}</span>
                                    </x-sheet.button>
                                </div>
                                @php $restoreState = $site->edgeMeta()['database']['restore'] ?? null; @endphp
                                @if ($postgresRestoreResult)
                                    <x-sheet.note>{{ $postgresRestoreResult }}</x-sheet.note>
                                @elseif (is_array($restoreState) && ($restoreState['status'] ?? '') === 'running')
                                    <p wire:poll.5s class="flex items-center gap-2 text-xs font-semibold text-brand-ink"><x-spinner size="sm" />{{ __('Restoring to :time UTC… this can take a few minutes.', ['time' => str_replace(['T', 'Z'], [' ', ''], $restoreState['target'] ?? '')]) }}</p>
                                @elseif (is_array($restoreState) && ($restoreState['status'] ?? '') === 'done')
                                    <x-sheet.note tone="ok">{{ __('Restored to :time UTC. The app keeps its password and address.', ['time' => str_replace(['T', 'Z'], [' ', ''], $restoreState['target'] ?? '')]) }}</x-sheet.note>
                                @elseif (is_array($restoreState) && ($restoreState['status'] ?? '') === 'failed')
                                    <x-sheet.note tone="danger">{{ __('Restore failed: :error', ['error' => $restoreState['error'] ?? '']) }}</x-sheet.note>
                                @endif
                            </div>
                        </x-sheet.section>
                    @endif

                    {{-- Export and import --}}
                    @php
                        $transfer = is_array($dbRecord['transfer'] ?? null) ? $dbRecord['transfer'] : null;
                        $loadEffect = $databaseEngine === 'mongodb' ? __('Everything in the database is replaced.') : __('Tables in the file are replaced; others are left alone.');
                    @endphp
                    <x-sheet.section :title="__('Export and import')" x-init="$wire.$island('resources-database').loadDatabaseExports()">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <p class="max-w-xl text-xs leading-5 text-brand-moss">{{ __('A full copy of the data as one file (:format), kept beside the backups. Download it, or load it back.', ['format' => ['postgres' => 'pg_dump -Fc', 'mysql' => 'mysqldump, gzip', 'mongodb' => 'mongodump archive'][$databaseEngine] ?? '']) }}</p>
                            <x-sheet.button variant="primary" wire:click="exportDatabase" wire:loading.attr="disabled" wire:target="exportDatabase">{{ __('Export now') }}</x-sheet.button>
                        </div>
                        @if ($transfer && ($transfer['status'] ?? '') === 'running')
                            <p wire:poll.5s="loadDatabaseExports" class="flex items-center gap-2 text-xs font-semibold text-brand-ink"><x-spinner size="sm" />{{ ($transfer['kind'] ?? '') === 'import' ? __('Loading :file…', ['file' => $transfer['file'] ?? '']) : __('Exporting… a large database takes a few minutes.') }}</p>
                        @elseif ($transfer && ($transfer['status'] ?? '') === 'failed')
                            <x-sheet.note tone="danger">{{ ($transfer['kind'] ?? '') === 'import' ? __('Import failed: :error', ['error' => $transfer['error'] ?? '']) : __('Export failed: :error', ['error' => $transfer['error'] ?? '']) }}</x-sheet.note>
                        @elseif ($transfer && ($transfer['status'] ?? '') === 'done' && ($transfer['kind'] ?? '') === 'import')
                            <x-sheet.note tone="ok">{{ __('Loaded :file :ago.', ['file' => $transfer['file'] ?? '', 'ago' => \Illuminate\Support\Carbon::parse($transfer['finished_at'] ?? 'now')->diffForHumans()]) }}</x-sheet.note>
                        @endif
                        <ul class="divide-y divide-brand-ink/10 rounded-xl border border-brand-ink/10 dark:divide-brand-mist/15 dark:border-brand-mist/15">
                            @forelse ($databaseExports ?? [] as $export)
                                <li class="flex flex-wrap items-center gap-3 px-3.5 py-2.5 text-xs">
                                    <span class="min-w-0 flex-1 truncate font-mono text-brand-ink">{{ $export['file'] }}</span>
                                    <span class="text-brand-moss">{{ \Illuminate\Support\Carbon::parse($export['at'])->diffForHumans() }}</span>
                                    <span class="w-16 text-right tabular-nums text-brand-ink">{{ $bytes((int) $export['bytes']) }}</span>
                                    <x-sheet.button href="{{ $export['url'] }}">{{ __('Download') }}</x-sheet.button>
                                    <x-sheet.button wire:click="importDatabase('exports', {{ \Illuminate\Support\Js::from($export['file']) }})" wire:confirm="{{ __('Load this export into the database?').' '.$loadEffect }}">{{ __('Load') }}</x-sheet.button>
                                </li>
                            @empty
                                <li class="px-3.5 py-2.5 text-xs text-brand-moss">{{ $databaseExports === null ? __('Loading…') : __('No exports yet.') }}</li>
                            @endforelse
                        </ul>
                        @if ($databaseEngine !== 'mysql')
                            <div class="grid gap-2 rounded-xl border border-brand-ink/10 px-3.5 py-3 dark:border-brand-mist/15">
                                <div>
                                    <p class="text-xs font-semibold text-brand-ink">{{ __('Import your own dump') }}</p>
                                    <p class="mt-0.5 text-2xs leading-4 text-brand-mist">{{ $databaseEngine === 'postgres' ? __('A pg_dump custom-format file (pg_dump -Fc). It is restored as the app\'s login.') : __('A mongodump --archive file, gzipped or not.') }}</p>
                                </div>
                                <form wire:submit="prepareDatabaseUpload" class="flex flex-wrap items-center gap-2">
                                    <input type="text" wire:model="databaseImportFile" placeholder="{{ $databaseEngine === 'postgres' ? 'app.pgdump' : 'app.archive.gz' }}" class="dply-input mt-0 w-56 font-mono" />
                                    <x-sheet.button type="submit">{{ __('Get upload command') }}</x-sheet.button>
                                </form>
                                @if ($databaseUploadCommand)
                                    <div class="grid gap-2" x-data="{ copied: false }">
                                        <pre class="overflow-x-auto rounded-xl bg-brand-ink px-3.5 py-3 font-mono text-xs text-brand-cream dark:bg-zinc-950">{{ $databaseUploadCommand }}</pre>
                                        <div class="flex flex-wrap items-center gap-2 text-xs">
                                            <x-sheet.button x-on:click="navigator.clipboard.writeText({{ \Illuminate\Support\Js::from($databaseUploadCommand) }}); copied = true; setTimeout(() => copied = false, 1200)" x-text="copied ? '{{ __('Copied') }}' : '{{ __('Copy') }}'"></x-sheet.button>
                                            <span class="text-brand-moss">{{ __('Run it where the file is (the link works for an hour), then:') }}</span>
                                            <x-sheet.button variant="primary" wire:click="importDatabase('imports', {{ \Illuminate\Support\Js::from(trim($databaseImportFile)) }})" wire:confirm="{{ __('Load the uploaded file into the database?').' '.$loadEffect }}">{{ __('Load it') }}</x-sheet.button>
                                        </div>
                                    </div>
                                @endif
                            </div>
                        @endif
                    </x-sheet.section>
                </div>

                {{-- Connect --}}
                <div x-show="tab === 'connect'" x-cloak class="grid gap-5">
                    @php
                        $user = 'app';
                        $scheme = ['postgres' => 'postgresql', 'mysql' => 'mysql', 'mongodb' => 'mongodb'][$databaseEngine];
                        $query = ['postgres' => '?sslmode=require', 'mysql' => '?ssl-mode=REQUIRED', 'mongodb' => '?tls=true&authSource=app'][$databaseEngine];
                    @endphp
                    @if ($dbHost === '')
                        <x-sheet.note>{{ __('The address appears after the next deploy starts the database.') }}</x-sheet.note>
                    @else
                        <x-sheet.metrics :cols="2">
                            @foreach ([__('Host') => $dbHost, __('Port') => $dbPort, __('Database') => 'app', __('User') => $user] as $label => $value)
                                <x-sheet.metric :label="$label" x-data="{ copied: false }" title="{{ $value }}">
                                    {{ $value }}
                                    <x-slot:extra>
                                        <x-sheet.button class="mt-2" x-on:click="navigator.clipboard.writeText({{ \Illuminate\Support\Js::from($value) }}); copied = true; setTimeout(() => copied = false, 1200)" x-text="copied ? '{{ __('Copied') }}' : '{{ __('Copy') }}'"></x-sheet.button>
                                    </x-slot:extra>
                                </x-sheet.metric>
                            @endforeach
                            <x-sheet.metric :label="__('Password')" class="col-span-2">
                                <span x-text="password || '••••••••••••••••'"></span>
                                <x-slot:extra>
                                    <div class="mt-2 flex gap-2">
                                        <x-sheet.button x-show="! password" x-on:click="password = await $wire.$island('resources-database').databasePassword()">{{ __('Show') }}</x-sheet.button>
                                        <x-sheet.button x-show="password" x-cloak x-on:click="navigator.clipboard.writeText(password)">{{ __('Copy') }}</x-sheet.button>
                                    </div>
                                </x-slot:extra>
                            </x-sheet.metric>
                        </x-sheet.metrics>
                        <x-sheet.section :title="__('Open a shell')">
                            <pre class="overflow-x-auto rounded-xl bg-brand-ink px-3.5 py-3 font-mono text-xs text-brand-cream dark:bg-zinc-950">{{ match ($databaseEngine) {
                                'postgres' => "psql \"postgresql://app@{$dbHost}:5432/app?sslmode=require\"",
                                'mysql' => "mysql -h {$dbHost} -P 3306 -u app -p --ssl-mode=REQUIRED --tls-sni-servername={$dbHost} app",
                                default => "mongosh \"mongodb://app@{$dbHost}:27017/app?tls=true&authSource=app\"",
                            } }}</pre>
                            <p class="text-2xs leading-4 text-brand-mist">{{ __('It asks for the password above. Connecting wakes the database if it is asleep.') }}</p>
                        </x-sheet.section>
                        <x-sheet.section :title="__('Connection URL')">
                            <pre class="overflow-x-auto rounded-xl bg-brand-sand/40 px-3.5 py-3 font-mono text-xs text-brand-ink dark:bg-zinc-950">{{ $scheme }}://app:<span x-text="password || 'PASSWORD'"></span>{{ '@'.$dbHost.':'.$dbPort.'/app'.$query }}</pre>
                            <p class="text-2xs leading-4 text-brand-mist">{{ __('The app already has this. Deploys set it as DATABASE_URL (MONGODB_URI for MongoDB).') }}</p>
                        </x-sheet.section>

                        @if (in_array($databaseEngine, ['postgres', 'mongodb'], true))
                            <x-sheet.section :title="__('Read-only login')" x-data="{ ro: '' }">
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <p class="max-w-xl text-xs leading-5 text-brand-moss">{{ __('User app_ro can read every table and change nothing. For BI tools like Metabase, or a teammate who only needs to look.') }}</p>
                                    <div class="flex gap-2">
                                        <x-sheet.button x-on:click="ro = await $wire.$island('resources-database').setDatabaseReadonlyLogin(true)">{{ ($dbRecord['readonly'] ?? false) ? __('New password') : __('Turn on') }}</x-sheet.button>
                                        @if ($dbRecord['readonly'] ?? false)
                                            <x-sheet.button variant="danger" x-on:click="ro = ''; $wire.$island('resources-database').setDatabaseReadonlyLogin(false)">{{ __('Turn off') }}</x-sheet.button>
                                        @endif
                                    </div>
                                </div>
                                <pre x-show="ro" x-cloak class="overflow-x-auto rounded-xl bg-brand-sand/40 px-3.5 py-3 font-mono text-xs text-brand-ink dark:bg-zinc-950">{{ $scheme }}://app_ro:<span x-text="ro"></span>{{ '@'.$dbHost.':'.$dbPort.'/app'.$query }}</pre>
                                <x-sheet.note tone="warn" x-show="ro" x-cloak>{{ __('Shown once. Copy it now.') }}</x-sheet.note>
                            </x-sheet.section>
                        @endif
                    @endif
                    <div class="grid gap-2 border-t border-brand-ink/10 pt-5 dark:border-brand-mist/15">
                        @if ($databaseEngine === 'postgres' || $databaseEngine === 'mysql')
                            <x-sheet.section :title="__('Laravel')">
                                <p class="text-xs leading-5 text-brand-moss">{{ __('The next deploy sets DB_CONNECTION, the host, the password, and DATABASE_URL.') }}</p>
                                <pre class="overflow-x-auto rounded-xl bg-brand-sand/40 px-3.5 py-3 font-mono text-xs text-brand-ink dark:bg-zinc-950">DB::table('users')->count();</pre>
                            </x-sheet.section>
                            <x-sheet.section :title="__('Rails')" class="mt-3">
                                <p class="text-xs leading-5 text-brand-moss">{{ __('The next deploy sets DATABASE_URL. ActiveRecord uses it.') }}</p>
                                <pre class="overflow-x-auto rounded-xl bg-brand-sand/40 px-3.5 py-3 font-mono text-xs text-brand-ink dark:bg-zinc-950">User.count</pre>
                            </x-sheet.section>
                        @elseif ($databaseEngine === 'mongodb')
                            <x-sheet.section :title="__('Node')">
                                <p class="text-xs leading-5 text-brand-moss">{{ __('The next deploy sets MONGODB_URI (also MONGO_URL) and MONGODB_DATABASE.') }}</p>
                                <pre class="overflow-x-auto rounded-xl bg-brand-sand/40 px-3.5 py-3 font-mono text-xs text-brand-ink dark:bg-zinc-950">{{ "import { MongoClient } from 'mongodb';
const db = new MongoClient(process.env.MONGODB_URI).db();
await db.collection('notes').countDocuments();" }}</pre>
                            </x-sheet.section>
                        @elseif ($databaseEngine === 'sql')
                            <p class="text-xs text-brand-moss">{{ __('The next deploy sets DB_CONNECTION to sqlite and DB_DATABASE to /tmp/database.sqlite.') }}</p>
                        @else
                            <p class="text-xs text-brand-moss">{{ __('Pick a database first.') }}</p>
                        @endif
                    </div>
                </div>
            @endif

            @if ($databaseEngine !== 'none')
                {{-- Settings --}}
                <div x-show="tab === 'settings'" x-cloak class="grid gap-5">
                    <div>
                        <x-sheet.toggle wire:model.live="migrateOnBoot" :label="__('Run migrations when a container starts')" :help="__('Laravel: migrate --force --isolated. Rails: db:prepare.')" />
                    </div>
                    @if ($site->isLaravelFrameworkDetected() || $site->isRailsFrameworkDetected())
                        <x-sheet.section :title="__('Tools')">
                            <div class="flex flex-wrap gap-2">
                                <x-sheet.button wire:click="runDatabaseCommand('migrate')" wire:loading.attr="disabled" wire:target="runDatabaseCommand,confirmDatabaseCommand">{{ __('Migrate') }}</x-sheet.button>
                                <x-sheet.button wire:click="runDatabaseCommand('status')" wire:loading.attr="disabled" wire:target="runDatabaseCommand,confirmDatabaseCommand">{{ __('Status') }}</x-sheet.button>
                                <x-sheet.button wire:click="runDatabaseCommand('seed')" wire:loading.attr="disabled" wire:target="runDatabaseCommand,confirmDatabaseCommand">{{ __('Seed') }}</x-sheet.button>
                                @if ($site->isRailsFrameworkDetected())
                                    <x-sheet.button wire:click="runDatabaseCommand('prepare')" wire:loading.attr="disabled" wire:target="runDatabaseCommand,confirmDatabaseCommand">{{ __('Prepare') }}</x-sheet.button>
                                @endif
                                <x-sheet.button wire:click="runDatabaseCommand('rollback')" wire:loading.attr="disabled" wire:target="runDatabaseCommand,confirmDatabaseCommand">{{ __('Roll back') }}</x-sheet.button>
                            </div>
                            <p wire:loading wire:target="runDatabaseCommand,confirmDatabaseCommand" class="text-xs text-brand-moss">{{ __('Running…') }}</p>
                            @if ($pendingDatabaseCommand === 'rollback')
                                <x-sheet.danger :title="__('Roll back the last migration on this database?')">
                                    <div class="flex gap-2">
                                        <x-sheet.button variant="danger" wire:click="confirmDatabaseCommand">{{ __('Roll back') }}</x-sheet.button>
                                        <x-sheet.button wire:click="$set('pendingDatabaseCommand', '')">{{ __('Cancel') }}</x-sheet.button>
                                    </div>
                                </x-sheet.danger>
                            @endif
                            @if ($databaseCommandOutput !== '')
                                <pre class="max-h-48 overflow-auto whitespace-pre-wrap rounded-xl bg-brand-sand/40 px-3.5 py-3 font-mono text-xs text-brand-ink dark:bg-zinc-950">{{ $databaseCommandOutput }}</pre>
                            @endif
                        </x-sheet.section>
                    @endif
                    <x-sheet.note>
                        {{ __('dply :engine in :region. A disk only grows; pick more later if you need it.', ['engine' => ['mongodb' => 'MongoDB', 'mysql' => 'MySQL'][$databaseEngine] ?? 'Postgres', 'region' => \App\Modules\Providers\Valkey\ValkeyRegions::get(\App\Modules\Edge\Support\DataRegion::forSite($site))['label']]) }}
                        @if ($postgresSuspend === -1)
                            {{ __('Stays on bills every hour. Hours awake is only the estimate.') }}
                        @else
                            {{ __('Day and month assume :hours hours awake. That number does not change the database.', ['hours' => $postgresAwakeHours]) }}
                        @endif
                    </x-sheet.note>
                    @if (! $dbDply)
                        <x-sheet.note>{{ __('SQLite is a file at /tmp/database.sqlite. It is saved while the app runs and restored when the app wakes.') }}</x-sheet.note>
                    @endif
                </div>
            @endif

            {{-- How it works --}}
            <div x-show="tab === 'how'" x-cloak class="grid gap-5">
                @if ($dbDply)
                    @php
                        $plan = $postgresSizes[$postgresSize] ?? null;
                        $sleepLabel = $postgresSuspend === -1 ? null : __($postgresSleeps[$postgresSuspend] ?? '5 minutes');
                        $howCards = [
                            [__('Connect'), __('Save and redeploy. The deploy sets :vars on the app, so there is nothing to copy. Connections use TLS only.', ['vars' => $databaseEngine === 'mongodb' ? 'MONGODB_URI' : ($databaseEngine === 'mysql' ? 'DB_CONNECTION, DB_HOST, DB_PASSWORD, DATABASE_URL' : 'DB_CONNECTION, DB_HOST, DB_PASSWORD, DATABASE_URL')]).($databaseEngine === 'mysql' ? ' '.__('The mysql command line needs --tls-sni-servername=<host>, or the database id as the user.') : ''), 'connect'],
                            [__('Sleep and wake'), $sleepLabel ? __('It sleeps :sleep after the last connection closes and wakes on the next one in about a third of a second. The data stays on its disk.', ['sleep' => $sleepLabel]) : __('It stays on. Pick a sleep time under Resources to pay only while it is used.'), null],
                            [__('Backups'), __('Every change is streamed to storage and a full backup runs daily. Restore to any second in the last 7 days, or export a copy to download.'), 'backups'],
                            [__('Tuned for its size'), match ($databaseEngine) {
                                'postgres' => __('Memory settings follow the plan: a quarter of it for shared buffers, working memory per query from the rest, the write-ahead log capped at a quarter of the disk and compressed. Query statistics are always on.'),
                                'mysql' => __('The buffer pool gets half the memory, and per-query statistics are kept by performance_schema.'),
                                default => __('The WiredTiger cache is sized to the memory the plan has.'),
                            }, null],
                            [__('See what is slow'), __('Queries lists the queries that take the most time and what is running now. Health flags a full disk, tables that need an index, and indexes nothing uses. None of it wakes the database until you refresh.'), 'queries'],
                            [__('Look at the data'), __('Console runs read-only queries from here. :ro', ['ro' => in_array($databaseEngine, ['postgres', 'mongodb'], true) ? __('A read-only login for BI tools is under Connect.') : '']), 'console'],
                        ];
                    @endphp
                    @if ($plan)
                        <div class="grid gap-3">
                            <div class="grid gap-2 sm:grid-cols-3">
                                <x-sheet.cost :label="__('Compute')">${{ $plan['hour'] }}<span class="text-xs font-normal text-brand-moss">/{{ __('hour awake') }}</span></x-sheet.cost>
                                <x-sheet.cost :label="__('A month')">${{ $plan['month'] }}</x-sheet.cost>
                                <x-sheet.cost :label="__('Storage')">${{ $postgresGigabyte }}<span class="text-xs font-normal text-brand-moss">/GB {{ __('a month') }}</span></x-sheet.cost>
                            </div>
                            <p class="text-xs leading-5 text-brand-moss">
                                @if ($postgresSuspend === -1)
                                    {{ __('Stays on, so every hour bills. Storage bills while it sleeps too.') }}
                                @elseif ($postgresSize === '0.25')
                                    {{ __(':memory at :hours hours awake a day. Storage bills while it sleeps too.', ['memory' => $plan['memory'], 'hours' => $postgresAwakeHours]) }}
                                @else
                                    {{ __('Starts at 1 GB and grows to :memory under load; the month is at full size for :hours hours awake a day. Storage bills while it sleeps too.', ['memory' => $plan['memory'], 'hours' => $postgresAwakeHours]) }}
                                @endif
                            </p>
                        </div>
                    @endif
                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach ($howCards as $i => [$title, $body, $goto])
                            <div class="flex flex-col rounded-xl border border-brand-ink/10 px-3.5 py-3 dark:border-brand-mist/15">
                                <p class="flex items-center gap-2 text-sm font-semibold text-brand-ink"><span class="flex h-5 w-5 items-center justify-center rounded-full bg-brand-sage/20 text-2xs font-semibold text-brand-ink">{{ $i + 1 }}</span>{{ $title }}</p>
                                <p class="mt-2 flex-1 text-xs leading-5 text-brand-moss">{{ $body }}</p>
                                @if ($goto && isset($tabs[$goto]))
                                    <x-sheet.button class="mt-3 self-start" x-on:click="tab = '{{ $goto }}'">{{ __('Open :tab', ['tab' => $tabs[$goto]]) }}</x-sheet.button>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @elseif ($databaseEngine === 'sql')
                    <x-sheet.section :title="__('SQLite')">
                        <div class="grid gap-2 rounded-xl border border-brand-ink/10 px-3.5 py-3 text-xs leading-5 text-brand-moss dark:border-brand-mist/15">
                            <p>{{ __('SQLite is a file inside the app. It is saved while the app runs and restored when the app wakes. One instance serves the app so that file stays consistent.') }}</p>
                            <p>{{ __('The next deploy sets DB_CONNECTION to sqlite and DB_DATABASE to /tmp/database.sqlite.') }}</p>
                        </div>
                    </x-sheet.section>
                @else
                    <x-sheet.note>{{ __('No database is attached. Pick Postgres, MySQL, or SQLite, then save and redeploy.') }}</x-sheet.note>
                @endif
            </div>
        </x-sheet.body>
    </div>
</x-sheet>
