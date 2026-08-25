<div>
    <x-livewire-validation-errors />

    @if ($emailLocked)
        <div class="mb-6 border border-edge-lime/30 bg-edge-lime/5 p-4">
            <p class="flex items-center gap-2 text-sm font-semibold text-edge-text">
                <x-heroicon-o-sparkles class="h-4 w-4 shrink-0 text-edge-lime" aria-hidden="true" />
                {{ __('You’re invited to the dply beta') }}
            </p>
            <p class="mt-1 text-sm text-edge-mute">
                {{ __('Connect your own cloud servers free during the beta, plus one dply-managed server on us. Finish creating your account below.') }}
            </p>
        </div>
    @endif

    @if (!empty($oauthProviders) && ! $emailLocked)
        <div class="mb-6">
            <p class="mb-3 flex items-center gap-2 text-sm font-medium text-brand-moss">
                <x-heroicon-o-link class="h-4 w-4 shrink-0 text-edge-lime" aria-hidden="true" />
                {{ __('Or continue with') }}
            </p>
            <div class="flex flex-col gap-2">
                @foreach ($oauthProviders as $p)
                    <a href="{{ route('oauth.redirect', ['provider' => $p['id']]) }}" class="inline-flex items-center justify-center gap-2 border border-edge-line bg-transparent px-4 py-2.5 text-sm font-medium text-edge-text transition-colors hover:border-edge-lime/50 hover:bg-edge-lime/10">
                        <x-oauth-provider-icon :provider="$p['id']" />
                        {{ $p['name'] }}
                    </a>
                @endforeach
            </div>
        </div>
        <p class="mb-5 flex items-center justify-center gap-2 text-center text-sm text-brand-moss">
            <span class="h-px flex-1 max-w-[4rem] bg-edge-line" aria-hidden="true"></span>
            {{ __('Or register with email') }}
            <span class="h-px flex-1 max-w-[4rem] bg-edge-line" aria-hidden="true"></span>
        </p>
    @endif

    <form wire:submit="submit" class="space-y-5">
        <div>
            <x-input-label for="name" :value="__('Name')" />
            <x-text-input id="name" wire:model="form.name" class="block w-full mt-1" type="text" required autofocus autocomplete="name" />
            <x-input-error :messages="$errors->get('form.name')" class="mt-2" />
        </div>
        <div>
            <x-input-label for="email" :value="__('Email')" />
            <x-text-input id="email" wire:model="form.email" class="block w-full mt-1 {{ $emailLocked ? 'opacity-70' : '' }}" type="email" required autocomplete="username" :readonly="$emailLocked" />
            @if ($emailLocked)
                <p class="mt-1 text-xs text-brand-moss">{{ __('Your invite is tied to this address.') }}</p>
            @endif
            <x-input-error :messages="$errors->get('form.email')" class="mt-2" />
        </div>
        <div>
            <x-input-label for="password" :value="__('Password')" />
            <x-text-input id="password" wire:model="form.password" class="block w-full mt-1" type="password" required autocomplete="new-password" />
            <x-input-error :messages="$errors->get('form.password')" class="mt-2" />
        </div>
        <div>
            <x-input-label for="password_confirmation" :value="__('Confirm Password')" />
            <x-text-input id="password_confirmation" wire:model="form.password_confirmation" class="block w-full mt-1" type="password" required autocomplete="new-password" />
            <x-input-error :messages="$errors->get('form.password_confirmation')" class="mt-2" />
        </div>
        <div class="flex flex-col-reverse sm:flex-row sm:items-center sm:justify-end gap-3 pt-2 border-t border-edge-line">
            <a href="{{ route('login') }}" class="inline-flex items-center justify-center gap-2 text-center text-sm font-medium text-edge-mute transition-colors hover:text-edge-lime sm:text-left">
                <x-heroicon-o-arrow-right-end-on-rectangle class="h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
                {{ __('Already registered?') }}
            </a>
            <x-primary-button class="w-full sm:w-auto min-w-[8rem]" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="submit">{{ __('Register') }}</span>
                <span wire:loading wire:target="submit" class="inline-flex items-center justify-center gap-2">
                    <x-spinner variant="cream" />
                    {{ __('Creating account…') }}
                </span>
            </x-primary-button>
        </div>
    </form>
</div>
