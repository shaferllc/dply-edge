<x-sheet name="resources-edge" maxWidth="lg">
    <x-sheet.header :eyebrow="__('Traffic')" :title="__('Edge network')">{{ $hostname }}</x-sheet.header>

    <x-sheet.body>
        <div>
            <x-sheet.stat :label="__('DDoS protection')" class="font-sans font-semibold text-emerald-700 dark:text-emerald-300">{{ __('Active') }}</x-sheet.stat>
            <x-sheet.stat :label="__('CDN')" class="font-sans font-semibold text-emerald-700 dark:text-emerald-300">{{ __('Enabled') }}</x-sheet.stat>
        </div>

        <x-sheet.toggle
            :label="__('Edge caching')"
            :help="$cacheMode === 'off' ? __('Off') : __('Enabled')"
            :checked="$cacheMode !== 'off'"
            wire:click="toggleEdgeCache({{ $cacheMode === 'off' ? 'true' : 'false' }})"
        />

        @if ($cacheMode !== 'off')
            <x-sheet.field :label="__('Cache')" :help="__('Stored at the edge.')">
                <x-sheet.options aria-label="{{ __('Cache') }}">
                    @foreach ($cacheModes as $mode => $label)
                        <x-sheet.option wire:click="selectCache('{{ $mode }}')" :selected="$cacheMode === $mode" :title="$label" />
                    @endforeach
                </x-sheet.options>
            </x-sheet.field>
            <x-sheet.row wire:click="openPanel('cache')" x-on:click="$dispatch('open-modal', 'resources-cache')" :title="__('Cache settings')" :hint="__('TTLs, query strings, purge')" />
        @endif

        <x-sheet.row :href="route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'routing'])" wire:navigate :title="__('Domains')" :hint="$hostname" />
    </x-sheet.body>
</x-sheet>
