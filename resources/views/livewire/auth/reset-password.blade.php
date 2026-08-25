<div>
    <x-livewire-validation-errors />
    <form wire:submit="submit" class="space-y-5">
        <div>
            <x-input-label for="email" :value="__('Email')" />
            <div class="relative mt-1">
                <span class="pointer-events-none absolute inset-y-0 start-0 flex items-center ps-3 text-brand-mist" aria-hidden="true">
                    <x-heroicon-o-envelope class="h-4 w-4" />
                </span>
                <x-text-input id="email" wire:model="email" class="block w-full ps-10" type="email" required autofocus autocomplete="username" />
            </div>
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>
        <div>
            <x-input-label for="password" :value="__('Password')" />
            <x-text-input id="password" wire:model="password" class="block w-full mt-1" type="password" required autocomplete="new-password" />
            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>
        <div>
            <x-input-label for="password_confirmation" :value="__('Confirm Password')" />
            <x-text-input id="password_confirmation" wire:model="password_confirmation" class="block w-full mt-1" type="password" required autocomplete="new-password" />
            <x-input-error :messages="$errors->get('password_confirmation')" class="mt-2" />
        </div>
        <div class="pt-2 border-t border-edge-line">
            <x-primary-button class="w-full sm:w-auto min-w-[10rem]" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="submit">{{ __('Reset Password') }}</span>
                <span wire:loading wire:target="submit" class="inline-flex items-center justify-center gap-2">
                    <x-spinner variant="cream" />
                    {{ __('Resetting…') }}
                </span>
            </x-primary-button>
        </div>
    </form>
</div>
