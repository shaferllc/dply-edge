{{-- Expects: $prefix (e.g. new_ or edit_), $type (channel type string) --}}
@php
    $p = $prefix;
@endphp

@if ($type === \App\Models\NotificationChannel::TYPE_SLACK)
    @php
        // One connected workspace backs every Slack channel this owner creates,
        // so the picker is the normal path and the pasted webhook is the escape
        // hatch — reversed from how this form worked before "Add to Slack".
        $slackInstallations = $this->slackInstallations();
        $slackOauthReady = $this->slackOauthConfigured();
        $slackMode = $this->slackMode($p);
        $slackChannels = $slackMode === 'oauth' ? $this->slackChannelOptions($p) : [];
    @endphp

    <div class="space-y-4">
        {{-- No workspace connected yet — offer the connect path in *both* modes,
             and even where the deployment has no Slack app registered. Hiding the
             prompt there left operators staring at a bare webhook box with no hint
             the channel picker exists; the redirect already turns an unconfigured
             deployment into a plain explanation rather than a broken flow. --}}
        @if ($slackInstallations->isEmpty())
            <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/40 p-4">
                <p class="text-sm font-semibold text-brand-ink">{{ __('Connect your Slack workspace') }}</p>
                <p class="mt-1 text-xs text-brand-moss">
                    {{ __('Approve dply once, then pick a channel here — no webhook URLs to copy. Connecting again later adds more workspaces.') }}
                </p>
                {{-- return_to is appended here, not server-side: this markup is
                     usually rendered by a Livewire update whose request path is
                     the Livewire endpoint, not the page. --}}
                <a
                    href="{{ $this->slackConnectUrl() }}"
                    x-on:click.prevent="window.location.href = @js($this->slackConnectUrl()) + '&return_to=' + encodeURIComponent(window.location.pathname + window.location.search)"
                    class="mt-3 inline-flex items-center gap-2 rounded-lg bg-brand-ink px-3 py-1.5 text-xs font-semibold text-white shadow-sm transition hover:bg-brand-ink/90"
                >
                    <x-heroicon-o-plus-circle class="h-4 w-4 shrink-0" aria-hidden="true" />
                    {{ __('Add to Slack') }}
                </a>

                @unless ($slackOauthReady)
                    <p class="mt-2 text-xs text-brand-moss">
                        {{ __('Not set up on this deployment yet — paste an incoming webhook URL below instead.') }}
                    </p>
                @endunless

                {{-- The escape hatch has to live here too: with no install to pick
                     from, the picker branch below renders nothing, so without this
                     the webhook fields were unreachable. --}}
                @if ($slackMode === 'oauth')
                    <button
                        type="button"
                        wire:click="$set('{{ $p }}slack_mode', 'webhook')"
                        class="mt-3 block text-xs font-semibold text-brand-moss underline-offset-2 transition hover:text-brand-ink hover:underline"
                    >
                        {{ __('Use a webhook URL instead') }}
                    </button>
                @endif
            </div>
        @endif

        @if ($slackMode === 'oauth' && $slackInstallations->isNotEmpty())
            @if ($slackInstallations->count() > 1)
                <div>
                    <x-input-label for="{{ $p }}slack_installation_id" :value="__('Workspace')" />
                    <select
                        id="{{ $p }}slack_installation_id"
                        wire:model.live="{{ $p }}slack_installation_id"
                        class="mt-1 block w-full rounded-xl border border-brand-ink/15 bg-white px-3 py-2 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                    >
                        @foreach ($slackInstallations as $installation)
                            <option value="{{ $installation->id }}">{{ $installation->team_name }}</option>
                        @endforeach
                    </select>
                    @error($p.'slack_installation_id')
                        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                    @enderror
                </div>
            @endif

            <div>
                <div class="flex items-end justify-between gap-3">
                    <x-input-label for="{{ $p }}slack_channel_id" :value="__('Channel')" />
                    <button
                        type="button"
                        wire:click="refreshSlackChannels('{{ $p }}')"
                        wire:loading.attr="disabled"
                        wire:target="refreshSlackChannels"
                        class="text-2xs font-semibold uppercase tracking-[0.14em] text-brand-moss transition hover:text-brand-ink disabled:opacity-50"
                    >
                        {{ __('Refresh') }}
                    </button>
                </div>
                <select
                    id="{{ $p }}slack_channel_id"
                    wire:model="{{ $p }}slack_channel_id"
                    class="mt-1 block w-full rounded-xl border border-brand-ink/15 bg-white px-3 py-2 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                >
                    <option value="">{{ __('Select a channel…') }}</option>
                    @foreach ($slackChannels as $slackChannel)
                        <option value="{{ $slackChannel['id'] }}">
                            {{ $slackChannel['is_private'] ? '🔒 ' : '#' }}{{ $slackChannel['name'] }}
                        </option>
                    @endforeach
                </select>
                @error($p.'slack_channel_id')
                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                @enderror
                @if ($slackChannels === [])
                    <p class="mt-1 text-xs text-brand-moss">
                        {{ __('No channels came back. The Slack connection may have been revoked — reconnect the workspace.') }}
                    </p>
                @else
                    {{-- Slack grants no scope for posting into a private channel uninvited, so this is a real prerequisite, not a nicety. --}}
                    <p class="mt-1 text-xs text-brand-moss">
                        {{ __('Private channels (🔒) need dply invited first: run /invite @dply in that channel.') }}
                    </p>
                @endif
            </div>

            <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs">
                <a
                    href="{{ $this->slackConnectUrl() }}"
                    x-on:click.prevent="window.location.href = @js($this->slackConnectUrl()) + '&return_to=' + encodeURIComponent(window.location.pathname + window.location.search)"
                    class="font-semibold text-brand-moss underline-offset-2 transition hover:text-brand-ink hover:underline"
                >
                    {{ __('Add another workspace') }}
                </a>
                <span class="text-brand-ink/20" aria-hidden="true">·</span>
                <button
                    type="button"
                    wire:click="$set('{{ $p }}slack_mode', 'webhook')"
                    class="font-semibold text-brand-moss underline-offset-2 transition hover:text-brand-ink hover:underline"
                >
                    {{ __('Use a webhook URL instead') }}
                </button>
            </div>
        @elseif ($slackMode !== 'oauth')
            <div>
                <x-input-label for="{{ $p }}slack_webhook_url" :value="__('Webhook URL')" />
                <input
                    id="{{ $p }}slack_webhook_url"
                    type="url"
                    wire:model="{{ $p }}slack_webhook_url"
                    class="mt-1 block w-full rounded-xl border border-brand-ink/15 px-3 py-2 text-sm font-mono shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                    placeholder="https://hooks.slack.com/services/…"
                    autocomplete="off"
                />
                @error($p.'slack_webhook_url')
                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                @enderror
            </div>
            <div>
                <x-input-label for="{{ $p }}slack_channel" :value="__('Channel (optional)')" />
                <input
                    id="{{ $p }}slack_channel"
                    type="text"
                    wire:model="{{ $p }}slack_channel"
                    class="mt-1 block w-full rounded-xl border border-brand-ink/15 px-3 py-2 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                    placeholder="general"
                />
            </div>

            {{-- Only a switch-back: with no install the connect prompt above is
                 already the way back to the picker. --}}
            @if ($slackInstallations->isNotEmpty())
                <button
                    type="button"
                    wire:click="$set('{{ $p }}slack_mode', 'oauth')"
                    class="text-xs font-semibold text-brand-moss underline-offset-2 transition hover:text-brand-ink hover:underline"
                >
                    {{ __('Pick from a connected workspace instead') }}
                </button>
            @endif
        @endif
    </div>
@elseif ($type === \App\Models\NotificationChannel::TYPE_DISCORD)
    @php
        $discordInstallations = $this->discordInstallations();
        $discordOauthReady = $this->discordOauthConfigured();
        $discordMode = $this->discordMode($p);
        $discordChannels = $discordMode === 'oauth' ? $this->discordChannelOptions($p) : [];
    @endphp

    <div class="space-y-4">
        {{-- Same shape as Slack above: the connect prompt shows in both modes
             while nothing is connected, unconfigured deployment included. --}}
        @if ($discordInstallations->isEmpty())
            <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/40 p-4">
                <p class="text-sm font-semibold text-brand-ink">{{ __('Connect your Discord server') }}</p>
                <p class="mt-1 text-xs text-brand-moss">
                    {{ __('Adds the dply bot to your server so you can pick a channel here — no webhook URLs to copy. You can connect more than one server.') }}
                </p>
                <a
                    href="{{ $this->discordConnectUrl() }}"
                    x-on:click.prevent="window.location.href = @js($this->discordConnectUrl()) + '&return_to=' + encodeURIComponent(window.location.pathname + window.location.search)"
                    class="mt-3 inline-flex items-center gap-2 rounded-lg bg-brand-ink px-3 py-1.5 text-xs font-semibold text-white shadow-sm transition hover:bg-brand-ink/90"
                >
                    <x-heroicon-o-plus-circle class="h-4 w-4 shrink-0" aria-hidden="true" />
                    {{ __('Add to Discord') }}
                </a>

                @unless ($discordOauthReady)
                    <p class="mt-2 text-xs text-brand-moss">
                        {{ __('Not set up on this deployment yet — paste a webhook URL below instead.') }}
                    </p>
                @endunless

                @if ($discordMode === 'oauth')
                    <button
                        type="button"
                        wire:click="$set('{{ $p }}discord_mode', 'webhook')"
                        class="mt-3 block text-xs font-semibold text-brand-moss underline-offset-2 transition hover:text-brand-ink hover:underline"
                    >
                        {{ __('Use a webhook URL instead') }}
                    </button>
                @endif
            </div>
        @endif

        @if ($discordMode === 'oauth' && $discordInstallations->isNotEmpty())
            @if ($discordInstallations->count() > 1)
                <div>
                    <x-input-label for="{{ $p }}discord_installation_id" :value="__('Server')" />
                    <select
                        id="{{ $p }}discord_installation_id"
                        wire:model.live="{{ $p }}discord_installation_id"
                        class="mt-1 block w-full rounded-xl border border-brand-ink/15 bg-white px-3 py-2 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                    >
                        @foreach ($discordInstallations as $installation)
                            <option value="{{ $installation->id }}">{{ $installation->guild_name }}</option>
                        @endforeach
                    </select>
                    @error($p.'discord_installation_id')
                        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                    @enderror
                </div>
            @endif

            <div>
                <div class="flex items-end justify-between gap-3">
                    <x-input-label for="{{ $p }}discord_channel_id" :value="__('Channel')" />
                    <button
                        type="button"
                        wire:click="refreshDiscordChannels('{{ $p }}')"
                        wire:loading.attr="disabled"
                        wire:target="refreshDiscordChannels"
                        class="text-2xs font-semibold uppercase tracking-[0.14em] text-brand-moss transition hover:text-brand-ink disabled:opacity-50"
                    >
                        {{ __('Refresh') }}
                    </button>
                </div>
                <select
                    id="{{ $p }}discord_channel_id"
                    wire:model="{{ $p }}discord_channel_id"
                    class="mt-1 block w-full rounded-xl border border-brand-ink/15 bg-white px-3 py-2 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                >
                    <option value="">{{ __('Select a channel…') }}</option>
                    @foreach ($discordChannels as $discordChannel)
                        <option value="{{ $discordChannel['id'] }}">
                            {{ $discordChannel['is_announcement'] ? '📣 ' : '#' }}{{ $discordChannel['name'] }}
                        </option>
                    @endforeach
                </select>
                @error($p.'discord_channel_id')
                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                @enderror
                @if ($discordChannels === [])
                    <p class="mt-1 text-xs text-brand-moss">
                        {{ __('No channels came back. The dply bot may have been removed from the server, or it cannot see any channels — reconnect it.') }}
                    </p>
                @else
                    {{-- A channel-level permission overwrite beats the server-wide grant, and only shows up as a failed send. --}}
                    <p class="mt-1 text-xs text-brand-moss">
                        {{ __('Channels with restricted permissions need the dply role allowed to View Channel and Send Messages.') }}
                    </p>
                @endif
            </div>

            <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs">
                <a
                    href="{{ $this->discordConnectUrl() }}"
                    x-on:click.prevent="window.location.href = @js($this->discordConnectUrl()) + '&return_to=' + encodeURIComponent(window.location.pathname + window.location.search)"
                    class="font-semibold text-brand-moss underline-offset-2 transition hover:text-brand-ink hover:underline"
                >
                    {{ __('Add another server') }}
                </a>
                <span class="text-brand-ink/20" aria-hidden="true">·</span>
                <button
                    type="button"
                    wire:click="$set('{{ $p }}discord_mode', 'webhook')"
                    class="font-semibold text-brand-moss underline-offset-2 transition hover:text-brand-ink hover:underline"
                >
                    {{ __('Use a webhook URL instead') }}
                </button>
            </div>
        @elseif ($discordMode !== 'oauth')
            <div>
                <x-input-label for="{{ $p }}discord_webhook_url" :value="__('Webhook URL')" />
                <input
                    id="{{ $p }}discord_webhook_url"
                    type="url"
                    wire:model="{{ $p }}discord_webhook_url"
                    class="mt-1 block w-full rounded-xl border border-brand-ink/15 px-3 py-2 text-sm font-mono shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                    placeholder="https://discord.com/api/webhooks/…"
                    autocomplete="off"
                />
                @error($p.'discord_webhook_url')
                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                @enderror
            </div>

            @if ($discordInstallations->isNotEmpty())
                <button
                    type="button"
                    wire:click="$set('{{ $p }}discord_mode', 'oauth')"
                    class="text-xs font-semibold text-brand-moss underline-offset-2 transition hover:text-brand-ink hover:underline"
                >
                    {{ __('Pick from a connected server instead') }}
                </button>
            @endif
        @endif
    </div>
@elseif ($type === \App\Models\NotificationChannel::TYPE_EMAIL)
    <div>
        <x-input-label for="{{ $p }}email_address" :value="__('E-mail address')" />
        <input
            id="{{ $p }}email_address"
            type="email"
            wire:model="{{ $p }}email_address"
            class="mt-1 block w-full rounded-xl border border-brand-ink/15 px-3 py-2 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage"
            autocomplete="off"
        />
        @error($p.'email_address')
            <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
        @enderror
    </div>
@elseif ($type === \App\Models\NotificationChannel::TYPE_TELEGRAM)
    @php
        $telegramChats = $this->telegramInstallations();
        $telegramBotReady = $this->telegramBotConfigured();
        $telegramMode = $this->telegramMode($p);
        $telegramWaiting = $this->telegramConnectToken !== '';
    @endphp

    <div class="space-y-4">
        @if ($telegramMode === 'connected' && $telegramWaiting)
            {{-- Telegram never redirects back, so the form waits here and polls
                 the claim check instead. Polling stops the moment it resolves. --}}
            <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/40 p-4" wire:poll.2s="pollTelegramConnect">
                <div class="flex items-start gap-3">
                    <span class="mt-0.5 flex h-4 w-4 shrink-0 animate-spin rounded-full border-2 border-brand-ink/20 border-t-brand-ink" aria-hidden="true"></span>
                    <div class="min-w-0">
                        <p class="text-sm font-semibold text-brand-ink">{{ __('Waiting for you to pick a chat…') }}</p>
                        <p class="mt-1 text-xs text-brand-moss">
                            {{ __('Telegram should have opened. Choose the group to add the dply bot to, and this will finish by itself.') }}
                        </p>
                        <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs">
                            <a href="{{ $this->telegramConnectLink }}" target="_blank" rel="noopener" class="font-semibold text-brand-moss underline-offset-2 hover:text-brand-ink hover:underline">
                                {{ __('Reopen Telegram') }}
                            </a>
                            @if ($this->telegramDirectMessageLink() !== '')
                                <span class="text-brand-ink/20" aria-hidden="true">·</span>
                                <a href="{{ $this->telegramDirectMessageLink() }}" target="_blank" rel="noopener" class="font-semibold text-brand-moss underline-offset-2 hover:text-brand-ink hover:underline">
                                    {{ __('Send to me directly instead') }}
                                </a>
                            @endif
                            <span class="text-brand-ink/20" aria-hidden="true">·</span>
                            <button type="button" wire:click="cancelTelegramConnect" class="font-semibold text-brand-moss underline-offset-2 hover:text-brand-ink hover:underline">
                                {{ __('Cancel') }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        @else
            {{-- Nothing connected: the prompt shows in both modes, and on a
                 deployment with no bot token too — startTelegramConnect() answers
                 with a plain explanation rather than a dead button. --}}
            @if ($telegramChats->isEmpty())
                <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/40 p-4">
                    <p class="text-sm font-semibold text-brand-ink">{{ __('Connect a Telegram chat') }}</p>
                    <p class="mt-1 text-xs text-brand-moss">
                        {{ __('Adds the dply bot to a group you choose — no bot tokens or chat IDs to look up.') }}
                    </p>
                    <button
                        type="button"
                        wire:click="startTelegramConnect"
                        wire:loading.attr="disabled"
                        wire:target="startTelegramConnect"
                        class="mt-3 inline-flex items-center gap-2 rounded-lg bg-brand-ink px-3 py-1.5 text-xs font-semibold text-white shadow-sm transition hover:bg-brand-ink/90 disabled:opacity-60"
                    >
                        <x-heroicon-o-plus-circle class="h-4 w-4 shrink-0" aria-hidden="true" />
                        {{ __('Connect Telegram') }}
                    </button>

                    @unless ($telegramBotReady)
                        <p class="mt-2 text-xs text-brand-moss">
                            {{ __('Not set up on this deployment yet — enter a bot token below instead.') }}
                        </p>
                    @endunless

                    @if ($telegramMode === 'connected')
                        <button
                            type="button"
                            wire:click="$set('{{ $p }}telegram_mode', 'manual')"
                            class="mt-3 block text-xs font-semibold text-brand-moss underline-offset-2 transition hover:text-brand-ink hover:underline"
                        >
                            {{ __('Enter a bot token manually instead') }}
                        </button>
                    @endif
                </div>
            @endif

            @if ($telegramMode === 'connected' && $telegramChats->isNotEmpty())
                <div>
                    <x-input-label for="{{ $p }}telegram_installation_id" :value="__('Chat')" />
                    <select
                        id="{{ $p }}telegram_installation_id"
                        wire:model="{{ $p }}telegram_installation_id"
                        class="mt-1 block w-full rounded-xl border border-brand-ink/15 bg-white px-3 py-2 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                    >
                        <option value="">{{ __('Select a chat…') }}</option>
                        @foreach ($telegramChats as $chat)
                            <option value="{{ $chat->id }}">
                                {{ $chat->isDirectMessage() ? '👤 ' : ($chat->requiresAdmin() ? '📣 ' : '👥 ') }}{{ $chat->chat_title }}
                            </option>
                        @endforeach
                    </select>
                    @error($p.'telegram_installation_id')
                        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                    @enderror
                    {{-- Channels differ from groups: an ordinary member bot cannot post. --}}
                    <p class="mt-1 text-xs text-brand-moss">
                        {{ __('Channels (📣) need the dply bot promoted to administrator before it can post.') }}
                    </p>
                </div>

                <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs">
                    <button type="button" wire:click="startTelegramConnect" class="font-semibold text-brand-moss underline-offset-2 transition hover:text-brand-ink hover:underline">
                        {{ __('Connect another chat') }}
                    </button>
                    <span class="text-brand-ink/20" aria-hidden="true">·</span>
                    <button type="button" wire:click="$set('{{ $p }}telegram_mode', 'manual')" class="font-semibold text-brand-moss underline-offset-2 transition hover:text-brand-ink hover:underline">
                        {{ __('Enter a bot token manually instead') }}
                    </button>
                </div>
            @elseif ($telegramMode !== 'connected')
                @include('livewire.settings.partials.notification-channel-telegram-manual', ['p' => $p])

                {{-- Only a switch-back: with no chat connected the prompt above is
                     already the way to the picker. --}}
                @if ($telegramChats->isNotEmpty())
                    <button
                        type="button"
                        wire:click="$set('{{ $p }}telegram_mode', 'connected')"
                        class="text-xs font-semibold text-brand-moss underline-offset-2 transition hover:text-brand-ink hover:underline"
                    >
                        {{ __('Pick from a connected chat instead') }}
                    </button>
                @endif
            @endif
        @endif
    </div>
@elseif ($type === \App\Models\NotificationChannel::TYPE_PUSHOVER)
    <div class="grid gap-4 sm:grid-cols-2">
        <div>
            <x-input-label for="{{ $p }}pushover_app_token" :value="__('Application token')" />
            <input
                id="{{ $p }}pushover_app_token"
                type="password"
                wire:model="{{ $p }}pushover_app_token"
                class="mt-1 block w-full rounded-xl border border-brand-ink/15 px-3 py-2 text-sm font-mono shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                autocomplete="off"
            />
            @error($p.'pushover_app_token')
                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
            @enderror
        </div>
        <div>
            <x-input-label for="{{ $p }}pushover_user_key" :value="__('User key')" />
            <input
                id="{{ $p }}pushover_user_key"
                type="password"
                wire:model="{{ $p }}pushover_user_key"
                class="mt-1 block w-full rounded-xl border border-brand-ink/15 px-3 py-2 text-sm font-mono shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                autocomplete="off"
            />
            @error($p.'pushover_user_key')
                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
            @enderror
        </div>
    </div>
@elseif ($type === \App\Models\NotificationChannel::TYPE_MICROSOFT_TEAMS)
    @include('livewire.settings.partials.notification-channel-microsoft-teams', ['p' => $p])
@elseif ($type === \App\Models\NotificationChannel::TYPE_ROCKETCHAT)
    <div>
        <x-input-label for="{{ $p }}rocketchat_webhook_url" :value="__('Webhook URL')" />
        <input
            id="{{ $p }}rocketchat_webhook_url"
            type="url"
            wire:model="{{ $p }}rocketchat_webhook_url"
            class="mt-1 block w-full rounded-xl border border-brand-ink/15 px-3 py-2 text-sm font-mono shadow-sm focus:border-brand-sage focus:ring-brand-sage"
            autocomplete="off"
        />
        @error($p.'rocketchat_webhook_url')
            <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
        @enderror
    </div>
@elseif ($type === \App\Models\NotificationChannel::TYPE_GOOGLE_CHAT)
    <div>
        <x-input-label for="{{ $p }}google_chat_webhook_url" :value="__('Webhook URL')" />
        <input
            id="{{ $p }}google_chat_webhook_url"
            type="url"
            wire:model="{{ $p }}google_chat_webhook_url"
            class="mt-1 block w-full rounded-xl border border-brand-ink/15 px-3 py-2 text-sm font-mono shadow-sm focus:border-brand-sage focus:ring-brand-sage"
            autocomplete="off"
        />
        @error($p.'google_chat_webhook_url')
            <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
        @enderror
    </div>
@elseif ($type === \App\Models\NotificationChannel::TYPE_MOBILE_APP)
    <div class="grid gap-4 sm:grid-cols-2">
        <div>
            <x-input-label for="{{ $p }}mobile_device_token" :value="__('Device token')" />
            <textarea
                id="{{ $p }}mobile_device_token"
                wire:model="{{ $p }}mobile_device_token"
                rows="3"
                class="mt-1 block w-full rounded-xl border border-brand-ink/15 px-3 py-2 text-sm font-mono shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                placeholder="FCM / APNs token"
            ></textarea>
            @error($p.'mobile_device_token')
                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
            @enderror
        </div>
        <div>
            <x-input-label for="{{ $p }}mobile_platform" :value="__('Platform')" />
            <select
                id="{{ $p }}mobile_platform"
                wire:model="{{ $p }}mobile_platform"
                class="mt-1 block w-full rounded-xl border border-brand-ink/15 bg-white px-3 py-2 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage"
            >
                <option value="ios">iOS</option>
                <option value="android">Android</option>
            </select>
        </div>
    </div>
@elseif ($type === \App\Models\NotificationChannel::TYPE_INTERCOM)
    @include('livewire.settings.partials.notification-channel-intercom', ['p' => $p])
@elseif ($type === \App\Models\NotificationChannel::TYPE_PAGERDUTY)
    @include('livewire.settings.partials.notification-channel-pagerduty', ['p' => $p])
@else
    <div>
        <x-input-label for="{{ $p }}webhook_url" :value="__('Endpoint URL')" />
        <input
            id="{{ $p }}webhook_url"
            type="url"
            wire:model="{{ $p }}webhook_url"
            class="mt-1 block w-full rounded-xl border border-brand-ink/15 px-3 py-2 text-sm font-mono shadow-sm focus:border-brand-sage focus:ring-brand-sage"
            placeholder="https://"
            autocomplete="off"
        />
        @error($p.'webhook_url')
            <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
        @enderror
        {{-- $channel is the row being edited (the owner's own list), never the client-settable editing_id. --}}
        @if ($p === 'edit_' && isset($channel) && $channel instanceof \App\Models\NotificationChannel && ($editing_id ?? null) === $channel->id)
            <x-input-label for="edit_webhook_signing_secret" :value="__('Signing secret')" class="mt-3" />
            <input
                id="edit_webhook_signing_secret"
                type="text"
                readonly
                value="{{ \App\Models\NotificationChannel::webhookSigningSecret((string) $channel->id) }}"
                class="mt-1 block w-full rounded-xl border border-brand-ink/15 bg-brand-sand/20 px-3 py-2 text-xs font-mono shadow-sm"
                onclick="this.select()"
            />
            <p class="mt-1 text-xs text-brand-moss">{{ __('Verify the X-Dply-Signature header with this secret: HMAC-SHA256 of "<timestamp>.<body>".') }}</p>
        @else
            <p class="mt-1 text-xs text-brand-moss">{{ __('Every delivery is signed. The signing secret appears when you edit this channel after creating it.') }}</p>
        @endif
    </div>
@endif
