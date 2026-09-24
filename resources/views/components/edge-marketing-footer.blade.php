{{-- Guest-facing footer for the dply-edge marketing pages ("Terminal"). --}}
<footer class="border-t border-edge-line bg-edge-void">
    <div class="mx-auto max-w-6xl px-6 py-10 lg:px-10">
        <div class="flex flex-col gap-8 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <a href="{{ url('/') }}" class="font-terminal text-sm font-bold tracking-[-0.02em] text-edge-text">
                    dply<span class="text-edge-lime">/</span>edge
                </a>
                <p class="mt-3 max-w-xs text-sm leading-6 text-edge-mute">
                    {{ __('Git repository in, site on the edge out. Static, hybrid or Worker SSR.') }}
                </p>
            </div>

            <div class="grid grid-cols-2 gap-x-12 gap-y-6 sm:grid-cols-3">
                <div>
                    <p class="font-terminal text-[11px] uppercase tracking-[0.14em] text-edge-faint">{{ __('Product') }}</p>
                    <ul class="mt-3 space-y-2">
                        <li><a href="{{ route('pricing') }}" class="text-sm text-edge-mute transition-colors hover:text-edge-text">{{ __('Pricing') }}</a></li>
                        <li><a href="{{ route('features') }}" class="text-sm text-edge-mute transition-colors hover:text-edge-text">{{ __('Features') }}</a></li>
                    </ul>
                </div>
                <div>
                    <p class="font-terminal text-[11px] uppercase tracking-[0.14em] text-edge-faint">{{ __('Move') }}</p>
                    <ul class="mt-3 space-y-2">
                        <li><a href="{{ route('cli.install') }}" class="text-sm text-edge-mute transition-colors hover:text-edge-text">{{ __('CLI') }}</a></li>
                    </ul>
                </div>
                <div>
                    <p class="font-terminal text-[11px] uppercase tracking-[0.14em] text-edge-faint">{{ __('Account') }}</p>
                    <ul class="mt-3 space-y-2">
                        @auth
                            <li><a href="{{ route('dashboard') }}" class="text-sm text-edge-mute transition-colors hover:text-edge-text">{{ __('Dashboard') }}</a></li>
                        @else
                            <li><a href="{{ route('login') }}" class="text-sm text-edge-mute transition-colors hover:text-edge-text">{{ __('Sign in') }}</a></li>
                            <li><a href="{{ route('register') }}" class="text-sm text-edge-mute transition-colors hover:text-edge-text">{{ __('Create account') }}</a></li>
                        @endauth
                    </ul>
                </div>
            </div>
        </div>

        <div class="mt-10 flex flex-col gap-3 border-t border-edge-line pt-6 sm:flex-row sm:items-center sm:justify-between">
            <p class="font-terminal text-xs text-edge-faint">&copy; {{ date('Y') }} dply</p>
            <p class="font-terminal text-xs text-edge-faint">{{ __('Built on Dply Edge') }}</p>
        </div>
    </div>
</footer>
