<x-sheet name="resources-remove-browser" :show="$confirmRemoveBrowser" maxWidth="md" focusable>
    <x-sheet.header :title="__('Remove the browser?')" close-wire="$set('confirmRemoveBrowser', false)" />

    <x-sheet.body>
        <x-sheet.note tone="warn">{{ __('The app will stop being able to open pages, save pictures, and save PDFs after the next deploy.') }}</x-sheet.note>
    </x-sheet.body>

    <x-sheet.footer class="justify-end">
        <x-sheet.button wire:click="$set('confirmRemoveBrowser', false)" x-on:click="$dispatch('close-modal', 'resources-remove-browser')">{{ __('Cancel') }}</x-sheet.button>
        <x-sheet.button variant="danger" wire:click="removeBrowser">{{ __('Remove browser') }}</x-sheet.button>
    </x-sheet.footer>
</x-sheet>
