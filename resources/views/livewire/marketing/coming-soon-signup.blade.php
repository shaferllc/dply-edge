<div class="edge-auth min-h-screen bg-edge-void font-display text-edge-text">

    <x-edge-marketing-header />

    @if (session('status'))
        <div class="px-4 pt-6 sm:px-6 lg:px-8">
            <div class="mx-auto max-w-7xl">
                <div class="border border-edge-line bg-edge-panel px-4 py-3 text-sm font-medium text-edge-lime">
                    {{ session('status') }}
                </div>
            </div>
        </div>
    @endif

    <main class="px-4 py-14 sm:px-6 sm:py-20 lg:px-8 lg:py-24">
        <section class="mx-auto max-w-7xl">
            <div class="grid gap-8 lg:grid-cols-[minmax(0,1.1fr)_minmax(360px,0.9fr)] lg:items-center">
                <div class="max-w-2xl">
                    <p class="inline-flex items-center gap-2 rounded-full border border-edge-line bg-edge-panel px-4 py-1.5 text-xs font-semibold uppercase tracking-[0.2em] text-edge-lime">
                        <span class="h-2 w-2 rounded-full bg-edge-lime/10" aria-hidden="true"></span>
                        {{ $eyebrow }}
                    </p>
                    <h1 class="mt-8 text-4xl font-bold tracking-tight text-edge-text sm:text-5xl lg:text-6xl lg:leading-[1.05]">
                        {{ $headline }}
                    </h1>
                    <p class="mt-6 max-w-xl text-lg leading-8 text-edge-mute sm:text-xl">
                        {{ $subheadline }}
                    </p>

                    <dl class="mt-10 grid gap-4 sm:grid-cols-3">
                        <div class="border border-edge-line bg-edge-panel p-4">
                            <dt class="text-xs font-semibold uppercase tracking-[0.18em] text-edge-faint">{{ __('Access') }}</dt>
                            <dd class="mt-2 text-sm font-medium text-edge-text">{{ __('Early invite list') }}</dd>
                        </div>
                        <div class="border border-edge-line bg-edge-panel p-4">
                            <dt class="text-xs font-semibold uppercase tracking-[0.18em] text-edge-faint">{{ __('For teams') }}</dt>
                            <dd class="mt-2 text-sm font-medium text-edge-text">{{ __('Ops, platform, and delivery') }}</dd>
                        </div>
                        <div class="border border-edge-line bg-edge-panel p-4">
                            <dt class="text-xs font-semibold uppercase tracking-[0.18em] text-edge-faint">{{ __('Existing users') }}</dt>
                            <dd class="mt-2 text-sm font-medium text-edge-text">{{ __('Use the login path below') }}</dd>
                        </div>
                    </dl>
                </div>

                <div class="border border-edge-line bg-edge-panel p-6 ring-1 ring-edge-line sm:p-8">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <p class="text-sm font-semibold uppercase tracking-[0.18em] text-edge-lime">{{ __('Get launch updates') }}</p>
                            <h2 class="mt-3 text-2xl font-semibold tracking-tight text-edge-text">{{ __('Request early access') }}</h2>
                        </div>
                        <span class="inline-flex items-center border border-edge-line px-3 py-1 text-xs font-semibold uppercase tracking-[0.16em] text-edge-mute">
                            {{ __('Email list') }}
                        </span>
                    </div>

                    <p class="mt-4 text-sm leading-6 text-edge-mute">
                        {{ __('Leave your email and we will reach out when the live rollout is ready. Existing customers can continue straight to login.') }}
                    </p>

                    @if ($submitted)
                        <div class="mt-6 border border-edge-line bg-edge-lime/10 px-4 py-4 text-sm leading-6 text-edge-lime">
                            {{ $successMessage }}
                        </div>
                    @endif

                    <form wire:submit="submit" class="mt-6 space-y-4">
                        <div>
                            <x-input-label for="coming_soon_email" :value="__('Work email')" />
                            <x-text-input
                                id="coming_soon_email"
                                wire:model.live.debounce.300ms="email"
                                type="email"
                                inputmode="email"
                                autocomplete="email"
                                class="w-full"
                                placeholder="you@company.com"
                            />
                            <x-input-error :messages="$errors->get('email')" class="mt-2" />
                        </div>

                        <x-primary-button class="w-full" wire:loading.attr="disabled" wire:target="submit">
                            <span wire:loading.remove wire:target="submit">{{ __('Join the list') }}</span>
                            <span wire:loading wire:target="submit">{{ __('Saving...') }}</span>
                        </x-primary-button>
                    </form>

                    <div class="mt-6 flex flex-col gap-3 border-t border-edge-line pt-6 sm:flex-row sm:items-center sm:justify-between">
                        <div class="text-sm text-edge-mute">
                            {{ __('Already have access?') }}
                        </div>
                        <a
                            href="{{ route('login') }}"
                            class="inline-flex items-center justify-center border border-edge-line bg-edge-panel px-4 py-2.5 text-sm font-semibold text-edge-text transition hover:border-edge-line hover:bg-edge-panel"
                        >
                            {{ __('Log in') }}
                        </a>
                    </div>
                </div>
            </div>
        </section>
    </main>
</div>
