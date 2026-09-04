@php
    $u = auth()->user();
    $isProfile = $section !== 'servers';
    $themeOptions = config('user_preferences.theme_options', []);
    $navLayoutOptions = config('user_preferences.navigation_layout_options', []);
    $countries = collect(config('profile_options.countries', []))->sort();
    $locales = config('profile_options.locales', []);
    $sessions = $this->sessions;
    $otherSessions = count(array_filter($sessions, fn ($s) => ! $s['is_current']));

    // Active values surfaced as stat tiles so the user can see at a glance
    // what they're currently set to without scrolling each form section.
    $currentTheme = $ui['theme'] ?? 'system';
    $currentNavLayout = $ui['navigation_layout'] ?? 'sidebar';
@endphp

<div
    x-data="{
        profileSaved: false,
        sessionRevoked: false,
        sessionsRevoked: false,
        init() {
            $wire.on('profile-updated', () => { this.profileSaved = true; setTimeout(() => { this.profileSaved = false }, 2000); });
            $wire.on('session-revoked', () => { this.sessionRevoked = true; setTimeout(() => { this.sessionRevoked = false }, 3000); });
            $wire.on('sessions-revoked', () => { this.sessionsRevoked = true; setTimeout(() => { this.sessionsRevoked = false }, 3000); });
        },
    }"
>
    @push('breadcrumbs')
        <x-breadcrumb-trail
            doc-route="docs.index"
            :items="[
                ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
                ['label' => __('Settings'), 'href' => route('settings.profile'), 'icon' => 'cog-6-tooth'],
                ['label' => $isProfile ? __('Profile') : __('Servers & Sites'), 'icon' => $isProfile ? 'user-circle' : 'server'],
            ]"
        />
    @endpush

    <x-profile-shell
        :title="$isProfile ? __('Profile') : __('Servers & Sites')"
        :description="$isProfile
            ? __('Identity, preferences, sessions, and account on this page.')
            : __('Organization and team defaults for servers and sites.')"
        :icon="$isProfile ? 'heroicon-o-user-circle' : 'heroicon-o-server'"
    >
        {{-- No header actions: Security is one click away in the settings nav. --}}

        {{-- Not on the profile page: both figures are controls further down this
             same screen — Nav is the Navigation layout toggle, Timezone is the
             Timezone select. A glance row earns its place when it summarises
             something you would otherwise have to go and find; restating a
             setting a scroll above the switch that sets it is just a second
             copy to keep in sync. Other sections keep it. --}}
        @unless ($isProfile)
        <x-slot:stats>
            <dl class="grid grid-cols-2 gap-px bg-brand-ink/5" aria-label="{{ __('Your settings at a glance') }}">
                <div class="bg-white px-3 py-2">
                    <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Nav') }}</dt>
                    <dd class="mt-0.5 flex items-center gap-1.5">
                        @if ($currentNavLayout === 'top')
                            <x-heroicon-m-bars-3 class="h-3.5 w-3.5 shrink-0 text-brand-moss" aria-hidden="true" />
                            <span class="truncate text-sm font-semibold text-brand-ink">{{ __('Top') }}</span>
                        @else
                            <x-heroicon-m-squares-2x2 class="h-3.5 w-3.5 shrink-0 text-brand-moss" aria-hidden="true" />
                            <span class="truncate text-sm font-semibold text-brand-ink">{{ __('Sidebar') }}</span>
                        @endif
                    </dd>
                </div>
                <div class="bg-white px-3 py-2">
                    <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Timezone') }}</dt>
                    <dd class="mt-0.5 flex items-baseline gap-1.5">
                        <span class="truncate text-sm font-semibold text-brand-ink" title="{{ $u?->timezone ?? config('app.timezone') }}">{{ $u?->timezone ?? config('app.timezone') }}</span>
                        <span class="shrink-0 font-mono text-xs tabular-nums text-brand-moss">{{ now($u?->timezone ?? config('app.timezone'))->format('g:i A') }}</span>
                    </dd>
                </div>
            </dl>
        </x-slot:stats>
        @endunless

        @if ($section === 'profile')
            {{-- Identity: name / email / country / locale / timezone.
                 Lifted from the old /profile/edit page so settings/profile
                 is the single personal-settings surface. --}}
            {{-- Identity band: who you are, before the fields that edit it.
                 The avatar used to be 24px of chrome in a section header, which
                 read as decoration rather than "this is your account". --}}
            <div class="flex flex-wrap items-center gap-4 border-b border-brand-ink/10 bg-brand-sand/15 px-4 py-4 sm:px-5">
                <img
                    src="{{ $this->gravatarUrl }}"
                    alt=""
                    width="56"
                    height="56"
                    class="h-14 w-14 shrink-0 rounded-2xl border border-brand-ink/10 bg-white object-cover shadow-sm"
                    title="{{ __('Gravatar, resolved from your email address.') }}"
                />
                <div class="min-w-0 flex-1">
                    <p class="truncate text-base font-semibold tracking-tight text-brand-ink">{{ $u?->name }}</p>
                    <p class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-brand-moss">
                        <span class="truncate font-mono">{{ $u?->email }}</span>
                        @if ($u instanceof \Illuminate\Contracts\Auth\MustVerifyEmail && ! $u->hasVerifiedEmail())
                            <span class="inline-flex items-center gap-1 rounded-full border border-amber-200 bg-amber-50 px-1.5 py-px text-2xs font-semibold uppercase tracking-wide text-amber-800">
                                <x-heroicon-m-exclamation-triangle class="h-3 w-3 shrink-0" aria-hidden="true" />
                                {{ __('Unverified') }}
                            </span>
                        @else
                            <span class="inline-flex items-center gap-1 rounded-full border border-brand-sage/30 bg-brand-sage/15 px-1.5 py-px text-2xs font-semibold uppercase tracking-wide text-brand-forest">
                                <x-heroicon-m-check-badge class="h-3 w-3 shrink-0" aria-hidden="true" />
                                {{ __('Verified') }}
                            </span>
                        @endif
                    </p>
                </div>
                {{-- Member since only. The session count was a second copy of the
                     badge on the "Active sessions" heading below, which also lists
                     the devices — this chip could only ever agree with it. --}}
                <dl class="flex shrink-0 flex-wrap items-center gap-2">
                    <div class="rounded-xl border border-brand-ink/10 bg-white/80 px-3 py-2">
                        <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Member since') }}</dt>
                        <dd class="mt-0.5 text-sm font-semibold text-brand-ink">{{ $u?->created_at?->format('M Y') ?? '—' }}</dd>
                    </div>
                </dl>
            </div>

            <div class="border-b border-brand-ink/10">
                <x-workspace-panel-head
                    dense
                    icon="heroicon-o-user-circle"
                    :title="__('Your details')"
                    :note="__('Name, login email, country, language, and timezone.')"
                >
                    <x-slot:actions>
                        <p x-show="profileSaved" x-transition x-cloak class="inline-flex items-center gap-1 text-xs font-semibold text-emerald-700">
                            <x-heroicon-m-check-circle class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                            {{ __('Saved') }}
                        </p>
                    </x-slot:actions>
                </x-workspace-panel-head>
                <div class="px-3 py-3 sm:px-4">
                    {{-- Six-column base: the two identity fields take half each,
                         the three locale fields take a third each, so every row
                         fills the width instead of trailing off mid-panel. --}}
                    <div class="grid gap-2.5 sm:grid-cols-6">
                        <div class="sm:col-span-3">
                            <x-input-label for="profile-name" :value="__('Name')" required />
                            <x-text-input id="profile-name" wire:model="profileForm.name" type="text" class="mt-1 block w-full" required autocomplete="name" />
                            <x-input-error class="mt-1" :messages="$errors->get('profileForm.name')" />
                        </div>
                        <div class="sm:col-span-3">
                            <x-input-label for="profile-email" :value="__('Email')" required />
                            <x-text-input id="profile-email" wire:model.live="profileForm.email" type="email" class="mt-1 block w-full" required autocomplete="username" />
                            <x-input-error class="mt-1" :messages="$errors->get('profileForm.email')" />
                            @if ($u instanceof \Illuminate\Contracts\Auth\MustVerifyEmail && ! $u->hasVerifiedEmail())
                                <div class="mt-1.5 rounded-md border border-amber-200 bg-amber-50 px-2.5 py-1.5 text-xs text-amber-900">
                                    <p class="font-semibold">{{ __('Your email address is unverified.') }}</p>
                                    <button type="button" wire:click="sendVerificationEmail" class="mt-0.5 inline-flex items-center gap-1 text-xs font-semibold text-amber-950 underline underline-offset-2 hover:no-underline">
                                        {{ __('Re-send verification email') }} →
                                    </button>
                                    @if ($verificationLinkSent)
                                        <p class="mt-1 inline-flex items-center gap-1 font-semibold text-emerald-800">
                                            <x-heroicon-m-check-circle class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                            {{ __('Verification link sent.') }}
                                        </p>
                                    @endif
                                </div>
                            @endif
                        </div>
                        <div class="sm:col-span-2">
                            <x-input-label for="profile-country" :value="__('Country')" />
                            <select id="profile-country" wire:model="profileForm.country_code" class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white px-2.5 py-1.5 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:ring-brand-sage">
                                <option value="">{{ __('Select a country') }}</option>
                                @foreach ($countries as $code => $label)
                                    <option value="{{ $code }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            <x-input-error class="mt-1" :messages="$errors->get('profileForm.country_code')" />
                        </div>
                        <div class="sm:col-span-2">
                            <x-input-label for="profile-locale" :value="__('Language')" required />
                            <select id="profile-locale" wire:model="profileForm.locale" required class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white px-2.5 py-1.5 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:ring-brand-sage">
                                @foreach ($locales as $code => $label)
                                    <option value="{{ $code }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            <x-input-error class="mt-1" :messages="$errors->get('profileForm.locale')" />
                        </div>
                        <div class="sm:col-span-2">
                            <x-input-label for="profile-timezone" :value="__('Timezone')" required />
                            <select id="profile-timezone" wire:model="profileForm.timezone" required class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white px-2.5 py-1.5 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:ring-brand-sage">
                                @foreach ($this->timezones as $tz)
                                    <option value="{{ $tz }}">{{ $tz }}</option>
                                @endforeach
                            </select>
                            <x-input-error class="mt-1" :messages="$errors->get('profileForm.timezone')" />
                        </div>
                    </div>
                </div>
            </div>

            <div class="border-b border-brand-ink/10">
                <x-workspace-panel-head
                    dense
                    icon="heroicon-o-adjustments-horizontal"
                    :title="__('Your preferences')"
                    :note="__('Only you see these — not shared with your organization or teams.')"
                />
                <form wire:submit="saveProfile" class="px-3 py-2.5 sm:px-4">
                    <button type="submit" class="sr-only">{{ __('Save settings') }}</button>

                    {{-- One settings list rather than a toggle box followed by
                         loose stacked pickers: every preference is a row with its
                         name + explanation on the left and its control on the
                         right, so the wide column is actually used and the two
                         kinds of setting line up on a single control edge. --}}
                    @php
                        $segmented = fn (bool $on) => $on
                            ? 'inline-flex h-7 items-center gap-1 rounded-lg px-2.5 text-xs font-semibold transition bg-brand-ink text-brand-cream shadow-sm'
                            : 'inline-flex h-7 items-center gap-1 rounded-lg px-2.5 text-xs font-semibold transition text-brand-moss hover:bg-brand-sand/40 hover:text-brand-ink';
                        $rowClass = 'flex flex-wrap items-center justify-between gap-x-4 gap-y-1.5 px-3 py-2.5';
                        $groupHead = 'flex items-center gap-1.5 border-b border-brand-ink/10 bg-brand-sand/25 px-3 py-2 text-2xs font-semibold uppercase tracking-[0.16em] text-brand-moss';
                    @endphp

                    {{-- Two grouped cards: appearance is a set of pickers, email &
                         behavior is a set of switches. Interleaving them in one
                         flat list made every row look equally important and left
                         the checkbox column visually unrelated to the pickers. --}}
                    <div class="grid gap-3 lg:grid-cols-2 lg:items-start">
                    <div class="overflow-hidden rounded-xl border border-brand-ink/10 bg-white">
                        <p class="{{ $groupHead }}">
                            <x-heroicon-o-swatch class="h-3.5 w-3.5 shrink-0 text-brand-sage" aria-hidden="true" />
                            {{ __('Appearance & layout') }}
                        </p>
                        <div class="divide-y divide-brand-ink/10">

                        <div class="{{ $rowClass }}">
                            <div class="min-w-0 flex-1 basis-64">
                                <p class="text-sm font-medium text-brand-ink">{{ __('Navigation layout') }}</p>
                                <p class="mt-0.5 text-xs leading-relaxed text-brand-moss">{{ __('Sidebar on large screens, or a horizontal link row under the header.') }}</p>
                                @error('ui.navigation_layout') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                            </div>
                            <div class="inline-flex shrink-0 flex-wrap gap-1 rounded-lg border border-brand-ink/10 bg-white p-0.5 shadow-sm">
                                @foreach ($navLayoutOptions as $opt)
                                    <button type="button" wire:click="persistNavigationLayout('{{ $opt }}')" class="{{ $segmented(($ui['navigation_layout'] ?? '') === $opt) }}">
                                        @if ($opt === 'sidebar')
                                            <x-heroicon-o-squares-2x2 class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                            {{ __('Sidebar') }}
                                        @else
                                            <x-heroicon-o-bars-3 class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                            {{ __('Top') }}
                                        @endif
                                    </button>
                                @endforeach
                            </div>
                        </div>

                        <div class="{{ $rowClass }}">
                            <div class="min-w-0 flex-1 basis-64">
                                <label for="notification-position" class="text-sm font-medium text-brand-ink">{{ __('Notification position') }}</label>
                                <p class="mt-0.5 text-xs leading-relaxed text-brand-moss">{{ __('Where toast notifications appear on screen.') }}</p>
                                @error('ui.notification_position') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                            </div>
                            <div class="flex shrink-0 items-center gap-1.5">
                                <select
                                    id="notification-position"
                                    wire:model="ui.notification_position"
                                    class="h-7 w-44 rounded-md border-brand-ink/15 bg-white py-0 ps-2.5 pe-8 text-xs text-brand-ink shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                                >
                                    @foreach (config('user_preferences.notification_positions', []) as $value => $label)
                                        <option value="{{ $value }}">{{ __($label) }}</option>
                                    @endforeach
                                </select>
                                <button
                                    type="button"
                                    data-notification-preview-message="{{ __('This is where notifications will appear.') }}"
                                    onclick="window.dispatchEvent(new CustomEvent('toast', { detail: { message: this.dataset.notificationPreviewMessage, type: 'success', position: document.getElementById('notification-position').value } }))"
                                    class="inline-flex h-7 shrink-0 items-center justify-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40"
                                >
                                    <x-heroicon-o-paper-airplane class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                    {{ __('Test') }}
                                </button>
                            </div>
                        </div>

                        </div>
                    </div>

                    <div class="overflow-hidden rounded-xl border border-brand-ink/10 bg-white">
                        <p class="{{ $groupHead }}">
                            <x-heroicon-o-envelope class="h-3.5 w-3.5 shrink-0 text-brand-sage" aria-hidden="true" />
                            {{ __('Email & behavior') }}
                        </p>

                        {{-- Real switches, not bare checkboxes: on/off is the whole
                             point of these four rows, and a 16px tick reads as a
                             form field rather than a state you can flip. --}}
                        <div class="divide-y divide-brand-ink/10">
                        @foreach ([
                            ['key' => 'newsletter', 'title' => __('Receive newsletter'), 'desc' => __('Product updates only — no spam.')],
                            ['key' => 'keyboard_shortcuts', 'title' => __('Enable keyboard shortcuts'), 'desc' => __('Turns keyboard shortcuts on or off in the app.')],
                            ['key' => 'redirect_home_to_app', 'title' => __('Redirect to app when logged in'), 'desc' => __('Visiting the marketing homepage signed in sends you to the dashboard.')],
                            ['key' => 'subscription_invoice_emails', 'title' => __('Subscription invoice emails'), 'desc' => __('When your org moves from trial to Pro, include Stripe invoice PDFs in email.')],
                        ] as $toggle)
                            <div class="{{ $rowClass }} transition-colors hover:bg-brand-sand/15">
                                <span class="min-w-0 flex-1 basis-56">
                                    <span class="text-sm font-medium text-brand-ink">{{ $toggle['title'] }}</span>
                                    <span class="mt-0.5 block text-xs leading-relaxed text-brand-moss">{{ $toggle['desc'] }}</span>
                                </span>
                                <x-toggle-switch
                                    :enabled="(bool) ($ui[$toggle['key']] ?? false)"
                                    wire:model.boolean.live="ui.{{ $toggle['key'] }}"
                                    :on-label="__('On')"
                                    :off-label="__('Off')"
                                />
                            </div>
                        @endforeach
                        </div>
                    </div>
                    </div>
                </form>
            </div>

            {{-- Active sessions --}}
            <div class="border-b border-brand-ink/10">
                <x-workspace-panel-head
                    dense
                    icon="heroicon-o-device-phone-mobile"
                    :title="__('Active sessions')"
                    :count="count($sessions) ?: null"
                    :note="__('Revoking a session logs that device out on its next request.')"
                >
                    <x-slot:actions>
                        <p x-show="sessionRevoked" x-transition x-cloak class="inline-flex items-center gap-1 text-xs font-semibold text-emerald-700">
                            <x-heroicon-m-check-circle class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                            {{ __('Session revoked.') }}
                        </p>
                        <p x-show="sessionsRevoked" x-transition x-cloak class="inline-flex items-center gap-1 text-xs font-semibold text-emerald-700">
                            <x-heroicon-m-check-circle class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                            {{ __('All other sessions revoked.') }}
                        </p>
                        @if ($otherSessions > 0)
                            <button type="button" wire:click="openConfirmActionModal('revokeOtherSessions', [], @js(__('Revoke all other sessions')), @js(__('Revoke all other sessions? You will stay logged in on this device only.')), @js(__('Revoke sessions')), true)" class="inline-flex h-6 shrink-0 items-center gap-1 rounded-md border border-red-200 bg-red-50 px-2 text-xs font-semibold text-red-700 shadow-sm transition hover:bg-red-100">
                                <x-heroicon-o-x-mark class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                {{ __('Revoke other devices') }}
                            </button>
                        @endif
                    </x-slot:actions>
                </x-workspace-panel-head>
                @error('session')
                    <p class="px-3 pt-2 text-xs text-red-600 sm:px-4">{{ $message }}</p>
                @enderror

                @if ($sessions === [])
                    <div class="px-3 py-3 text-center sm:px-4">
                        <x-empty-state
                            borderless
                            compact
                            icon="heroicon-o-device-phone-mobile"
                            :title="__('No active sessions')"
                            :description="__('Sessions appear here as you sign in from other browsers or devices.')"
                        />
                    </div>
                @else
                    <ul class="divide-y divide-brand-ink/5">
                        @foreach ($sessions as $session)
                            @php
                                $label = (string) $session['device_label'];
                                $isMobile = (bool) preg_match('/iphone|android|ipad|mobile/i', $label);
                            @endphp
                            <li @class([
                                'group flex items-center gap-3 px-3 py-2.5 transition-colors hover:bg-brand-sand/15 sm:px-4',
                                'bg-brand-sage/[0.06]' => $session['is_current'],
                            ])>
                                <span @class([
                                    'flex h-8 w-8 shrink-0 items-center justify-center rounded-lg ring-1',
                                    'bg-brand-sage/15 text-brand-forest ring-brand-sage/25' => $session['is_current'],
                                    'bg-brand-sand/40 text-brand-moss ring-brand-ink/10' => ! $session['is_current'],
                                ])>
                                    @if ($isMobile)
                                        <x-heroicon-o-device-phone-mobile class="h-4 w-4" aria-hidden="true" />
                                    @else
                                        <x-heroicon-o-computer-desktop class="h-4 w-4" aria-hidden="true" />
                                    @endif
                                </span>

                                <div class="min-w-0 flex-1">
                                    <p class="flex flex-wrap items-center gap-1.5">
                                        <span class="truncate text-sm font-semibold text-brand-ink">{{ $label }}</span>
                                        @if ($session['is_current'])
                                            <span class="inline-flex items-center rounded border border-brand-sage/30 bg-brand-sage/15 px-1 py-px text-2xs font-semibold uppercase tracking-wide text-brand-forest">{{ __('This device') }}</span>
                                        @endif
                                    </p>
                                    <p class="mt-0.5 truncate text-xs text-brand-moss">
                                        <span class="font-mono">{{ $session['ip_address'] ?? __('Unknown IP') }}</span>
                                        <span class="text-brand-mist"> · </span>
                                        {{ __('Last active :time', ['time' => \Carbon\Carbon::createFromTimestamp($session['last_activity'])->diffForHumans()]) }}
                                    </p>
                                </div>

                                @if (! $session['is_current'])
                                    {{-- Revealed on hover/focus: seven always-on red
                                         buttons made the list read as a danger zone. --}}
                                    <button
                                        type="button"
                                        wire:click="openConfirmActionModal('revokeSession', ['{{ $session['id'] }}'], @js(__('Revoke session')), @js(__('Revoke this session? That device will be logged out on its next request.')), @js(__('Revoke')), true)"
                                        class="inline-flex h-7 shrink-0 items-center gap-1 rounded-lg border border-brand-ink/15 bg-white px-2.5 text-xs font-semibold text-brand-moss opacity-0 shadow-sm transition group-hover:opacity-100 focus-visible:opacity-100 hover:border-rose-200 hover:bg-rose-50 hover:text-rose-700"
                                    >
                                        <x-heroicon-o-x-mark class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                        {{ __('Revoke') }}
                                    </button>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            {{-- Danger zone --}}
            <div class="flex flex-wrap items-center gap-3 border-t border-rose-200/70 bg-rose-50/50 px-4 py-3.5 sm:px-5">
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-white text-rose-600 ring-1 ring-rose-200">
                    <x-heroicon-o-trash class="h-4.5 w-4.5" aria-hidden="true" />
                </span>
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-semibold text-rose-900">{{ __('Delete account') }}</p>
                    <p class="mt-0.5 text-xs leading-relaxed text-rose-900/70">{{ __('Signs you out and drops access to organizations and data tied to this login. Cannot be undone.') }}</p>
                </div>
                <a
                    href="{{ route('profile.delete-account') }}"
                    wire:navigate
                    class="inline-flex h-8 shrink-0 items-center gap-1.5 rounded-lg border border-rose-300 bg-white px-3 text-xs font-semibold text-rose-700 shadow-sm transition hover:bg-rose-50"
                >
                    {{ __('Delete account') }}
                    <x-heroicon-o-arrow-right class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                </a>
            </div>

            <x-unsaved-changes-bar
                :message="__('You have unsaved changes to your profile information.')"
                saveAction="updateProfile"
                discardAction="discardProfileFormUnsaved"
                :targets="$profileFormUnsavedTargets"
                :saveLabel="__('Save profile')"
            />

            <x-unsaved-changes-bar
                :message="__('You have unsaved changes to your profile preferences.')"
                saveAction="saveProfile"
                discardAction="discardProfileUnsaved"
                :targets="$profileUnsavedTargets"
                :saveLabel="__('Save settings')"
            />
        @endif

        @if ($section === 'servers')
            {{-- Your timezone --}}
            <div class="border-b border-brand-ink/10">
                <x-workspace-panel-head
                    dense
                    icon="heroicon-o-clock"
                    :title="__('Your timezone')"
                    :note="__('Used for schedules, Insights quiet hours, and applying a timezone to new servers.')"
                />
                <form wire:submit="saveProfileTimezone" class="px-3 py-2.5 sm:px-4">
                    <button type="submit" class="sr-only">{{ __('Save timezone') }}</button>
                    <x-input-label for="hub-profile-timezone" :value="__('Timezone')" required />
                    <select
                        id="hub-profile-timezone"
                        wire:model="profileTimezone"
                        required
                        class="mt-1 block w-full max-w-md rounded-md border-brand-ink/15 bg-white px-2.5 py-1.5 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                    >
                        @foreach ($this->timezones as $tz)
                            <option value="{{ $tz }}">{{ $tz }}</option>
                        @endforeach
                    </select>
                    @error('profileTimezone') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </form>
            </div>

            <x-unsaved-changes-bar
                :message="__('You have unsaved changes to your timezone.')"
                saveAction="saveProfileTimezone"
                discardAction="discardProfileTimezoneUnsaved"
                :targets="$profileTimezoneUnsavedTargets"
                :saveLabel="__('Save timezone')"
            />

            {{-- Organization defaults --}}
            <div class="border-b border-brand-ink/10">
                <x-workspace-panel-head
                    dense
                    icon="heroicon-o-building-office-2"
                    :title="__('Organization defaults')"
                    :note="__('Email and new-server policy for the current organization.')"
                >
                    @if ($currentOrg)
                        <x-slot:actions>
                            <span class="inline-flex items-center rounded border border-brand-ink/10 bg-brand-sand/40 px-1.5 py-px text-2xs font-semibold uppercase tracking-wide text-brand-moss" title="{{ $currentOrg->name }}">{{ $currentOrg->name }}</span>
                        </x-slot:actions>
                    @endif
                </x-workspace-panel-head>
                <form wire:submit="saveOrganizationServersSites" class="px-3 py-2.5 sm:px-4">
                    <button type="submit" class="sr-only">{{ __('Save organization settings') }}</button>
                    @if (! $currentOrg)
                        <div class="rounded-md border border-amber-200 bg-amber-50 px-2.5 py-1.5 text-xs text-amber-900">
                            {{ __('Create or join an organization to configure these options.') }}
                        </div>
                    @elseif (! $canEditOrgPrefs)
                        <div class="rounded-md border border-brand-ink/10 bg-brand-cream/40 px-2.5 py-1.5 text-xs text-brand-moss">
                            {{ __('Only organization admins can change organization defaults.') }}
                        </div>
                    @else
                        <div class="divide-y divide-brand-ink/10 overflow-hidden rounded-lg border border-brand-ink/10">
                            <label class="flex cursor-pointer items-start gap-2.5 bg-white px-2.5 py-2 transition-colors hover:bg-brand-sand/15">
                                <input type="checkbox" wire:model.boolean="organizationServerSite.email_server_passwords" class="mt-0.5 h-4 w-4 rounded border-brand-ink/30 text-brand-forest focus:ring-brand-forest" />
                                <span class="min-w-0 flex-1">
                                    <span class="text-sm font-medium text-brand-ink">{{ __('Receive server passwords via email') }}</span>
                                    <span class="mt-0.5 block text-xs leading-relaxed text-brand-moss">{{ __('When off, retrieve credentials from each server\'s settings in the app.') }}</span>
                                </span>
                            </label>
                            <label class="flex cursor-pointer items-start gap-2.5 bg-white px-2.5 py-2 transition-colors hover:bg-brand-sand/15">
                                <input type="checkbox" wire:model.boolean="organizationServerSite.set_timezone_on_new_servers" class="mt-0.5 h-4 w-4 rounded border-brand-ink/30 text-brand-forest focus:ring-brand-forest" />
                                <span class="min-w-0 flex-1">
                                    <span class="text-sm font-medium text-brand-ink">{{ __('Set timezone on new servers') }}</span>
                                    <span class="mt-0.5 block text-xs leading-relaxed text-brand-moss">{{ __('Apply the timezone above to new servers. (Currently: :tz)', ['tz' => $userTimezoneLabel]) }}</span>
                                </span>
                            </label>
                        </div>
                    @endif
                </form>
            </div>

            {{-- Insights preferences --}}
            <div class="border-b border-brand-ink/10">
                <x-workspace-panel-head
                    dense
                    icon="heroicon-o-light-bulb"
                    :title="__('Insights preferences')"
                    :note="__('Alert batching and quiet hours. Critical findings still notify immediately.')"
                />
                <form wire:submit="saveOrganizationInsights" class="px-3 py-2.5 sm:px-4">
                    <button type="submit" class="sr-only">{{ __('Save Insights preferences') }}</button>
                    @if (! $currentOrg)
                        <div class="rounded-md border border-amber-200 bg-amber-50 px-2.5 py-1.5 text-xs text-amber-900">
                            {{ __('Create or join an organization to configure these options.') }}
                        </div>
                    @elseif (! $canEditOrgPrefs)
                        <div class="rounded-md border border-brand-ink/10 bg-brand-cream/40 px-2.5 py-1.5 text-xs text-brand-moss">
                            {{ __('Only organization admins can change Insights preferences.') }}
                        </div>
                    @else
                        <div class="space-y-2.5">
                            <div class="divide-y divide-brand-ink/10 overflow-hidden rounded-lg border border-brand-ink/10">
                                <label class="flex cursor-pointer items-start gap-2.5 bg-white px-2.5 py-2 transition-colors hover:bg-brand-sand/15">
                                    <input type="checkbox" wire:model.boolean="organizationInsights.digest_non_critical" class="mt-0.5 h-4 w-4 rounded border-brand-ink/30 text-brand-forest focus:ring-brand-forest" />
                                    <span class="min-w-0 flex-1">
                                        <span class="text-sm font-medium text-brand-ink">{{ __('Digest non-critical findings') }}</span>
                                        <span class="mt-0.5 block text-xs leading-relaxed text-brand-moss">{{ __('Batch warning and info findings into email. Critical stays immediate.') }}</span>
                                    </span>
                                </label>
                                <label class="flex cursor-pointer items-start gap-2.5 bg-white px-2.5 py-2 transition-colors hover:bg-brand-sand/15">
                                    <input type="checkbox" wire:model.boolean="organizationInsights.quiet_hours_enabled" class="mt-0.5 h-4 w-4 rounded border-brand-ink/30 text-brand-forest focus:ring-brand-forest" />
                                    <span class="min-w-0 flex-1">
                                        <span class="text-sm font-medium text-brand-ink">{{ __('Quiet hours for non-critical') }}</span>
                                        <span class="mt-0.5 block text-xs leading-relaxed text-brand-moss">{{ __('Suppress immediate non-critical insight alerts within the window below. Uses the app timezone (:tz).', ['tz' => config('app.timezone')]) }}</span>
                                    </span>
                                </label>
                                <label class="flex cursor-pointer items-start gap-2.5 bg-white px-2.5 py-2 transition-colors hover:bg-brand-sand/15">
                                    <input type="checkbox" wire:model.boolean="organizationInsights.allow_config_mutation" class="mt-0.5 h-4 w-4 rounded border-brand-ink/30 text-brand-forest focus:ring-brand-forest" />
                                    <span class="min-w-0 flex-1">
                                        <span class="text-sm font-medium text-brand-ink">{{ __('Allow Insights to mutate server configs') }}</span>
                                        <span class="mt-0.5 block text-xs leading-relaxed text-brand-moss">{{ __('Apply-fix actions that edit on-disk service configs (e.g. pm.max_children) can run. Restart-only fixes are unaffected. Backups are always taken; revert is one click.') }}</span>
                                    </span>
                                </label>
                            </div>

                            <div class="grid gap-2.5 sm:grid-cols-3">
                                <div>
                                    <label for="org-insights-digest-frequency" class="block text-xs font-semibold text-brand-ink">{{ __('Digest frequency') }}</label>
                                    <select
                                        id="org-insights-digest-frequency"
                                        wire:model="organizationInsights.digest_frequency"
                                        class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white px-2.5 py-1.5 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                                    >
                                        <option value="daily">{{ __('Daily (08:00)') }}</option>
                                        <option value="weekly">{{ __('Weekly (Mon 08:15)') }}</option>
                                    </select>
                                    @error('organizationInsights.digest_frequency') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label for="org-insights-quiet-start" class="block text-xs font-semibold text-brand-ink">{{ __('Quiet start (hour)') }}</label>
                                    <input
                                        id="org-insights-quiet-start"
                                        type="number"
                                        min="0"
                                        max="23"
                                        wire:model="organizationInsights.quiet_hours_start"
                                        class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white px-2.5 py-1.5 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                                    />
                                    @error('organizationInsights.quiet_hours_start') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label for="org-insights-quiet-end" class="block text-xs font-semibold text-brand-ink">{{ __('Quiet end (hour)') }}</label>
                                    <input
                                        id="org-insights-quiet-end"
                                        type="number"
                                        min="0"
                                        max="23"
                                        wire:model="organizationInsights.quiet_hours_end"
                                        class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white px-2.5 py-1.5 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                                    />
                                    @error('organizationInsights.quiet_hours_end') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                                </div>
                            </div>
                        </div>
                    @endif
                </form>
            </div>

            {{-- Team defaults --}}
            <div>
                <x-workspace-panel-head
                    dense
                    icon="heroicon-o-rectangle-group"
                    :title="__('Team defaults')"
                    :note="__('List and creation defaults for servers and sites in the selected team.')"
                />
                <form wire:submit="saveTeamServersSites" class="px-3 py-2.5 sm:px-4">
                    <button type="submit" class="sr-only">{{ __('Save team settings') }}</button>
                    @if (! $currentOrg)
                        <div class="rounded-md border border-amber-200 bg-amber-50 px-2.5 py-1.5 text-xs text-amber-900">
                            {{ __('Create or join an organization first.') }}
                        </div>
                    @elseif ($teams->isEmpty())
                        <div class="rounded-md border border-brand-ink/10 bg-brand-cream/40 px-2.5 py-1.5 text-xs text-brand-moss">
                            {{ __('Add a team to this organization to configure team defaults.') }}
                        </div>
                    @else
                        <div class="space-y-2.5">
                            <div>
                                <label for="settings-team" class="block text-xs font-semibold text-brand-ink">{{ __('Team') }}</label>
                                <select
                                    id="settings-team"
                                    wire:model.live="selectedTeamId"
                                    class="mt-1 block w-full max-w-md rounded-md border-brand-ink/15 bg-white px-2.5 py-1.5 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                                >
                                    @foreach ($teams as $team)
                                        <option value="{{ $team->id }}">{{ $team->name }}</option>
                                    @endforeach
                                </select>
                                <p class="mt-1 text-xs text-brand-mist">{{ __('Choose which team\'s defaults you\'re editing.') }}</p>
                            </div>

                            @if (! $canEditTeamPrefs)
                                <div class="rounded-md border border-brand-ink/10 bg-brand-cream/40 px-2.5 py-1.5 text-xs text-brand-moss">
                                    {{ __('Only team admins (or organization admins) can change team defaults.') }}
                                </div>
                            @else
                                <div class="divide-y divide-brand-ink/10 overflow-hidden rounded-lg border border-brand-ink/10">
                                    <label class="flex cursor-pointer items-start gap-2.5 bg-white px-2.5 py-2 transition-colors hover:bg-brand-sand/15">
                                        <input type="checkbox" wire:model.boolean="teamServerSite.show_server_updates_in_list" class="mt-0.5 h-4 w-4 rounded border-brand-ink/30 text-brand-forest focus:ring-brand-forest" />
                                        <span class="min-w-0 flex-1">
                                            <span class="text-sm font-medium text-brand-ink">{{ __('Show server updates in list') }}</span>
                                            <span class="mt-0.5 block text-xs leading-relaxed text-brand-moss">{{ __('Surface pending updates in the server list when available.') }}</span>
                                        </span>
                                    </label>
                                    <label class="flex cursor-pointer items-start gap-2.5 bg-white px-2.5 py-2 transition-colors hover:bg-brand-sand/15">
                                        <input type="checkbox" wire:model.boolean="teamServerSite.isolate_new_sites" class="mt-0.5 h-4 w-4 rounded border-brand-ink/30 text-brand-forest focus:ring-brand-forest" />
                                        <span class="min-w-0 flex-1">
                                            <span class="text-sm font-medium text-brand-ink">{{ __('Always use isolation for new sites') }}</span>
                                            <span class="mt-0.5 block text-xs leading-relaxed text-brand-moss">{{ __('Prefer isolated system users for new sites when the stack supports it.') }}</span>
                                        </span>
                                    </label>
                                </div>

                                <div class="grid gap-2.5 sm:grid-cols-2">
                                    <div>
                                        <label for="team-default-server-sort" class="block text-xs font-semibold text-brand-ink">{{ __('Default server sort') }}</label>
                                        <select
                                            id="team-default-server-sort"
                                            wire:model="teamServerSite.default_server_sort"
                                            class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white px-2.5 py-1.5 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                                        >
                                            @foreach (config('user_preferences.server_sort_options', []) as $value => $label)
                                                <option value="{{ $value }}">{{ __($label) }}</option>
                                            @endforeach
                                        </select>
                                        @error('teamServerSite.default_server_sort') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                                    </div>
                                    <div>
                                        <label for="team-default-site-sort" class="block text-xs font-semibold text-brand-ink">{{ __('Default site sort') }}</label>
                                        <select
                                            id="team-default-site-sort"
                                            wire:model="teamServerSite.default_site_sort"
                                            class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white px-2.5 py-1.5 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                                        >
                                            @foreach (config('user_preferences.site_sort_options', []) as $value => $label)
                                                <option value="{{ $value }}">{{ __($label) }}</option>
                                            @endforeach
                                        </select>
                                        @error('teamServerSite.default_site_sort') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                                    </div>
                                </div>
                            @endif
                        </div>
                    @endif
                </form>
            </div>

            <x-unsaved-changes-bar
                :message="__('You have unsaved changes to organization defaults.')"
                saveAction="saveOrganizationServersSites"
                discardAction="discardOrganizationServersSitesUnsaved"
                :targets="$organizationServerSiteUnsavedTargets"
                :save-disabled="! $currentOrg || ! $canEditOrgPrefs"
                :saveLabel="__('Save organization settings')"
            />

            <x-unsaved-changes-bar
                :message="__('You have unsaved changes to Insights preferences.')"
                saveAction="saveOrganizationInsights"
                discardAction="discardOrganizationInsightsUnsaved"
                :targets="$organizationInsightsUnsavedTargets"
                :save-disabled="! $currentOrg || ! $canEditOrgPrefs"
                :saveLabel="__('Save Insights preferences')"
            />

            <x-unsaved-changes-bar
                :message="__('You have unsaved changes to team defaults.')"
                saveAction="saveTeamServersSites"
                discardAction="discardTeamServersSitesUnsaved"
                :targets="$teamServersSitesUnsavedTargets"
                :save-disabled="! $currentOrg || $teams->isEmpty() || ! $canEditTeamPrefs"
                :saveLabel="__('Save team settings')"
            />
        @endif
    </x-profile-shell>

    <x-slot name="modals">
        @include('livewire.partials.confirm-action-modal')
    </x-slot>
</div>
