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
    $dbStats = is_array($databaseStats ?? null) ? $databaseStats : null;
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
        'backups' => $dbDply ? __('Backups') : null,
        'connect' => $databaseEngine !== 'none' ? __('Connect') : null,
        'settings' => $databaseEngine !== 'none' ? __('Settings') : null,
        'how' => __('How it works'),
    ]);
    $firstTab = array_key_first($tabs);
@endphp
<x-modal name="resources-app-database" maxWidth="5xl" focusable>
    <div
        class="flex max-h-[88vh] flex-col bg-white dark:bg-zinc-900"
        x-data="{ tab: @js($firstTab), password: '' }"
        x-on:database-tab.window="tab = (Array.isArray($event.detail) ? $event.detail[0] : $event.detail); if (! @js(array_keys($tabs)).includes(tab)) tab = @js($firstTab); @if ($dbDply) $wire.loadDatabaseStatus(); @endif"
    >
        {{-- Header --}}
        <div class="flex flex-wrap items-start justify-between gap-4 border-b border-brand-ink/10 px-6 py-5">
            <div class="min-w-0">
                <p class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Database') }}</p>
                <h2 class="mt-1 flex flex-wrap items-center gap-2 text-lg font-semibold text-brand-ink">
                    {{ $dbDply ? __('dply :engine', ['engine' => $dbName]) : $dbName }}
                    @if ($dbDply)
                        @if ($dbAwake === true)
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-500/10 px-2 py-0.5 text-xs font-semibold text-emerald-700 ring-1 ring-emerald-500/30 dark:text-emerald-300"><span class="h-1.5 w-1.5 animate-pulse rounded-full bg-emerald-500"></span>{{ __('Awake') }}</span>
                        @elseif ($dbAwake === false)
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-brand-sand/50 px-2 py-0.5 text-xs font-semibold text-brand-moss ring-1 ring-brand-ink/10"><span class="h-1.5 w-1.5 rounded-full bg-brand-mist"></span>{{ __('Asleep') }}</span>
                        @else
                            <span wire:loading.delay wire:target="loadDatabaseStatus" class="text-xs font-normal text-brand-moss">{{ __('Checking…') }}</span>
                        @endif
                    @endif
                </h2>
                @if ($dbHost !== '')
                    <p class="mt-1 truncate font-mono text-xs text-brand-moss">{{ $dbHost }}:{{ $dbPort }}</p>
                @endif
            </div>
            <button type="button" x-on:click="$dispatch('close-modal', 'resources-app-database')" class="text-xs font-semibold text-brand-ink underline">{{ __('Close') }}</button>
        </div>

        <div class="flex gap-5 overflow-x-auto border-b border-brand-ink/10 px-6" role="tablist">
            @foreach ($tabs as $key => $label)
                <button type="button" role="tab" x-on:click="tab = '{{ $key }}'" :aria-selected="tab === '{{ $key }}'" :class="tab === '{{ $key }}' ? 'border-brand-sage text-brand-ink' : 'border-transparent text-brand-moss hover:text-brand-ink'" class="-mb-px shrink-0 border-b-2 py-3 text-sm font-semibold">{{ $label }}</button>
            @endforeach
        </div>

        <div class="flex-1 overflow-y-auto px-6 py-5">
            @if ($dbDply && ! $cardOnFile)
                <p class="mb-4 rounded-lg bg-brand-sand/40 px-3 py-2 text-xs text-brand-ink">{{ __('Add a card before starting a database. It is billed to that card.') }}
                    @if ($site->organization)<a href="{{ route('billing.show', $site->organization) }}" class="font-semibold underline">{{ __('Billing') }}</a>@endif
                </p>
            @endif

            @if ($dbDply)
                {{-- Overview --}}
                <div x-show="tab === 'overview'" class="space-y-5">
                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        @foreach ([
                            [__('Status'), $dbAwake === true ? __('Awake') : ($dbAwake === false ? __('Asleep') : '—'), $dbAwake === true ? ($dbSleepAfter > 0 ? __('Sleeps in :time without a connection', ['time' => $duration(max(0, $dbSleepAfter - $dbIdle))]) : __('Stays on')) : ($dbAwake === false ? __('Wakes on the next connection') : __('Status loads when the panel opens'))],
                            [__('Size'), $postgresSizes[$postgresSize]['memory'] ?? '—', ($postgresSizes[$postgresSize]['cpu'] ?? '').' · '.($postgresSuspend === -1 ? __('stays on') : __('sleeps after :time', ['time' => __($postgresSleeps[$postgresSuspend] ?? '5 minutes')]))],
                            [__('Disk'), $dbStats ? $bytes($dbStats['size_bytes']).' / '.$dbDiskGb.' GB' : $dbDiskGb.' GB', $dbStats ? __(':pct% used', ['pct' => number_format(min(100, $dbStats['size_bytes'] / max(1, $dbDiskGb * 1024 ** 3) * 100), 1)]) : __('Load statistics to see what is used')],
                            [__('Awake this month'), $usage ? $duration($usage['awake_seconds']) : '—', $usage ? __(':gb GB-hours of storage', ['gb' => number_format($usage['storage_gb_hours'], 1)]) : ''],
                        ] as [$label, $value, $hint])
                            <div class="rounded-xl border border-brand-ink/10 p-4">
                                <p class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ $label }}</p>
                                <p class="mt-1 text-xl font-semibold tabular-nums text-brand-ink">{{ $value }}</p>
                                <p class="mt-1 text-xs text-brand-moss">{{ $hint }}</p>
                            </div>
                        @endforeach
                    </div>

                    @if ($usage)
                        <div class="rounded-xl border border-brand-ink/10 p-4">
                            <div class="flex items-baseline justify-between gap-3">
                                <p class="text-sm font-semibold text-brand-ink">{{ __('Awake hours, last 14 days') }}</p>
                                <p class="text-xs text-brand-moss">{{ __('Compute bills only while awake') }}</p>
                            </div>
                            <div class="mt-4 flex h-28 items-end gap-1.5">
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
                    @endif

                    <div class="grid gap-3 sm:grid-cols-2">
                        <button type="button" x-on:click="tab = 'backups'" class="rounded-xl border border-brand-ink/10 p-4 text-left hover:border-brand-sage">
                            <p class="text-sm font-semibold text-brand-ink">{{ __('Backups') }}</p>
                            <p class="mt-1 text-xs {{ $dbProblem ? 'font-semibold text-red-700 dark:text-red-400' : 'text-brand-moss' }}">
                                {{ $dbProblem ?? ($dbLastFull ? __('Last full backup :ago. Restore to any second in the last 7 days.', ['ago' => $dbLastFull->diffForHumans()]) : __('The first backup runs a minute or two after the database first starts.')) }}
                            </p>
                        </button>
                        <button type="button" x-on:click="tab = 'connect'" class="rounded-xl border border-brand-ink/10 p-4 text-left hover:border-brand-sage">
                            <p class="text-sm font-semibold text-brand-ink">{{ __('Connect') }}</p>
                            <p class="mt-1 text-xs text-brand-moss">{{ __('Address, login, and a command to open a shell. TLS only.') }}</p>
                        </button>
                    </div>
                </div>

                {{-- Statistics --}}
                <div x-show="tab === 'stats'" x-cloak class="space-y-5">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <p class="max-w-xl text-xs text-brand-moss">{{ __('Read live from the database with the app\'s own login. Loading them wakes it if it is asleep, which counts as awake time.') }}</p>
                        <x-secondary-button type="button" wire:click="loadDatabaseStats" wire:loading.attr="disabled" wire:target="loadDatabaseStats">
                            <span wire:loading.remove wire:target="loadDatabaseStats">{{ $dbStats ? __('Refresh') : __('Load live stats') }}</span>
                            <span wire:loading wire:target="loadDatabaseStats">{{ __('Waking and reading…') }}</span>
                        </x-secondary-button>
                    </div>
                    @if ($databaseStatsError)
                        <p class="rounded-lg bg-red-500/10 px-3 py-2 text-xs font-semibold text-red-700 dark:text-red-300">{{ $databaseStatsError }}</p>
                    @endif
                    @if ($dbStats)
                        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                            @foreach ([
                                [__('Data'), $bytes($dbStats['size_bytes']), __('of :gb GB disk', ['gb' => $dbDiskGb])],
                                [__('Tables'), number_format($dbStats['tables']), __(':rows rows (estimate)', ['rows' => number_format($dbStats['rows'])])],
                                [__('Connections'), $dbStats['connections'].' / '.$dbStats['max_connections'], __('open now')],
                                [__('Cache hit rate'), $dbStats['cache_hit_ratio'] !== null ? $dbStats['cache_hit_ratio'].'%' : '—', __('reads served from memory')],
                                [__('Commits'), number_format($dbStats['commits']), __(':n rolled back', ['n' => number_format($dbStats['rollbacks'])])],
                                [__('Up for'), $duration($dbStats['uptime_seconds']), __('since this wake or restart')],
                                [__('Version'), $dbStats['version'], ''],
                            ] as [$label, $value, $hint])
                                <div class="rounded-xl border border-brand-ink/10 p-4 {{ $label === __('Version') ? 'sm:col-span-2 lg:col-span-1' : '' }}">
                                    <p class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ $label }}</p>
                                    <p class="mt-1 truncate text-xl font-semibold tabular-nums text-brand-ink" title="{{ $value }}">{{ $value }}</p>
                                    <p class="mt-1 text-xs text-brand-moss">{{ $hint }}</p>
                                </div>
                            @endforeach
                        </div>
                        <div class="rounded-xl border border-brand-ink/10">
                            <p class="border-b border-brand-ink/10 px-4 py-3 text-sm font-semibold text-brand-ink">{{ __('Largest tables') }}</p>
                            @forelse ($dbStats['largest'] as $table)
                                <div class="flex items-center gap-3 px-4 py-2 text-xs [&:not(:last-child)]:border-b [&:not(:last-child)]:border-brand-ink/5">
                                    <span class="min-w-0 flex-1 truncate font-mono text-brand-ink">{{ $table['name'] }}</span>
                                    <span class="w-24 text-right tabular-nums text-brand-moss">{{ number_format($table['rows']) }} {{ __('rows') }}</span>
                                    <span class="w-20 text-right tabular-nums font-semibold text-brand-ink">{{ $bytes($table['bytes']) }}</span>
                                    <span class="hidden h-1.5 w-32 overflow-hidden rounded-full bg-brand-ink/10 sm:block"><span class="block h-full rounded-full bg-brand-sage" style="width: {{ $dbStats['largest'][0]['bytes'] > 0 ? $table['bytes'] / $dbStats['largest'][0]['bytes'] * 100 : 0 }}%"></span></span>
                                </div>
                            @empty
                                <p class="px-4 py-3 text-xs text-brand-moss">{{ __('No tables yet. Migrations create them on the next deploy or with Migrate under Settings.') }}</p>
                            @endforelse
                        </div>
                    @elseif (! $databaseStatsError)
                        <div class="rounded-xl border border-dashed border-brand-ink/20 px-4 py-10 text-center text-xs text-brand-moss">
                            {{ $databaseEngine === 'mongodb' ? __('Size, collections, documents, connections, cache hit rate, and the largest collections. Load them when you need them.') : __('Size, tables, connections, cache hit rate, and the largest tables. Load them when you need them.') }}
                        </div>
                    @endif
                </div>

                {{-- Backups --}}
                <div x-show="tab === 'backups'" x-cloak class="space-y-5">
                    <div class="grid gap-3 sm:grid-cols-3">
                        <div class="rounded-xl border border-brand-ink/10 p-4">
                            <p class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Last full backup') }}</p>
                            <p class="mt-1 text-xl font-semibold text-brand-ink">{{ $dbLastFull?->diffForHumans() ?? __('Not yet') }}</p>
                            <p class="mt-1 text-xs text-brand-moss">{{ $dbLastFull ? $dbLastFull->utc()->format('M j, H:i').' UTC' : __('The first runs a minute or two after the database starts') }}</p>
                        </div>
                        <div class="rounded-xl border border-brand-ink/10 p-4">
                            <p class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Changes saved') }}</p>
                            <p class="mt-1 text-xl font-semibold text-brand-ink">{{ $dbLastLog?->diffForHumans() ?? '—' }}</p>
                            <p class="mt-1 text-xs text-brand-moss">{{ __('Every change is streamed to storage') }}</p>
                        </div>
                        <div class="rounded-xl border p-4 {{ $dbProblem ? 'border-red-500/40 bg-red-500/5' : 'border-brand-ink/10' }}">
                            <p class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Health') }}</p>
                            <p class="mt-1 text-xl font-semibold {{ $dbProblem ? 'text-red-700 dark:text-red-400' : 'text-emerald-700 dark:text-emerald-300' }}">{{ $dbProblem ? __('Needs attention') : ($dbLastFull ? __('Healthy') : __('Waiting')) }}</p>
                            <p class="mt-1 text-xs {{ $dbProblem ? 'text-red-700 dark:text-red-400' : 'text-brand-moss' }}">{{ $dbProblem ?? (($dbBackupLive['last_error'] ?? '') !== '' ? $dbBackupLive['last_error'] : __('Kept for 7 days')) }}</p>
                        </div>
                    </div>

                    <div class="rounded-xl border border-brand-ink/10 p-4">
                        <div class="flex items-baseline justify-between gap-3">
                            <p class="text-sm font-semibold text-brand-ink">{{ __('Restore window') }}</p>
                            <p class="text-xs text-brand-moss">{{ $coveredFrom ? __('Any second from :from to now', ['from' => $coveredFrom->utc()->format('M j, H:i').' UTC']) : __('Opens after the first full backup') }}</p>
                        </div>
                        <div class="relative mt-3 h-3 overflow-hidden rounded-full bg-brand-ink/10">
                            <div class="absolute inset-y-0 right-0 rounded-full bg-brand-sage" style="width: {{ $coveragePct }}%"></div>
                        </div>
                        <div class="mt-1 flex justify-between text-2xs text-brand-mist"><span>{{ __('7 days ago') }}</span><span>{{ __('Now') }}</span></div>
                        @if (($dbBackupMeta['lost'] ?? '') !== '')
                            <p class="mt-2 text-xs text-brand-ink">{{ ucfirst($dbBackupMeta['lost']) }}. {{ __('Restoring to a time after that works as usual.') }}</p>
                        @endif
                    </div>

                    @if ($coveredFrom)
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="text-xs font-semibold text-brand-ink">{{ __('Quick pick') }}</span>
                        @foreach (['1 hour ago' => now()->utc()->subHour(), '6 hours ago' => now()->utc()->subHours(6), 'Yesterday, same time' => now()->utc()->subDay()] as $label => $at)
                            @if ($at->gte($coveredFrom))
                                <button type="button" wire:click="$set('postgresRestoreAt', '{{ $at->format('Y-m-d\TH:i:s') }}')" class="rounded-full border border-brand-ink/15 px-3 py-1 text-xs font-semibold text-brand-ink hover:border-brand-sage disabled:opacity-50">{{ __($label) }}</button>
                            @endif
                        @endforeach
                    </div>
                    @endif

                        @if (($site->edgeMeta()['database']['provider'] ?? '') === 'dply' && ($site->edgeMeta()['database']['engine'] ?? '') === $databaseEngine)
                            <div class="rounded-lg border border-brand-ink/10 p-3">
                                <p class="text-xs font-semibold text-brand-ink">{{ __('Restore to a point in time') }}</p>
                                @if ($databaseEngine === 'postgres')
                                    <p class="mt-1 text-xs text-brand-moss">{{ __('Changes are backed up continuously for 7 days. Restoring replaces the data with how it was at that moment (UTC); the data from before the restore is kept aside until the next one.') }}</p>
                                @else
                                    <p class="mt-1 text-xs text-brand-moss">{{ __('Changes are backed up continuously for 7 days. Restoring replaces the data with how it was at that moment (UTC); the data from before the restore is saved as a backup first.') }}</p>
                                @endif
                                @php
                                    $backup = (array) ($site->edgeMeta()['database']['backup'] ?? []);
                                    $backupOk = ($backup['last_ok_at'] ?? '') !== '' ? \Illuminate\Support\Carbon::parse($backup['last_ok_at']) : null;
                                    $changesOk = ($backup['log_ok_at'] ?? '') !== '' ? \Illuminate\Support\Carbon::parse($backup['log_ok_at']) : null;
                                    $backupProblem = \App\Modules\Edge\Support\EdgeDplyDatabase::backupProblem($backup);
                                @endphp
                                @if ($backupProblem)
                                    <p class="mt-1 text-xs font-semibold text-red-700 dark:text-red-400">{{ $backupProblem }}@if ($backupOk) {{ __('The last good full backup was :ago.', ['ago' => $backupOk->diffForHumans()]) }}@endif</p>
                                @elseif ($backupOk)
                                    <p class="mt-1 text-xs text-brand-moss">{{ __('Last full backup :ago.', ['ago' => $backupOk->diffForHumans()]) }}@if ($changesOk) {{ __('Changes saved :ago.', ['ago' => $changesOk->diffForHumans()]) }}@endif</p>
                                @else
                                    <p class="mt-1 text-xs text-brand-moss">{{ __('No backup yet. The first one runs a minute or two after the database first starts.') }}</p>
                                @endif
                                @if (($backup['lost'] ?? '') !== '')
                                    <p class="mt-1 text-xs text-brand-ink">{{ ucfirst($backup['lost']) }}. {{ __('Restoring to a time after that works as usual.') }}</p>
                                @endif
                                <div class="mt-2 flex flex-wrap items-end gap-2">
                                    <label class="text-xs font-semibold text-brand-ink">
                                        {{ __('Time (UTC)') }}
                                        <input type="datetime-local" step="1" wire:model="postgresRestoreAt" min="{{ now()->utc()->subDays(7)->format('Y-m-d\TH:i') }}" max="{{ now()->utc()->format('Y-m-d\TH:i:s') }}" class="mt-1 block rounded-md border border-brand-ink/15 bg-white px-2 py-1 text-xs text-brand-ink dark:bg-zinc-900" />
                                    </label>
                                    <x-secondary-button type="button" wire:click="restorePostgres" wire:confirm="{{ __('Replace this database with how it was at that time? Changes after it are set aside.') }}" wire:loading.attr="disabled" wire:target="restorePostgres">
                                        <span wire:loading.remove wire:target="restorePostgres">{{ __('Restore') }}</span>
                                        <span wire:loading wire:target="restorePostgres">{{ __('Restoring… this can take a few minutes') }}</span>
                                    </x-secondary-button>
                                </div>
                                @php $restoreState = $site->edgeMeta()['database']['restore'] ?? null; @endphp
                                @if ($postgresRestoreResult)
                                    <p class="mt-2 text-xs font-semibold text-brand-ink">{{ $postgresRestoreResult }}</p>
                                @elseif (is_array($restoreState) && ($restoreState['status'] ?? '') === 'running')
                                    <p wire:poll.5s class="mt-2 flex items-center gap-2 text-xs font-semibold text-brand-ink"><x-spinner size="sm" />{{ __('Restoring to :time UTC… this can take a few minutes.', ['time' => str_replace(['T', 'Z'], [' ', ''], $restoreState['target'] ?? '')]) }}</p>
                                @elseif (is_array($restoreState) && ($restoreState['status'] ?? '') === 'done')
                                    <p class="mt-2 text-xs font-semibold text-brand-sage">{{ __('Restored to :time UTC. The app keeps its password and address.', ['time' => str_replace(['T', 'Z'], [' ', ''], $restoreState['target'] ?? '')]) }}</p>
                                @elseif (is_array($restoreState) && ($restoreState['status'] ?? '') === 'failed')
                                    <p class="mt-2 text-xs font-semibold text-red-700 dark:text-red-400">{{ __('Restore failed: :error', ['error' => $restoreState['error'] ?? '']) }}</p>
                                @endif
                            </div>
                        @endif
                </div>

                {{-- Connect --}}
                <div x-show="tab === 'connect'" x-cloak class="space-y-5">
                    @php
                        $user = 'app';
                        $scheme = ['postgres' => 'postgresql', 'mysql' => 'mysql', 'mongodb' => 'mongodb'][$databaseEngine];
                        $query = ['postgres' => '?sslmode=require', 'mysql' => '?ssl-mode=REQUIRED', 'mongodb' => '?tls=true&authSource=app'][$databaseEngine];
                    @endphp
                    @if ($dbHost === '')
                        <p class="text-xs text-brand-moss">{{ __('The address appears after the next deploy starts the database.') }}</p>
                    @else
                        <dl class="grid gap-3 sm:grid-cols-2">
                            @foreach ([__('Host') => $dbHost, __('Port') => $dbPort, __('Database') => 'app', __('User') => $user] as $label => $value)
                                <div class="rounded-xl border border-brand-ink/10 p-3" x-data="{ copied: false }">
                                    <dt class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ $label }}</dt>
                                    <dd class="mt-1 flex items-center justify-between gap-2 font-mono text-xs text-brand-ink">
                                        <span class="truncate">{{ $value }}</span>
                                        <button type="button" x-on:click="navigator.clipboard.writeText(@js($value)); copied = true; setTimeout(() => copied = false, 1200)" class="shrink-0 font-sans font-semibold underline" x-text="copied ? '{{ __('Copied') }}' : '{{ __('Copy') }}'"></button>
                                    </dd>
                                </div>
                            @endforeach
                            <div class="rounded-xl border border-brand-ink/10 p-3 sm:col-span-2">
                                <dt class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Password') }}</dt>
                                <dd class="mt-1 flex items-center justify-between gap-2 font-mono text-xs text-brand-ink">
                                    <span class="truncate" x-text="password || '••••••••••••••••'"></span>
                                    <span class="flex shrink-0 gap-3 font-sans">
                                        <button type="button" x-show="! password" x-on:click="password = await $wire.databasePassword()" class="font-semibold underline">{{ __('Show') }}</button>
                                        <button type="button" x-show="password" x-cloak x-on:click="navigator.clipboard.writeText(password)" class="font-semibold underline">{{ __('Copy') }}</button>
                                    </span>
                                </dd>
                            </div>
                        </dl>
                        <div>
                            <p class="text-sm font-semibold text-brand-ink">{{ __('Open a shell') }}</p>
                            <pre class="mt-2 overflow-x-auto rounded-lg bg-brand-ink px-4 py-3 font-mono text-xs text-brand-cream">{{ match ($databaseEngine) {
                                'postgres' => "psql \"postgresql://app@{$dbHost}:5432/app?sslmode=require\"",
                                'mysql' => "mysql -h {$dbHost} -P 3306 -u app -p --ssl-mode=REQUIRED --tls-sni-servername={$dbHost} app",
                                default => "mongosh \"mongodb://app@{$dbHost}:27017/app?tls=true&authSource=app\"",
                            } }}</pre>
                            <p class="mt-1 text-xs text-brand-moss">{{ __('It asks for the password above. Connecting wakes the database if it is asleep.') }}</p>
                        </div>
                        <div>
                            <p class="text-sm font-semibold text-brand-ink">{{ __('Connection URL') }}</p>
                            <pre class="mt-2 overflow-x-auto rounded-lg bg-brand-sand/40 px-4 py-3 font-mono text-xs text-brand-ink dark:bg-zinc-950">{{ $scheme }}://app:<span x-text="password || 'PASSWORD'"></span>{{ '@'.$dbHost.':'.$dbPort.'/app'.$query }}</pre>
                            <p class="mt-1 text-xs text-brand-moss">{{ __('The app already has this. Deploys set it as DATABASE_URL (MONGODB_URI for MongoDB).') }}</p>
                        </div>
                    @endif
                    <div class="border-t border-brand-ink/10 pt-5">
                @if ($databaseEngine === 'postgres' || $databaseEngine === 'mysql')
                    <p class="text-xs font-semibold text-brand-ink">{{ __('Laravel') }}</p>
                    <p class="mt-1 max-w-xl text-xs text-brand-moss">{{ __('The next deploy sets DB_CONNECTION, the host, the password, and DATABASE_URL.') }}</p>
                    <pre class="mt-2 overflow-x-auto rounded-lg bg-brand-sand/40 p-3 text-xs text-brand-ink dark:bg-zinc-950">DB::table('users')->count();</pre>
                    <p class="mt-4 text-xs font-semibold text-brand-ink">{{ __('Rails') }}</p>
                    <p class="mt-1 max-w-xl text-xs text-brand-moss">{{ __('The next deploy sets DATABASE_URL. ActiveRecord uses it.') }}</p>
                    <pre class="mt-2 overflow-x-auto rounded-lg bg-brand-sand/40 p-3 text-xs text-brand-ink dark:bg-zinc-950">User.count</pre>
                @elseif ($databaseEngine === 'mongodb')
                    <p class="text-xs font-semibold text-brand-ink">{{ __('Node') }}</p>
                    <p class="mt-1 max-w-xl text-xs text-brand-moss">{{ __('The next deploy sets MONGODB_URI (also MONGO_URL) and MONGODB_DATABASE.') }}</p>
                    <pre class="mt-2 overflow-x-auto rounded-lg bg-brand-sand/40 p-3 text-xs text-brand-ink dark:bg-zinc-950">{{ "import { MongoClient } from 'mongodb';
const db = new MongoClient(process.env.MONGODB_URI).db();
await db.collection('notes').countDocuments();" }}</pre>
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
                <div x-show="tab === 'settings'" x-cloak class="space-y-4">
                    <label class="flex items-start gap-2 text-xs text-brand-ink">
                        <input type="checkbox" wire:model.live="migrateOnBoot" class="mt-0.5 rounded border-brand-ink/20" />
                        <span>
                            <span class="block font-semibold">{{ __('Run migrations when a container starts') }}</span>
                            <span class="mt-1 block text-brand-moss">{{ __('Laravel: migrate --force --isolated. Rails: db:prepare.') }}</span>
                        </span>
                    </label>
                    @if ($site->isLaravelFrameworkDetected() || $site->isRailsFrameworkDetected())
                        <div class="border-t border-brand-ink/10 pt-3">
                            <p class="text-xs font-semibold text-brand-ink">{{ __('Tools') }}</p>
                            <div class="mt-2 flex flex-wrap gap-2">
                                <button type="button" wire:click="runDatabaseCommand('migrate')" wire:loading.attr="disabled" wire:target="runDatabaseCommand,confirmDatabaseCommand" class="rounded-md border border-brand-ink/15 px-2.5 py-1.5 text-xs font-semibold text-brand-ink">{{ __('Migrate') }}</button>
                                <button type="button" wire:click="runDatabaseCommand('status')" wire:loading.attr="disabled" wire:target="runDatabaseCommand,confirmDatabaseCommand" class="rounded-md border border-brand-ink/15 px-2.5 py-1.5 text-xs font-semibold text-brand-ink">{{ __('Status') }}</button>
                                <button type="button" wire:click="runDatabaseCommand('seed')" wire:loading.attr="disabled" wire:target="runDatabaseCommand,confirmDatabaseCommand" class="rounded-md border border-brand-ink/15 px-2.5 py-1.5 text-xs font-semibold text-brand-ink">{{ __('Seed') }}</button>
                                @if ($site->isRailsFrameworkDetected())
                                    <button type="button" wire:click="runDatabaseCommand('prepare')" wire:loading.attr="disabled" wire:target="runDatabaseCommand,confirmDatabaseCommand" class="rounded-md border border-brand-ink/15 px-2.5 py-1.5 text-xs font-semibold text-brand-ink">{{ __('Prepare') }}</button>
                                @endif
                                <button type="button" wire:click="runDatabaseCommand('rollback')" wire:loading.attr="disabled" wire:target="runDatabaseCommand,confirmDatabaseCommand" class="rounded-md border border-brand-ink/15 px-2.5 py-1.5 text-xs font-semibold text-brand-ink">{{ __('Roll back') }}</button>
                            </div>
                            <p wire:loading wire:target="runDatabaseCommand,confirmDatabaseCommand" class="mt-2 text-xs text-brand-moss">{{ __('Running…') }}</p>
                            @if ($pendingDatabaseCommand === 'rollback')
                                <div class="mt-3 rounded-lg border border-brand-ink/10 p-3">
                                    <p class="text-xs text-brand-ink">{{ __('Roll back the last migration on this database?') }}</p>
                                    <div class="mt-2 flex gap-2">
                                        <button type="button" wire:click="confirmDatabaseCommand" class="rounded-md bg-brand-ink px-2.5 py-1.5 text-xs font-semibold text-white">{{ __('Roll back') }}</button>
                                        <button type="button" wire:click="$set('pendingDatabaseCommand', '')" class="rounded-md border border-brand-ink/15 px-2.5 py-1.5 text-xs font-semibold text-brand-ink">{{ __('Cancel') }}</button>
                                    </div>
                                </div>
                            @endif
                            @if ($databaseCommandOutput !== '')
                                <pre class="mt-3 max-h-48 overflow-auto whitespace-pre-wrap rounded-lg bg-brand-sand/40 p-3 text-xs text-brand-ink dark:bg-zinc-950">{{ $databaseCommandOutput }}</pre>
                            @endif
                        </div>
                    @endif
                        <p class="text-xs text-brand-moss">
                            {{ __('dply :engine in New York. A disk only grows; pick more later if you need it.', ['engine' => ['mongodb' => 'MongoDB', 'mysql' => 'MySQL'][$databaseEngine] ?? 'Postgres']) }}
                            @if ($postgresSuspend === -1)
                                {{ __('Stays on bills every hour. Hours awake is only the estimate.') }}
                            @else
                                {{ __('Day and month assume :hours hours awake. That number does not change the database.', ['hours' => $postgresAwakeHours]) }}
                            @endif
                        </p>
                    @if (! $dbDply)
                        <p class="text-xs text-brand-moss">{{ __('SQLite is a file at /tmp/database.sqlite. It is saved while the app runs and restored when the app wakes.') }}</p>
                    @endif
                </div>
            @endif

            {{-- How it works --}}
            <div x-show="tab === 'how'" x-cloak>
                @if ($databaseEngine === 'postgres')
                    <ol class="list-decimal space-y-1 pl-4 text-xs text-brand-ink">
                        <li>{{ __('Save and redeploy. The next deploy sets the database address on the app.') }}</li>
                        @if ($postgresPlan === 'awake')
                            <li>{{ __('This plan stays on at :memory, about $:hour/hour, $:day/day, $:month/month.', ['memory' => $postgresSizes[$postgresSize]['memory'], 'hour' => $postgresSizes[$postgresSize]['hour'], 'day' => $postgresSizes[$postgresSize]['day'], 'month' => $postgresSizes[$postgresSize]['month']]) }}</li>
                        @elseif ($postgresSize === '0.25')
                            <li>{{ __('This plan sleeps :sleep after the last connection. 1 GB is about $:hour/hour, $:day/day, $:month/month at :hours hours awake.', ['sleep' => __($postgresSleeps[$postgresSuspend]), 'hour' => $postgresSizes[$postgresSize]['hour'], 'day' => $postgresSizes[$postgresSize]['day'], 'month' => $postgresSizes[$postgresSize]['month'], 'hours' => $postgresAwakeHours]) }}</li>
                        @else
                            <li>{{ __('This plan sleeps :sleep after the last connection. It starts at 1 GB and grows to :memory, about $:hour/hour, $:day/day, $:month/month at full size for :hours hours awake.', ['sleep' => __($postgresSleeps[$postgresSuspend]), 'memory' => $postgresSizes[$postgresSize]['memory'], 'hour' => $postgresSizes[$postgresSize]['hour'], 'day' => $postgresSizes[$postgresSize]['day'], 'month' => $postgresSizes[$postgresSize]['month'], 'hours' => $postgresAwakeHours]) }}</li>
                        @endif
                        <li>{{ __('Storage is about $:gigabyte/GB each month, including while compute sleeps. Connections require TLS.', ['gigabyte' => $postgresGigabyte]) }}</li>
                    </ol>
                @elseif ($databaseEngine === 'mongodb')
                    <ol class="list-decimal space-y-1 pl-4 text-xs text-brand-ink">
                        <li>{{ __('Save and redeploy. The next deploy sets MONGODB_URI on the app.') }}</li>
                        <li>{{ __('It sleeps after the last connection and wakes on the next one; data stays on its disk.') }}</li>
                        <li>{{ __('Disk is billed each month whether it is awake or asleep. Connections require TLS. Changes are backed up continuously; restore to any second in the last 7 days.') }}</li>
                    </ol>
                @elseif ($databaseEngine === 'mysql')
                    <ol class="list-decimal space-y-1 pl-4 text-xs text-brand-ink">
                        <li>{{ __('Save and redeploy. The next deploy sets DB_CONNECTION, the host, the password, and DATABASE_URL.') }}</li>
                        <li>{{ __('It sleeps after the last connection and wakes on the next one; data stays on its disk.') }}</li>
                        <li>{{ __('Disk is billed each month whether it is awake or asleep. Connections require TLS. The mysql command line needs --tls-sni-servername=<host>, or the database id as the user. Changes are backed up continuously; restore to any second in the last 7 days.') }}</li>
                    </ol>
                @elseif ($databaseEngine === 'sql')
                    <p class="text-xs text-brand-ink">{{ __('SQLite is a file inside the app. It is saved while the app runs and restored when the app wakes. One instance serves the app so that file stays consistent.') }}</p>
                @else
                    <p class="text-xs text-brand-ink">{{ __('No database is attached. Pick Postgres, MySQL, or SQLite, then save and redeploy.') }}</p>
                @endif
                @if ($databaseEngine === 'none' || $databaseEngine === 'sql')
                    <div class="mt-4">
                @if ($databaseEngine === 'postgres' || $databaseEngine === 'mysql')
                    <p class="text-xs font-semibold text-brand-ink">{{ __('Laravel') }}</p>
                    <p class="mt-1 max-w-xl text-xs text-brand-moss">{{ __('The next deploy sets DB_CONNECTION, the host, the password, and DATABASE_URL.') }}</p>
                    <pre class="mt-2 overflow-x-auto rounded-lg bg-brand-sand/40 p-3 text-xs text-brand-ink dark:bg-zinc-950">DB::table('users')->count();</pre>
                    <p class="mt-4 text-xs font-semibold text-brand-ink">{{ __('Rails') }}</p>
                    <p class="mt-1 max-w-xl text-xs text-brand-moss">{{ __('The next deploy sets DATABASE_URL. ActiveRecord uses it.') }}</p>
                    <pre class="mt-2 overflow-x-auto rounded-lg bg-brand-sand/40 p-3 text-xs text-brand-ink dark:bg-zinc-950">User.count</pre>
                @elseif ($databaseEngine === 'mongodb')
                    <p class="text-xs font-semibold text-brand-ink">{{ __('Node') }}</p>
                    <p class="mt-1 max-w-xl text-xs text-brand-moss">{{ __('The next deploy sets MONGODB_URI (also MONGO_URL) and MONGODB_DATABASE.') }}</p>
                    <pre class="mt-2 overflow-x-auto rounded-lg bg-brand-sand/40 p-3 text-xs text-brand-ink dark:bg-zinc-950">{{ "import { MongoClient } from 'mongodb';
const db = new MongoClient(process.env.MONGODB_URI).db();
await db.collection('notes').countDocuments();" }}</pre>
                @elseif ($databaseEngine === 'sql')
                    <p class="text-xs text-brand-moss">{{ __('The next deploy sets DB_CONNECTION to sqlite and DB_DATABASE to /tmp/database.sqlite.') }}</p>
                @else
                    <p class="text-xs text-brand-moss">{{ __('Pick a database first.') }}</p>
                @endif
                    </div>
                @endif
            </div>
        </div>
    </div>
</x-modal>
