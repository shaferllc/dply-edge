{{-- "Add a database": create a dply database for this app, or attach one the organization already has
     (Resources::createDatabase → DplyDatabases). The first becomes the app's primary (DB_*); others get
     their name as an env prefix. --}}
@php
    $engines = ['postgres' => 'Postgres', 'mysql' => 'MySQL', 'mongodb' => 'MongoDB'];
    $isFirst = $appDatabases->isEmpty();
    $prefix = \App\Modules\Edge\Services\DplyDatabases::envName((string) ($newDatabase['name'] ?? '') ?: 'name');
@endphp
<x-sheet name="resources-database-add" :show="$panel === 'database-add'" maxWidth="lg" focusable>
    <x-sheet.header :eyebrow="__('Add a resource')" :title="__('Database')" close-wire="openPanel('')">
        {{ __('A dply database in your app’s region. It sleeps when idle and wakes on the next connection.') }}
    </x-sheet.header>

    <x-sheet.body>
        <x-sheet.segmented :aria-label="__('Create or attach')">
            <x-sheet.segment wire:click="$set('newDatabase.mode', 'create')" :active="($newDatabase['mode'] ?? '') === 'create'">{{ __('Create new') }}</x-sheet.segment>
            <x-sheet.segment wire:click="$set('newDatabase.mode', 'attach')" :active="($newDatabase['mode'] ?? '') === 'attach'">{{ __('Attach existing') }}</x-sheet.segment>
        </x-sheet.segmented>

        @if (($newDatabase['mode'] ?? '') === 'attach')
            @if ($attachableDatabases->isEmpty())
                <x-sheet.note>{{ __('Your organization has no other databases yet.') }}</x-sheet.note>
            @else
                <x-sheet.options id="attach-database" :aria-label="__('Database to attach')">
                    @foreach ($attachableDatabases as $candidate)
                        <x-sheet.option wire:click="$set('newDatabase.attach', '{{ $candidate->id }}')" :selected="($newDatabase['attach'] ?? '') === $candidate->id"
                            :title="$candidate->name" :description="$engines[$candidate->engine] ?? $candidate->engine" />
                    @endforeach
                </x-sheet.options>
            @endif
        @else
            <x-sheet.field :label="__('Engine')">
                <x-sheet.segmented :aria-label="__('Engine')">
                    @foreach ($engines as $engine => $label)
                        <x-sheet.segment wire:click="$set('newDatabase.engine', '{{ $engine }}')" :active="($newDatabase['engine'] ?? '') === $engine">{{ $label }}</x-sheet.segment>
                    @endforeach
                </x-sheet.segmented>
            </x-sheet.field>
            <x-sheet.field :label="__('Name')" for="new-database-name" :help="__('Letters, numbers and dashes. It names the database in your organization.')">
                <input id="new-database-name" type="text" wire:model.live.debounce.300ms="newDatabase.name" autocomplete="off" class="dply-input font-mono" placeholder="analytics" />
            </x-sheet.field>
            <p class="text-2xs text-brand-mist">{{ __('Starts at the smallest size, 1 GB disk, sleeping after 5 idle minutes. Change them on its sheet.') }}</p>
        @endif

        <x-sheet.note>
            @if ($isFirst)
                {{ __('It becomes this app’s primary database: DB_CONNECTION, DB_HOST and the rest, and DATABASE_URL. Migrations run against it on deploy.') }}
            @else
                {{ __('The app already has a primary database, so this one’s connection goes in :prefix_DB_HOST, :prefix_DATABASE_URL and the rest. You can make it primary later.', ['prefix' => $prefix]) }}
            @endif
        </x-sheet.note>

        @error('newDatabase') <x-sheet.note tone="warn" role="alert">{{ $message }}</x-sheet.note> @enderror
    </x-sheet.body>

    <x-sheet.footer>
        <x-sheet.button variant="primary" wire:click="createDatabase" wire:loading.attr="disabled" wire:target="createDatabase" class="ms-auto">
            <span wire:loading.remove wire:target="createDatabase">{{ ($newDatabase['mode'] ?? '') === 'attach' ? __('Attach') : __('Create database') }}</span>
            <span wire:loading wire:target="createDatabase">{{ __('Starting…') }}</span>
        </x-sheet.button>
    </x-sheet.footer>
</x-sheet>
