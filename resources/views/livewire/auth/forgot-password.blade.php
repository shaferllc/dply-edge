<div>
    <x-livewire-validation-errors />
    <div class="mb-6 flex gap-3 border border-edge-line bg-edge-panel px-4 py-3 text-sm text-edge-mute">
        <span class="flex h-9 w-9 shrink-0 items-center justify-center border border-edge-line bg-edge-void text-edge-lime" aria-hidden="true">
            <x-heroicon-o-information-circle class="h-5 w-5" />
        </span>
        <p class="leading-relaxed">{{ __('Forgot your password? No problem. Enter the email for your account and we will send a password reset link.') }}</p>
    </div>
    <x-auth-session-status class="mb-4 border border-edge-lime/40 bg-edge-lime/10 px-4 py-3 text-sm text-edge-lime" :status="session('status')" />
    <form wire:submit="submit" class="space-y-5">
        <div>
            <x-input-label for="email" :value="__('Email')" />
            <x-text-input id="email" wire:model="email" class="block w-full mt-1" type="email" required autofocus />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>
        <div class="flex flex-col-reverse sm:flex-row sm:items-center sm:justify-end gap-3 pt-2 border-t border-edge-line">
            <a href="{{ route('login') }}" class="inline-flex items-center justify-center gap-2 text-sm font-medium text-edge-mute hover:text-edge-lime text-center sm:text-left">
                <x-heroicon-o-arrow-left class="h-4 w-4 shrink-0 text-edge-lime" aria-hidden="true" />
                {{ __('Back to log in') }}
            </a>
            <x-primary-button class="w-full sm:w-auto min-w-[8rem]" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="submit">{{ __('Email Password Reset Link') }}</span>
                <span wire:loading wire:target="submit" class="inline-flex items-center justify-center gap-2">
                    <x-spinner variant="cream" />
                    {{ __('Sending…') }}
                </span>
            </x-primary-button>
        </div>
    </form>
</div>
