{{-- Guest-facing footer for the dply-edge marketing pages ("Terminal"). --}}
<footer class="border-t border-edge-line bg-edge-void">
    <div class="mx-auto max-w-6xl px-6 py-10 lg:px-10">
        <div class="flex flex-col gap-8 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <a href="{{ url('/') }}" class="font-terminal text-sm font-bold tracking-[-0.02em] text-edge-text">
                    dply<span class="text-edge-lime">/</span>edge
                </a>
                <p class="mt-3 max-w-xs text-sm leading-6 text-edge-mute">
                    {{ __('Git repository in, whole app out: Laravel, Rails, Node and static sites, with their databases and workers.') }}
                </p>
            </div>

            <div class="grid grid-cols-2 gap-x-12 gap-y-6 sm:grid-cols-3">
                <div>
                    <p class="font-terminal text-[11px] uppercase tracking-[0.14em] text-edge-faint">{{ __('Product') }}</p>
                    <ul class="mt-3 space-y-2">
                        <li><a href="{{ route('pricing') }}" class="text-sm text-edge-mute transition-colors hover:text-edge-text">{{ __('Pricing') }}</a></li>
                        <li><a href="{{ route('features') }}" class="text-sm text-edge-mute transition-colors hover:text-edge-text">{{ __('Features') }}</a></li>
                        <li><a href="{{ route('docs.index') }}" class="text-sm text-edge-mute transition-colors hover:text-edge-text">{{ __('Docs') }}</a></li>
                        <li><a href="{{ route('compliance') }}" class="text-sm text-edge-mute transition-colors hover:text-edge-text">{{ __('Security & compliance') }}</a></li>
                        <li><a href="mailto:{{ config('dply.support_email') }}" class="text-sm text-edge-mute transition-colors hover:text-edge-text">{{ __('Contact') }}</a></li>
                    </ul>
                </div>
                <div>
                    <p class="font-terminal text-[11px] uppercase tracking-[0.14em] text-edge-faint">{{ __('Move') }}</p>
                    <ul class="mt-3 space-y-2">
                        <li><a href="{{ route('compare', 'forge') }}" class="text-sm text-edge-mute transition-colors hover:text-edge-text">{{ __('vs Laravel Forge') }}</a></li>
                        <li><a href="{{ route('compare', 'laravel-cloud') }}" class="text-sm text-edge-mute transition-colors hover:text-edge-text">{{ __('vs Laravel Cloud') }}</a></li>
                        <li><a href="{{ route('compare', 'heroku') }}" class="text-sm text-edge-mute transition-colors hover:text-edge-text">{{ __('vs Heroku') }}</a></li>
                        <li><a href="{{ route('docs.show', 'guides/migrate-from-vercel-netlify') }}" class="text-sm text-edge-mute transition-colors hover:text-edge-text">{{ __('From Vercel or Netlify') }}</a></li>
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
            <p class="font-terminal text-xs text-edge-faint">&copy; {{ date('Y') }} {{ config('legal.entity') }}</p>
            <nav aria-label="{{ __('Legal') }}" class="flex flex-wrap gap-x-4 gap-y-1 font-terminal text-xs">
                <a href="{{ route('legal.terms') }}" class="text-edge-faint transition-colors hover:text-edge-text">{{ __('Terms') }}</a>
                <a href="{{ route('legal.privacy') }}" class="text-edge-faint transition-colors hover:text-edge-text">{{ __('Privacy') }}</a>
                <a href="{{ route('legal.acceptable-use') }}" class="text-edge-faint transition-colors hover:text-edge-text">{{ __('Acceptable use') }}</a>
                <a href="{{ route('legal.dpa') }}" class="text-edge-faint transition-colors hover:text-edge-text">{{ __('DPA') }}</a>
            </nav>
        </div>
    </div>
</footer>
