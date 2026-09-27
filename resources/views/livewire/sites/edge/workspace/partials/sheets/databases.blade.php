<x-sheet name="resources-databases" :show="$panel === 'databases'" maxWidth="6xl" focusable>
    <x-sheet.header :title="__('Databases')" />
    @if ($panel === 'databases')
        @livewire(\App\Modules\Edge\Livewire\Databases::class, ['compact' => true], key('resources-databases'))
    @endif
</x-sheet>
