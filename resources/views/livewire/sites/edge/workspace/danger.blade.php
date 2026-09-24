<div @if ($site->status === \App\Models\Site::STATUS_EDGE_DELETING) wire:poll.2s="watchTeardown" @endif>
    @include('livewire.sites.partials.edge.danger')
</div>
