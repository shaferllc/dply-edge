{{-- A database on this app that isn't its primary (DplyDatabases): its connection env, its settings,
     and Make primary / Detach / Delete. The primary opens the full database sheet instead. --}}
@php
    $engines = ['postgres' => 'Postgres', 'mysql' => 'MySQL', 'mongodb' => 'MongoDB'];
    $sleeps = [60 => __('1 minute'), 300 => __('5 minutes'), 900 => __('15 minutes'), -1 => __('Stays on')];
@endphp
<x-sheet name="resources-database-extra" maxWidth="lg" focusable>
    @if ($openDatabase)
        @php
            $db = $openDatabase;
            $prefix = (string) $db->attached_env_name;
            $keys = array_keys(\App\Modules\Edge\Services\EdgeAppDatabase::credentialPairs($db->engine, ['host' => 'h', 'port' => '1', 'database' => 'd', 'username' => 'u', 'password' => 'p'], $prefix));
            $shared = $db->sites()->count();
        @endphp
        <x-sheet.header :eyebrow="$engines[$db->engine] ?? $db->engine" :title="$db->name" close-wire="$set('openDatabaseId', null)">
            {{ __('Attached to this app as :prefix. It sleeps when idle and wakes on the next connection.', ['prefix' => $prefix]) }}
        </x-sheet.header>

        <x-sheet.body>
            @include('livewire.sites.edge.workspace.partials.database-resize', ['resizeOf' => $db, 'resizeId' => $db->id])

            {{-- Health from the hourly sampler and the backup tracker (its own row's state: DplyDatabases). --}}
            @php
                $state = (array) ($db->state ?? []);
                $point = collect((array) ($state['history'] ?? []))->last();
                $backupAt = filled($state['backup']['last_ok_at'] ?? null) ? \Illuminate\Support\Carbon::parse($state['backup']['last_ok_at']) : null;
                $backupProblem = \App\Modules\Edge\Support\EdgeDplyDatabase::backupProblem((array) ($state['backup'] ?? []));
            @endphp
            <x-sheet.section :title="__('Health')">
                <dl class="grid grid-cols-3 gap-2 text-xs">
                    <div><dt class="text-brand-mist">{{ __('Disk') }}</dt><dd class="font-mono text-brand-ink">{{ is_array($point) && ($point['disk'] ?? 0) > 0 ? round($point['disk_used'] / 1024 ** 3, 2).' / '.round($point['disk'] / 1024 ** 3).' GB' : '—' }}</dd></div>
                    <div><dt class="text-brand-mist">{{ __('Connections') }}</dt><dd class="font-mono text-brand-ink">{{ is_array($point) ? (int) ($point['connections'] ?? 0) : '—' }}</dd></div>
                    <div><dt class="text-brand-mist">{{ __('Last backup') }}</dt><dd class="font-mono text-brand-ink">{{ $backupAt?->diffForHumans(short: true) ?? __('none yet') }}</dd></div>
                </dl>
                @if ($backupProblem)
                    <x-sheet.note tone="warn">{{ $backupProblem }}</x-sheet.note>
                @endif
                <p class="text-2xs text-brand-mist">{{ __('Updated hourly, without waking it.') }}</p>
                <x-sheet.row :title="__('Stats, console & backups')" x-on:click="$dispatch('close-modal', 'resources-database-extra'); $wire.$island('resources-database').openDatabasePanel({{ \Illuminate\Support\Js::from($db->id) }}).then(() => { $dispatch('database-tab', 'overview'); $dispatch('open-modal', 'resources-app-database') })" />
            </x-sheet.section>

            <x-sheet.section :title="__('Connect')">
                <p class="text-xs text-brand-moss">{{ __('On the next deploy the app gets:') }}</p>
                <p class="font-mono text-2xs leading-relaxed text-brand-ink">{{ implode(', ', $keys) }}</p>
                @if ($db->engine !== 'mongodb' && $site->isLaravelFrameworkDetected())
                    <x-edge-yaml-example file="config/database.php" :hint="__('Add a connection that reads them, then use DB::connection(:name).', ['name' => '\''.strtolower($prefix).'\''])">{{ "'".strtolower($prefix)."' => [\n    'driver' => env('{$prefix}_DB_CONNECTION'),\n    'url' => env('{$prefix}_DATABASE_URL'),\n],"}}</x-edge-yaml-example>
                @endif
            </x-sheet.section>

            <x-sheet.section :title="__('Size')">
                <x-sheet.options id="extra-database-size" :aria-label="__('Size')">
                    @foreach ($postgresSizes as $key => $size)
                        <x-sheet.option wire:click="updateExtraDatabase('size', '{{ $key }}')" :selected="$db->size === (string) $key" :title="$size['cpu'].' · '.$size['memory']" :meta="'$'.$size['month'].'/mo'" />
                    @endforeach
                </x-sheet.options>
            </x-sheet.section>

            <x-sheet.section :title="__('Sleep')">
                <x-sheet.segmented :aria-label="__('Sleep after')">
                    @foreach ($sleeps as $seconds => $label)
                        <x-sheet.segment wire:click="updateExtraDatabase('suspend', '{{ $seconds }}')" :active="$db->suspend === $seconds">{{ $label }}</x-sheet.segment>
                    @endforeach
                </x-sheet.segmented>
            </x-sheet.section>

            {{-- A disk only grows, and growing is permanent: ask first. --}}
            <x-sheet.section :title="__('Disk')" x-data="{ grow: null }">
                <x-sheet.segmented :aria-label="__('Disk size')">
                    @foreach (\App\Modules\Edge\Support\EdgeDplyDatabase::DISKS as $gb => $label)
                        <x-sheet.segment x-on:click="grow = {{ $gb }}; $nextTick(() => $refs.grow.focus())" :active="$db->disk_gb === $gb" :disabled="$gb <= $db->disk_gb">{{ __($label) }}</x-sheet.segment>
                    @endforeach
                </x-sheet.segmented>
                <div x-show="grow" x-cloak class="grid gap-2 rounded-xl border border-amber-500/40 bg-amber-500/10 p-3 text-sm text-brand-ink">
                    <p>{{ __('Grow the disk? A database disk can’t shrink afterwards.') }}</p>
                    <div class="flex gap-2">
                        <x-sheet.button variant="primary" x-ref="grow" x-on:click="$wire.updateExtraDatabase('disk', String(grow)); grow = null">{{ __('Grow') }}</x-sheet.button>
                        <x-sheet.button x-on:click="grow = null">{{ __('Keep :gb GB', ['gb' => $db->disk_gb]) }}</x-sheet.button>
                    </div>
                </div>
            </x-sheet.section>

            @include('livewire.sites.edge.workspace.partials.database-tools', ['toolsTarget' => $db->id])

            @error('database') <x-sheet.note tone="warn" role="alert">{{ $message }}</x-sheet.note> @enderror

            <x-sheet.section :title="__('Manage')">
                <div class="flex flex-wrap gap-2">
                    <x-sheet.button wire:click="makeDatabasePrimary('{{ $db->id }}')">{{ __('Make primary') }}</x-sheet.button>
                    <x-sheet.button wire:click="detachDatabase('{{ $db->id }}')">{{ __('Detach') }}</x-sheet.button>
                </div>
                <p class="text-2xs text-brand-mist">{{ __('Make primary moves it to DB_* and DATABASE_URL. Detach takes it off this app but keeps it, and its data, in your organization.') }}</p>
                @if ($shared > 1)
                    <p class="text-2xs text-brand-mist">{{ trans_choice('Also attached to :count other app. Detach it there before deleting.|Also attached to :count other apps. Detach it there before deleting.', $shared - 1) }}</p>
                @else
                    <div class="grid gap-2 rounded-xl border border-rose-500/30 p-3">
                        <p class="text-xs text-brand-ink">{{ __('Delete destroys the database right away, and its backups within a week. Export it first if you need the data.') }}</p>
                        <input type="text" wire:model="deleteDatabaseConfirm" placeholder="{{ $db->name }}" aria-label="{{ __('Type :name to confirm', ['name' => $db->name]) }}" class="dply-input font-mono text-xs" />
                        <x-sheet.button variant="danger" wire:click="deleteDatabase('{{ $db->id }}')" class="justify-self-start">{{ __('Delete :name', ['name' => $db->name]) }}</x-sheet.button>
                    </div>
                @endif
            </x-sheet.section>
        </x-sheet.body>
    @endif
</x-sheet>
