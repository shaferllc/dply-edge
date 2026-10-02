@if ($databaseVisible)
    <x-sheet name="resources-database" maxWidth="lg">
        @if ($databaseEngine === 'sql')
            <x-sheet.header :eyebrow="__('Database · in the app')" :title="__('SQLite')">
                {{ __('A file inside the app. It is saved while the app runs and restored when it wakes.') }}
            </x-sheet.header>
        @else
            <x-sheet.header :title="__('Database')" />
        @endif

        <x-sheet.body>
            @php
                $dplyEngine = in_array($databaseEngine, ['postgres', 'mongodb', 'mysql'], true);
                $postgresLocked = ! $cardOnFile;
            @endphp
            @if ($dplyEngine)
                @include('livewire.sites.edge.workspace.partials.database-resize', ['resizeOf' => $site, 'resizeId' => null])
            @endif
            {{-- The app's primary database (DplyDatabases), or SQLite in the app. Add more from Add resource → Database;
                 there is no engine switch here: Detach or Delete, then add another. --}}
            @php $primaryDatabase = $appDatabases->first(fn ($d) => (bool) $d->attached_primary); @endphp
            @if ($dplyEngine && $primaryDatabase)
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-sm font-bold text-brand-ink">{{ $primaryDatabase->name }}</p>
                        <p class="text-xs text-brand-moss">{{ ['postgres' => 'Postgres', 'mongodb' => 'MongoDB', 'mysql' => 'MySQL'][$primaryDatabase->engine] ?? $primaryDatabase->engine }} · {{ \App\Modules\Providers\Valkey\ValkeyRegions::get($primaryDatabase->region)['label'] ?? $primaryDatabase->region }} · {{ __('uses DB_* and DATABASE_URL') }}</p>
                    </div>
                    <span class="shrink-0 rounded-full bg-brand-sage/15 px-2 py-0.5 text-2xs font-semibold text-brand-forest dark:text-brand-sage">{{ __('Primary') }}</span>
                </div>
            @elseif ($databaseEngine === 'sql')
                {{-- SQLite is managed here; dply databases are added beside it, not switched to. --}}
                <x-sheet.section :title="__('The file')">
                    <dl class="grid gap-1.5 text-xs" x-init="$wire.loadSqliteFile()">
                        <div class="flex justify-between gap-3"><dt class="text-brand-moss">{{ __('In the app') }}</dt><dd class="font-mono text-brand-ink">/tmp/database.sqlite</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-brand-moss">{{ __('Env') }}</dt><dd class="font-mono text-brand-ink">DB_CONNECTION=sqlite · DB_DATABASE=/tmp/database.sqlite</dd></div>
                        <div class="flex justify-between gap-3">
                            <dt class="text-brand-moss">{{ __('Last saved') }}</dt>
                            <dd class="font-mono text-brand-ink">
                                @if ($sqliteFile === null)
                                    <span wire:loading wire:target="loadSqliteFile">{{ __('Checking…') }}</span><span wire:loading.remove wire:target="loadSqliteFile">—</span>
                                @elseif (! ($sqliteFile['exists'] ?? false))
                                    {{ __('Not yet: it is saved once the app has run') }}
                                @else
                                    {{ \Illuminate\Support\Number::fileSize($sqliteFile['bytes'], 1) }} · {{ \Illuminate\Support\Carbon::createFromTimestamp($sqliteFile['at'])->diffForHumans() }}
                                @endif
                            </dd>
                        </div>
                    </dl>
                    @if (($sqliteFile['exists'] ?? false) && (auth()->user()?->can('update', $site) ?? false))
                        <x-sheet.button wire:click="downloadSqlite" class="justify-self-start">{{ __('Download a copy') }}</x-sheet.button>
                    @endif
                    <p class="text-2xs text-brand-mist">{{ __('While the app runs, the file is copied to dply’s object storage every 20 seconds; when it wakes, that copy is put back. Writes from the last few seconds before it sleeps can be lost. One instance serves the app so the file stays consistent, and migrations run each time it starts.') }}</p>
                </x-sheet.section>

                @include('livewire.sites.edge.workspace.partials.database-tools')

                <x-sheet.section :title="__('Add a database')">
                    <p class="text-xs text-brand-moss">{{ __('For data that several instances or queue workers share, add Postgres, MySQL or MongoDB. The first one becomes the app’s primary and takes over DB_*.') }}</p>
                    <x-sheet.button variant="primary" wire:click="openAddDatabase" wire:island="resources-database-add" x-on:click="$dispatch('close-modal', 'resources-database'); $dispatch('open-modal', 'resources-database-add')" class="justify-self-start">{{ __('Add a database') }}</x-sheet.button>
                </x-sheet.section>
            @endif

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

            @if (! $cardOnFile && $databaseEngine !== 'sql')
                <x-sheet.note tone="warn">
                    {{ __('Add a card before starting a database. It is billed to that card. SQLite does not need one.') }}
                    @if ($site->organization)
                        <a href="{{ route('billing.show', $site->organization) }}" class="font-semibold underline">{{ __('Billing') }}</a>
                    @endif
                </x-sheet.note>
            @endif

            <div @class(['grid gap-2', 'hidden' => $databaseEngine === 'sql'])>
                <x-sheet.row :title="__('How to use')" x-on:click="$wire.$island('resources-database').openDatabasePanel(null).then(() => { $dispatch('database-tab', 'how'); $dispatch('open-modal', 'resources-app-database') })" />
                @if ($databaseEngine !== 'none')
                    @if ($dplyEngine)
                        <x-sheet.row :title="__('Stats & backups')" x-on:click="$wire.$island('resources-database').openDatabasePanel(null).then(() => { $dispatch('database-tab', 'overview'); $dispatch('open-modal', 'resources-app-database') })" />
                    @endif
                    {{-- Migrate / status / seed / roll back live on the panel's settings tab. --}}
                    <x-sheet.row :title="__('Migrations & tools')" x-on:click="$wire.$island('resources-database').openDatabasePanel(null).then(() => { $dispatch('database-tab', 'settings'); $dispatch('open-modal', 'resources-app-database') })" />
                @endif
            </div>
            @if ($dplyEngine && ($primaryDatabase ?? null))
                @php $sharedWith = $primaryDatabase->sites()->count(); @endphp
                <x-sheet.section :title="__('Manage')">
                    <x-sheet.button wire:click="detachDatabase('{{ $primaryDatabase->id }}')" class="justify-self-start">{{ __('Detach') }}</x-sheet.button>
                    <p class="text-2xs text-brand-mist">{{ $appDatabases->count() > 1 ? __('Detach takes it off this app and keeps it in your organization; the next database becomes primary.') : __('Detach takes it off this app and keeps it in your organization; the app goes back to SQLite.') }}</p>
                    @if ($sharedWith > 1)
                        <p class="text-2xs text-brand-mist">{{ trans_choice('Also attached to :count other app. Detach it there before deleting.|Also attached to :count other apps. Detach it there before deleting.', $sharedWith - 1) }}</p>
                    @else
                        <div class="grid gap-2 rounded-xl border border-rose-500/30 p-3">
                            <p class="text-xs text-brand-ink">{{ __('Delete destroys the database right away, and its backups within a week. Export it first if you need the data.') }}</p>
                            <input type="text" wire:model="deleteDatabaseConfirm" placeholder="{{ $primaryDatabase->name }}" aria-label="{{ __('Type :name to confirm', ['name' => $primaryDatabase->name]) }}" class="dply-input font-mono text-xs" />
                            <x-sheet.button variant="danger" wire:click="deleteDatabase('{{ $primaryDatabase->id }}')" class="justify-self-start">{{ __('Delete :name', ['name' => $primaryDatabase->name]) }}</x-sheet.button>
                        </div>
                    @endif
                    @error('database') <x-sheet.note tone="warn" role="alert">{{ $message }}</x-sheet.note> @enderror
                </x-sheet.section>
            @endif
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
                        <p class="font-semibold text-rose-700 dark:text-rose-300">{{ __('This deletes the :engine database right away, and its backups within a week. Export it first if you need the data.', ['engine' => ['postgres' => 'Postgres', 'mongodb' => 'MongoDB', 'mysql' => 'MySQL'][$savedDatabase]]) }}</p>
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
