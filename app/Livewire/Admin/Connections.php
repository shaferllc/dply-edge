<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Livewire\Admin\Concerns\AuthorizesPlatformAdmin;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Models\PlatformConnection;
use App\Modules\Notifications\Services\PlatformNotificationApps;
use App\Modules\Notifications\Services\TelegramBotClient;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * @property-read array<string, mixed> $status
 */
#[Layout('layouts.admin')]
class Connections extends Component
{
    use AuthorizesPlatformAdmin;
    use DispatchesToastNotifications;

    /**
     * Client-controlled Livewire state — a tampered payload can drop keys, so
     * readers use `?? ''` and the shape marks every key optional.
     *
     * @var array{client_id?: string, client_secret?: string, redirect?: string}
     */
    public array $slack = [
        'client_id' => '',
        'client_secret' => '',
        'redirect' => '',
    ];

    /** @var array{client_id?: string, client_secret?: string, bot_token?: string, redirect?: string} */
    public array $discord = [
        'client_id' => '',
        'client_secret' => '',
        'bot_token' => '',
        'redirect' => '',
    ];

    /** @var array{bot_token: string, webhook_secret: string, webhook_url: string} */
    public array $telegram = [
        'bot_token' => '',
        'webhook_secret' => '',
        'webhook_url' => '',
    ];

    public function mount(): void
    {
        $this->mountAuthorizesPlatformAdmin();
        $this->hydrateForms();
    }

    public function saveSlack(): void
    {
        $this->authorizePlatformAdmin();
        $this->validate([
            'slack.client_id' => ['required', 'string', 'max:120'],
            'slack.client_secret' => ['nullable', 'string', 'max:200'],
            'slack.redirect' => ['nullable', 'string', 'max:255'],
        ]);

        if (! $this->hasSecret('slack.client_secret', PlatformNotificationApps::slack()['client_secret'])) {
            $this->addError('slack.client_secret', __('Enter the Slack client secret.'));

            return;
        }

        PlatformNotificationApps::save(PlatformConnection::PROVIDER_SLACK, $this->slack, ['client_secret']);
        $this->slack['client_secret'] = '';
        unset($this->status);
        $this->toastSuccess(__('Slack app saved. Add to Slack is live on notification channels.'));
    }

    public function testSlack(): void
    {
        $this->authorizePlatformAdmin();
        $effective = $this->effectiveSlack();
        if ($effective['client_id'] === '' || $effective['client_secret'] === '') {
            $this->toastError(__('Save a Slack client ID and secret first.'));

            return;
        }

        PlatformNotificationApps::markOk(PlatformConnection::PROVIDER_SLACK);
        unset($this->status);
        $this->toastSuccess(__('Slack app looks complete. Install it on a workspace from any notification-channels page to confirm the callback URL.'));
    }

    public function saveDiscord(): void
    {
        $this->authorizePlatformAdmin();
        $this->validate([
            'discord.client_id' => ['required', 'string', 'max:120'],
            'discord.client_secret' => ['nullable', 'string', 'max:200'],
            'discord.bot_token' => ['nullable', 'string', 'max:200'],
            'discord.redirect' => ['nullable', 'string', 'max:255'],
        ]);

        $current = PlatformNotificationApps::discord();
        if (! $this->hasSecret('discord.client_secret', $current['client_secret'])) {
            $this->addError('discord.client_secret', __('Enter the Discord client secret.'));

            return;
        }
        if (! $this->hasSecret('discord.bot_token', $current['bot_token'])) {
            $this->addError('discord.bot_token', __('Enter the Discord bot token.'));

            return;
        }

        PlatformNotificationApps::save(PlatformConnection::PROVIDER_DISCORD, $this->discord, ['client_secret', 'bot_token']);
        $this->discord['client_secret'] = '';
        $this->discord['bot_token'] = '';
        unset($this->status);
        $this->toastSuccess(__('Discord app saved. Add to Discord is live on notification channels.'));
    }

    public function testDiscord(): void
    {
        $this->authorizePlatformAdmin();
        $effective = $this->effectiveDiscord();
        if ($effective['client_id'] === '' || $effective['client_secret'] === '' || $effective['bot_token'] === '') {
            $this->toastError(__('Save a Discord client ID, secret, and bot token first.'));

            return;
        }

        PlatformNotificationApps::markOk(PlatformConnection::PROVIDER_DISCORD);
        unset($this->status);
        $this->toastSuccess(__('Discord app looks complete. Invite the bot from any notification-channels page to confirm the callback URL.'));
    }

    public function saveTelegram(): void
    {
        $this->authorizePlatformAdmin();
        $this->persistTelegram();
    }

    public function testTelegram(): void
    {
        $this->authorizePlatformAdmin();
        if (trim($this->telegram['bot_token']) !== '' || trim($this->telegram['webhook_secret']) !== '') {
            if (! $this->persistTelegram(quiet: true)) {
                return;
            }
        }
        if (! TelegramBotClient::botConfigured()) {
            $this->toastError(__('Save a Telegram bot token first.'));

            return;
        }

        $result = TelegramBotClient::make()->me();
        if (! $result['ok']) {
            PlatformNotificationApps::markError(
                PlatformConnection::PROVIDER_TELEGRAM,
                TelegramBotClient::describeError($result['error']),
            );
            unset($this->status);
            $this->toastError(TelegramBotClient::describeError($result['error']));

            return;
        }

        PlatformNotificationApps::markOk(PlatformConnection::PROVIDER_TELEGRAM);
        unset($this->status);
        $this->toastSuccess($result['username'] !== ''
            ? __('Telegram bot @ :username is reachable.', ['username' => $result['username']])
            : __('Telegram bot is reachable.'));
    }

    public function registerTelegramWebhook(): void
    {
        $this->authorizePlatformAdmin();
        if (! $this->persistTelegram(quiet: true)) {
            return;
        }

        $apps = PlatformNotificationApps::telegram();
        if ($apps['bot_token'] === '' || $apps['webhook_secret'] === '') {
            $this->toastError(__('Save a Telegram bot token and webhook secret first.'));

            return;
        }

        $url = PlatformNotificationApps::telegramWebhookUrl();
        if (! str_starts_with($url, 'https://')) {
            $this->toastError(__('Telegram requires a public HTTPS URL. Set a tunnel URL (Expose/ngrok) as the webhook URL.'));

            return;
        }

        $result = TelegramBotClient::make()->setWebhook($url, $apps['webhook_secret']);
        if (! $result['ok']) {
            PlatformNotificationApps::markError(
                PlatformConnection::PROVIDER_TELEGRAM,
                TelegramBotClient::describeError($result['error']),
            );
            unset($this->status);
            $this->toastError(TelegramBotClient::describeError($result['error']));

            return;
        }

        PlatformNotificationApps::markOk(PlatformConnection::PROVIDER_TELEGRAM);
        unset($this->status);
        $this->toastSuccess(__('Telegram webhook registered: :url', ['url' => $url]));
    }

    /**
     * @return array<string, array{ready: bool, hints: array<string, string>, last_ok_at: ?string, last_error: ?string}>
     */
    #[Computed]
    public function status(): array
    {
        $rows = PlatformConnection::query()
            ->whereIn('provider', PlatformConnection::PROVIDERS)
            ->get()
            ->keyBy('provider');

        $slack = PlatformNotificationApps::slack();
        $discord = PlatformNotificationApps::discord();
        $telegram = PlatformNotificationApps::telegram();

        return [
            'slack' => $this->providerStatus($rows->get(PlatformConnection::PROVIDER_SLACK), PlatformNotificationApps::slackReady(), [
                'client_secret' => PlatformNotificationApps::maskedSecret($slack['client_secret']),
            ]),
            'discord' => $this->providerStatus($rows->get(PlatformConnection::PROVIDER_DISCORD), PlatformNotificationApps::discordReady(), [
                'client_secret' => PlatformNotificationApps::maskedSecret($discord['client_secret']),
                'bot_token' => PlatformNotificationApps::maskedSecret($discord['bot_token']),
            ]),
            'telegram' => $this->providerStatus($rows->get(PlatformConnection::PROVIDER_TELEGRAM), PlatformNotificationApps::telegramReady(), [
                'bot_token' => PlatformNotificationApps::maskedSecret($telegram['bot_token']),
                'webhook_secret' => PlatformNotificationApps::maskedSecret($telegram['webhook_secret']),
            ]),
        ];
    }

    public function render(): View
    {
        $this->authorizePlatformAdmin();

        return view('livewire.admin.connections', [
            'slackCallback' => PlatformNotificationApps::slackRedirectUri(),
            'discordCallback' => PlatformNotificationApps::discordRedirectUri(),
            'telegramWebhook' => PlatformNotificationApps::telegramWebhookUrl(),
        ]);
    }

    private function hydrateForms(): void
    {
        $slack = PlatformNotificationApps::slack();
        $this->slack = [
            'client_id' => $slack['client_id'],
            'client_secret' => '',
            'redirect' => $slack['redirect'] !== '' ? $slack['redirect'] : PlatformNotificationApps::slackRedirectUri(),
        ];

        $discord = PlatformNotificationApps::discord();
        $this->discord = [
            'client_id' => $discord['client_id'],
            'client_secret' => '',
            'bot_token' => '',
            'redirect' => $discord['redirect'] !== '' ? $discord['redirect'] : PlatformNotificationApps::discordRedirectUri(),
        ];

        $telegram = PlatformNotificationApps::telegram();
        $this->telegram = [
            'bot_token' => '',
            'webhook_secret' => '',
            'webhook_url' => $telegram['webhook_url'] !== '' ? $telegram['webhook_url'] : PlatformNotificationApps::telegramWebhookUrl(),
        ];
    }

    private function persistTelegram(bool $quiet = false): bool
    {
        $this->validate([
            'telegram.bot_token' => ['nullable', 'string', 'max:200'],
            'telegram.webhook_secret' => ['nullable', 'string', 'max:200'],
            'telegram.webhook_url' => ['nullable', 'string', 'max:255'],
        ]);

        $current = PlatformNotificationApps::telegram();
        if (! $this->hasSecret('telegram.bot_token', $current['bot_token'])) {
            $this->addError('telegram.bot_token', __('Enter the Telegram bot token from @BotFather.'));

            return false;
        }

        $input = $this->telegram;
        $generatedSecret = false;
        if (trim($input['webhook_secret']) === '' && $current['webhook_secret'] === '') {
            $input['webhook_secret'] = Str::password(40);
            $generatedSecret = true;
        }

        PlatformNotificationApps::save(PlatformConnection::PROVIDER_TELEGRAM, $input, ['bot_token', 'webhook_secret']);
        $this->telegram['bot_token'] = '';
        $this->telegram['webhook_secret'] = '';
        unset($this->status);

        if (! $quiet) {
            $this->toastSuccess($generatedSecret
                ? __('Telegram bot saved. A webhook secret was generated — register the webhook next.')
                : __('Telegram bot saved. Connect Telegram is live on notification channels after the webhook is registered.'));
        }

        return true;
    }

    /**
     * @return array{client_id: string, client_secret: string, redirect: string}
     */
    private function effectiveSlack(): array
    {
        $base = PlatformNotificationApps::slack();
        foreach (['client_id', 'redirect'] as $key) {
            $value = trim($this->slack[$key] ?? '');
            if ($value !== '') {
                $base[$key] = $value;
            }
        }
        $secret = trim($this->slack['client_secret'] ?? '');
        if ($secret !== '') {
            $base['client_secret'] = $secret;
        }

        return $base;
    }

    /**
     * @return array{client_id: string, client_secret: string, redirect: string, bot_token: string}
     */
    private function effectiveDiscord(): array
    {
        $base = PlatformNotificationApps::discord();
        foreach (['client_id', 'redirect'] as $key) {
            $value = trim($this->discord[$key] ?? '');
            if ($value !== '') {
                $base[$key] = $value;
            }
        }
        foreach (['client_secret', 'bot_token'] as $key) {
            $value = trim($this->discord[$key] ?? '');
            if ($value !== '') {
                $base[$key] = $value;
            }
        }

        return $base;
    }

    private function hasSecret(string $field, string $existing): bool
    {
        $typed = data_get($this, $field);
        $typed = is_string($typed) ? trim($typed) : '';

        return $typed !== '' || $existing !== '';
    }

    /**
     * @param  array<string, string>  $hints
     * @return array{ready: bool, hints: array<string, string>, last_ok_at: ?string, last_error: ?string}
     */
    private function providerStatus(?PlatformConnection $row, bool $ready, array $hints): array
    {
        return [
            'ready' => $ready,
            'hints' => $hints,
            'last_ok_at' => $row?->last_ok_at?->toIso8601String(),
            'last_error' => $row?->last_error,
        ];
    }
}
