<x-sheet name="resources-delete-connection" :show="$panel === 'delete-connection'" maxWidth="md" focusable>
    @php
        $deleting = collect($connections)->firstWhere('host', $deleteConnectionHost) ?? [];
        $deleteKind = (string) ($deleting['kind'] ?? '');
        // Kinds that are switched on or pointed at: removing them deletes nothing.
        $unlinkOnly = in_array($deleteKind, ['ai', 'images', 'service', 'workflow'], true)
            || ($deleteKind === 'redis' && ! \App\Modules\Edge\Support\EdgeValkey::isTarget((string) ($deleting['target'] ?? '')));
    @endphp
    <x-sheet.header :title="$unlinkOnly ? __('Remove from this app?') : __('Delete this resource?')" close-wire="$set('panel', '')" />

    <x-sheet.body>
        @if ($unlinkOnly)
            <x-sheet.note tone="warn">{{ $deleteKind === 'redis'
                ? __('The app stops getting REDIS_URL on the next deploy. The Redis server itself is not touched.')
                : __('The app loses this binding on the next deploy, and code that uses it gets errors. Nothing is deleted; you can add it again later.') }}</x-sheet.note>
        @elseif ($deleteKind === 'durable_object')
            {{-- Every State on an app shares one store (EdgeState, one object per app), so its keys cannot be wiped per connection. --}}
            <x-sheet.note tone="danger">{{ __('The app loses this State on the next deploy, and code that calls it gets errors. The keys are not wiped: every State on this app shares one store, so another State here, or one you add later, sees the same keys again. They are deleted only with the app.') }}</x-sheet.note>
        @else
            <x-sheet.note tone="danger">{{ __('This destroys it, not just the link on this app. A key-value store, bucket, database, or queue is removed. Redis removes REDIS_URL. A Redis started here is deleted. A pasted address is left where it is. Anything else is only detached. This cannot be undone.') }}</x-sheet.note>
        @endif
        @php $deletingBucket = $deleteKind === 'object_storage'; @endphp
        @if ($deletingBucket)
            <x-sheet.note tone="warn">{{ __('A bucket with files in it cannot be deleted. Empty and delete removes every file first, then the bucket. A large bucket can take a few runs.') }}</x-sheet.note>
        @endif
        <x-input-error :messages="$errors->get('connectionDelete')" />
    </x-sheet.body>

    <x-sheet.footer class="justify-end">
        <x-sheet.button wire:click="$set('panel', '')" x-on:click="$dispatch('close-modal', 'resources-delete-connection')">{{ __('Cancel') }}</x-sheet.button>
        @if ($deletingBucket)
            <x-sheet.button variant="danger" wire:click="emptyAndDeleteConnection" wire:loading.attr="disabled" wire:target="emptyAndDeleteConnection,deleteConnection">
                <span wire:loading.remove wire:target="emptyAndDeleteConnection">{{ __('Empty and delete') }}</span>
                <span wire:loading wire:target="emptyAndDeleteConnection">{{ __('Emptying…') }}</span>
            </x-sheet.button>
        @endif
        <x-sheet.button variant="danger" wire:click="deleteConnection">{{ $unlinkOnly ? __('Remove') : __('Delete resource') }}</x-sheet.button>
    </x-sheet.footer>
</x-sheet>
