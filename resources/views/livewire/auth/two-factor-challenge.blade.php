<div>
    <x-livewire-validation-errors />
    <div class="mb-6 flex gap-3 border border-edge-line bg-edge-panel px-4 py-3 text-sm text-edge-mute">
        <span class="flex h-9 w-9 shrink-0 items-center justify-center border border-edge-line bg-edge-void text-edge-lime" aria-hidden="true">
            <x-heroicon-o-device-tablet class="h-5 w-5" />
        </span>
        <p class="leading-relaxed">{{ __('Enter the code from your authenticator app, or one of your recovery codes.') }}</p>
    </div>
    <form wire:submit="submit" class="space-y-5">
        <div>
            <x-input-label for="code" :value="__('Code')" />
            <x-text-input
                id="code"
                wire:model="code"
                class="block w-full mt-1 font-mono text-lg tracking-[0.2em] text-center sm:text-start"
                type="text"
                inputmode="numeric"
                autocomplete="one-time-code"
                autofocus
                placeholder="000000"
            />
            <x-input-error :messages="$errors->get('code')" class="mt-2" />
        </div>
        <div class="pt-2 border-t border-edge-line">
            <x-primary-button class="w-full sm:w-auto min-w-[8rem]" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="submit">{{ __('Continue') }}</span>
                <span wire:loading wire:target="submit" class="inline-flex items-center justify-center gap-2">
                    <x-spinner variant="cream" />
                    {{ __('Verifying…') }}
                </span>
            </x-primary-button>
        </div>
    </form>
</div>
