{{-- Connected chat apps: Slack workspaces, Discord servers, Telegram chats.
     One connect turns later Slack/Discord destinations into a dropdown pick
     instead of a webhook hunt. Telegram connects from the channel form. --}}
@php
    $disconnectBtn = 'inline-flex h-7 shrink-0 items-center rounded-lg border border-brand-ink/15 bg-white px-2.5 text-xs font-semibold text-brand-moss shadow-sm transition hover:border-rose-300 hover:text-rose-700';
    $groups = [
        [
            'title' => __('Slack workspaces'),
            'icon' => 'heroicon-o-chat-bubble-left-right',
            'rows' => $slackWorkspaces->map(fn ($w) => ['id' => $w->id, 'name' => $w->team_name, 'at' => $w->created_at]),
            'method' => 'disconnectSlackWorkspace',
            'warning' => __('Destinations pointed at it stop delivering until you reconnect.'),
        ],
        [
            'title' => __('Discord servers'),
            'icon' => 'heroicon-o-chat-bubble-oval-left-ellipsis',
            'rows' => $discordGuilds->map(fn ($g) => ['id' => $g->id, 'name' => $g->guild_name, 'at' => $g->created_at]),
            'method' => 'disconnectDiscordGuild',
            'warning' => __('Destinations pointed at it stop delivering. Remove the dply bot in Discord to fully revoke access.'),
        ],
        [
            'title' => __('Telegram chats'),
            'icon' => 'heroicon-o-paper-airplane',
            'rows' => $telegramChats->map(fn ($c) => ['id' => $c->id, 'name' => $c->chat_title, 'at' => $c->created_at]),
            'method' => 'disconnectTelegramChat',
            'warning' => __('Destinations pointed at it stop delivering. Remove the dply bot in Telegram to fully revoke access.'),
        ],
    ];
@endphp
<section class="dply-card overflow-hidden p-0" aria-labelledby="nc-apps-heading">
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-brand-ink/10 px-5 py-3.5 sm:px-6">
        <div class="min-w-0">
            <h2 id="nc-apps-heading" class="text-sm font-semibold text-brand-ink">{{ __('Connected apps') }}</h2>
            <p class="mt-0.5 text-xs text-brand-moss">{{ __('Route alerts to any channel without copying webhook URLs.') }}</p>
        </div>
        <div class="flex flex-wrap gap-2">
            @if ($slackConnect)
                <a href="{{ $this->slackConnectUrl() }}" x-on:click.prevent="window.location.href = @js($this->slackConnectUrl()) {!! $returnTo !!}" class="{{ $connectBtn }}">
                    <x-heroicon-o-chat-bubble-left-right class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                    {{ $slackWorkspaces->isEmpty() ? __('Add to Slack') : __('Add workspace') }}
                </a>
            @endif
            @if ($discordConnect)
                <a href="{{ $this->discordConnectUrl() }}" x-on:click.prevent="window.location.href = @js($this->discordConnectUrl()) {!! $returnTo !!}" class="{{ $connectBtn }}">
                    <x-heroicon-o-chat-bubble-oval-left-ellipsis class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                    {{ $discordGuilds->isEmpty() ? __('Add to Discord') : __('Add server') }}
                </a>
            @endif
        </div>
    </div>

    @foreach ($groups as $group)
        @if ($group['rows']->isNotEmpty())
            <p class="border-b border-brand-ink/10 bg-brand-sand/20 px-5 py-1.5 text-2xs font-semibold uppercase tracking-[0.14em] text-brand-mist sm:px-6">{{ $group['title'] }}</p>
            <ul class="divide-y divide-brand-ink/8 border-b border-brand-ink/10 last:border-b-0">
                @foreach ($group['rows'] as $row)
                    <li wire:key="nc-app-{{ $row['id'] }}" class="flex items-center gap-3 px-5 py-2.5 sm:px-6">
                        <x-dynamic-component :component="$group['icon']" class="h-4 w-4 shrink-0 text-brand-moss" aria-hidden="true" />
                        <span class="min-w-0 flex-1 truncate text-sm font-medium text-brand-ink">{{ $row['name'] }}</span>
                        <span class="hidden shrink-0 text-xs text-brand-mist sm:inline">{{ __('Connected :when', ['when' => $row['at']?->diffForHumans() ?? '—']) }}</span>
                        <button
                            type="button"
                            wire:click="openConfirmActionModal('{{ $group['method'] }}', ['{{ $row['id'] }}'], @js(__('Disconnect :name', ['name' => $row['name']])), @js($group['warning']), @js(__('Disconnect')), true)"
                            class="{{ $disconnectBtn }}"
                        >
                            {{ __('Disconnect') }}
                        </button>
                    </li>
                @endforeach
            </ul>
        @endif
    @endforeach
</section>
