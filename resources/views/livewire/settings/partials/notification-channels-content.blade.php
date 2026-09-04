@php
    $hasChannelSearch = trim($search ?? '') !== '';
    $channelTotal = $channels->count();
@endphp

{{-- On the settings layout the trail is hoisted above the nav + grid via the
     stack; in the org shell it's passed to x-organization-shell's :breadcrumb. --}}
@if (! empty($breadcrumbs) && empty($useOrgShell))
    @push('breadcrumbs')
        <x-breadcrumb-trail doc-route="docs.index" :items="$breadcrumbs" />
    @endpush
@endif

@if (! empty($backUrl))
    <div class="border-b border-brand-ink/10 px-3 py-2 sm:px-4">
        <a href="{{ $backUrl }}" wire:navigate class="inline-flex items-center gap-1 text-sm font-medium text-brand-sage hover:text-brand-ink">
            <x-heroicon-m-chevron-left class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
            {{ $backLabel ?? __('Back') }}
        </a>
    </div>
@endif

@if ($errors->isNotEmpty())
    <div class="border-b border-brand-ink/10 px-3 py-2 sm:px-4">
        <x-livewire-validation-errors />
    </div>
@endif

{{-- "Available beyond your personal channels" — shown only on the personal
     /settings/notification-channels page, where the user can also see org +
     team-owned channels that target them. --}}
@php
    // One flat list of inherited channels: an owner caption row, then its
    // channels. Three nested card levels (section → per-owner card → rows)
    // were spending most of their height on borders.
    //
    // Groups with nothing in them are dropped rather than rendered as "None
    // yet." — an owner caption plus an empty-state line is a whole band of
    // chrome saying nothing. The counts already live in the stats row above.
    $inheritedGroups = collect();
    if (empty($useOrgShell)) {
        if (($currentOrganization ?? null) && isset($organizationChannels)) {
            $inheritedGroups->push([
                'kind' => __('Organization'),
                'name' => $currentOrganization->name,
                'href' => auth()->user()?->can('viewNotificationChannels', $currentOrganization)
                    ? route('organizations.notification-channels', $currentOrganization)
                    : null,
                'channels' => $organizationChannels,
            ]);
        }
        foreach (($teamChannelGroups ?? collect()) as $entry) {
            $inheritedGroups->push([
                'kind' => __('Team'),
                'name' => $entry['team']->name,
                'href' => route('teams.notification-channels', [$entry['team']->organization, $entry['team']]),
                'channels' => $entry['channels'],
            ]);
        }
        $inheritedGroups = $inheritedGroups->filter(fn ($g) => $g['channels']->isNotEmpty())->values();
    }
@endphp
@if ($inheritedGroups->isNotEmpty())
    <div class="border-b border-brand-ink/10">
        <x-workspace-panel-head
            dense
            icon="heroicon-o-user-group"
            :title="__('Available beyond your personal channels')"
            :note="__('Organization- and team-owned channels can be assigned too — manage them from their own pages.')"
        />

        <ul class="divide-y divide-brand-ink/10">
            @foreach ($inheritedGroups as $group)
                <li>
                    <div class="flex flex-wrap items-center gap-2 bg-brand-sand/25 px-3 py-1.5 sm:px-4">
                        <span class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-moss">{{ $group['kind'] }}</span>
                        <span class="min-w-0 flex-1 truncate text-sm font-semibold text-brand-ink">{{ $group['name'] }}</span>
                        @if ($group['href'])
                            <a href="{{ $group['href'] }}" wire:navigate class="shrink-0 text-xs font-semibold text-brand-sage hover:text-brand-ink">
                                {{ __('Manage') }} →
                            </a>
                        @endif
                    </div>
                    @foreach ($group['channels'] as $channel)
                            <div class="group flex items-center justify-between gap-3 border-t border-brand-ink/10 px-3 py-1.5 transition-colors hover:bg-brand-sand/15 sm:px-4">
                                <div class="flex min-w-0 flex-1 flex-wrap items-baseline gap-x-2">
                                    <span class="truncate text-sm font-medium text-brand-ink">{{ $channel->label }}</span>
                                    <span class="truncate text-xs text-brand-moss">{{ \App\Models\NotificationChannel::labelForType($channel->type) }}</span>
                                </div>
                                <span class="shrink-0 rounded bg-brand-sand/60 px-1.5 py-px text-2xs font-semibold uppercase tracking-wide text-brand-moss">{{ trans_choice(':n use|:n uses', $channel->subscriptions_count, ['n' => $channel->subscriptions_count]) }}</span>
                            </div>
                    @endforeach
                </li>
            @endforeach
        </ul>
    </div>
@endif

{{-- The two-step model. Hidden while searching so it doesn't sit between the
     query and its results, and — once you already have a channel — folded behind
     a disclosure. Standing teaching copy earns its space on an empty page; above
     a list you've already built, it is the tallest thing you scroll past. --}}
@if (! $hasChannelSearch)
    @php
        $explainerData = [
            'canManage' => $canManage,
            'bulkAssignUrl' => ! empty($showBulkAssign ?? false) ? route('profile.notification-channels.bulk-assign') : null,
        ];
    @endphp
    @if ($channelTotal === 0)
        @include('livewire.settings.partials.notification-channels-explainer', $explainerData)
    @else
        <details class="group border-b border-brand-ink/10">
            <summary class="flex cursor-pointer list-none items-center gap-1.5 px-3 py-2 text-xs font-semibold text-brand-moss transition-colors hover:text-brand-ink sm:px-4 [&::-webkit-details-marker]:hidden">
                <x-heroicon-m-chevron-right class="h-3.5 w-3.5 shrink-0 transition-transform group-open:rotate-90" aria-hidden="true" />
                {{ __('How notification channels work') }}
            </summary>
            @include('livewire.settings.partials.notification-channels-explainer', $explainerData + ['hideTitle' => true])
        </details>
    @endif
@endif

@if ($canManage && count($types) === 0)
    <div class="border-b border-brand-ink/10 px-3 py-2.5 sm:px-4">
        <div class="rounded-md border border-dashed border-brand-ink/15 bg-brand-cream/30 px-3 py-3 text-center">
            <p class="text-sm font-medium text-brand-ink">{{ __('No notification channel types are enabled.') }}</p>
            <p class="mt-1 text-xs text-brand-mist">{{ __('Add types via DPLY_NOTIFICATION_CHANNEL_TYPES when ready.') }}</p>
        </div>
    </div>
@elseif (! $canManage)
    <div class="border-b border-brand-ink/10 px-3 py-2.5 sm:px-4">
        <div class="rounded-md border border-dashed border-brand-ink/15 bg-brand-cream/30 px-3 py-3 text-center">
            <p class="text-sm font-medium text-brand-ink">{{ __('You can view channels here.') }}</p>
            <p class="mt-1 text-xs text-brand-mist">{{ __('Ask an admin to add or change destinations.') }}</p>
        </div>
    </div>
@endif

{{-- Connected Slack workspaces. Lives above the channel list because it is the
     prerequisite, not a peer: one connect here turns every later Slack channel
     into a dropdown pick instead of a webhook hunt. Hidden entirely on
     deployments with no Slack app registered — those operators paste webhook
     URLs and this strip would just be a dead end. --}}
@php
    $slackWorkspaces = $canManage ? $this->slackInstallations() : collect();
    $discordGuilds = $canManage ? $this->discordInstallations() : collect();
    $slackAvailable = $canManage && ($this->slackOauthConfigured() || $slackWorkspaces->isNotEmpty());
    $discordAvailable = $canManage && ($this->discordOauthConfigured() || $discordGuilds->isNotEmpty());
    // Nothing connected anywhere: the two provider strips carry no list, only an
    // offer each, so they collapse into one row instead of two full-width bands.
    $noAppsConnected = $slackWorkspaces->isEmpty() && $discordGuilds->isEmpty();
@endphp
@if (($slackAvailable || $discordAvailable) && $noAppsConnected)
    <div class="flex flex-wrap items-center gap-x-3 gap-y-2 border-b border-brand-ink/10 px-3 py-2 sm:px-4">
        <span class="shrink-0 text-xs font-semibold text-brand-ink">{{ __('Connect a chat app') }}</span>
        <span class="min-w-0 flex-1 truncate text-xs text-brand-mist">{{ __('Route alerts to any channel without copying webhook URLs.') }}</span>
        @if ($slackAvailable && $this->slackOauthConfigured())
            <a
                href="{{ $this->slackConnectUrl() }}"
                x-on:click.prevent="window.location.href = @js($this->slackConnectUrl()) + '&return_to=' + encodeURIComponent(window.location.pathname + window.location.search)"
                class="inline-flex h-6 shrink-0 items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40"
            >
                <x-heroicon-o-chat-bubble-left-right class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                {{ __('Add to Slack') }}
            </a>
        @endif
        @if ($discordAvailable && $this->discordOauthConfigured())
            <a
                href="{{ $this->discordConnectUrl() }}"
                x-on:click.prevent="window.location.href = @js($this->discordConnectUrl()) + '&return_to=' + encodeURIComponent(window.location.pathname + window.location.search)"
                class="inline-flex h-6 shrink-0 items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40"
            >
                <x-heroicon-o-chat-bubble-oval-left-ellipsis class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                {{ __('Add to Discord') }}
            </a>
        @endif
    </div>
@endif
@if ($slackAvailable && ! $noAppsConnected)
    <div class="border-b border-brand-ink/10">
        <x-workspace-panel-head
            dense
            icon="heroicon-o-chat-bubble-left-right"
            :title="__('Slack workspaces')"
            :count="$slackWorkspaces->isNotEmpty() ? $slackWorkspaces->count() : null"
            :note="__('Connect once, then route alerts to any channel without copying webhook URLs.')"
        >
            @if ($this->slackOauthConfigured())
                <x-slot:actions>
                    <a
                        href="{{ $this->slackConnectUrl() }}"
                        x-on:click.prevent="window.location.href = @js($this->slackConnectUrl()) + '&return_to=' + encodeURIComponent(window.location.pathname + window.location.search)"
                        class="inline-flex h-6 shrink-0 items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40"
                    >
                        <x-heroicon-o-plus class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                        {{ $slackWorkspaces->isEmpty() ? __('Add to Slack') : __('Add workspace') }}
                    </a>
                </x-slot:actions>
            @endif
        </x-workspace-panel-head>

        @if ($slackWorkspaces->isNotEmpty())
            <ul class="divide-y divide-brand-ink/10 border-t border-brand-ink/10">
                @foreach ($slackWorkspaces as $workspace)
                    <li class="group flex items-center justify-between gap-3 px-3 py-1.5 transition-colors hover:bg-brand-sand/15 sm:px-4">
                        <div class="flex min-w-0 flex-1 flex-wrap items-baseline gap-x-2">
                            <span class="truncate text-sm font-medium text-brand-ink">{{ $workspace->team_name }}</span>
                            <span class="truncate text-xs text-brand-mist">{{ __('Connected :when', ['when' => $workspace->created_at?->diffForHumans() ?? '—']) }}</span>
                        </div>
                        <button
                            type="button"
                            wire:click="disconnectSlackWorkspace('{{ $workspace->id }}')"
                            wire:confirm="{{ __('Disconnect :team? Channels pointed at it stop delivering until you reconnect.', ['team' => $workspace->team_name]) }}"
                            class="inline-flex h-6 shrink-0 items-center rounded-md border border-brand-ink/15 bg-white px-2.5 text-xs font-semibold text-brand-moss shadow-sm transition hover:border-rose-200 hover:bg-rose-50 hover:text-rose-700"
                        >
                            {{ __('Disconnect') }}
                        </button>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
@endif

{{-- Connected Discord servers — same shape as the Slack strip above. --}}
@if ($discordAvailable && ! $noAppsConnected)
    <div class="border-b border-brand-ink/10">
        <x-workspace-panel-head
            dense
            icon="heroicon-o-chat-bubble-oval-left-ellipsis"
            :title="__('Discord servers')"
            :count="$discordGuilds->isNotEmpty() ? $discordGuilds->count() : null"
            :note="__('Add the dply bot once, then route alerts to any channel without copying webhook URLs.')"
        >
            @if ($this->discordOauthConfigured())
                <x-slot:actions>
                    <a
                        href="{{ $this->discordConnectUrl() }}"
                        x-on:click.prevent="window.location.href = @js($this->discordConnectUrl()) + '&return_to=' + encodeURIComponent(window.location.pathname + window.location.search)"
                        class="inline-flex h-6 shrink-0 items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40"
                    >
                        <x-heroicon-o-plus class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                        {{ $discordGuilds->isEmpty() ? __('Add to Discord') : __('Add server') }}
                    </a>
                </x-slot:actions>
            @endif
        </x-workspace-panel-head>

        @if ($discordGuilds->isNotEmpty())
            <ul class="divide-y divide-brand-ink/10 border-t border-brand-ink/10">
                @foreach ($discordGuilds as $guild)
                    <li class="group flex items-center justify-between gap-3 px-3 py-1.5 transition-colors hover:bg-brand-sand/15 sm:px-4">
                        <div class="flex min-w-0 flex-1 flex-wrap items-baseline gap-x-2">
                            <span class="truncate text-sm font-medium text-brand-ink">{{ $guild->guild_name }}</span>
                            <span class="truncate text-xs text-brand-mist">{{ __('Connected :when', ['when' => $guild->created_at?->diffForHumans() ?? '—']) }}</span>
                        </div>
                        <button
                            type="button"
                            wire:click="disconnectDiscordGuild('{{ $guild->id }}')"
                            wire:confirm="{{ __('Disconnect :guild? Channels pointed at it stop delivering. Remove the dply bot in Discord to fully revoke access.', ['guild' => $guild->guild_name]) }}"
                            class="inline-flex h-6 shrink-0 items-center rounded-md border border-brand-ink/15 bg-white px-2.5 text-xs font-semibold text-brand-moss shadow-sm transition hover:border-rose-200 hover:bg-rose-50 hover:text-rose-700"
                        >
                            {{ __('Disconnect') }}
                        </button>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
@endif

{{-- Connected Telegram chats. No "Add" button here: connecting is a wait-and-poll
     flow that belongs inside the channel form, not on a page that isn't watching
     for it. --}}
@php
    $telegramChatRows = $canManage ? $this->telegramInstallations() : collect();
@endphp
@if ($canManage && $telegramChatRows->isNotEmpty())
    <div class="border-b border-brand-ink/10">
        <x-workspace-panel-head
            dense
            icon="heroicon-o-paper-airplane"
            :title="__('Telegram chats')"
            :count="$telegramChatRows->count()"
            :note="__('Chats the dply bot has been added to. Connect a new one from the Telegram channel form.')"
        />

        <ul class="divide-y divide-brand-ink/10 border-t border-brand-ink/10">
            @foreach ($telegramChatRows as $chatRow)
                <li class="group flex items-center justify-between gap-3 px-3 py-1.5 transition-colors hover:bg-brand-sand/15 sm:px-4">
                    <div class="flex min-w-0 flex-1 flex-wrap items-baseline gap-x-2">
                        <span class="truncate text-sm font-medium text-brand-ink">{{ $chatRow->chat_title }}</span>
                        <span class="truncate text-xs text-brand-mist">{{ __('Connected :when', ['when' => $chatRow->created_at?->diffForHumans() ?? '—']) }}</span>
                    </div>
                    <button
                        type="button"
                        wire:click="disconnectTelegramChat('{{ $chatRow->id }}')"
                        wire:confirm="{{ __('Disconnect :chat? Channels pointed at it stop delivering. Remove the dply bot in Telegram to fully revoke access.', ['chat' => $chatRow->chat_title]) }}"
                        class="inline-flex h-6 shrink-0 items-center rounded-md border border-brand-ink/15 bg-white px-2.5 text-xs font-semibold text-brand-moss shadow-sm transition hover:border-rose-200 hover:bg-rose-50 hover:text-rose-700"
                    >
                        {{ __('Disconnect') }}
                    </button>
                </li>
            @endforeach
        </ul>
    </div>
@endif

{{-- Channel list. No second identity strip and no duplicate Add — shell
     header (when list has items) or empty-state CTA (when empty). --}}
<div class="border-b border-brand-ink/10 last:border-b-0">
    @if ($channelTotal > 0 || $hasChannelSearch)
        <div class="flex flex-col gap-2 border-b border-brand-ink/10 px-3 py-2 sm:flex-row sm:items-center sm:justify-end sm:px-4">
            <div class="w-full sm:max-w-xs">
                <label for="nc_my_channels_search" class="sr-only">{{ __('Search') }}</label>
                <div class="relative">
                    <span class="pointer-events-none absolute inset-y-0 start-0 flex items-center ps-2.5 text-brand-mist">
                        <x-heroicon-o-magnifying-glass class="h-3.5 w-3.5" aria-hidden="true" />
                    </span>
                    <input
                        id="nc_my_channels_search"
                        type="search"
                        wire:model.live.debounce.300ms="search"
                        placeholder="{{ __('Search channels…') }}"
                        autocomplete="off"
                        class="h-7 w-full rounded-md border-brand-ink/15 bg-white py-0 ps-8 pe-2.5 text-xs text-brand-ink placeholder:text-brand-mist shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                    />
                </div>
            </div>
        </div>
    @endif

    @if ($channels->isEmpty())
        <div class="flex flex-col items-center justify-center px-3 py-10 text-center sm:px-4">
            <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-brand-sand/45 text-brand-mist ring-1 ring-brand-ink/10">
                <x-heroicon-o-bell-slash class="h-4 w-4" aria-hidden="true" />
            </span>
            <p class="mt-2.5 text-sm font-semibold text-brand-ink">
                {{ $hasChannelSearch ? __('No channels match this search.') : __('No notification channels yet') }}
            </p>
            @if (! $hasChannelSearch)
                <p class="mt-1 max-w-md text-xs leading-relaxed text-brand-moss">
                    {{ __('Add a destination so alerts have somewhere to go — chat, email, webhook, or mobile.') }}
                </p>
            @endif
            @if ($hasChannelSearch)
                <button type="button" wire:click="$set('search', '')" class="mt-2 text-xs font-semibold text-brand-sage hover:text-brand-ink">{{ __('Clear search') }}</button>
            @elseif ($canManage && count($types) > 0)
                {{-- Empty list: this is the only Add CTA (shell header omits it). --}}
                <button
                    type="button"
                    wire:click="openCreateChannelModal"
                    class="mt-3 inline-flex h-7 items-center gap-1.5 rounded-md bg-brand-ink px-2.5 text-xs font-semibold text-brand-cream shadow-sm transition-colors hover:bg-brand-forest"
                >
                    <x-heroicon-o-plus class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                    {{ __('Add channel') }}
                </button>
            @endif
        </div>
    @else
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-brand-ink/5 text-left text-sm">
                <thead class="bg-brand-sand/35 text-2xs font-semibold uppercase tracking-wide text-brand-moss">
                    <tr>
                        <th scope="col" class="px-3 py-1.5 sm:px-4">{{ __('Label') }}</th>
                        <th scope="col" class="px-3 py-1.5">{{ __('Type') }}</th>
                        <th scope="col" class="px-3 py-1.5 text-right">{{ __('Usages') }}</th>
                        <th scope="col" class="px-3 py-1.5 text-right sm:px-4">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-brand-ink/5 bg-white">
                    @foreach ($channels as $channel)
                        <tr wire:key="nc-{{ $channel->id }}" class="transition-colors hover:bg-brand-sand/15">
                            @if ($editing_id === $channel->id)
                                <td colspan="4" class="bg-brand-sand/20 px-3 py-2.5 sm:px-4">
                                    <form wire:submit="saveEdit" class="space-y-2.5">
                                        <div class="grid gap-2.5 sm:grid-cols-2">
                                            <div>
                                                <x-input-label for="edit_type" :value="__('Type')" />
                                                <x-select id="edit_type" wire:model.live="edit_type">
                                                    @foreach ($typesForEdit as $t)
                                                        <option value="{{ $t }}">{{ \App\Models\NotificationChannel::labelForType($t) }}</option>
                                                    @endforeach
                                                </x-select>
                                                @error('edit_type')
                                                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                                                @enderror
                                            </div>
                                            <div>
                                                <x-input-label for="edit_label" :value="__('Label')" />
                                                <x-text-input id="edit_label" type="text" wire:model="edit_label" />
                                                @error('edit_label')
                                                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                                                @enderror
                                            </div>
                                        </div>
                                        @include('livewire.settings.partials.notification-channel-fields', ['prefix' => 'edit_', 'type' => $edit_type])
                                        <div class="flex flex-wrap gap-2">
                                            <x-primary-button type="submit">
                                                <x-heroicon-o-check class="h-4 w-4 shrink-0" aria-hidden="true" />
                                                {{ __('Save changes') }}
                                            </x-primary-button>
                                            <x-secondary-button type="button" wire:click="cancelEdit">{{ __('Cancel') }}</x-secondary-button>
                                        </div>
                                    </form>
                                </td>
                            @else
                                <td class="px-3 py-1.5 sm:px-4">
                                    <div class="flex min-w-0 items-start gap-2">
                                        <span class="mt-px flex h-6 w-6 shrink-0 items-center justify-center rounded-md bg-brand-sand/45 text-brand-moss ring-1 ring-brand-ink/10">
                                            <x-dynamic-component
                                                :component="\App\Models\NotificationChannel::iconForType($channel->type)"
                                                class="h-3.5 w-3.5"
                                                aria-hidden="true"
                                            />
                                        </span>
                                        <div class="min-w-0">
                                            <div class="flex flex-wrap items-center gap-1.5">
                                                <span class="truncate font-semibold text-brand-ink">{{ $channel->label }}</span>
                                                @if ($channel->isPaging())
                                                    {{-- A pager mistaken for a chat room is a 3am phone call. --}}
                                                    <span
                                                        class="inline-flex shrink-0 items-center gap-0.5 rounded bg-rose-50 px-1.5 py-px text-2xs font-semibold uppercase tracking-wide text-rose-700 ring-1 ring-rose-200"
                                                        title="{{ __('Alerts sent here page whoever is on call for the PagerDuty service.') }}"
                                                    >
                                                        <x-heroicon-m-bell-alert class="h-3 w-3" aria-hidden="true" />
                                                        {{ __('Pages on-call') }}
                                                    </span>
                                                @endif
                                            </div>
                                            {{-- Destination, so two channels of the same type are
                                                 tellable apart without opening the edit form. --}}
                                            @php($destination = $channel->describeDestination())
                                            @if ($destination !== '')
                                                <p class="mt-px truncate font-mono text-2xs text-brand-mist" title="{{ $destination }}">{{ $destination }}</p>
                                            @endif
                                        </div>
                                    </div>
                                </td>
                                <td class="px-3 py-1.5">
                                    <span class="inline-flex items-center rounded-md bg-brand-sand/55 px-2 py-0.5 text-2xs font-semibold uppercase tracking-wide text-brand-moss">
                                        {{ \App\Models\NotificationChannel::labelForType($channel->type) }}
                                    </span>
                                </td>
                                <td class="px-3 py-1.5 text-right">
                                    @if ($channel->subscriptions_count > 0)
                                        <span class="font-mono tabular-nums text-brand-moss">{{ $channel->subscriptions_count }}</span>
                                    @else
                                        {{-- 0 usages is the page's quietest failure: the channel is
                                             configured, its test passes, and it will never fire. --}}
                                        <span
                                            class="inline-flex items-center gap-0.5 rounded bg-amber-50 px-1.5 py-px text-2xs font-semibold uppercase tracking-wide text-amber-800 ring-1 ring-amber-200"
                                            title="{{ __('This channel is not subscribed to any events, so nothing will be delivered to it.') }}"
                                        >
                                            <x-heroicon-m-exclamation-triangle class="h-3 w-3" aria-hidden="true" />
                                            {{ __('Not routed') }}
                                        </span>
                                    @endif
                                </td>
                                <td class="px-3 py-1.5 text-right sm:px-4">
                                    @if ($canManage)
                                        <div class="flex flex-wrap items-center justify-end gap-1">
                                            <x-secondary-button
                                                size="xs"
                                                type="button"
                                                wire:click="sendTest('{{ $channel->id }}')"
                                                wire:loading.attr="disabled"
                                                wire:target="sendTest"
                                            >
                                                <span wire:loading.remove wire:target="sendTest" class="inline-flex items-center gap-2">
                                                    <x-heroicon-o-paper-airplane class="h-4 w-4 shrink-0" aria-hidden="true" />
                                                    {{ __('Test') }}
                                                </span>
                                                <span wire:loading wire:target="sendTest" class="inline-flex items-center gap-2">
                                                    <x-spinner variant="forest" size="sm" />
                                                    {{ __('Sending…') }}
                                                </span>
                                            </x-secondary-button>
                                            <x-secondary-button size="xs" type="button" wire:click="startEdit('{{ $channel->id }}')">
                                                <x-heroicon-o-pencil-square class="h-4 w-4 shrink-0" aria-hidden="true" />
                                                {{ __('Edit') }}
                                            </x-secondary-button>
                                            <button
                                                type="button"
                                                wire:click="openConfirmActionModal('deleteChannel', ['{{ $channel->id }}'], @js(__('Delete notification channel')), @js(__('Remove this channel?')), @js(__('Delete')), true)"
                                                class="inline-flex h-6 items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2.5 text-xs font-semibold text-brand-moss shadow-sm transition hover:border-rose-200 hover:bg-rose-50 hover:text-rose-700"
                                            >
                                                <x-heroicon-o-trash class="h-4 w-4 shrink-0" aria-hidden="true" />
                                                {{ __('Delete') }}
                                            </button>
                                        </div>
                                    @endif
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>

@if ($canManage && count($types) > 0)
    <x-modal
        name="settings-create-channel-modal"
        :show="false"
        maxWidth="2xl"
        overlayClass="bg-brand-ink/30"
        panelClass="dply-modal-panel overflow-hidden shadow-xl flex max-h-[min(90vh,880px)] flex-col"
        focusable
    >
        <form wire:submit="createChannel" class="flex min-h-0 flex-1 flex-col">
            <div class="flex shrink-0 items-start gap-3 border-b border-brand-ink/10 px-6 py-5">
                <x-icon-badge>
                    <x-heroicon-o-plus-circle class="h-5 w-5" aria-hidden="true" />
                </x-icon-badge>
                <div class="min-w-0">
                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('New') }}</p>
                    <h2 class="mt-1 text-lg font-semibold text-brand-ink">{{ __('Create notification channel') }}</h2>
                    <p class="mt-1 text-sm leading-6 text-brand-moss">
                        {{ __('Connect chat, email, Pushover, webhooks, or mobile tokens. Credentials are stored encrypted.') }}
                    </p>
                </div>
            </div>

            <div class="min-h-0 flex-1 space-y-5 overflow-y-auto px-6 py-6">
                <div class="grid gap-5 sm:grid-cols-2">
                    <div>
                        <x-input-label for="new_type_modal" :value="__('Type')" />
                        <x-select id="new_type_modal" wire:model.live="new_type">
                            @foreach ($types as $t)
                                <option value="{{ $t }}">{{ \App\Models\NotificationChannel::labelForType($t) }}</option>
                            @endforeach
                        </x-select>
                        @error('new_type')
                            <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <x-input-label for="new_label_modal" :value="__('Label')" />
                        <x-text-input id="new_label_modal" type="text" wire:model="new_label" placeholder="{{ __('e.g. #alerts') }}" required />
                        @error('new_label')
                            <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                @include('livewire.settings.partials.notification-channel-fields', ['prefix' => 'new_', 'type' => $new_type])
            </div>

            <div class="flex shrink-0 flex-wrap justify-end gap-3 border-t border-brand-ink/10 bg-brand-sand/25 px-6 py-4">
                <x-secondary-button type="button" wire:click="closeCreateChannelModal">
                    {{ __('Cancel') }}
                </x-secondary-button>
                <button
                    type="submit"
                    wire:loading.attr="disabled"
                    wire:target="createChannel"
                    class="inline-flex items-center justify-center gap-2 rounded-xl bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream shadow-md transition-colors hover:bg-brand-forest disabled:cursor-not-allowed disabled:opacity-60"
                >
                    <span wire:loading.remove wire:target="createChannel" class="inline-flex items-center gap-2">
                        <x-heroicon-o-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                        {{ __('Create channel') }}
                    </span>
                    <span wire:loading wire:target="createChannel" class="inline-flex items-center gap-2">
                        <x-spinner variant="cream" size="sm" />
                        {{ __('Creating…') }}
                    </span>
                </button>
            </div>
        </form>
    </x-modal>
@endif
