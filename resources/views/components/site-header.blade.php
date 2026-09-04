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
    $pricingActive   = $active === 'pricing'   || $req->routeIs('pricing');
    $homeActive      = $active === 'home'      || ($req->is('/') && ! $req->routeIs('dashboard'));
    $hi      = 'h-5 w-5 shrink-0';
    $hiGuest = 'h-4 w-4 shrink-0 opacity-90';
@endphp

<header x-data="{ open: false }" class="border-b border-brand-ink/10 bg-brand-cream/85 backdrop-blur-xl sticky top-0 z-30">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex items-center justify-between gap-2 sm:gap-3 py-2 sm:py-2.5">
            <div class="flex items-center justify-between sm:justify-start gap-2 sm:gap-3 min-w-0 shrink-0 w-full sm:w-auto">
                <a
                    href="{{ auth()->check() ? route('dashboard') : url('/') }}"
                    class="flex items-center gap-3 group shrink-0"
                >
                    <x-dply-wordmark @class([
                        'transition-opacity duration-200 group-hover:opacity-80',
                        'text-[15px]' => auth()->check(),
                        'text-lg sm:text-xl' => ! auth()->check(),
                    ]) />
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
                                class="group me-2 inline-flex shrink-0 items-center gap-2 rounded-md border border-brand-ink/15 px-2.5 py-1.5 text-brand-mist transition-colors hover:border-brand-ink/30 hover:text-brand-ink focus:outline-none focus-visible:ring-2 focus-visible:ring-edge-lime/40"
                                aria-label="{{ __('Search') }}"
                                title="{{ __('Search — ⌘K') }}"
                            >
                                <x-heroicon-o-magnifying-glass class="h-4 w-4 shrink-0" />
                                <kbd class="hidden items-center font-terminal text-2xs font-semibold tracking-tight text-brand-mist lg:inline-flex">⌘K</kbd>
                            </button>
                            <x-nav-link :href="route('dashboard')" :active="request()->routeIs('dashboard')">
                                <x-slot name="icon">
                                    <x-heroicon-o-squares-2x2 class="{{ $hi }}" />
                                </x-slot>
                                {{ __('Dashboard') }}
                            </x-nav-link>
                        </nav>
                    </div>
                    @auth
                        <livewire:notifications.bell />
                    @endauth
                    <div class="flex shrink-0 items-center border-l border-brand-ink/10 ps-1.5 lg:ps-2" aria-label="{{ __('Account') }}">
                        <x-dropdown align="right" width="17rem" contentClasses="p-0 overflow-hidden">
                            <x-slot name="trigger">
                                <button type="button" class="inline-flex items-center gap-1.5 lg:gap-2 rounded-md border border-transparent px-2 py-1.5 text-sm font-medium text-brand-ink transition-colors hover:border-brand-ink/15 hover:bg-brand-ink/5 focus:outline-none focus-visible:ring-2 focus-visible:ring-edge-lime/40">
                                    <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded bg-brand-ink/10 text-brand-mist" aria-hidden="true">
                                        <x-heroicon-o-user-circle class="h-3.5 w-3.5 shrink-0" />
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
                        {{ __('Apps') }}
                    </x-responsive-nav-link>
                @else
                    <x-coming-soon-responsive-nav-link>
                        <x-slot name="icon">
                            <x-heroicon-o-globe-alt class="{{ $hi }}" />
                        </x-slot>
                        {{ __('Apps') }}
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
         marketing pages (features, pricing, welcome) when viewed
         while signed in. Rendered as a sibling of <header> (not nested) so the
         full-screen overlay isn't trapped in the header's stacking context. --}}
    <livewire:command-palette :key="'global-command-palette'" />
@endauth
