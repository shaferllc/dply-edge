@php
    $workflowConnection = collect($connections)->first(fn ($c) => $c['host'] === $resourceHost && $c['kind'] === 'workflow');
@endphp
<x-sheet name="resources-workflow" maxWidth="lg" focusable>
    <x-sheet.header :eyebrow="is_array($workflowConnection) ? $workflowConnection['host'] : null" :title="__('Workflow')" />

    <x-sheet.body>
        <x-sheet.note tone="warn">{{ __('Workflows are not supported yet. A workflow needs its code inside this app\'s worker, and dply does not build that, so a deploy with this resource fails. Remove it to deploy again.') }}</x-sheet.note>
        @if (is_array($workflowConnection))
            <div>
                <x-sheet.stat :label="__('Name')">{{ $workflowConnection['name'] }}</x-sheet.stat>
                <x-sheet.stat :label="__('Workflow')">{{ $workflowConnection['target'] }}</x-sheet.stat>
            </div>
            @can('update', $site)
                <div><x-sheet.button variant="danger" wire:click="askDeleteConnection({{ \Illuminate\Support\Js::from($workflowConnection['host']) }})" wire:island="resources-delete-connection" x-on:click="$dispatch('open-modal', 'resources-delete-connection')">{{ __('Remove') }}</x-sheet.button></div>
            @endcan
        @endif
    </x-sheet.body>
</x-sheet>
