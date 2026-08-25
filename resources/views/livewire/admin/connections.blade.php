<div>
    <x-breadcrumb-trail :items="[
        ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
        ['label' => __('Platform admin'), 'href' => route('admin.overview'), 'icon' => 'shield-check'],
        ['label' => __('Connections'), 'icon' => 'puzzle-piece'],
    ]" />

    <x-profile-shell
        class="mt-4"
        :title="__('Connections')"
        :description="__('Platform Slack, Discord, and Telegram apps. Once saved, organization notification pages show Add to Slack, Add to Discord, and Connect Telegram instead of a paste-a-webhook form. Values here overlay .env — this page never writes the env file.')"
        icon="heroicon-o-puzzle-piece"
    >

    {{-- Slack --}}
    <section class="border-b border-brand-ink/10 last:border-0">
        <x-workspace-panel-head
            dense
            icon="heroicon-o-chat-bubble-left-right"
            :title="__('Slack')"
        >
            <x-slot:actions>
                <span @class([
                    'inline-flex items-center rounded-full px-2 py-0.5 text-2xs font-semibold uppercase tracking-wide',
                    'bg-emerald-100 text-emerald-800' => $this->status['slack']['ready'],
                    'bg-brand-ink/[0.06] text-brand-moss' => ! $this->status['slack']['ready'],
                ])>{{ $this->status['slack']['ready'] ? __('Live') : __('Not configured') }}</span>
            </x-slot:actions>
        </x-workspace-panel-head>

        <div class="px-3 py-3 sm:px-4">
        <p class="text-xs text-brand-moss">{{ __('Register an app at api.slack.com/apps. Bot scopes: chat:write, chat:write.public, channels:read, groups:read, team:read. Turn on Manage Distribution so other workspaces can install.') }}</p>

        <div class="mt-3 space-y-3">
            <div>
                <label class="block text-xs font-medium text-brand-moss" for="slack-client-id">{{ __('Client ID') }}</label>
                <input id="slack-client-id" type="text" wire:model="slack.client_id" autocomplete="off"
                    class="dply-input mt-1 block w-full font-mono text-sm" />
                @error('slack.client_id')<p class="mt-1 text-xs text-brand-rust">{{ $message }}</p>@enderror
            </div>
            <x-password-field id="slack-client-secret" label="{{ __('Client secret') }}" wire:model="slack.client_secret" mono
                placeholder="{{ $this->status['slack']['hints']['client_secret'] !== '' ? __('Saved :hint — leave blank to keep', ['hint' => $this->status['slack']['hints']['client_secret']]) : __('Paste from Slack') }}" />
            @error('slack.client_secret')<p class="mt-1 text-xs text-brand-rust">{{ $message }}</p>@enderror
            <div>
                <label class="block text-xs font-medium text-brand-moss" for="slack-redirect">{{ __('Redirect URI') }}</label>
                <input id="slack-redirect" type="url" wire:model="slack.redirect" autocomplete="off"
                    class="dply-input mt-1 block w-full font-mono text-sm" />
                <p class="mt-1 text-xs text-brand-moss">{{ __('Paste this exact URL into the Slack app. Slack rejects .test hosts — use a tunnel locally.') }}</p>
                <p class="mt-1 break-all font-mono text-xs text-brand-ink">{{ $slackCallback }}</p>
            </div>
        </div>

        <div class="mt-4 flex flex-wrap gap-2">
            <button type="button" wire:click="saveSlack" class="rounded-lg bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream hover:bg-brand-forest">
                {{ __('Save Slack') }}
            </button>
            <button type="button" wire:click="testSlack" class="rounded-lg border border-brand-ink/15 bg-white px-4 py-2 text-sm font-semibold text-brand-ink hover:bg-brand-sand/40">
                {{ __('Test') }}
            </button>
        </div>
        @if ($this->status['slack']['last_error'])
            <p class="mt-3 text-xs text-brand-rust">{{ $this->status['slack']['last_error'] }}</p>
        @endif
        </div>
    </section>

    {{-- Discord --}}
    <section class="border-b border-brand-ink/10 last:border-0">
        <x-workspace-panel-head
            dense
            icon="heroicon-o-hashtag"
            :title="__('Discord')"
        >
            <x-slot:actions>
                <span @class([
                    'inline-flex items-center rounded-full px-2 py-0.5 text-2xs font-semibold uppercase tracking-wide',
                    'bg-emerald-100 text-emerald-800' => $this->status['discord']['ready'],
                    'bg-brand-ink/[0.06] text-brand-moss' => ! $this->status['discord']['ready'],
                ])>{{ $this->status['discord']['ready'] ? __('Live') : __('Not configured') }}</span>
            </x-slot:actions>
        </x-workspace-panel-head>

        <div class="px-3 py-3 sm:px-4">
        <p class="text-xs text-brand-moss">{{ __('Create an application at discord.com/developers. Add the redirect under OAuth2, then reset the bot token. All three values are required — Discord does not return a bot token from the OAuth exchange.') }}</p>

        <div class="mt-3 space-y-3">
            <div>
                <label class="block text-xs font-medium text-brand-moss" for="discord-client-id">{{ __('Client ID') }}</label>
                <input id="discord-client-id" type="text" wire:model="discord.client_id" autocomplete="off"
                    class="dply-input mt-1 block w-full font-mono text-sm" />
                @error('discord.client_id')<p class="mt-1 text-xs text-brand-rust">{{ $message }}</p>@enderror
            </div>
            <x-password-field id="discord-client-secret" label="{{ __('Client secret') }}" wire:model="discord.client_secret" mono
                placeholder="{{ $this->status['discord']['hints']['client_secret'] !== '' ? __('Saved :hint — leave blank to keep', ['hint' => $this->status['discord']['hints']['client_secret']]) : __('Paste from Discord') }}" />
            @error('discord.client_secret')<p class="mt-1 text-xs text-brand-rust">{{ $message }}</p>@enderror
            <x-password-field id="discord-bot-token" label="{{ __('Bot token') }}" wire:model="discord.bot_token" mono
                placeholder="{{ $this->status['discord']['hints']['bot_token'] !== '' ? __('Saved :hint — leave blank to keep', ['hint' => $this->status['discord']['hints']['bot_token']]) : __('Bot → Reset Token') }}" />
            @error('discord.bot_token')<p class="mt-1 text-xs text-brand-rust">{{ $message }}</p>@enderror
            <div>
                <label class="block text-xs font-medium text-brand-moss" for="discord-redirect">{{ __('Redirect URI') }}</label>
                <input id="discord-redirect" type="url" wire:model="discord.redirect" autocomplete="off"
                    class="dply-input mt-1 block w-full font-mono text-sm" />
                <p class="mt-1 text-xs text-brand-moss">{{ __('Paste this exact URL into Discord OAuth2 Redirects. Discord rejects .test hosts — use a tunnel locally.') }}</p>
                <p class="mt-1 break-all font-mono text-xs text-brand-ink">{{ $discordCallback }}</p>
            </div>
        </div>

        <div class="mt-4 flex flex-wrap gap-2">
            <button type="button" wire:click="saveDiscord" class="rounded-lg bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream hover:bg-brand-forest">
                {{ __('Save Discord') }}
            </button>
            <button type="button" wire:click="testDiscord" class="rounded-lg border border-brand-ink/15 bg-white px-4 py-2 text-sm font-semibold text-brand-ink hover:bg-brand-sand/40">
                {{ __('Test') }}
            </button>
        </div>
        @if ($this->status['discord']['last_error'])
            <p class="mt-3 text-xs text-brand-rust">{{ $this->status['discord']['last_error'] }}</p>
        @endif
        </div>
    </section>

    {{-- Telegram --}}
    <section class="border-b border-brand-ink/10 last:border-0">
        <x-workspace-panel-head
            dense
            icon="heroicon-o-paper-airplane"
            :title="__('Telegram')"
        >
            <x-slot:actions>
                <span @class([
                    'inline-flex items-center rounded-full px-2 py-0.5 text-2xs font-semibold uppercase tracking-wide',
                    'bg-emerald-100 text-emerald-800' => $this->status['telegram']['ready'],
                    'bg-brand-ink/[0.06] text-brand-moss' => ! $this->status['telegram']['ready'],
                ])>{{ $this->status['telegram']['ready'] ? __('Live') : __('Not configured') }}</span>
            </x-slot:actions>
        </x-workspace-panel-head>

        <div class="px-3 py-3 sm:px-4">
        <p class="text-xs text-brand-moss">{{ __('Create a bot with @BotFather. Connect Telegram stays silent until a public HTTPS webhook is registered — save, then Register webhook (or php artisan telegram:set-webhook).') }}</p>

        <div class="mt-3 space-y-3">
            <x-password-field id="telegram-bot-token" label="{{ __('Bot token') }}" wire:model="telegram.bot_token" mono
                placeholder="{{ $this->status['telegram']['hints']['bot_token'] !== '' ? __('Saved :hint — leave blank to keep', ['hint' => $this->status['telegram']['hints']['bot_token']]) : __('From @BotFather') }}" />
            @error('telegram.bot_token')<p class="mt-1 text-xs text-brand-rust">{{ $message }}</p>@enderror
            <x-password-field id="telegram-webhook-secret" label="{{ __('Webhook secret') }}" wire:model="telegram.webhook_secret" mono
                placeholder="{{ $this->status['telegram']['hints']['webhook_secret'] !== '' ? __('Saved :hint — leave blank to keep', ['hint' => $this->status['telegram']['hints']['webhook_secret']]) : __('Leave blank to generate') }}" />
            <div>
                <label class="block text-xs font-medium text-brand-moss" for="telegram-webhook-url">{{ __('Webhook URL') }}</label>
                <input id="telegram-webhook-url" type="url" wire:model="telegram.webhook_url" autocomplete="off"
                    class="dply-input mt-1 block w-full font-mono text-sm" />
                <p class="mt-1 text-xs text-brand-moss">{{ __('Must be public HTTPS. Telegram rejects .test hosts — use a tunnel locally.') }}</p>
                <p class="mt-1 break-all font-mono text-xs text-brand-ink">{{ $telegramWebhook }}</p>
            </div>
        </div>

        <div class="mt-4 flex flex-wrap gap-2">
            <button type="button" wire:click="saveTelegram" class="rounded-lg bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream hover:bg-brand-forest">
                {{ __('Save Telegram') }}
            </button>
            <button type="button" wire:click="testTelegram" class="rounded-lg border border-brand-ink/15 bg-white px-4 py-2 text-sm font-semibold text-brand-ink hover:bg-brand-sand/40">
                {{ __('Test bot') }}
            </button>
            <button type="button" wire:click="registerTelegramWebhook" class="rounded-lg border border-brand-forest bg-white px-4 py-2 text-sm font-semibold text-brand-forest hover:bg-brand-sage/10">
                {{ __('Register webhook') }}
            </button>
        </div>
        @if ($this->status['telegram']['last_error'])
            <p class="mt-3 text-xs text-brand-rust">{{ $this->status['telegram']['last_error'] }}</p>
        @endif
        </div>
    </section>
    </x-profile-shell>
</div>
