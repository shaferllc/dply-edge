<div>
    <h1 class="text-xl font-bold text-edge-text">{{ $updated ? __('We updated our terms') : __('One more step') }}</h1>
    <p class="mt-2 text-sm leading-6 text-edge-mute">
        {{ $updated
            ? __('Please review and accept the updated terms to keep using dply. They took effect on :date.', ['date' => config('legal.version')])
            : __('Please review and accept the terms to use dply.') }}
    </p>
    <ul class="mt-4 space-y-1 text-sm">
        <li><a href="{{ route('legal.terms') }}" target="_blank" class="font-semibold text-edge-text underline hover:text-edge-lime">{{ __('Terms of Service') }}</a></li>
        <li><a href="{{ route('legal.privacy') }}" target="_blank" class="font-semibold text-edge-text underline hover:text-edge-lime">{{ __('Privacy Policy') }}</a></li>
        <li><a href="{{ route('legal.acceptable-use') }}" target="_blank" class="font-semibold text-edge-text underline hover:text-edge-lime">{{ __('Acceptable Use Policy') }}</a></li>
        <li><a href="{{ route('legal.dpa') }}" target="_blank" class="font-semibold text-edge-text underline hover:text-edge-lime">{{ __('Data Processing Addendum') }}</a></li>
    </ul>
    <form wire:submit="accept" class="mt-6 space-y-4">
        <label for="agree" class="flex items-start gap-2 text-sm text-edge-mute">
            <input id="agree" type="checkbox" wire:model="agree" class="mt-0.5 rounded border-edge-line bg-transparent text-edge-lime focus:ring-edge-lime" />
            <span>{{ __('I agree to the Terms of Service, Privacy Policy and Acceptable Use Policy.') }}</span>
        </label>
        <x-input-error :messages="$errors->get('agree')" />
        <div class="flex items-center justify-between gap-3 border-t border-edge-line pt-4">
            <button type="button" onclick="document.getElementById('accept-terms-logout').submit()" class="text-sm text-edge-mute hover:text-edge-text">{{ __('Sign out') }}</button>
            <x-primary-button>{{ __('Accept and continue') }}</x-primary-button>
        </div>
    </form>
    <form id="accept-terms-logout" method="POST" action="{{ route('logout') }}" class="hidden">@csrf</form>
</div>
