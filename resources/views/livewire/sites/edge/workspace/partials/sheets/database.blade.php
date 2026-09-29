@if ($databaseVisible)
    <x-sheet name="resources-database" maxWidth="lg">
        <x-sheet.header :title="__('Database')" />

        <x-sheet.body>
            @php
                $dplyEngine = in_array($databaseEngine, ['postgres', 'mongodb', 'mysql'], true);
                $postgresLocked = ! $cardOnFile;
                // A suggested resize (EdgeDatabaseResize): never automatic, since it restarts the database.
                $resize = $dplyEngine ? \App\Modules\Edge\Support\EdgeDatabaseResize::suggestion($site) : null;
                $resizeScheduled = $dplyEngine ? ($site->edgeMeta()['database']['resize_scheduled'] ?? null) : null;
                $sizeLabel = fn (string $key): string => isset(\App\Modules\Edge\Services\EdgeAppDatabase::POSTGRES_SIZES[$key]) ? \App\Modules\Edge\Services\EdgeAppDatabase::POSTGRES_SIZES[$key]['cpu'].' · '.\App\Modules\Edge\Services\EdgeAppDatabase::POSTGRES_SIZES[$key]['memory'] : $key;
                $canResize = auth()->user()?->can('update', $site) ?? false;
            @endphp
            @if (is_array($resizeScheduled))
                <x-sheet.note tone="warn">
                    {{ __('Resizing to :size at :time. The database restarts then; open connections drop once.', ['size' => $sizeLabel((string) $resizeScheduled['size']), 'time' => \Illuminate\Support\Carbon::createFromTimestamp((int) $resizeScheduled['at'], $site->organization?->timezone ?: 'UTC')->format('D H:i T')]) }}
                    @if ($canResize)
                        <button type="button" wire:click="cancelDatabaseResize" class="ms-2 font-semibold underline">{{ __('Cancel') }}</button>
                    @endif
                </x-sheet.note>
            @elseif ($resize)
                <div class="grid gap-2 rounded-xl border border-brand-forest/40 bg-brand-forest/5 p-3.5" data-resize-suggestion>
                    <p class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ $resize['direction'] === 'up' ? __('Suggested: a bigger size') : __('Suggested: a smaller size') }}</p>
                    <p class="text-sm font-semibold text-brand-ink">{{ $sizeLabel($resize['from']) }} → {{ $sizeLabel($resize['size']) }}
                        @isset($postgresSizes[$resize['size']]['month'], $postgresSizes[$resize['from']]['month'])
                            <span class="font-normal text-brand-moss">· {{ __('$:from → $:to/mo', ['from' => $postgresSizes[$resize['from']]['month'], 'to' => $postgresSizes[$resize['size']]['month']]) }}</span>
                        @endisset
                    </p>
                    <p class="text-xs text-brand-moss">{{ $resize['reason'] }}</p>
                    <p class="text-2xs text-brand-mist">{{ __('Resizing restarts the database: open connections drop for a few seconds, and it starts at the new size on the next connection.') }}</p>
                    @if ($canResize)
                        <div class="flex flex-wrap items-center gap-2">
                            <x-sheet.button variant="primary" wire:click="resizeDatabaseNow" wire:loading.attr="disabled" wire:target="resizeDatabaseNow">{{ __('Resize now') }}</x-sheet.button>
                            <x-sheet.button wire:click="resizeDatabaseTonight">{{ __('Resize tonight (:time)', ['time' => \App\Modules\Edge\Support\EdgeDatabaseResize::tonight($site)->format('H:i T')]) }}</x-sheet.button>
                            <button type="button" wire:click="dismissDatabaseResize" class="text-xs font-semibold text-brand-moss hover:text-brand-ink">{{ __('Dismiss for :days days', ['days' => \App\Modules\Edge\Support\EdgeDatabaseResize::DISMISS_DAYS]) }}</button>
                        </div>
                    @endif
                </div>
            @endif
            @php
                $engineNames = ['none' => __('None'), 'postgres' => __('Postgres'), 'mongodb' => __('MongoDB'), 'mysql' => __('MySQL'), 'sql' => __('SQLite')];
                $engineHelp = [
                    'none' => __('No database attached.'),
                    'postgres' => __('dply :engine · :region', ['engine' => 'Postgres', 'region' => \App\Modules\Providers\Valkey\ValkeyRegions::get(\App\Modules\Edge\Support\DataRegion::forSite($site))['label']]),
                    'mongodb' => __('dply :engine · :region', ['engine' => 'MongoDB', 'region' => \App\Modules\Providers\Valkey\ValkeyRegions::get(\App\Modules\Edge\Support\DataRegion::forSite($site))['label']]),
                    'mysql' => __('dply :engine · :region', ['engine' => 'MySQL', 'region' => \App\Modules\Providers\Valkey\ValkeyRegions::get(\App\Modules\Edge\Support\DataRegion::forSite($site))['label']]),
                    'sql' => __('A file inside the app, saved while it runs and restored when it wakes.'),
                ];
            @endphp
            <x-sheet.field :label="__('Database')">
                <x-sheet.options id="database-engine">
                    @foreach ($engineNames as $engine => $label)
                        @php
                            $locked = $engine !== 'sql' && $engine !== 'none' && (! $dplyDatabases || ! $cardOnFile);
                            $why = $engine !== 'sql' && $engine !== 'none' ? (! $dplyDatabases ? __('Coming soon') : (! $cardOnFile ? __('Add a card') : null)) : null;
                        @endphp
                        <x-sheet.option
                            wire:click="selectDatabase('{{ $engine }}')"
                            data-engine="{{ $engine }}"
                            :selected="$databaseEngine === $engine"
                            :disabled="$locked"
                            :title="$label"
                            :description="$why ?? $engineHelp[$engine]"
                        />
                    @endforeach
                </x-sheet.options>
            </x-sheet.field>

            @if ($dplyEngine)
                @php $trialCap = \App\Modules\Edge\Support\EdgeTrialLimits::applies($site->organization); @endphp
                <x-sheet.field :label="__('Size')" :help="$trialCap ? __('During the trial a database runs the smallest size and sleeps when idle.') : null">
                    <x-sheet.options id="database-size">
                        @foreach ($postgresSizes as $key => $size)
                            <x-sheet.option
                                wire:click="selectPostgresSize('{{ $key }}')"
                                :selected="$postgresSize === (string) $key"
                                :disabled="$postgresLocked || ($trialCap && (string) $key !== \App\Modules\Edge\Support\EdgeTrialLimits::databaseSize())"
                                :title="$size['cpu'].' · '.$size['memory']"
                                :meta="$trialCap && (string) $key !== \App\Modules\Edge\Support\EdgeTrialLimits::databaseSize() ? __('Available after your trial') : '$'.$size['month'].'/mo'"
                            />
                        @endforeach
                    </x-sheet.options>
                </x-sheet.field>

                @php $alwaysOn = \App\Modules\Edge\Support\EdgeDplyDatabase::alwaysOn((string) $postgresSize); @endphp
                <x-sheet.field :label="__('Sleep')" :help="$alwaysOn ? __('Sizes of 1 vCPU and up stay on.') : ($postgresSuspend === -1 ? null : __('After :time idle', ['time' => __($postgresSleeps[$postgresSuspend] ?? '')]))">
                    <x-sheet.segmented id="database-sleep">
                        @foreach ($postgresSleeps as $seconds => $label)
                            <x-sheet.segment wire:click="selectPostgresSuspend({{ $seconds }})" :active="$postgresSuspend === $seconds" :disabled="$postgresLocked || ($trialCap && $seconds === -1) || ($alwaysOn && $seconds !== -1)" :title="$trialCap && $seconds === -1 ? __('Available after your trial') : ($alwaysOn && $seconds !== -1 ? __('Sizes of 1 vCPU and up stay on') : null)">{{ __($label) }}</x-sheet.segment>
                        @endforeach
                    </x-sheet.segmented>
                </x-sheet.field>

                @php
                    // The running database's disk: smaller ones can't be picked, bigger ones ask first (Resources::selectPostgresDisk).
                    $runningDisk = (string) ($site->edgeMeta()['database']['remote_id'] ?? '') !== '' ? (int) ($site->edgeMeta()['database']['disk_gb'] ?? 0) : 0;
                @endphp
                <x-sheet.field :label="__('Disk')" :help="$runningDisk > 0 ? __('A database disk only grows.') : null">
                    <x-sheet.segmented id="postgres-disk">
                        @foreach ($postgresDisks as $gb => $label)
                            <x-sheet.segment wire:click="selectPostgresDisk({{ $gb }})" :active="$postgresDisk === $gb" :disabled="$postgresLocked || $gb < $runningDisk">{{ __($label) }}</x-sheet.segment>
                        @endforeach
                    </x-sheet.segmented>
                    @if ($growDiskTo !== null)
                        <div class="mt-2 grid gap-2 rounded-xl border border-amber-500/40 bg-amber-500/10 p-3 text-sm text-brand-ink" data-disk-grow>
                            <p>{{ __('Grow the disk to :gb GB? A database disk can’t shrink afterwards. Disk goes from $:from to $:to a month.', [
                                'gb' => $growDiskTo,
                                'from' => number_format((float) $postgresGigabyte * $postgresDisk, 2),
                                'to' => number_format((float) $postgresGigabyte * $growDiskTo, 2),
                            ]) }}</p>
                            <div class="flex gap-2">
                                <x-sheet.button variant="primary" wire:click="confirmDiskGrow">{{ __('Grow to :gb GB', ['gb' => $growDiskTo]) }}</x-sheet.button>
                                <x-sheet.button wire:click="cancelDiskGrow">{{ __('Keep :gb GB', ['gb' => $postgresDisk]) }}</x-sheet.button>
                            </div>
                        </div>
                    @endif
                </x-sheet.field>

                @if ($postgresSuspend !== -1)
                    <x-sheet.field :label="__('Awake')" for="postgres-awake">
                        <div class="flex items-center gap-2">
                            <input id="postgres-awake" type="number" min="0" max="24" wire:model.live.debounce.400ms="awakeHours" @disabled($postgresLocked) class="dply-input mt-0 w-20 disabled:opacity-60" />
                            <span class="text-xs text-brand-moss">{{ __('hours a day') }}</span>
                        </div>
                    </x-sheet.field>
                @endif

                <x-sheet.cost :label="__('Compute $:compute · disk $:disk', ['compute' => $postgresSizes[$postgresSize]['month'], 'disk' => number_format((float) $postgresGigabyte * $postgresDisk, 2)])" :sub="__(':second/s ($:hour/hour) awake · disk $:gigabyte/GB-month', ['second' => $postgresSizes[$postgresSize]['second'], 'hour' => $postgresSizes[$postgresSize]['hour'], 'gigabyte' => $postgresGigabyte])">{{ __('About $:total/mo', ['total' => number_format((float) str_replace(',', '', $postgresSizes[$postgresSize]['month']) + (float) $postgresGigabyte * $postgresDisk, 2)]) }}</x-sheet.cost>
                @if ($databaseEngine === 'postgres' && $postgresSuspend !== -1 && $postgresSize !== '0.25')
                    <p class="-mt-3 text-2xs text-brand-mist">{{ __('Uses spare CPU on its node when there is some.') }}</p>
                @endif
            @endif

            @error('database')
                <x-sheet.note tone="danger">{{ $message }}</x-sheet.note>
            @enderror

            @if (! $cardOnFile)
                <x-sheet.note tone="warn">
                    {{ __('Add a card before starting a database. It is billed to that card. SQLite does not need one.') }}
                    @if ($site->organization)
                        <a href="{{ route('billing.show', $site->organization) }}" class="font-semibold underline">{{ __('Billing') }}</a>
                    @endif
                </x-sheet.note>
            @endif

            <div class="grid gap-2">
                <x-sheet.row :title="__('How to use')" x-on:click="$dispatch('database-tab', 'how'); $dispatch('open-modal', 'resources-app-database')" />
                @if ($databaseEngine !== 'none')
                    <x-sheet.row :title="$dplyEngine ? __('Stats & backups') : __('Tools')" x-on:click="$dispatch('database-tab', {{ \Illuminate\Support\Js::from($dplyEngine ? 'overview' : 'settings') }}); $dispatch('open-modal', 'resources-app-database')" />
                @endif
            </div>
        </x-sheet.body>

        @if ($databaseEngine !== $savedDatabase)
            {{-- Switching engines starts (and bills) a database or drops one: never on a dropdown change alone. --}}
            <x-sheet.footer>
                <div class="min-w-0">
                    <p class="font-semibold text-brand-ink">
                        @if ($databaseEngine === 'none')
                            {{ __('Remove the database from this app?') }}
                        @else
                            {{ __('Switch to :engine?', ['engine' => ['postgres' => 'Postgres', 'mongodb' => 'MongoDB', 'mysql' => 'MySQL', 'sql' => 'SQLite'][$databaseEngine] ?? $databaseEngine]) }}
                        @endif
                    </p>
                    @php($dropsDply = in_array($savedDatabase, ['postgres', 'mysql', 'mongodb'], true))
                    @if ($dropsDply)
                        {{-- The gateway deletes the database and its backups on removal (packages/valkey-gateway/database.go). --}}
                        <p class="font-semibold text-rose-700 dark:text-rose-300">{{ __('This deletes the :engine database and all of its backups right away. Export it first if you need the data.', ['engine' => ['postgres' => 'Postgres', 'mongodb' => 'MongoDB', 'mysql' => 'MySQL'][$savedDatabase]]) }}</p>
                    @else
                        <p>{{ __('The app uses it from the next deploy.') }}</p>
                    @endif
                </div>
                <div class="flex gap-2">
                    <x-sheet.button wire:click="discardPending">{{ __('Keep :engine', ['engine' => ['postgres' => 'Postgres', 'mongodb' => 'MongoDB', 'mysql' => 'MySQL', 'sql' => 'SQLite'][$savedDatabase] ?? __('none')]) }}</x-sheet.button>
                    <x-sheet.button :variant="$databaseEngine === 'none' || $dropsDply ? 'danger' : 'primary'" wire:click="saveSettings" wire:loading.attr="disabled" wire:target="saveSettings">{{ $databaseEngine === 'none' ? __('Remove') : __('Confirm') }}</x-sheet.button>
                </div>
            </x-sheet.footer>
        @endif
    </x-sheet>
@endif
