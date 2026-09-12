<nav x-data="{ open: false }" class="border-b border-slate-200 bg-white/95 backdrop-blur-sm">
    <!-- Primary Navigation Menu -->
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-16">
            <div class="flex">
                <!-- Logo -->
                <div class="shrink-0 flex items-center">
                    <a href="{{ route('dashboard') }}" class="text-lg font-semibold text-slate-900">
                        {{ config('app.name') }}
                    </a>
                </div>

                <!-- Navigation Links -->
                <div class="hidden space-x-8 sm:-my-px sm:ms-10 sm:flex items-center">
                    <x-nav-link :href="route('dashboard')" :active="request()->routeIs('dashboard')">
                        {{ __('Dashboard') }}
                    </x-nav-link>
                    <x-nav-link :href="route('edge.index')" :active="request()->routeIs('edge.*')">
                        {{ __('Projects') }}
                    </x-nav-link>
                    @can('viewAny', App\Models\ProviderCredential::class)
                        <x-nav-link :href="route('credentials.index')" :active="request()->routeIs('credentials.*')">
                            {{ __('Credentials') }}
                        </x-nav-link>
                    @endcan
                    <x-nav-link :href="route('organizations.index')" :active="request()->routeIs('organizations.*')">
                        {{ __('Organizations') }}
                    </x-nav-link>
                    @php $currentOrg = Auth::user()->currentOrganization(); @endphp
                    @if ($currentOrg)
                        <span class="text-sm text-slate-500">
                            <a href="{{ route('organizations.show', $currentOrg) }}" class="hover:text-slate-700">{{ $currentOrg->name }}</a>
                        </span>
                    @endif
                    <a href="{{ route('pricing') }}" class="inline-flex items-center border-b-2 border-transparent px-1 pt-1 text-sm font-medium text-slate-600 hover:border-slate-300 hover:text-slate-900">Pricing</a>
                </div>
            </div>

            <!-- Settings Dropdown -->
            <div class="hidden sm:flex sm:items-center sm:ms-6">
                <x-dropdown align="right" width="48">
                    <x-slot name="trigger">
                        <button class="inline-flex items-center px-3 py-2 border border-transparent text-sm leading-4 font-medium rounded-lg text-slate-600 bg-white hover:text-slate-900 focus:outline-none transition ease-in-out duration-150">
                            <div>{{ Auth::user()->name }}</div>

                            <div class="ms-1">
                                <x-heroicon-m-chevron-down class="h-4 w-4 fill-current" />
                            </div>
                        </button>
                    </x-slot>

                    <x-slot name="content">
                        <x-dropdown-link :href="route('settings.profile')">
                            {{ __('Profile') }}
                        </x-dropdown-link>

                        <!-- Authentication -->
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf

                            <x-dropdown-link :href="route('logout')"
                                    onclick="event.preventDefault();
                                                this.closest('form').submit();">
                                {{ __('Log Out') }}
                            </x-dropdown-link>
                        </form>
                    </x-slot>
                </x-dropdown>
            </div>

            <!-- Hamburger -->
            <div class="-me-2 flex items-center sm:hidden">
                <button @click="open = ! open" type="button" class="relative inline-flex h-10 w-10 items-center justify-center rounded-lg text-slate-500 hover:text-slate-700 hover:bg-slate-100 focus:outline-none transition duration-150 ease-in-out">
                    <x-heroicon-o-bars-3 class="absolute h-6 w-6" x-show="! open" />
                    <x-heroicon-o-x-mark class="absolute h-6 w-6" x-show="open" x-cloak />
                </button>
            </div>
        </div>
    </div>

    <!-- Responsive Navigation Menu -->
    <div :class="{'block': open, 'hidden': ! open}" class="hidden sm:hidden">
        <div class="pt-2 pb-3 space-y-1">
            <x-responsive-nav-link :href="route('dashboard')" :active="request()->routeIs('dashboard')">
                {{ __('Dashboard') }}
            </x-responsive-nav-link>
            <x-responsive-nav-link :href="route('edge.index')" :active="request()->routeIs('edge.*')">
                {{ __('Projects') }}
            </x-responsive-nav-link>
            @can('viewAny', App\Models\ProviderCredential::class)
                <x-responsive-nav-link :href="route('credentials.index')" :active="request()->routeIs('credentials.*')">
                    {{ __('Credentials') }}
                </x-responsive-nav-link>
            @endcan
            <x-responsive-nav-link :href="route('organizations.index')" :active="request()->routeIs('organizations.*')">
                {{ __('Organizations') }}
            </x-responsive-nav-link>
            @if ($currentOrg = Auth::user()->currentOrganization())
                <a href="{{ route('organizations.show', $currentOrg) }}" class="block border-l-4 border-transparent py-2 ps-4 pe-4 text-sm text-slate-500 hover:bg-slate-50">Current: {{ $currentOrg->name }}</a>
            @endif
            <a href="{{ route('pricing') }}" class="block border-l-4 border-transparent py-2 ps-4 pe-4 text-base font-medium text-slate-600 hover:border-slate-300 hover:bg-slate-50 hover:text-slate-900">Pricing</a>
        </div>

        <!-- Responsive Settings Options -->
        <div class="pt-4 pb-1 border-t border-slate-200">
            <div class="px-4">
                <div class="font-medium text-base text-slate-900">{{ Auth::user()->name }}</div>
                <div class="font-medium text-sm text-slate-500">{{ Auth::user()->email }}</div>
            </div>

            <div class="mt-3 space-y-1">
                <x-responsive-nav-link :href="route('settings.profile')">
                    {{ __('Profile') }}
                </x-responsive-nav-link>

                <!-- Authentication -->
                <form method="POST" action="{{ route('logout') }}">
                    @csrf

                    <x-responsive-nav-link :href="route('logout')"
                            onclick="event.preventDefault();
                                        this.closest('form').submit();">
                        {{ __('Log Out') }}
                    </x-responsive-nav-link>
                </form>
            </div>
        </div>
    </div>
</nav>
