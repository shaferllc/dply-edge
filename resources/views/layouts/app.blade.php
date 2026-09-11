<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        @include('partials.theme-head')

        <title>@yield('title', config('app.name', 'Laravel'))</title>

        @php
            // Driver-aware: resolves whichever broadcast connection is active —
            // reverb (local dev) or pusher → the dply realtime Worker (prod).
            $echoClient = \App\Support\EchoClientConfig::forBrowser();
        @endphp
        @if ($echoClient)
            {{-- Echo reads this at runtime (bypasses stale Vite env in public/build). Meta is fallback if window is cleared. --}}
            <meta name="dply-reverb-config" content="{{ e(json_encode($echoClient)) }}">
            <script>
                window.__DPLY_REVERB__ = @json($echoClient);
            </script>
        @endif

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @if ($echoClient)
            {{-- After Vite so a stale app-*.js that still bundled Echo cannot overwrite this. --}}
            @include('partials.reverb-echo-module')
        @endif
        @livewireStyles
        <style>[x-cloak]{display:none!important}</style>
        @php
            $toastPosition = \App\Support\NotificationToastPosition::resolvedFor(auth()->user());
        @endphp
    </head>
    <body class="font-sans antialiased bg-brand-cream text-brand-ink min-h-dvh flex flex-col" x-data="toastStore({ position: @js($toastPosition) })">
        @include('partials.skip-link')
        <x-impersonation-banner />
        <div class="flex flex-col flex-1 min-h-0">
            <x-site-header />

            @auth
                <div
                    id="dply-broadcast-context"
                    class="hidden"
                    aria-hidden="true"
                    data-organization-id="{{ auth()->user()->currentOrganization()?->id }}"
                    data-user-id="{{ auth()->id() }}"
                ></div>

                {{-- Billing state (a canceled subscription in its grace period) is
                     app-wide, so it lives here rather than in the shells.

                     No wrapper: the component renders its own full-bleed band with
                     an inner max-w-7xl container, so an org with nothing to show
                     leaves no blank strip here. --}}
                <x-trial-pause-banner :organization="auth()->user()->currentOrganization()" />
            @endauth

            <!-- Page Heading -->
            @isset($header)
                <header class="border-b border-brand-ink/10 bg-brand-cream/90 backdrop-blur-sm">
                    <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
                        {{ $header }}
                    </div>
                </header>
            @endisset

            <!-- Page Content -->
            <main id="main-content" tabindex="-1" class="flex-1 w-full pb-28 sm:pb-32">
                {{ $slot }}
            </main>
        </div>

        <x-marketing-footer />

        {{ $modals ?? '' }}

        {{-- Toasts (from Livewire dispatch('notify')) --}}
        @include('partials.toast-stack')

        @auth
            {{-- The global command palette (⌘K) is now mounted inside
                 <x-site-header> (rendered above) so the shortcut + search also
                 work on guest marketing pages (features / pricing / welcome)
                 when signed in — not just inside this app layout. --}}

            {{-- Shared Git provider connect modal (OAuth + PAT). Mounted here — not
                 inside page Livewire components — so teleported modal actions stay
                 bound to this component instead of the parent page. --}}
            <livewire:settings.connect-provider-modal :key="'global-connect-provider-modal'" />

        @endauth

        @include('partials.session-flash-toasts')
        @livewireScripts
        @include('partials.livewire-toast-events')
        @stack('scripts')
        <script>
            document.addEventListener('livewire:init', () => {
                Livewire.on('provision-journey-complete', (e) => {
                    const payload = Array.isArray(e) ? e[0] : e;
                    const url = payload?.url ?? payload?.detail?.url;

                    if (url) {
                        window.location.assign(url);
                    }
                });

                // Open a third-party link in a new tab WITHOUT navigating away —
                // used by the Telegram connect flow, which has no redirect back,
                // so the half-filled channel form has to survive on this page
                // while the operator picks a chat in the Telegram app.
                Livewire.on('open-external', (e) => {
                    const payload = Array.isArray(e) ? e[0] : e;
                    const url = payload?.url ?? payload?.detail?.url;

                    if (typeof url === 'string' && url.startsWith('https://')) {
                        window.open(url, '_blank', 'noopener,noreferrer');
                    }
                });
            });
        </script>
    </body>
</html>
