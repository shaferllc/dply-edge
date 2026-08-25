<div>
    <div class="mb-6 space-y-4">
        <div class="flex gap-3 border border-edge-line bg-edge-panel px-4 py-3 text-sm text-edge-mute">
            <span class="flex h-9 w-9 shrink-0 items-center justify-center border border-edge-line bg-edge-void text-edge-lime" aria-hidden="true">
                <x-heroicon-o-envelope class="h-5 w-5" />
            </span>
            <p class="leading-relaxed">{{ __('Thanks for signing up! Before getting started, verify your email using the link we sent. If you did not receive it, you can request another below.') }}</p>
        </div>
        <div class="flex gap-3 border border-edge-lime/30 bg-edge-lime/5 px-4 py-3 text-sm text-edge-text">
            <x-heroicon-o-shield-check class="mt-0.5 h-5 w-5 shrink-0 text-edge-lime" aria-hidden="true" />
            <p class="leading-relaxed">{{ __('Creating servers, organizations, and other sensitive actions require a verified email address.') }}</p>
        </div>
    </div>
    @if (session('error'))
        <div class="mb-5 flex gap-3 border border-rose-500/40 bg-rose-500/10 px-4 py-3 text-sm text-rose-300" role="alert">
            <x-heroicon-o-exclamation-circle class="h-5 w-5 shrink-0 text-rose-400" aria-hidden="true" />
            <span>{{ session('error') }}</span>
        </div>
    @endif
    @if (session('status') == 'verification-link-sent')
        <div class="mb-5 flex gap-3 border border-edge-lime/40 bg-edge-lime/10 px-4 py-3 text-sm text-edge-lime" role="status">
            <x-heroicon-o-check class="h-5 w-5 shrink-0 text-emerald-600" aria-hidden="true" />
            <span class="font-medium">{{ __('A new verification link has been sent to the email address you provided during registration.') }}</span>
        </div>
    @endif
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 pt-2 border-t border-edge-line">
        <button type="button" wire:click="sendNotification" class="dply-btn-primary inline-flex items-center justify-center gap-2 px-5 py-2.5 text-sm font-bold transition-colors focus:outline-none focus:ring-2 focus:ring-edge-lime/50">
            <x-heroicon-o-arrow-path class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
            {{ __('Resend Verification Email') }}
        </button>
        <form method="POST" action="{{ route('logout') }}" class="inline flex justify-center sm:justify-end">
            @csrf
            <button type="submit" class="inline-flex items-center gap-2 px-2 py-1 text-sm font-medium text-edge-mute transition-colors hover:text-edge-lime focus:outline-none focus:ring-2 focus:ring-edge-lime/40">
                <x-heroicon-o-arrow-left-end-on-rectangle class="h-4 w-4 text-brand-sage" aria-hidden="true" />
                {{ __('Log Out') }}
            </button>
        </form>
    </div>
</div>
