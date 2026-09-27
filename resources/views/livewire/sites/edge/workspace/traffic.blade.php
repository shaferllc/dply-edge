<div wire:init="loadTraffic">
    @if ($loaded)
        @include('livewire.sites.partials.edge.traffic')
    @else
        <div class="grid gap-3 px-5 py-8 sm:px-6" aria-busy="true">
            <div class="h-4 w-40 animate-pulse rounded bg-brand-ink/10"></div>
            <div class="h-24 w-full animate-pulse rounded bg-brand-ink/8"></div>
            <p class="text-xs text-brand-mist">{{ __('Loading traffic…') }}</p>
        </div>
    @endif
</div>
