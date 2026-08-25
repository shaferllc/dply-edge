@php
    $u = auth()->user();
    $twoFactorOn = $u->hasTwoFactorEnabled();
    $passkeyCount = $passkeys->count();
    $linkedOAuth = collect($oauthProviders ?? [])->sum(fn ($p) => $socialAccounts->where('provider', $p['id'])->count());

    // Overall posture: green when 2FA + ≥1 passkey OR ≥1 OAuth link;
    // amber if password-only; neutral when nothing is set up yet.
    if ($twoFactorOn && ($passkeyCount > 0 || $linkedOAuth > 0)) {
        $postureTone = 'success';
        $postureLabel = __('Hardened');
        $postureSub = __('2FA + passkey/OAuth');
    } elseif ($twoFactorOn) {
        $postureTone = 'info';
        $postureLabel = __('Good');
        $postureSub = __('Password + 2FA');
    } else {
        $postureTone = 'warning';
        $postureLabel = __('Password only');
        $postureSub = __('Add 2FA or a passkey');
    }
    $postureTile = [
        'success' => 'bg-brand-sage/10',
        'info' => 'bg-sky-50',
        'warning' => 'bg-amber-50',
    ][$postureTone];
    $postureDot = [
        'success' => 'bg-brand-sage',
        'info' => 'bg-sky-500',
        'warning' => 'bg-amber-500',
    ][$postureTone];
@endphp

<div
    x-data="{
        passwordSaved: false,
        init() {
            $wire.on('password-updated', () => {
                this.passwordSaved = true;
                setTimeout(() => { this.passwordSaved = false }, 2000);
            });
        },
    }"
>
    {{-- Must stay INSIDE the root: Livewire injects wire:id into the first tag
         of the rendered view, so a @vite <link>/<script> above the root steals
         it and orphans this div. --}}
    @vite(['resources/js/dply-passkeys-lazy.js'])

    <x-livewire-validation-errors />

    @push('breadcrumbs')
        <x-breadcrumb-trail doc-contextual :items="[
            ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
            ['label' => __('Profile'), 'href' => route('settings.profile'), 'icon' => 'user-circle'],
            ['label' => __('Security'), 'icon' => 'shield-check'],
        ]" />
    @endpush

    <x-profile-shell
        :title="__('Security')"
        :description="__('Password, passkeys, OAuth sign-in, and 2FA — layer at least two so a stolen credential alone can\'t reach your account.')"
        icon="heroicon-o-shield-check"
    >
        {{-- No header actions: the breadcrumb already goes back to Profile, and
             2FA setup lives in its own section header below. --}}

        <x-slot:stats>
            <dl class="grid grid-cols-3 gap-px bg-brand-ink/5" aria-label="{{ __('Security at a glance') }}">
                <div class="px-3 py-2 {{ $postureTile }}">
                    <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Posture') }}</dt>
                    <dd class="mt-0.5 flex items-baseline gap-1.5">
                        <span class="inline-flex items-center gap-1.5 text-sm font-semibold text-brand-ink">
                            <span class="inline-block h-1.5 w-1.5 shrink-0 rounded-full {{ $postureDot }}" aria-hidden="true"></span>
                            {{ $postureLabel }}
                        </span>
                        <span class="truncate text-xs text-brand-moss" title="{{ $postureSub }}">{{ $postureSub }}</span>
                    </dd>
                </div>
                <div class="bg-white px-3 py-2">
                    <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Passkeys') }}</dt>
                    <dd class="mt-0.5 flex items-baseline gap-1.5">
                        <span class="font-mono text-base font-semibold tabular-nums text-brand-ink">{{ $passkeyCount }}</span>
                        <span class="truncate text-xs text-brand-moss">{{ __('registered') }}</span>
                    </dd>
                </div>
                <div @class([
                    'px-3 py-2',
                    'bg-brand-sage/10' => $twoFactorOn,
                    'bg-amber-50' => ! $twoFactorOn,
                ])>
                    <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('2FA') }}</dt>
                    <dd class="mt-0.5 flex items-baseline gap-1.5">
                        <span class="inline-flex items-center gap-1.5 text-sm font-semibold text-brand-ink">
                            @if ($twoFactorOn)
                                <x-heroicon-m-check-circle class="h-3.5 w-3.5 shrink-0 text-brand-forest" aria-hidden="true" />
                                {{ __('Enabled') }}
                            @else
                                <x-heroicon-m-exclamation-triangle class="h-3.5 w-3.5 shrink-0 text-amber-900" aria-hidden="true" />
                                {{ __('Off') }}
                            @endif
                        </span>
                        <span class="truncate text-xs text-brand-moss">{{ __('authenticator code') }}</span>
                    </dd>
                </div>
            </dl>
        </x-slot:stats>

        {{-- Password --}}
        <div class="border-b border-brand-ink/10">
            <x-workspace-panel-head
                dense
                icon="heroicon-o-lock-closed"
                :title="__('Password')"
                :note="__('Use a long, random password and store it in a password manager.')"
            >
                <x-slot:actions>
                    <p x-show="passwordSaved" x-transition x-cloak class="inline-flex items-center gap-1 text-xs font-semibold text-emerald-700">
                        <x-heroicon-m-check-circle class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                        {{ __('Saved') }}
                    </p>
                </x-slot:actions>
            </x-workspace-panel-head>
            <form wire:submit="updatePassword" autocomplete="on">
                {{-- Hidden username so password managers can pair the new value
                     with the right login. Without this, browsers complain. --}}
                <div class="sr-only">
                    <label for="security_autocomplete_username">{{ __('Account email') }}</label>
                    <input
                        id="security_autocomplete_username"
                        type="email"
                        name="username"
                        autocomplete="username"
                        value="{{ auth()->user()->email }}"
                        readonly
                        tabindex="-1"
                    />
                </div>
                <div class="px-3 py-2.5 sm:px-4">
                    <div class="grid gap-2.5 sm:grid-cols-2">
                        <div class="sm:col-span-2">
                            <x-input-label for="security_current_password" :value="__('Current password')" />
                            <x-text-input id="security_current_password" wire:model="current_password" type="password" class="mt-1 block w-full" autocomplete="current-password" />
                            <x-input-error :messages="$errors->get('current_password')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="security_password" :value="__('New password')" />
                            <x-text-input id="security_password" wire:model="password" type="password" class="mt-1 block w-full" autocomplete="new-password" />
                            <x-input-error :messages="$errors->get('password')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="security_password_confirmation" :value="__('Confirm new password')" />
                            <x-text-input id="security_password_confirmation" wire:model="password_confirmation" type="password" class="mt-1 block w-full" autocomplete="new-password" />
                            <x-input-error :messages="$errors->get('password_confirmation')" class="mt-2" />
                        </div>
                    </div>
                </div>
            </form>
        </div>

        <x-unsaved-changes-bar
            :message="__('You have unsaved changes to your password.')"
            saveAction="updatePassword"
            discardAction="discardPasswordUnsaved"
            targets="current_password,password,password_confirmation"
            :saveLabel="__('Save password')"
        />

        {{-- Passkeys --}}
        <div class="border-b border-brand-ink/10">
            <x-workspace-panel-head
                dense
                icon="heroicon-o-finger-print"
                :title="__('Passkeys')"
                :count="$passkeyCount > 0 ? $passkeyCount : null"
                :note="__('Sign in with your device PIN, fingerprint, or a security key.')"
            />
            <div class="px-3 py-2.5 sm:px-4">
                @error('passkey')
                    <p class="mb-2 text-xs text-red-600">{{ $message }}</p>
                @enderror
                <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                    <div class="flex-1">
                        <label for="dply-passkey-alias" class="sr-only">{{ __('Passkey name') }}</label>
                        <input
                            id="dply-passkey-alias"
                            type="text"
                            maxlength="255"
                            autocomplete="off"
                            placeholder="{{ __('Name this passkey — e.g. Work laptop') }}"
                            class="h-7 w-full rounded-md border-brand-ink/15 bg-white py-0 px-2.5 text-xs text-brand-ink placeholder:text-brand-mist shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                        />
                    </div>
                    <button
                        type="button"
                        id="dply-passkey-register-btn"
                        class="inline-flex h-7 shrink-0 items-center gap-1 rounded-md bg-brand-ink px-2.5 text-xs font-semibold text-brand-cream shadow-sm transition-colors hover:bg-brand-forest disabled:opacity-60"
                    >
                        <x-heroicon-o-plus class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                        {{ __('Add a passkey') }}
                    </button>
                </div>
                <p id="dply-passkey-register-error" class="mt-1.5 hidden text-xs text-red-700" role="alert"></p>
            </div>

            @if ($passkeys->isEmpty())
                {{-- An empty state that shows what a passkey is, rather than one
                     grey line of text under a caption bar. --}}
                <div class="flex flex-col items-center gap-1.5 border-t border-brand-ink/10 px-3 py-7 text-center sm:px-4">
                    <span class="flex h-10 w-10 items-center justify-center rounded-2xl bg-brand-sand/45 text-brand-mist ring-1 ring-brand-ink/10">
                        <x-heroicon-o-finger-print class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <p class="mt-1 text-sm font-semibold text-brand-ink">{{ __('No passkeys registered yet') }}</p>
                    <p class="max-w-sm text-xs leading-relaxed text-brand-moss">{{ __('Add one above to sign in with your device PIN, fingerprint, or a hardware key instead of a password.') }}</p>
                </div>
            @else
                <ul class="divide-y divide-brand-ink/5 border-t border-brand-ink/10">
                    @foreach ($passkeys as $cred)
                        <li class="group flex items-center gap-3 px-3 py-2.5 transition-colors hover:bg-brand-sand/15 sm:px-4">
                            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                                <x-heroicon-o-finger-print class="h-4 w-4" aria-hidden="true" />
                            </span>
                            <div class="min-w-0 flex-1">
                                <label class="sr-only" for="passkey-alias-{{ $cred->getKey() }}">{{ __('Passkey name') }}</label>
                                <input
                                    id="passkey-alias-{{ $cred->getKey() }}"
                                    type="text"
                                    wire:key="passkey-alias-{{ $cred->getKey() }}"
                                    wire:model="passkeyAliases.{{ $cred->getKey() }}"
                                    wire:blur="savePasskeyAlias(@js($cred->getKey()))"
                                    maxlength="255"
                                    autocomplete="off"
                                    class="block w-full max-w-md border-0 bg-transparent p-0 text-sm font-semibold text-brand-ink focus:ring-0"
                                    placeholder="{{ __('Passkey name') }}"
                                />
                                <p class="mt-0.5 text-xs text-brand-mist">{{ __('Added :time', ['time' => $cred->created_at->diffForHumans()]) }}</p>
                                @error('passkeyAliases.'.$cred->getKey())
                                    <p class="text-xs text-red-600">{{ $message }}</p>
                                @enderror
                            </div>
                            <button
                                type="button"
                                wire:click="openConfirmActionModal('removePasskey', @js([(string) $cred->getKey()]), @js(__('Remove passkey')), @js(__('Remove this passkey? You\'ll need another way to sign in if it was your only method.')), @js(__('Remove')), true)"
                                class="inline-flex h-7 shrink-0 items-center gap-1 rounded-lg border border-brand-ink/15 bg-white px-2.5 text-xs font-semibold text-brand-moss opacity-0 shadow-sm transition group-hover:opacity-100 focus-visible:opacity-100 hover:border-rose-200 hover:bg-rose-50 hover:text-rose-700"
                            >
                                <x-heroicon-o-trash class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                {{ __('Remove') }}
                            </button>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        {{-- OAuth sign-in --}}
        @if (! empty($oauthProviders))
            <div class="border-b border-brand-ink/10">
                <x-workspace-panel-head
                    dense
                    icon="heroicon-o-arrow-top-right-on-square"
                    :title="__('OAuth sign-in')"
                    :count="$linkedOAuth > 0 ? $linkedOAuth : null"
                    :note="__('Sign in with the same GitHub, GitLab, or Bitbucket account you use for Git.')"
                />
                @error('unlink')
                    <p class="px-3 pt-2 text-xs text-red-600 sm:px-4">{{ $message }}</p>
                @enderror
                {{-- One row per provider: mark, name, link state, action. The old
                     nested card repeated a sand header for each of three rows and
                     buried the linked identity a level down. --}}
                <ul class="divide-y divide-brand-ink/5">
                    @foreach ($oauthProviders as $p)
                        @php $linked = $socialAccounts->where('provider', $p['id']); @endphp
                        <li class="px-3 py-2.5 sm:px-4">
                            <div class="flex flex-wrap items-center gap-2.5">
                                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-white ring-1 ring-brand-ink/10">
                                    <x-oauth-provider-icon :provider="$p['id']" size="h-4 w-4" />
                                </span>
                                <span class="text-sm font-semibold text-brand-ink">{{ $p['name'] }}</span>
                                @if ($linked->isNotEmpty())
                                    <span class="inline-flex items-center gap-1 rounded-full border border-brand-sage/30 bg-brand-sage/15 px-1.5 py-px text-2xs font-semibold uppercase tracking-wide text-brand-forest">
                                        {{ trans_choice(':n linked|:n linked', $linked->count(), ['n' => $linked->count()]) }}
                                    </span>
                                @else
                                    <span class="text-xs text-brand-mist">{{ __('Not linked') }}</span>
                                @endif
                                <a
                                    href="{{ route('oauth.redirect', ['provider' => $p['id'], 'return' => 'security']) }}"
                                    class="ms-auto inline-flex h-7 shrink-0 items-center gap-1 rounded-lg border border-brand-ink/15 bg-white px-2.5 text-xs font-semibold text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/50"
                                >
                                    <x-heroicon-o-link class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                    {{ $linked->isNotEmpty() ? __('Link another') : __('Link account') }}
                                </a>
                            </div>

                            @foreach ($linked as $account)
                                <div class="group mt-1.5 flex items-center justify-between gap-3 rounded-lg bg-brand-sand/25 px-2.5 py-1.5">
                                    <span class="truncate font-mono text-xs text-brand-ink">{{ $account->nickname ?? $account->provider_id }}</span>
                                    <button
                                        type="button"
                                        wire:click="openConfirmActionModal('unlinkOAuthAccount', [{{ $account->id }}], @js(__('Unlink account')), @js(__('Unlink this account? You can link it again later from this page.')), @js(__('Unlink')), true)"
                                        class="inline-flex h-6 shrink-0 items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-moss opacity-0 shadow-sm transition group-hover:opacity-100 focus-visible:opacity-100 hover:border-rose-200 hover:bg-rose-50 hover:text-rose-700"
                                    >
                                        <x-heroicon-o-link-slash class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                        {{ __('Unlink') }}
                                    </button>
                                </div>
                            @endforeach
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        {{-- Two-factor authentication --}}
        <div>
            <x-workspace-panel-head
                dense
                icon="heroicon-o-device-phone-mobile"
                :title="__('Two-factor authentication')"
                :note="__('Require a code from your authenticator app when signing in.')"
                :tone="$twoFactorOn ? null : 'amber'"
            >
                <x-slot:actions>
                    <span @class([
                        'inline-flex items-center gap-1 rounded-full px-1.5 py-px text-2xs font-semibold ring-1',
                        'bg-brand-sage/15 text-brand-forest ring-brand-sage/20' => $twoFactorOn,
                        'bg-amber-50 text-amber-900 ring-amber-200' => ! $twoFactorOn,
                    ])>
                        <span @class([
                            'inline-block h-1.5 w-1.5 rounded-full',
                            'bg-brand-sage' => $twoFactorOn,
                            'bg-amber-500' => ! $twoFactorOn,
                        ])></span>
                        {{ $twoFactorOn ? __('Enabled') : __('Disabled') }}
                    </span>
                    @if ($twoFactorOn)
                        <a href="{{ route('two-factor.setup') }}" class="inline-flex h-6 items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40">
                            <x-heroicon-o-cog-6-tooth class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                            {{ __('Manage or disable') }}
                        </a>
                    @else
                        <a href="{{ route('two-factor.setup') }}" class="inline-flex h-6 items-center gap-1 rounded-md bg-brand-ink px-2 text-xs font-semibold text-brand-cream shadow-sm transition-colors hover:bg-brand-forest">
                            <x-heroicon-o-shield-check class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                            {{ __('Set up 2FA') }}
                        </a>
                    @endif
                </x-slot:actions>
            </x-workspace-panel-head>
            @if (session('status') === 'two-factor-enabled' && session('recovery_codes'))
                {{-- Recovery codes are the only thing worth a body row here: the
                     enable/disable action now rides in the header strip. --}}
                <div class="px-3 py-2.5 sm:px-4">
                    <div class="rounded-lg border border-amber-200 bg-amber-50 px-2.5 py-2">
                        <p class="inline-flex items-center gap-1.5 text-xs font-semibold text-amber-900">
                            <x-heroicon-m-exclamation-triangle class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                            {{ __('Store these recovery codes in a secure place. Each code can only be used once.') }}
                        </p>
                        <div class="mt-2 grid grid-cols-2 gap-1.5 font-mono text-xs text-amber-950 sm:grid-cols-4">
                            @foreach (session('recovery_codes') as $code)
                                <span class="rounded bg-white/60 px-1.5 py-0.5">{{ $code }}</span>
                            @endforeach
                        </div>
                    </div>
                </div>
            @endif
        </div>
    </x-profile-shell>

    @include('livewire.partials.confirm-action-modal')
</div>
