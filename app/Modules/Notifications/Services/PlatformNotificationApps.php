<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Services;

use App\Models\PlatformConnection;

/**
 * Resolves the deployment's Slack / Discord / Telegram *apps* (the credentials
 * that make org "Add to Slack" buttons live). Stored {@see PlatformConnection}
 * values overlay `config/services.*` so .env keeps working and the admin
 * Connections page can fill gaps without writing the env file.
 */
final class PlatformNotificationApps
{
    /**
     * Container key for the per-request row memo. Scoped, so Octane requests
     * and queue jobs each start fresh; writes below forget it.
     */
    private const ROWS = 'dply.platform-notification-apps.rows';

    /**
     * @return array{client_id: string, client_secret: string, redirect: string}
     */
    public static function slack(): array
    {
        return self::overlay(PlatformConnection::PROVIDER_SLACK, [
            'client_id' => self::stringConfig('services.slack.client_id'),
            'client_secret' => self::stringConfig('services.slack.client_secret'),
            'redirect' => self::stringConfig('services.slack.redirect'),
        ]);
    }

    /**
     * @return array{client_id: string, client_secret: string, redirect: string, bot_token: string}
     */
    public static function discord(): array
    {
        return self::overlay(PlatformConnection::PROVIDER_DISCORD, [
            'client_id' => self::stringConfig('services.discord.client_id'),
            'client_secret' => self::stringConfig('services.discord.client_secret'),
            'redirect' => self::stringConfig('services.discord.redirect'),
            'bot_token' => self::stringConfig('services.discord.bot_token'),
        ]);
    }

    /**
     * @return array{bot_token: string, webhook_secret: string, webhook_url: string}
     */
    public static function telegram(): array
    {
        return self::overlay(PlatformConnection::PROVIDER_TELEGRAM, [
            'bot_token' => self::stringConfig('services.telegram.bot_token'),
            'webhook_secret' => self::stringConfig('services.telegram.webhook_secret'),
            'webhook_url' => self::stringConfig('services.telegram.webhook_url'),
        ]);
    }

    public static function slackReady(): bool
    {
        $c = self::slack();

        return $c['client_id'] !== '' && $c['client_secret'] !== '';
    }

    public static function discordReady(): bool
    {
        $c = self::discord();

        return $c['client_id'] !== '' && $c['client_secret'] !== '' && $c['bot_token'] !== '';
    }

    public static function telegramReady(): bool
    {
        return self::telegram()['bot_token'] !== '';
    }

    public static function slackRedirectUri(): string
    {
        $configured = self::slack()['redirect'];

        return $configured !== ''
            ? $configured
            : route('notifications.oauth.slack.callback', [], true);
    }

    public static function discordRedirectUri(): string
    {
        $configured = self::discord()['redirect'];

        return $configured !== ''
            ? $configured
            : route('notifications.oauth.discord.callback', [], true);
    }

    public static function telegramWebhookUrl(): string
    {
        $configured = self::telegram()['webhook_url'];

        return $configured !== ''
            ? rtrim($configured, '/')
            : rtrim((string) config('app.url'), '/').'/hooks/telegram';
    }

    public static function maskedSecret(string $value): string
    {
        if ($value === '') {
            return '';
        }

        $len = mb_strlen($value);

        return $len <= 4 ? str_repeat('•', $len) : '••••'.mb_substr($value, -4);
    }

    /**
     * Persist overlay fields. Blank secret inputs keep the currently stored value.
     *
     * @param  array<string, string>  $input
     * @param  list<string>  $secretKeys
     */
    public static function save(string $provider, array $input, array $secretKeys): PlatformConnection
    {
        if (! in_array($provider, PlatformConnection::PROVIDERS, true)) {
            throw new \InvalidArgumentException('Unknown platform notification app.');
        }

        $row = PlatformConnection::query()->firstOrNew(['provider' => $provider]);
        $current = is_array($row->config) ? $row->config : [];

        $config = [];
        foreach ($input as $key => $value) {
            $value = trim($value);
            if (in_array($key, $secretKeys, true) && $value === '') {
                $config[$key] = (string) ($current[$key] ?? '');

                continue;
            }
            $config[$key] = $value;
        }

        $row->forceFill([
            'provider' => $provider,
            'config' => $config,
        ])->save();
        self::forgetRows();

        return $row;
    }

    public static function markOk(string $provider): void
    {
        PlatformConnection::query()->where('provider', $provider)->update([
            'last_ok_at' => now(),
            'last_error' => null,
        ]);
        self::forgetRows();
    }

    public static function markError(string $provider, string $error): void
    {
        PlatformConnection::query()->where('provider', $provider)->update([
            'last_error' => mb_substr($error, 0, 240),
        ]);
        self::forgetRows();
    }

    /**
     * @param  array<string, string>  $defaults
     * @return array<string, string>
     */
    private static function overlay(string $provider, array $defaults): array
    {
        $row = self::row($provider);
        $stored = is_array($row?->config) ? $row->config : [];

        foreach ($defaults as $key => $fallback) {
            $value = $stored[$key] ?? null;
            if (is_string($value) && $value !== '') {
                $defaults[$key] = $value;
            }
        }

        return $defaults;
    }

    /**
     * Every *Ready() / redirect / credential read lands here, and a channels
     * page asks several times per render — one query for all providers, once
     * per request, instead of one per call.
     */
    private static function row(string $provider): ?PlatformConnection
    {
        if (! app()->bound(self::ROWS)) {
            app()->scoped(self::ROWS, fn () => PlatformConnection::query()
                ->whereIn('provider', PlatformConnection::PROVIDERS)
                ->get()
                ->keyBy('provider'));
        }

        return app(self::ROWS)->get($provider);
    }

    private static function forgetRows(): void
    {
        app()->forgetInstance(self::ROWS);
    }

    private static function stringConfig(string $key): string
    {
        $value = config($key);

        return is_string($value) ? $value : '';
    }
}
