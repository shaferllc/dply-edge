{{-- Overview: identity, the service map (the app as a request travels, with live detail per box), then traffic and usage (lazy). --}}

{{-- While a deploy runs, checkDeploy() skips the render until that deploy settles (the journey card polls itself). --}}
<div @if ($isInProgress ?? false) wire:poll.2s="checkDeploy(@js((string) $inProgressDeployment->id))" @endif>
    @if (! empty($edgeDeliveryBanner))
        <div class="border-b border-brand-ink/10 px-5 py-3 sm:px-6">
            @include('livewire.sites.partials.edge.delivery-banner')
        </div>
    @endif

    @include('livewire.sites.partials.edge.hero', ['heroShowsUrl' => false])

    @if (($inProgressDeployment ?? null) !== null)
        <div class="border-b border-brand-ink/10">
            @include('livewire.sites.partials.edge.deployment-journey-card', [
                'deployment' => $inProgressDeployment,
            ])
        </div>
    @endif

    {{-- The resource map: every resource the app uses, each box opening its settings in a sheet. --}}
    @livewire('sites.edge.workspace.resources', ['server' => $server, 'site' => $site], key('edge-overview-resources-'.$site->id))

    {{-- Scheduled tasks: the sheets behind the map's box and "Add a resource" → Scheduled task. --}}
    @if (\App\Modules\Edge\Support\EdgeSiteHasWorker::for($site))
        @livewire('sites.edge.workspace.crons', ['server' => $server, 'site' => $site], key('edge-overview-crons-'.$site->id))
    @endif

    @include('livewire.sites.partials.edge.service-map')

    @livewire('sites.edge.workspace.overview-observability', ['server' => $server, 'site' => $site], key('edge-overview-observability-'.$site->id))
</div>
