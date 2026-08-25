<div>
    <x-livewire-validation-errors />
    <div class="mb-6 flex gap-3 border border-amber-400/40 bg-amber-400/10 px-4 py-3 text-sm text-amber-200">
        <span class="flex h-9 w-9 shrink-0 items-center justify-center border border-amber-400/40 bg-edge-void text-amber-300" aria-hidden="true">
            <x-heroicon-o-lock-closed class="h-5 w-5" />
        </span>
        <p class="leading-relaxed">{{ __('This is a secure area of the application. Please confirm your password before continuing.') }}</p>
    </div>
    <form wire:submit="submit" class="space-y-5" autocomplete="on">
        <div class="sr-only">
            <label for="confirm_area_username">{{ __('Account email') }}</label>
            <input
                id="confirm_area_username"
                type="email"
                name="username"
                autocomplete="username"
                value="{{ auth()->user()->email }}"
                readonly
                tabindex="-1"
            />
        </div>
        <div>
            <x-input-label for="password" :value="__('Password')" />
            <x-text-input id="password" wire:model="password" class="block w-full mt-1" type="password" required autocomplete="current-password" />
            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>
        <div class="pt-2 border-t border-edge-line">
            <x-primary-button class="w-full sm:w-auto min-w-[8rem]" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="submit">{{ __('Confirm') }}</span>
                <span wire:loading wire:target="submit" class="inline-flex items-center justify-center gap-2">
                    <x-spinner variant="cream" />
                    {{ __('Confirming…') }}
                </span>
            </x-primary-button>
        </div>
    </form>
</div>
