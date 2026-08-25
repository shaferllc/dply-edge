@props([
    'active' => null,
    'showGuestSignup' => true,
])

@php
    $authed = auth()->check();
    $req = request();

    // Resolve every nav surface flag in one query. Without this each
    // @feature directive below issues its own SELECT against `features`.
    if ($authed && auth()->user()->currentOrganization()) {
        \Laravel\Pennant\Feature::loadMissing([
            'surface.cloud',
            'surface.edge',
            'surface.serverless',
            'surface.projects',
            'surface.status_pages',
        ]);
    }

    $featuresActive  = $active === 'features'  || $req->routeIs('features');
    $changelogActive = $active === 'changelog' || $req->routeIs('changelog');
    $pricingActive   = $active === 'pricing'   || $req->routeIs('pricing');
    $roadmapActive   = $active === 'roadmap'   || $req->routeIs('roadmap');
    $homeActive      = $active === 'home'      || ($req->is('/') && ! $req->routeIs('dashboard'));
    $hi      = 'h-5 w-5 shrink-0';
    $hiGuest = 'h-4 w-4 shrink-0 opacity-90';
    $browseActive = $req->routeIs(
        'infrastructure.*', 'servers.*', 'cloud.*', 'serverless.*', 'edge.*',
        'realtime.*', 'sites.*', 'projects.*', 'organizations.*', 'backups.*',
    );
    $moreMenuActive = $featuresActive
        || $changelogActive
        || $pricingActive
        || $roadmapActive
        || $req->routeIs('status-pages.*', 'marketplace.index', 'scripts.*', 'docs.*', 'blog.*')
        || ($authed
            && \Illuminate\Support\Facades\Gate::check('viewPlatformAdmin')
            && ($req->routeIs('admin.*') || $req->is('horizon*', 'pulse*')));
@endphp

<header x-data="{ open: false }" class="border-b border-brand-ink/10 bg-brand-cream/85 backdrop-blur-xl sticky top-0 z-30">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex items-center justify-between gap-2 sm:gap-3 py-2 sm:py-2.5">
            <div class="flex items-center justify-between sm:justify-start gap-2 sm:gap-3 min-w-0 shrink-0 w-full sm:w-auto">
                <a
                    href="{{ auth()->check() ? route('dashboard') : url('/') }}"
                    class="flex items-center gap-3 group shrink-0"
                >
                    <img
                        src="{{ asset('images/dply-logo.svg') }}"
                        alt="{{ config('app.name') }}"
                        @class([
                            'w-auto shrink-0 transition-transform duration-300 group-hover:scale-[1.02]',
                            'h-10 sm:h-11' => auth()->check(),
                            'h-14 sm:h-16 lg:h-[4.25rem]' => ! auth()->check(),
                        ])
                        width="148"
                        height="96"
                    />
                </a>
                @auth
                    {{-- currentOrganization() is memoised (resolved in middleware) and
                         returns an org whenever the user belongs to any — so this
                         reuses that result instead of a fresh organization_user join. --}}
                    @if (auth()->user()->currentOrganization())
                        <div class="flex min-w-0 flex-1 basis-0 max-w-[min(68vw,13.5rem)] sm:max-w-[min(44vw,18rem)] lg:max-w-[22rem] lg:flex-none">
                            @livewire('layout.context-breadcrumb', ['variant' => 'inline'], key('site-header-workspace'))
                        </div>
                    @endif
                    <button
                        type="button"
                        @click="open = ! open"
                        class="inline-flex items-center justify-center p-2 rounded-lg text-brand-moss hover:text-brand-ink hover:bg-brand-sand/40 focus:outline-none sm:hidden ms-auto"
                        aria-expanded="false"
                        :aria-expanded="open"
                        aria-label="Toggle navigation"
                    >
                        <span class="relative block h-6 w-6 shrink-0" aria-hidden="true">
                            <x-heroicon-o-bars-3 class="absolute inset-0 h-6 w-6 text-current" x-show="! open" />
                            <x-heroicon-o-x-mark class="absolute inset-0 h-6 w-6 text-current" x-show="open" x-cloak />
                        </span>
                    </button>
                @endauth
            </div>

            @guest
                <nav class="flex flex-wrap items-center justify-end gap-x-5 gap-y-2 sm:gap-6 lg:gap-8 text-sm font-medium w-full sm:w-auto" aria-label="Primary">
                    <a
                        href="{{ url('/') }}"
                        class="inline-flex items-center gap-1.5 {{ $homeActive ? 'text-brand-ink' : 'text-brand-moss hover:text-brand-ink' }} transition-colors"
                    >
                        <x-heroicon-o-home class="{{ $hiGuest }}" />
                        {{ __('Home') }}
                    </a>
                    <a
                        href="{{ route('features') }}"
                        class="inline-flex items-center gap-1.5 {{ $featuresActive ? 'text-brand-ink' : 'text-brand-moss hover:text-brand-ink' }} transition-colors"
                    >
                        <x-heroicon-o-sparkles class="{{ $hiGuest }}" />
                        {{ __('Features') }}
                    </a>
                    <a
                        href="{{ route('changelog') }}"
                        class="inline-flex items-center gap-1.5 {{ $changelogActive ? 'text-brand-ink' : 'text-brand-moss hover:text-brand-ink' }} transition-colors"
                    >
                        <x-heroicon-o-sparkles class="{{ $hiGuest }}" />
                        {{ __('Changelog') }}
                    </a>
                    <a
                        href="{{ route('pricing') }}"
                        class="inline-flex items-center gap-1.5 {{ $pricingActive ? 'text-brand-ink' : 'text-brand-moss hover:text-brand-ink' }} transition-colors"
                    >
                        <x-heroicon-o-credit-card class="{{ $hiGuest }}" />
                        {{ __('Pricing') }}
                    </a>
                    <a href="{{ route('login') }}" class="inline-flex items-center gap-1.5 text-brand-moss hover:text-brand-ink transition-colors">
                        <x-heroicon-o-arrow-right-end-on-rectangle class="{{ $hiGuest }}" />
                        {{ __('Log in') }}
                    </a>
                    @if ($showGuestSignup)
                        <a
                            href="{{ route('register') }}"
                            class="inline-flex items-center gap-1.5 px-4 py-2.5 rounded-lg bg-brand-ink text-brand-cream text-sm font-semibold shadow-sm shadow-brand-ink/10 hover:bg-brand-forest transition-colors"
                        >
                            <x-heroicon-o-rocket-launch class="{{ $hiGuest }}" />
                            {{ __('Start trial') }}
                        </a>
                    @endif
                </nav>
            @endguest

            @auth
                <div class="hidden sm:flex flex-1 min-w-0 items-center justify-end gap-0.5 lg:gap-1 ms-1 lg:ms-2">
                    {{-- overflow visible so dropdown panels are not clipped (CSS overflow-x:auto implies vertical clipping) --}}
                    <div class="min-w-0 shrink overflow-visible">
                        <nav class="flex min-h-[2.5rem] flex-nowrap items-center justify-end gap-x-0.5 pe-1 text-sm font-medium" aria-label="{{ __('App') }}">
                            <button
                                type="button"
                                @click="window.dispatchEvent(new CustomEvent('dply-command-palette-open'))"
                                class="group me-1 inline-flex shrink-0 items-center gap-2 rounded-lg border border-brand-ink/10 bg-white/60 px-2.5 py-1.5 text-brand-moss transition hover:border-brand-sage/40 hover:text-brand-ink focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-gold/40"
                                aria-label="{{ __('Search') }}"
                                title="{{ __('Search — ⌘K') }}"
                            >
                                <x-heroicon-o-magnifying-glass class="h-4 w-4 shrink-0" />
                                <kbd class="hidden items-center rounded bg-brand-sand/60 px-1 py-0.5 text-2xs font-semibold text-brand-moss lg:inline-flex">⌘K</kbd>
                            </button>
                            <x-nav-link :href="route('dashboard')" :active="request()->routeIs('dashboard')">
                                <x-slot name="icon">
                                    <x-heroicon-o-squares-2x2 class="{{ $hi }}" />
                                </x-slot>
                                {{ __('Dashboard') }}
                            </x-nav-link>
                            <x-dropdown align="right" width="40rem" contentClasses="p-0 overflow-hidden">
                                <x-slot name="trigger">
                                    <button
                                        type="button"
                                        class="group inline-flex shrink-0 items-center gap-2 whitespace-nowrap px-1.5 py-2 border-b-2 text-sm font-medium leading-5 transition duration-150 ease-in-out focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-gold/40 rounded-t {{ $browseActive ? 'border-brand-gold text-brand-ink' : 'border-transparent text-brand-moss hover:text-brand-ink hover:border-brand-sage/40' }}"
                                        aria-haspopup="menu"
                                    >
                                        <x-heroicon-o-rectangle-group class="{{ $hi }}" />
                                        {{ __('Browse') }}
                                        <x-heroicon-m-chevron-down class="h-3.5 w-3.5 shrink-0 opacity-70" />
                                    </button>
                                </x-slot>
                                <x-slot name="content">
                                    <div class="p-2">
                                        <p class="px-3 pb-1 pt-2 text-2xs font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Compute') }}</p>
                                        @feature('surface.edge')
                                            <x-dropdown-link :href="route('edge.index')" :description="__('Deploy to the global edge')">
                                                <x-slot name="icon">
                                                    <x-heroicon-o-globe-alt class="{{ $hi }}" />
                                                </x-slot>
                                                {{ __('Edge') }}
                                            </x-dropdown-link>
                                        @else
                                            <x-coming-soon-dropdown-link :description="__('Deploy to the global edge')">
                                                <x-slot name="icon">
                                                    <x-heroicon-o-globe-alt class="{{ $hi }}" />
                                                </x-slot>
                                                {{ __('Edge') }}
                                            </x-coming-soon-dropdown-link>
                                        @endfeature

                                        <p class="px-3 pb-1 pt-3 text-2xs font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Org') }}</p>
                                        <x-dropdown-link :href="route('organizations.index')" :description="__('Workspaces, members & billing')">
                                            <x-slot name="icon">
                                                <x-heroicon-o-building-office-2 class="{{ $hi }}" />
                                            </x-slot>
                                            {{ __('Organizations') }}
                                        </x-dropdown-link>
                                        @feature('surface.status_pages')
                                            <x-dropdown-link :href="route('status-pages.index')" :description="__('Public status pages & incidents')">
                                                <x-slot name="icon">
                                                    <x-heroicon-o-heart class="{{ $hi }}" />
                                                </x-slot>
                                                {{ __('Status pages') }}
                                            </x-dropdown-link>
                                        @endfeature
                                    </div>
                                </x-slot>
                            </x-dropdown>
                        </nav>
                    </div>
                    @auth
                        <livewire:notifications.bell />
                    @endauth
                    <div class="flex shrink-0 items-center border-l border-brand-ink/10 ps-1.5 lg:ps-2" aria-label="{{ __('More navigation') }}">
                        <x-dropdown align="right" width="36rem" contentClasses="p-0 overflow-hidden">
                            <x-slot name="trigger">
                                <button
                                    type="button"
                                    class="group inline-flex shrink-0 items-center gap-1 whitespace-nowrap px-1.5 py-2 border-b-2 text-sm font-medium leading-5 transition duration-150 ease-in-out focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-gold/40 rounded-t {{ $moreMenuActive ? 'border-brand-gold text-brand-ink' : 'border-transparent text-brand-moss hover:text-brand-ink hover:border-brand-sage/40' }}"
                                    aria-haspopup="menu"
                                >
                                    <x-heroicon-o-ellipsis-horizontal class="h-5 w-5 shrink-0 opacity-90" />
                                    <span class="hidden xl:inline">{{ __('More') }}</span>
                                    <x-heroicon-m-chevron-down class="h-3.5 w-3.5 shrink-0 opacity-70" />
                                </button>
                            </x-slot>
                            <x-slot name="content">
                                <div class="grid grid-cols-2 divide-x divide-brand-ink/10">
                                    {{-- Product --}}
                                    <div class="p-2">
                                        <p class="px-3 pb-1 pt-2 text-2xs font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Product') }}</p>
                                        <x-dropdown-link :href="route('features')" :description="__('Everything dply can do')">
                                            <x-slot name="icon">
                                                <x-heroicon-o-sparkles class="{{ $hi }}" />
                                            </x-slot>
                                            {{ __('Features') }}
                                        </x-dropdown-link>
                                        <x-dropdown-link :href="route('changelog')" :description="__('Recently shipped updates')">
                                            <x-slot name="icon">
                                                <x-heroicon-o-megaphone class="{{ $hi }}" />
                                            </x-slot>
                                            {{ __('Changelog') }}
                                        </x-dropdown-link>
                                    </div>

                                    {{-- Resources + Workspace --}}
                                    <div class="p-2">
                                        <p class="px-3 pb-1 pt-2 text-2xs font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Resources') }}</p>
                                        <x-dropdown-link :href="route('pricing')" :description="__('Plans & pricing')">
                                            <x-slot name="icon">
                                                <x-heroicon-o-credit-card class="{{ $hi }}" />
                                            </x-slot>
                                            {{ __('Pricing') }}
                                        </x-dropdown-link>

                                        @if (feature('surface.status_pages'))
                                            <p class="px-3 pb-1 pt-3 text-2xs font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Workspace') }}</p>
                                            @feature('surface.status_pages')
                                                <x-dropdown-link :href="route('status-pages.index')" :description="__('Public status pages')">
                                                    <x-slot name="icon">
                                                        <x-heroicon-o-check-circle class="{{ $hi }}" />
                                                    </x-slot>
                                                    {{ __('Status') }}
                                                </x-dropdown-link>
                                            @endfeature
                                        @endif
                                    </div>
                                </div>

                                @can('viewPlatformAdmin')
                                    {{-- Privileged tools as a full-width footer strip. --}}
                                    <div class="border-t border-brand-ink/10 bg-brand-sand/20 p-3">
                                        <p class="flex items-center gap-1.5 px-1 pb-2 text-2xs font-semibold uppercase tracking-[0.14em] text-brand-moss">
                                            <x-heroicon-m-shield-check class="h-3.5 w-3.5 shrink-0 text-brand-sage" aria-hidden="true" />
                                            {{ __('Platform admin') }}
                                        </p>
                                        <div class="grid grid-cols-3 gap-2">
                                            <a href="{{ route('admin.overview') }}" class="group flex flex-col gap-1.5 rounded-xl border border-brand-ink/10 bg-white px-3 py-2.5 shadow-sm transition hover:border-brand-sage/30 hover:bg-brand-sage/5">
                                                <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-brand-ink/[0.045] text-brand-moss ring-1 ring-brand-ink/[0.08] transition group-hover:bg-brand-sage/15 group-hover:text-brand-forest">
                                                    <x-heroicon-o-squares-2x2 class="h-4 w-4" aria-hidden="true" />
                                                </span>
                                                <span class="text-xs font-semibold text-brand-ink">{{ __('Overview') }}</span>
                                            </a>
                                            <a href="{{ route('horizon.index') }}" class="group flex flex-col gap-1.5 rounded-xl border border-brand-ink/10 bg-white px-3 py-2.5 shadow-sm transition hover:border-brand-sage/30 hover:bg-brand-sage/5">
                                                <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-brand-ink/[0.045] text-brand-moss ring-1 ring-brand-ink/[0.08] transition group-hover:bg-brand-sage/15 group-hover:text-brand-forest">
                                                    <x-heroicon-o-queue-list class="h-4 w-4" aria-hidden="true" />
                                                </span>
                                                <span class="text-xs font-semibold text-brand-ink">{{ __('Horizon') }}</span>
                                            </a>
                                            <a href="{{ route('pulse') }}" class="group flex flex-col gap-1.5 rounded-xl border border-brand-ink/10 bg-white px-3 py-2.5 shadow-sm transition hover:border-brand-sage/30 hover:bg-brand-sage/5">
                                                <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-brand-ink/[0.045] text-brand-moss ring-1 ring-brand-ink/[0.08] transition group-hover:bg-brand-sage/15 group-hover:text-brand-forest">
                                                    <x-heroicon-o-chart-bar class="h-4 w-4" aria-hidden="true" />
                                                </span>
                                                <span class="text-xs font-semibold text-brand-ink">{{ __('Pulse') }}</span>
                                            </a>
                                        </div>
                                    </div>
                                @endcan
                            </x-slot>
                        </x-dropdown>
                    </div>
                    <div class="flex shrink-0 items-center border-l border-brand-ink/10 ps-1.5 lg:ps-2" aria-label="{{ __('Account') }}">
                        <x-dropdown align="right" width="17rem" contentClasses="p-0 overflow-hidden">
                            <x-slot name="trigger">
                                <button type="button" class="inline-flex items-center gap-1.5 lg:gap-2 rounded-lg border border-brand-ink/10 bg-white/90 px-2 py-2 text-sm font-medium text-brand-ink shadow-sm shadow-brand-ink/5 transition hover:border-brand-ink/20 hover:bg-white focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-gold/50">
                                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-brand-ink/[0.05] text-brand-moss ring-1 ring-brand-ink/[0.08]" aria-hidden="true">
                                        <x-heroicon-o-user-circle class="h-4 w-4 shrink-0" />
                                    </span>
                                    <span class="hidden xl:inline max-w-[10rem] truncate text-left leading-tight text-brand-moss">{{ Auth::user()->name }}</span>
                                    <x-heroicon-m-chevron-down class="h-4 w-4 shrink-0 text-brand-moss" />
                                </button>
                            </x-slot>
                            <x-slot name="content">
                                @php
                                    $accountUser = auth()->user();
                                    $accountInitials = collect(preg_split('/\s+/', trim((string) $accountUser->name)))
                                        ->filter()->take(2)
                                        ->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->implode('');
                                    $accountInitials = $accountInitials !== '' ? $accountInitials : mb_strtoupper(mb_substr((string) $accountUser->name, 0, 2));
                                    $accountOrg = $accountUser->currentOrganization();
                                @endphp
                                {{-- Identity header. --}}
                                <div class="flex items-center gap-3 border-b border-brand-ink/10 bg-brand-sand/25 px-4 py-3">
                                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-brand-sage/15 text-sm font-bold text-brand-forest ring-1 ring-brand-sage/25" aria-hidden="true">{{ $accountInitials }}</span>
                                    <div class="min-w-0">
                                        <p class="truncate text-sm font-semibold text-brand-ink">{{ $accountUser->name }}</p>
                                        <p class="truncate text-xs text-brand-moss">{{ $accountUser->email }}</p>
                                    </div>
                                </div>

                                <div class="p-1.5">
                                    <x-dropdown-link :href="route('settings.profile')" :description="__('Profile, password & preferences')">
                                        <x-slot name="icon">
                                            <x-heroicon-o-cog-8-tooth class="{{ $hi }}" />
                                        </x-slot>
                                        {{ __('Settings') }}
                                    </x-dropdown-link>
                                    @if ($accountOrg)
                                        <x-dropdown-link :href="route('organizations.show', $accountOrg)" :description="$accountOrg->name">
                                            <x-slot name="icon">
                                                <x-heroicon-o-building-office-2 class="{{ $hi }}" />
                                            </x-slot>
                                            {{ __('Organization') }}
                                        </x-dropdown-link>
                                    @endif
                                </div>

                                {{-- Sign out footer. --}}
                                <form method="POST" action="{{ route('logout') }}" class="border-t border-brand-ink/10 p-1.5">
                                    @csrf
                                    <a
                                        href="{{ route('logout') }}"
                                        onclick="event.preventDefault(); this.closest('form').submit();"
                                        class="group flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-start text-sm font-medium leading-5 text-brand-ink transition duration-150 ease-out hover:bg-red-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-300"
                                    >
                                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-brand-ink/[0.045] text-brand-moss ring-1 ring-brand-ink/[0.08] transition group-hover:bg-red-100 group-hover:text-red-600 group-hover:ring-red-200 [&>svg]:h-[1.15rem] [&>svg]:w-[1.15rem]" aria-hidden="true">
                                            <x-heroicon-o-arrow-right-start-on-rectangle class="{{ $hi }}" />
                                        </span>
                                        <span class="text-brand-moss transition group-hover:text-red-600">{{ __('Log out') }}</span>
                                    </a>
                                </form>
                            </x-slot>
                        </x-dropdown>
                    </div>
                </div>
            @endauth
        </div>
    </div>

    @auth
        <div
            x-cloak
            x-show="open"
            x-transition
            class="sm:hidden border-t border-brand-ink/10 bg-brand-cream/95"
            id="site-header-mobile-menu"
        >
            <div class="px-4 pt-2 pb-4 space-y-1 max-w-7xl mx-auto">
                <x-responsive-nav-link :href="route('dashboard')" :active="request()->routeIs('dashboard')">
                    <x-slot name="icon">
                        <x-heroicon-o-squares-2x2 class="{{ $hi }}" />
                    </x-slot>
                    {{ __('Dashboard') }}
                </x-responsive-nav-link>
                <p class="px-4 pt-2 pb-1 text-xs font-semibold uppercase tracking-wider text-brand-mist">{{ __('Compute') }}</p>
                @feature('surface.edge')
                    <x-responsive-nav-link :href="route('edge.index')" :active="request()->routeIs('edge.*')">
                        <x-slot name="icon">
                            <x-heroicon-o-globe-alt class="{{ $hi }}" />
                        </x-slot>
                        {{ __('Edge') }}
                    </x-responsive-nav-link>
                @else
                    <x-coming-soon-responsive-nav-link>
                        <x-slot name="icon">
                            <x-heroicon-o-globe-alt class="{{ $hi }}" />
                        </x-slot>
                        {{ __('Edge') }}
                    </x-coming-soon-responsive-nav-link>
                @endfeature
                <p class="px-4 pt-2 pb-1 text-xs font-semibold uppercase tracking-wider text-brand-mist">{{ __('Org') }}</p>
                <x-responsive-nav-link :href="route('organizations.index')" :active="request()->routeIs('organizations.*')">
                    <x-slot name="icon">
                        <x-heroicon-o-building-office-2 class="{{ $hi }}" />
                    </x-slot>
                    {{ __('Organizations') }}
                </x-responsive-nav-link>
                @feature('surface.status_pages')
                    <x-responsive-nav-link :href="route('status-pages.index')" :active="request()->routeIs('status-pages.*')">
                        <x-slot name="icon">
                            <x-heroicon-o-check-circle class="{{ $hi }}" />
                        </x-slot>
                        {{ __('Status') }}
                    </x-responsive-nav-link>
                @endfeature
                @can('viewPlatformAdmin')
                    <div class="border-t border-brand-ink/10 pt-2 mt-2">
                        <p class="px-4 pb-1 text-xs font-semibold uppercase tracking-wider text-brand-mist">{{ __('Admin') }}</p>
                        <x-responsive-nav-link :href="route('admin.overview')" :active="request()->routeIs('admin.*')">
                            <x-slot name="icon">
                                <x-heroicon-o-shield-check class="{{ $hi }}" />
                            </x-slot>
                            {{ __('Platform overview') }}
                        </x-responsive-nav-link>
                        <x-responsive-nav-link :href="route('horizon.index')" :active="request()->is('horizon*')">
                            <x-slot name="icon">
                                <x-heroicon-o-queue-list class="{{ $hi }}" />
                            </x-slot>
                            {{ __('Horizon') }}
                        </x-responsive-nav-link>
                        <x-responsive-nav-link :href="route('pulse')" :active="request()->is('pulse*')">
                            <x-slot name="icon">
                                <x-heroicon-o-chart-bar class="{{ $hi }}" />
                            </x-slot>
                            {{ __('Laravel Pulse') }}
                        </x-responsive-nav-link>
                    </div>
                @endcan
                <a href="{{ route('features') }}" class="flex items-center gap-2.5 border-l-4 {{ $featuresActive ? 'border-brand-gold bg-brand-sand/30 text-brand-ink' : 'border-transparent text-brand-moss hover:bg-brand-sand/30' }} py-2 ps-3 pe-4 text-base font-medium">
                    <x-heroicon-o-sparkles class="h-5 w-5 shrink-0 opacity-90" />
                    {{ __('Features') }}
                </a>
                <a href="{{ route('changelog') }}" class="flex items-center gap-2.5 border-l-4 {{ $changelogActive ? 'border-brand-gold bg-brand-sand/30 text-brand-ink' : 'border-transparent text-brand-moss hover:bg-brand-sand/30' }} py-2 ps-3 pe-4 text-base font-medium">
                    <x-heroicon-o-megaphone class="h-5 w-5 shrink-0 opacity-90" />
                    {{ __('Changelog') }}
                </a>
                <a href="{{ route('pricing') }}" class="flex items-center gap-2.5 border-l-4 {{ $pricingActive ? 'border-brand-gold bg-brand-sand/30 text-brand-ink' : 'border-transparent text-brand-moss hover:bg-brand-sand/30' }} py-2 ps-3 pe-4 text-base font-medium">
                    <x-heroicon-o-credit-card class="h-5 w-5 shrink-0 opacity-90" />
                    {{ __('Pricing') }}
                </a>
                <div class="pt-4 mt-2 border-t border-brand-ink/10">
                    <p class="px-4 text-xs font-semibold uppercase tracking-wider text-brand-mist">{{ Auth::user()->name }}</p>
                    <p class="px-4 text-sm text-brand-moss">{{ Auth::user()->email }}</p>
                    <div class="mt-2 space-y-1">
                        <x-responsive-nav-link :href="route('settings.profile')">
                            <x-slot name="icon">
                                <x-heroicon-o-cog-8-tooth class="{{ $hi }}" />
                            </x-slot>
                            {{ __('Settings') }}
                        </x-responsive-nav-link>
                        <x-responsive-nav-link :href="route('settings.profile')">
                            <x-slot name="icon">
                                <x-heroicon-o-user class="{{ $hi }}" />
                            </x-slot>
                            {{ __('Profile') }}
                        </x-responsive-nav-link>
                        @if (auth()->user()->currentOrganization())
                            <x-responsive-nav-link :href="route('organizations.show', auth()->user()->currentOrganization())">
                                <x-slot name="icon">
                                    <x-heroicon-o-building-office-2 class="{{ $hi }}" />
                                </x-slot>
                                {{ __('Org settings') }}
                            </x-responsive-nav-link>
                        @endif
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <x-responsive-nav-link :href="route('logout')" onclick="event.preventDefault(); this.closest('form').submit();">
                                <x-slot name="icon">
                                    <x-heroicon-o-arrow-right-start-on-rectangle class="{{ $hi }}" />
                                </x-slot>
                                {{ __('Log Out') }}
                            </x-responsive-nav-link>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    @endauth
</header>

@auth
    {{-- Global command palette (⌘K). Mounted alongside the header so it's
         available on EVERY page that renders the header — including the guest
         marketing pages (changelog, features, pricing, welcome) when viewed
         while signed in. Rendered as a sibling of <header> (not nested) so the
         full-screen overlay isn't trapped in the header's stacking context. --}}
    <livewire:command-palette :key="'global-command-palette'" />
@endauth
