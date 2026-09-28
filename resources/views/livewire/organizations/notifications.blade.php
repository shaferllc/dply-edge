{{--
  Org Notifications — "routing matrix" (redesign 2026-09-27, Notify2).
  Rows are events, columns are this org's channels; each tick is an org-wide
  subscription saved on click (NotificationChannels::toggleRoute). Forked from
  the shared settings partial, which the personal profile page still renders.
--}}
@php
    $canAddChannel = $canManage && count($types) > 0;
    $slackWorkspaces = $canManage ? $this->slackInstallations() : collect();
    $discordGuilds = $canManage ? $this->discordInstallations() : collect();
    $telegramChats = $canManage ? $this->telegramInstallations() : collect();
    $slackConnect = $canManage && $this->slackOauthConfigured();
    $discordConnect = $canManage && $this->discordOauthConfigured();
    $connectBtn = 'inline-flex h-8 shrink-0 items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40';
    // Connect links return here after the OAuth round trip.
    $returnTo = "+ '&return_to=' + encodeURIComponent(window.location.pathname + window.location.search)";
@endphp

<div>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <x-organization-shell
            :organization="$organization"
            section="notifications"
            :breadcrumb="[
                ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
                ['label' => $organization->name, 'href' => route('organizations.show', $organization), 'icon' => 'building-office-2'],
                ['label' => __('Notifications'), 'icon' => 'bell-alert'],
            ]"
        >
            <div class="space-y-6">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="min-w-0">
                        <h1 class="text-2xl font-semibold tracking-tight text-brand-ink">{{ __('Notifications') }}</h1>
                        <p class="mt-1 text-sm text-brand-moss">
                            {{ $canManage ? __('Tick where each event goes. Changes save as you click.') : __('Where each event goes. Ask an admin to change the routing.') }}
                        </p>
                    </div>
                    @if ($canAddChannel)
                        <button
                            type="button"
                            wire:click="openCreateChannelModal"
                            class="inline-flex h-9 items-center gap-1.5 rounded-lg bg-brand-ink px-3.5 text-sm font-semibold text-brand-cream transition-colors hover:bg-brand-forest"
                        >
                            <x-heroicon-o-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                            {{ __('Add destination') }}
                        </button>
                    @endif
                </div>

                @if ($channels->isEmpty())
                    <section class="dply-card flex flex-col items-center px-5 py-12 text-center sm:px-6">
                        <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-brand-sand/50 text-brand-moss ring-1 ring-brand-ink/10">
                            <x-heroicon-o-bell-slash class="h-5 w-5" aria-hidden="true" />
                        </span>
                        <h2 class="mt-3 text-sm font-semibold text-brand-ink">{{ __('No destinations yet') }}</h2>
                        <p class="mt-1 max-w-md text-sm text-brand-moss">
                            {{ __('Add a destination so alerts have somewhere to go — chat, email, a pager or a webhook.') }}
                        </p>
                        @if ($canAddChannel || $slackConnect || $discordConnect)
                            <div class="mt-4 flex flex-wrap justify-center gap-2">
                                @if ($canAddChannel)
                                    <button type="button" wire:click="openCreateChannelModal" class="inline-flex h-8 items-center gap-1.5 rounded-lg bg-brand-ink px-3 text-xs font-semibold text-brand-cream transition-colors hover:bg-brand-forest">
                                        <x-heroicon-o-plus class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                        {{ __('Add destination') }}
                                    </button>
                                @endif
                                @if ($slackConnect)
                                    <a href="{{ $this->slackConnectUrl() }}" x-on:click.prevent="window.location.href = @js($this->slackConnectUrl()) {!! $returnTo !!}" class="{{ $connectBtn }}">
                                        <x-heroicon-o-chat-bubble-left-right class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                        {{ __('Add to Slack') }}
                                    </a>
                                @endif
                                @if ($discordConnect)
                                    <a href="{{ $this->discordConnectUrl() }}" x-on:click.prevent="window.location.href = @js($this->discordConnectUrl()) {!! $returnTo !!}" class="{{ $connectBtn }}">
                                        <x-heroicon-o-chat-bubble-oval-left-ellipsis class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                        {{ __('Add to Discord') }}
                                    </a>
                                @endif
                            </div>
                        @endif
                        @if ($canManage && count($types) === 0)
                            <p class="mt-3 text-xs text-brand-mist">{{ __('No destination types are enabled yet.') }}</p>
                        @endif
                    </section>
                @else
                    @include('livewire.organizations.notifications.matrix')
                @endif

                <p class="text-xs text-brand-moss">
                    {{ __('Per-team overrides live on each team. The in-app bell always gets everything.') }}
                </p>

                @if ($slackWorkspaces->isNotEmpty() || $discordGuilds->isNotEmpty() || $telegramChats->isNotEmpty() || ($channels->isNotEmpty() && ($slackConnect || $discordConnect)))
                    @include('livewire.organizations.notifications.connected-apps')
                @endif
            </div>
        </x-organization-shell>
    </div>

    @include('livewire.organizations.notifications.channel-modals')

    <x-slot name="modals">
        @include('livewire.partials.confirm-action-modal')
    </x-slot>
</div>
