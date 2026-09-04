<footer class="border-t border-brand-ink/10 bg-brand-ink text-brand-sand/90">
    <div class="dply-page-shell py-7">
        <div class="flex flex-col gap-5 sm:flex-row sm:items-center sm:justify-between">
            <a href="{{ url('/') }}" class="inline-flex items-center">
                {{-- Same lockup component as both headers, so the footer cannot drift
                     from them again. It replaces a dply-mark-dark.svg + "ply" span,
                     which rendered a different wordmark ("d"+ply) in a different
                     typeface (Space Grotesk vs the terminal face). --}}
                <x-dply-wordmark class="text-base text-edge-void" slash="text-edge-void" />
            </a>
            {{-- One inline row rather than stacked Product/Account columns: same
                 destinations, a fraction of the height. "Trial & pricing" is gone
                 because it pointed at route('pricing'), same as "Pricing". --}}
            <nav class="flex flex-wrap items-center gap-x-6 gap-y-2 text-sm text-brand-sand/80">
                <a href="{{ url('/') }}" class="hover:text-brand-cream transition-colors">Overview</a>
                <a href="{{ route('features') }}" class="hover:text-brand-cream transition-colors">Features</a>
                <a href="{{ route('pricing') }}" class="hover:text-brand-cream transition-colors">Pricing</a>
                @auth
                    <a href="{{ route('dashboard') }}" class="hover:text-brand-cream transition-colors">Dashboard</a>
                    {{-- Moved here from the header "More" menu; same auth + feature gates. --}}
                    @feature('surface.status_pages')
                        <a href="{{ route('status-pages.index') }}" class="hover:text-brand-cream transition-colors">{{ __('Status') }}</a>
                    @endfeature
                @else
                    <a href="{{ route('login') }}" class="hover:text-brand-cream transition-colors">Log in</a>
                    <a href="{{ route('register') }}" class="font-medium text-brand-gold/90 hover:text-brand-cream transition-colors">Start trial</a>
                @endauth
            </nav>
        </div>
        {{-- Privileged tools, moved from the header "More" menu. Same @can gate;
             kept on their own line so they read as a distinct, restricted group. --}}
        @can('viewPlatformAdmin')
            <div class="mt-4 flex flex-wrap items-center gap-x-5 gap-y-2 text-xs text-brand-sand/70">
                <span class="inline-flex items-center gap-1.5 font-semibold uppercase tracking-[0.14em] text-brand-mist">
                    <x-heroicon-m-shield-check class="h-3.5 w-3.5 shrink-0 text-brand-sage" aria-hidden="true" />
                    {{ __('Platform admin') }}
                </span>
                <a href="{{ route('admin.overview') }}" class="hover:text-brand-cream transition-colors">{{ __('Overview') }}</a>
                <a href="{{ route('horizon.index') }}" class="hover:text-brand-cream transition-colors">{{ __('Horizon') }}</a>
                <a href="{{ route('pulse') }}" class="hover:text-brand-cream transition-colors">{{ __('Pulse') }}</a>
            </div>
        @endcan
        <div class="mt-5 pt-4 border-t border-white/10 flex flex-col sm:flex-row justify-between items-center gap-2 text-xs text-brand-mist">
            <span>&copy; {{ date('Y') }} {{ config('app.name') }}. All rights reserved.
                <span class="ml-2 font-mono text-brand-sand/40" title="{{ \App\Support\AppVersion::sha() }}">v{{ \App\Support\AppVersion::date() }}</span>
            </span>
            <span class="hidden sm:inline text-brand-sand/50">Built for regulated teams and growing engineering orgs.</span>
        </div>
    </div>
</footer>
