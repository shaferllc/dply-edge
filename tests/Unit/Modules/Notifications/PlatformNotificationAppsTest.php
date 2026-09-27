<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Notifications\PlatformNotificationAppsTest;

use App\Http\Controllers\Notifications\DiscordOAuthController;
use App\Http\Controllers\Notifications\SlackOAuthController;
use App\Models\PlatformConnection;
use App\Modules\Notifications\Services\PlatformNotificationApps;
use App\Modules\Notifications\Services\TelegramBotClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('services.slack.client_id', null);
    config()->set('services.slack.client_secret', null);
    config()->set('services.slack.redirect', null);
    config()->set('services.discord.client_id', null);
    config()->set('services.discord.client_secret', null);
    config()->set('services.discord.bot_token', null);
    config()->set('services.telegram.bot_token', null);
    config()->set('services.telegram.webhook_secret', null);
});

test('env values remain the fallback when nothing is stored', function () {
    config()->set('services.slack.client_id', 'env-id');
    config()->set('services.slack.client_secret', 'env-secret');

    expect(PlatformNotificationApps::slackReady())->toBeTrue()
        ->and(SlackOAuthController::configured())->toBeTrue()
        ->and(PlatformNotificationApps::slack()['client_id'])->toBe('env-id');
});

test('stored slack credentials overlay env and make add-to-slack live', function () {
    expect(SlackOAuthController::configured())->toBeFalse();

    PlatformNotificationApps::save(PlatformConnection::PROVIDER_SLACK, [
        'client_id' => 'ui-id',
        'client_secret' => 'ui-secret',
        'redirect' => 'https://example.test/hooks/slack',
    ], ['client_secret']);

    expect(SlackOAuthController::configured())->toBeTrue()
        ->and(PlatformNotificationApps::slack()['client_id'])->toBe('ui-id')
        ->and(PlatformNotificationApps::slack()['client_secret'])->toBe('ui-secret');
});

test('blank secret on save keeps the previously stored value', function () {
    PlatformNotificationApps::save(PlatformConnection::PROVIDER_SLACK, [
        'client_id' => 'ui-id',
        'client_secret' => 'keep-me',
        'redirect' => '',
    ], ['client_secret']);

    PlatformNotificationApps::save(PlatformConnection::PROVIDER_SLACK, [
        'client_id' => 'ui-id-2',
        'client_secret' => '',
        'redirect' => '',
    ], ['client_secret']);

    expect(PlatformNotificationApps::slack()['client_id'])->toBe('ui-id-2')
        ->and(PlatformNotificationApps::slack()['client_secret'])->toBe('keep-me');
});

test('stored discord credentials make add-to-discord live', function () {
    expect(DiscordOAuthController::configured())->toBeFalse();

    PlatformNotificationApps::save(PlatformConnection::PROVIDER_DISCORD, [
        'client_id' => 'd-id',
        'client_secret' => 'd-secret',
        'bot_token' => 'd-bot',
        'redirect' => '',
    ], ['client_secret', 'bot_token']);

    expect(DiscordOAuthController::configured())->toBeTrue()
        ->and(PlatformNotificationApps::discord()['bot_token'])->toBe('d-bot');
});

test('stored telegram token makes the bot configured', function () {
    expect(TelegramBotClient::botConfigured())->toBeFalse();

    PlatformNotificationApps::save(PlatformConnection::PROVIDER_TELEGRAM, [
        'bot_token' => '123:abc',
        'webhook_secret' => 'hook-secret',
        'webhook_url' => '',
    ], ['bot_token', 'webhook_secret']);

    expect(TelegramBotClient::botConfigured())->toBeTrue()
        ->and(PlatformNotificationApps::telegram()['webhook_secret'])->toBe('hook-secret');
});

test('masked secret shows only the last four characters', function () {
    expect(PlatformNotificationApps::maskedSecret('xoxb-abcdefgh'))->toBe('••••efgh')
        ->and(PlatformNotificationApps::maskedSecret(''))->toBe('');
});

test('credential reads share one query per request and writes refresh it', function () {
    DB::enableQueryLog();

    PlatformNotificationApps::slackReady();
    PlatformNotificationApps::discordReady();
    PlatformNotificationApps::telegramReady();
    PlatformNotificationApps::slackRedirectUri();

    $reads = collect(DB::getQueryLog())
        ->filter(fn ($q) => str_contains($q['query'], 'platform_connections'));
    expect($reads)->toHaveCount(1);

    PlatformNotificationApps::save(PlatformConnection::PROVIDER_SLACK, [
        'client_id' => 'fresh-id',
        'client_secret' => 'fresh-secret',
        'redirect' => '',
    ], ['client_secret']);

    expect(PlatformNotificationApps::slack()['client_id'])->toBe('fresh-id');
});
