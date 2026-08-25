<div>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <x-organization-shell
            :organization="$organization"
            section="secrets"
            :title="__('Secrets')"
            :description="$tab === 'residency'
                ? __('Who holds the residency key, and which external stores sites can reference.')
                : __('Shared vault you link onto sites. Write-never values apply on the next deploy.')"
            icon="heroicon-o-lock-closed"
            :breadcrumb="[
                ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
                ['label' => $organization->name, 'href' => route('organizations.show', $organization), 'icon' => 'building-office-2'],
                ['label' => __('Secrets'), 'icon' => 'lock-closed'],
            ]"
        >
            <x-slot:tabs>
                <x-server-workspace-tablist :aria-label="__('Secrets sections')" bare class="!mb-0">
                    <x-server-workspace-tab :active="$tab === 'secrets'" icon="heroicon-o-lock-closed" wire:click="setTab('secrets')">{{ __('Secrets') }}</x-server-workspace-tab>
                    <x-server-workspace-tab :active="$tab === 'residency'" icon="heroicon-o-key" wire:click="setTab('residency')">{{ __('Residency') }}</x-server-workspace-tab>
                </x-server-workspace-tablist>
            </x-slot:tabs>

            @if ($errors->isNotEmpty())
                <div class="border-b border-brand-ink/10 px-3 py-2 sm:px-4">
                    <x-livewire-validation-errors />
                </div>
            @endif

            @if ($tab === 'residency')
                @include('livewire.organizations.secrets.residency')
            @else
                @include('livewire.organizations.secrets.vault')
            @endif
        </x-organization-shell>
    </div>

    {{-- Confirm modal must live in the Livewire view tree (not only a layout slot) so state updates and wire: targets bind reliably. --}}
    @include('livewire.partials.confirm-action-modal')
</div>
