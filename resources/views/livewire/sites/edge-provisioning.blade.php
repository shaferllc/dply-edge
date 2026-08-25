<div>
    @if ($site->server_id)
        <div
            id="dply-site-provisioning-context"
            data-server-id="{{ $site->server_id }}"
            data-site-id="{{ $site->id }}"
            data-subscribe="1"
            class="hidden"
            aria-hidden="true"
        ></div>
    @endif

    <div class="dply-page-shell space-y-4 pb-12 pt-6">
        <x-breadcrumb-trail :items="$siteHeaderBreadcrumbs" :site="$site" doc-contextual />

        <x-profile-shell
            :title="__('Edge deployment')"
            :description="__('Track the git build and Edge CDN publish until this site goes live.')"
            icon="heroicon-o-rocket-launch"
        >
            <x-slot:actions>
                <x-outline-link size="sm" :href="route('edge.index')" wire:navigate>
                    <x-heroicon-o-globe-alt class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                    {{ __('All Edge sites') }}
                </x-outline-link>
            </x-slot:actions>

            <div class="space-y-6 px-3 py-4 sm:px-4">
                @include('livewire.sites.partials.show.edge-provisioning-journey')
            </div>
        </x-profile-shell>
    </div>

    @include('livewire.partials.confirm-action-modal')
</div>
