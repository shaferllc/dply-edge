{{-- Overview: identity, the service map (the app as a request travels, with live detail per box), then traffic and usage (lazy). --}}

<div @if ($isInProgress ?? false) wire:poll.2s @endif>
    @if (! empty($edgeDeliveryBanner))
        <div class="border-b border-brand-ink/10 px-5 py-3 sm:px-6">
            @include('livewire.sites.partials.edge.delivery-banner')
        </div>
    @endif

    @include('livewire.sites.partials.edge.hero', ['heroShowsUrl' => false])

    @if (($deploymentJourney ?? null) !== null && ($inProgressDeployment ?? null) !== null)
        <div class="border-b border-brand-ink/10">
            @include('livewire.sites.partials.edge.deployment-journey-card', [
                'journey' => $deploymentJourney,
                'deployment' => $inProgressDeployment,
            ])
        </div>
    @endif

    @include('livewire.sites.partials.edge.service-map')

    @livewire('sites.edge.workspace.overview-observability', ['server' => $server, 'site' => $site], key('edge-overview-observability-'.$site->id))
</div>
