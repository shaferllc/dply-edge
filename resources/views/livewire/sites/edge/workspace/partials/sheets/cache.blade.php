<x-sheet name="resources-cache" :show="$panel === 'cache'" maxWidth="4xl" focusable>
    <x-sheet.header :eyebrow="__('Edge network')" :title="__('Cache settings')" close-wire="$set('panel', '')" />
    @if ($panel === 'cache')
        <div>
            @livewire('sites.edge.workspace.cache', ['server' => $server, 'site' => $site], key('resources-cache-'.$site->id))
        </div>
    @endif
</x-sheet>
