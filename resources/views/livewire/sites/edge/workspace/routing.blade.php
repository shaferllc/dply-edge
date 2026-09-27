<div>
    {{-- Read-only for anyone who cannot configure this app (org Deployer, app Viewer/Deployer). --}}
    <fieldset @disabled(! auth()->user()?->can('update', $site)) class="min-w-0">
    @php
        $tabs = [
            ['id' => 'domains', 'label' => __('Domains'), 'icon' => 'heroicon-o-globe-alt'],
            ['id' => 'redirects', 'label' => __('Redirects'), 'icon' => 'heroicon-o-arrow-uturn-right'],
            ['id' => 'rewrites', 'label' => __('Rewrites'), 'icon' => 'heroicon-o-arrows-right-left'],
            ['id' => 'headers', 'label' => __('Headers'), 'icon' => 'heroicon-o-shield-check'],
        ];
    @endphp

    <x-server-workspace-tablist
        :aria-label="__('Routing sections')"
        :scroll="true"
        class="mb-0 rounded-none border-0 border-b border-brand-ink/10 bg-transparent p-2 shadow-none sm:px-4"
    >
        @foreach ($tabs as $entry)
            <x-server-workspace-tab
                id="edge-routing-tab-{{ $entry['id'] }}"
                :active="$tab === $entry['id']"
                :icon="$entry['icon']"
                wire:click="setTab('{{ $entry['id'] }}')"
            >{{ $entry['label'] }}</x-server-workspace-tab>
        @endforeach
    </x-server-workspace-tablist>

    <div wire:key="edge-routing-tab-{{ $tab }}">
        <div class="hidden" wire:loading.class.remove="hidden" wire:target="setTab">
            @include('livewire.sites.partials._panel-skeleton')
        </div>
        <div wire:loading.class="hidden" wire:target="setTab">
            @includeWhen($tab === 'domains', 'livewire.sites.partials.edge.routing-domains')
            @includeWhen($tab === 'redirects', 'livewire.sites.partials.edge.routing-redirects')
            @includeWhen($tab === 'rewrites', 'livewire.sites.partials.edge.routing-rewrites')
            @includeWhen($tab === 'headers', 'livewire.sites.partials.edge.routing-headers')
        </div>
    </div>

    @include('livewire.partials.confirm-action-modal')
    </fieldset>
</div>
