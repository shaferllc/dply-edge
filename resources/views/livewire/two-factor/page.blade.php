@php
    $user = auth()->user();
    $issuer = (string) config('app.name');
    $confirmedAt = $user->two_factor_confirmed_at;
    $codesLeft = $this->recoveryCodesRemaining;

    $shellTitle = $this->isManageMode ? __('Two-factor authentication') : __('Set up two-factor authentication');
    $shellDescription = $this->isManageMode
        ? __('A code from your authenticator app is required at sign-in. Disabling needs your password plus a current code or a recovery code.')
        : ($this->needsStart
            ? __('Pair an authenticator app with your account so a stolen password alone can\'t sign in as you.')
            : __('Scan the QR code with your authenticator app, then enter the 6-digit code it shows to confirm.'));

    // Status reads off the two 2FA columns: no secret → off, secret without a
    // confirmation stamp → half-finished setup, confirmed → on.
    [$statusLabel, $statusTone] = $this->isManageMode
        ? [__('Enabled'), 'ok']
        : ($this->needsStart ? [__('Off'), 'warn'] : [__('Awaiting confirmation'), 'warn']);

    $steps = [
        [
            'icon' => 'heroicon-o-device-phone-mobile',
            'title' => __('1. Install an authenticator'),
            'body' => __('1Password, Bitwarden, Authy, Google Authenticator, or Microsoft Authenticator — any TOTP app works.'),
        ],
        [
            'icon' => 'heroicon-o-qr-code',
            'title' => __('2. Scan the QR code'),
            'body' => __('The app stores the secret and starts generating a fresh 6-digit code every 30 seconds, offline.'),
        ],
        [
            'icon' => 'heroicon-o-lifebuoy',
            'title' => __('3. Save your recovery codes'),
            'body' => __('Shown once, right after you confirm. They are the way back in if you lose the device.'),
        ],
    ];
@endphp

<div>
    @push('breadcrumbs')
        <x-breadcrumb-trail :items="[
            ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
            ['label' => __('Profile'), 'href' => route('settings.profile'), 'icon' => 'user-circle'],
            ['label' => __('Security'), 'href' => route('profile.security'), 'icon' => 'shield-check'],
            ['label' => __('Two-factor authentication'), 'icon' => 'lock-closed'],
        ]" />
    @endpush

    <x-profile-shell
        :title="$shellTitle"
        :description="$shellDescription"
        icon="heroicon-o-lock-closed"
    >
        <x-slot:stats>
            <x-workspace-stat-strip
                :columns="4"
                :stats="[
                    ['label' => __('Status'), 'value' => $statusLabel, 'tone' => $statusTone],
                    ['label' => __('Method'), 'value' => __('Authenticator app'), 'hint' => __('Time-based one-time password (TOTP)')],
                    [
                        'label' => __('Recovery codes'),
                        'value' => $codesLeft === null ? '—' : trans_choice(':n left|:n left', $codesLeft, ['n' => $codesLeft]),
                        'tone' => $codesLeft !== null && $codesLeft <= 2 ? 'warn' : null,
                        'hint' => __('Single-use codes that stand in for your authenticator.'),
                    ],
                    [
                        'label' => $this->isManageMode ? __('Enabled') : __('Account'),
                        'value' => $this->isManageMode ? ($confirmedAt?->format('M j, Y') ?? '—') : $user->email,
                        'hint' => $this->isManageMode ? $confirmedAt?->diffForHumans() : $user->email,
                    ],
                ]"
            />
        </x-slot:stats>

        <div class="px-3 py-2.5 sm:px-4">
            <x-livewire-validation-errors />
        </div>

        @if ($this->isManageMode)
            {{-- ENABLED: what's protecting the account, then the disable form. --}}
            <x-workspace-panel-head
                dense
                class="border-y border-brand-ink/10"
                icon="heroicon-o-shield-check"
                :title="__('Protection in place')"
                :note="__('Sign-in asks for a code from your authenticator app.')"
            />
            <dl class="grid gap-px border-b border-brand-ink/10 bg-brand-ink/5 sm:grid-cols-3">
                <div class="bg-white px-3 py-2">
                    <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Issuer') }}</dt>
                    <dd class="mt-0.5 truncate text-sm font-medium text-brand-ink">{{ $issuer }}</dd>
                </div>
                <div class="bg-white px-3 py-2">
                    <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Account') }}</dt>
                    <dd class="mt-0.5 truncate text-sm font-medium text-brand-ink" title="{{ $user->email }}">{{ $user->email }}</dd>
                </div>
                <div class="bg-white px-3 py-2">
                    <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Confirmed') }}</dt>
                    <dd class="mt-0.5 truncate text-sm font-medium text-brand-ink">{{ $confirmedAt?->diffForHumans() ?? '—' }}</dd>
                </div>
            </dl>

            <x-workspace-panel-head
                dense
                class="border-b border-brand-ink/10"
                icon="heroicon-o-lifebuoy"
                :title="__('Recovery codes')"
                :count="$codesLeft"
                :note="__('Single-use. Each one signs you in once when the authenticator is unavailable.')"
                :tone="$codesLeft !== null && $codesLeft <= 2 ? 'amber' : null"
            />
            <div class="border-b border-brand-ink/10 px-3 py-2.5 text-xs leading-relaxed text-brand-moss sm:px-4">
                @if ($codesLeft === 0)
                    <p class="font-semibold text-amber-800">{{ __('You have no recovery codes left.') }}</p>
                    <p class="mt-0.5">{{ __('Disable and re-enable two-factor authentication to get a fresh set — you will need your authenticator app to do it.') }}</p>
                @else
                    <p>{{ __('Codes are stored hashed, so they can only be shown once — at the moment you turn 2FA on. If you no longer have that list, disable and re-enable 2FA to issue a new set.') }}</p>
                @endif
            </div>

            <x-workspace-panel-head
                dense
                tone="danger"
                class="border-b border-brand-ink/10"
                icon="heroicon-o-exclamation-triangle"
                :title="__('Disable two-factor')"
                :note="__('Your account falls back to password-only sign-in.')"
            />
            <form wire:submit="disable" class="px-3 py-2.5 sm:px-4" autocomplete="on">
                <div class="sr-only">
                    <label for="two_factor_disable_username">{{ __('Account email') }}</label>
                    <input
                        id="two_factor_disable_username"
                        type="email"
                        name="username"
                        autocomplete="username"
                        value="{{ $user->email }}"
                        readonly
                        tabindex="-1"
                    />
                </div>
                <div class="grid gap-2.5 sm:grid-cols-2">
                    <div>
                        <x-input-label for="password" :value="__('Password')" />
                        <x-text-input id="password" wire:model="password" type="password" class="mt-1 block w-full" autocomplete="current-password" />
                        <x-input-error :messages="$errors->get('password')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="disable_code" :value="__('Code')" />
                        <x-text-input id="disable_code" wire:model="disable_code" type="text" class="mt-1 block w-full font-mono" inputmode="numeric" autocomplete="one-time-code" placeholder="{{ __('6-digit code or recovery code') }}" />
                        <x-input-error :messages="$errors->get('disable_code')" class="mt-1" />
                    </div>
                </div>
                <div class="mt-2.5 flex flex-wrap items-center justify-end gap-2">
                    <a href="{{ route('profile.security') }}" wire:navigate class="text-xs font-medium text-brand-moss hover:text-brand-ink">{{ __('Cancel') }}</a>
                    <button
                        type="submit"
                        class="inline-flex h-6 items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2.5 text-xs font-semibold text-brand-moss shadow-sm transition hover:border-rose-200 hover:bg-rose-50 hover:text-rose-700"
                    >
                        <x-heroicon-o-shield-exclamation class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                        {{ __('Disable two-factor') }}
                    </button>
                </div>
            </form>
        @elseif ($this->needsStart)
            {{-- OFF: explain the three steps before asking for the commitment. --}}
            <x-workspace-panel-head
                dense
                class="border-y border-brand-ink/10"
                icon="heroicon-o-map"
                :title="__('How it works')"
                :note="__('About a minute, and you only do it once per device.')"
            >
                <x-slot:actions>
                    <button
                        type="button"
                        wire:click="store"
                        class="inline-flex h-6 items-center gap-1 rounded-md bg-brand-ink px-2 text-xs font-semibold text-brand-cream shadow-sm transition-colors hover:bg-brand-forest"
                    >
                        <x-heroicon-o-shield-check class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                        {{ __('Start setup') }}
                    </button>
                </x-slot:actions>
            </x-workspace-panel-head>
            <ol class="grid gap-px border-b border-brand-ink/10 bg-brand-ink/5 sm:grid-cols-3">
                @foreach ($steps as $step)
                    <li class="bg-white px-3 py-2.5">
                        <p class="flex items-center gap-1.5 text-sm font-semibold text-brand-ink">
                            <x-dynamic-component :component="$step['icon']" class="h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
                            {{ $step['title'] }}
                        </p>
                        <p class="mt-1 text-xs leading-relaxed text-brand-moss">{{ $step['body'] }}</p>
                    </li>
                @endforeach
            </ol>

            <x-workspace-panel-head
                dense
                class="border-b border-brand-ink/10"
                icon="heroicon-o-information-circle"
                :title="__('Good to know')"
            />
            <ul class="space-y-1 border-b border-brand-ink/10 px-3 py-2.5 text-xs leading-relaxed text-brand-moss sm:px-4">
                <li>· {{ __('Codes are generated on your device from a shared secret — the app needs no network connection.') }}</li>
                <li>· {{ __('Each code is valid for about 30 seconds, so a captured one is worthless minutes later.') }}</li>
                <li>· {{ __('You get eight single-use recovery codes when setup completes. Store them where you would keep a spare key.') }}</li>
                <li>· {{ __('Turning 2FA off later needs your password plus a current code or one recovery code.') }}</li>
            </ul>

            <div class="flex flex-wrap items-center justify-between gap-2 px-3 py-2.5 sm:px-4">
                <p class="text-xs text-brand-mist">{{ __('Ready? You will need your phone or password manager to hand.') }}</p>
                <div class="flex items-center gap-2">
                    <a href="{{ route('profile.security') }}" wire:navigate class="text-xs font-medium text-brand-moss hover:text-brand-ink">{{ __('Cancel') }}</a>
                    <button
                        type="button"
                        wire:click="store"
                        class="inline-flex h-7 items-center gap-1.5 rounded-md bg-brand-ink px-2.5 text-xs font-semibold text-brand-cream shadow-sm transition-colors hover:bg-brand-forest"
                    >
                        <x-heroicon-o-shield-check class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                        {{ __('Enable two-factor authentication') }}
                    </button>
                </div>
            </div>
        @else
            {{-- MID-SETUP: QR + manual key on the left, confirmation on the right. --}}
            <x-workspace-panel-head
                dense
                class="border-y border-brand-ink/10"
                icon="heroicon-o-qr-code"
                :title="__('Pair your authenticator')"
                :note="__('Nothing is enabled until you confirm a code below.')"
            >
                <x-slot:actions>
                    {{-- store() re-rolls the secret: the escape hatch when a scan
                         half-registered or the wrong account got paired. --}}
                    <button
                        type="button"
                        wire:click="store"
                        class="inline-flex h-6 items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/40"
                    >
                        <x-heroicon-o-arrow-path class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                        {{ __('New QR code') }}
                    </button>
                </x-slot:actions>
            </x-workspace-panel-head>

            <div class="grid gap-px border-b border-brand-ink/10 bg-brand-ink/5 lg:grid-cols-2">
                <div class="flex flex-col items-center justify-center bg-white px-3 py-3">
                    @if ($this->qrSvg)
                        <div class="inline-block rounded-lg border border-brand-ink/10 bg-white p-2">
                            {!! $this->qrSvg !!}
                        </div>
                    @endif
                    <p class="mt-2 text-center text-xs text-brand-mist">
                        {{ __('Scanning as :issuer (:email)', ['issuer' => $issuer, 'email' => $user->email]) }}
                    </p>
                </div>

                <div class="bg-white px-3 py-3 sm:px-4">
                    @if ($this->setupKey)
                        <p class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __("Can't scan? Enter this key") }}</p>
                        <div
                            class="mt-1 flex items-center gap-2"
                            x-data="{ copied: false }"
                        >
                            <code class="min-w-0 flex-1 truncate rounded-md border border-brand-ink/10 bg-brand-sand/25 px-2 py-1.5 font-mono text-xs tracking-wider text-brand-ink">{{ trim(chunk_split($this->setupKey, 4, ' ')) }}</code>
                            <button
                                type="button"
                                x-on:click="navigator.clipboard.writeText(@js($this->setupKey)); copied = true; setTimeout(() => copied = false, 1500)"
                                class="inline-flex h-6 shrink-0 items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/40"
                            >
                                <x-heroicon-o-clipboard class="h-3.5 w-3.5 shrink-0" x-show="! copied" aria-hidden="true" />
                                <x-heroicon-m-check class="h-3.5 w-3.5 shrink-0 text-emerald-600" x-show="copied" x-cloak aria-hidden="true" />
                                <span x-text="copied ? @js(__('Copied')) : @js(__('Copy'))">{{ __('Copy') }}</span>
                            </button>
                        </div>
                        <p class="mt-1 text-xs text-brand-mist">{{ __('Pick "time-based" or "TOTP" if your app asks for a key type.') }}</p>
                    @endif

                    <form wire:submit="confirm" class="{{ $this->setupKey ? 'mt-3 border-t border-brand-ink/10 pt-3' : '' }}">
                        <x-input-label for="code" :value="__('Verification code')" />
                        <div class="mt-1 flex flex-wrap items-center gap-2">
                            <x-text-input
                                id="code"
                                wire:model="code"
                                type="text"
                                class="w-32 font-mono tracking-[0.3em]"
                                inputmode="numeric"
                                autocomplete="one-time-code"
                                maxlength="6"
                                placeholder="000000"
                            />
                            <button
                                type="submit"
                                wire:loading.attr="disabled"
                                wire:target="confirm"
                                class="inline-flex h-7 items-center gap-1.5 rounded-md bg-brand-ink px-2.5 text-xs font-semibold text-brand-cream shadow-sm transition-colors hover:bg-brand-forest disabled:opacity-60"
                            >
                                <x-heroicon-o-check class="h-3.5 w-3.5 shrink-0" wire:loading.remove wire:target="confirm" aria-hidden="true" />
                                <span wire:loading wire:target="confirm" class="inline-flex h-3.5 w-3.5 shrink-0 items-center justify-center"><x-spinner variant="cream" size="sm" /></span>
                                {{ __('Confirm and enable') }}
                            </button>
                            <a href="{{ route('profile.security') }}" wire:navigate class="text-xs font-medium text-brand-moss hover:text-brand-ink">{{ __('Cancel') }}</a>
                        </div>
                        <x-input-error :messages="$errors->get('code')" class="mt-1" />
                        <p class="mt-1.5 text-xs leading-relaxed text-brand-mist">
                            {{ __('Your recovery codes appear on the Security page the moment this succeeds — that is the only time they are shown.') }}
                        </p>
                    </form>
                </div>
            </div>
        @endif
    </x-profile-shell>
</div>
