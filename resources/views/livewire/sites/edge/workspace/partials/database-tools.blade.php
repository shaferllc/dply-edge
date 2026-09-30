{{-- Migrate / status / seed / roll back, run in the live app (Resources::runDatabaseCommand) against
     $toolsTarget: null for the app's primary (or SQLite), else another database's id (Laravel only). --}}
@php
    $toolsTarget ??= null;
    $toolsArg = $toolsTarget === null ? '' : ', '.\Illuminate\Support\Js::from($toolsTarget);
    $toolsHere = $databaseCommandTarget === $toolsTarget;
@endphp
@if ($site->isLaravelFrameworkDetected() || ($toolsTarget === null && $site->isRailsFrameworkDetected()))
    <x-sheet.section :title="__('Tools')">
        <div class="flex flex-wrap gap-2">
            <x-sheet.button wire:click="runDatabaseCommand('migrate'{{ $toolsArg }})" wire:loading.attr="disabled" wire:target="runDatabaseCommand,confirmDatabaseCommand">{{ __('Migrate') }}</x-sheet.button>
            <x-sheet.button wire:click="runDatabaseCommand('status'{{ $toolsArg }})" wire:loading.attr="disabled" wire:target="runDatabaseCommand,confirmDatabaseCommand">{{ __('Status') }}</x-sheet.button>
            <x-sheet.button wire:click="runDatabaseCommand('seed'{{ $toolsArg }})" wire:loading.attr="disabled" wire:target="runDatabaseCommand,confirmDatabaseCommand">{{ __('Seed') }}</x-sheet.button>
            @if ($site->isRailsFrameworkDetected())
                <x-sheet.button wire:click="runDatabaseCommand('prepare'{{ $toolsArg }})" wire:loading.attr="disabled" wire:target="runDatabaseCommand,confirmDatabaseCommand">{{ __('Prepare') }}</x-sheet.button>
            @endif
            <x-sheet.button wire:click="runDatabaseCommand('rollback'{{ $toolsArg }})" wire:loading.attr="disabled" wire:target="runDatabaseCommand,confirmDatabaseCommand">{{ __('Roll back') }}</x-sheet.button>
        </div>
        <p wire:loading wire:target="runDatabaseCommand,confirmDatabaseCommand" class="text-xs text-brand-moss">{{ __('Running…') }}</p>
        @if ($toolsHere && $pendingDatabaseCommand === 'rollback')
            <x-sheet.danger :title="__('Roll back the last migration on this database?')">
                <div class="flex gap-2">
                    <x-sheet.button variant="danger" wire:click="confirmDatabaseCommand">{{ __('Roll back') }}</x-sheet.button>
                    <x-sheet.button wire:click="$set('pendingDatabaseCommand', '')">{{ __('Cancel') }}</x-sheet.button>
                </div>
            </x-sheet.danger>
        @endif
        @if ($toolsHere && $databaseCommandOutput !== '')
            <pre class="max-h-48 overflow-auto whitespace-pre-wrap rounded-xl bg-brand-sand/40 px-3.5 py-3 font-mono text-xs text-brand-ink dark:bg-zinc-950">{{ $databaseCommandOutput }}</pre>
        @endif
    </x-sheet.section>
@endif
