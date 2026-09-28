{{--
  Domains & DNS (was "Credentials"; org redesign 2026-09-27, Credentials 2).
  Zones first — $zones comes from Credentials\Index::zoneRows() — then the
  provider tokens that let dply manage them.
--}}
<div>
    @if (! empty($useOrgShell) && $organization)
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
            <x-organization-shell
                :organization="$organization"
                section="providers"
                :breadcrumb="[
                    ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
                    ['label' => $organization->name, 'href' => route('organizations.show', $organization), 'icon' => 'building-office-2'],
                    ['label' => __('Domains & DNS'), 'icon' => 'globe-alt'],
                ]"
            >
                @include('livewire.credentials.partials.index-content')
            </x-organization-shell>
        </div>
    @else
        <div class="py-2">
            @include('livewire.credentials.partials.index-content')
        </div>
    @endif

    {{-- One shared "Connect a provider" modal for the page: every button
         dispatches `open-add-provider-credential-modal` (optionally with a
         provider id) and the modal listens window-wide. --}}
    <livewire:credentials.add-provider-credential-modal />

    {{-- Inside the root, not a layout slot, so wire: targets bind (see secrets.blade.php). --}}
    @include('livewire.partials.confirm-action-modal')
</div>
