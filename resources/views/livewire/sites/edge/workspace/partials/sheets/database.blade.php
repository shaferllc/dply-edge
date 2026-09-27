@if ($databaseVisible)
    <x-sheet name="resources-database" maxWidth="lg">
        <x-sheet.header :title="__('Database')" />

        <x-sheet.body>
            @php
                $dplyEngine = in_array($databaseEngine, ['postgres', 'mongodb', 'mysql'], true);
                $postgresLocked = ! $cardOnFile;
            @endphp
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
                <x-sheet.field :label="__('Size')">
                    <x-sheet.options id="database-size">
                        @foreach ($postgresSizes as $key => $size)
                            <x-sheet.option
                                wire:click="selectPostgresSize('{{ $key }}')"
                                :selected="$postgresSize === (string) $key"
                                :disabled="$postgresLocked"
                                :title="$size['cpu'].' · '.$size['memory']"
                                :meta="'$'.$size['month'].'/mo'"
                            />
                        @endforeach
                    </x-sheet.options>
                </x-sheet.field>

                <x-sheet.field :label="__('Sleep')" :help="$postgresSuspend === -1 ? null : __('After :time idle', ['time' => __($postgresSleeps[$postgresSuspend] ?? '')])">
                    <x-sheet.segmented id="database-sleep">
                        @foreach ($postgresSleeps as $seconds => $label)
                            <x-sheet.segment wire:click="selectPostgresSuspend({{ $seconds }})" :active="$postgresSuspend === $seconds" :disabled="$postgresLocked">{{ __($label) }}</x-sheet.segment>
                        @endforeach
                    </x-sheet.segmented>
                </x-sheet.field>

                <x-sheet.field :label="__('Disk')">
                    <x-sheet.segmented id="postgres-disk">
                        @foreach ($postgresDisks as $gb => $label)
                            <x-sheet.segment wire:click="selectPostgresDisk({{ $gb }})" :active="$postgresDisk === $gb" :disabled="$postgresLocked">{{ __($label) }}</x-sheet.segment>
                        @endforeach
                    </x-sheet.segmented>
                </x-sheet.field>

                @if ($postgresSuspend !== -1)
                    <x-sheet.field :label="__('Awake')" for="postgres-awake">
                        <div class="flex items-center gap-2">
                            <input id="postgres-awake" type="number" min="0" max="24" wire:model.live.debounce.400ms="awakeHours" @disabled($postgresLocked) class="dply-input mt-0 w-20 disabled:opacity-60" />
                            <span class="text-xs text-brand-moss">{{ __('hours a day') }}</span>
                        </div>
                    </x-sheet.field>
                @endif

                <x-sheet.cost :label="__('Compute $:compute · disk $:disk', ['compute' => $postgresSizes[$postgresSize]['month'], 'disk' => number_format((float) $postgresGigabyte * $postgresDisk, 2)])" :sub="__('$:hour/hour awake · disk $:gigabyte/GB', ['hour' => $postgresSizes[$postgresSize]['hour'], 'gigabyte' => $postgresGigabyte])">{{ __('About $:total/mo', ['total' => number_format((float) str_replace(',', '', $postgresSizes[$postgresSize]['month']) + (float) $postgresGigabyte * $postgresDisk, 2)]) }}</x-sheet.cost>
                @if ($databaseEngine === 'postgres' && $postgresSuspend !== -1 && $postgresSize !== '0.25')
                    <p class="-mt-3 text-2xs text-brand-mist">{{ __('Scales from 1/4 vCPU · 1 GB; priced at full size.') }}</p>
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
                    <p>{{ __('The app uses it from the next deploy.') }}</p>
                </div>
                <div class="flex gap-2">
                    <x-sheet.button wire:click="discardPending">{{ __('Keep :engine', ['engine' => ['postgres' => 'Postgres', 'mongodb' => 'MongoDB', 'mysql' => 'MySQL', 'sql' => 'SQLite'][$savedDatabase] ?? __('none')]) }}</x-sheet.button>
                    <x-sheet.button :variant="$databaseEngine === 'none' ? 'danger' : 'primary'" wire:click="saveSettings" wire:loading.attr="disabled" wire:target="saveSettings">{{ $databaseEngine === 'none' ? __('Remove') : __('Confirm') }}</x-sheet.button>
                </div>
            </x-sheet.footer>
        @endif
    </x-sheet>
@endif
