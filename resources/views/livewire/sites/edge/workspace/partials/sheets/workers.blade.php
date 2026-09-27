{{-- Same test as the map's workers box (resources.blade.php); islands do not share its locals. --}}
@if ($isContainer && (($workers['enabled'] ?? false) || $scheduler))
    <x-sheet name="resources-workers" maxWidth="xl">
        <x-sheet.header :title="($workers['enabled'] ?? false) ? __('Queue workers') : __('Scheduler')" />

        <x-sheet.body>
            @include('livewire.sites.edge.workspace.partials.queue-workers-card')
        </x-sheet.body>
    </x-sheet>
@endif
