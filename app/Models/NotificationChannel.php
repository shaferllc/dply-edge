<?php

namespace App\Models;

use App\Jobs\DeliverNotificationWebhookJob;
use App\Jobs\SendNotificationChannelTestEmailJob;
use App\Mail\NotificationChannelMail;
use App\Modules\Notifications\Channels\Intercom\IntercomMessage;
use App\Modules\Notifications\Channels\MicrosoftTeams\MicrosoftTeamsMessage;
use App\Modules\Notifications\Channels\PagerDuty\PagerDutyMessage;
use App\Modules\Notifications\Services\DiscordGuildClient;
use App\Modules\Notifications\Services\IntercomClient;
use App\Modules\Notifications\Services\MicrosoftTeamsClient;
use App\Modules\Notifications\Services\PagerDutyClient;
use App\Modules\Notifications\Services\SlackWorkspaceClient;
use App\Modules\Notifications\Services\TelegramBotClient;
use App\Services\Webhooks\OutboundWebhookSignature;
use App\Support\Http\PublicOutboundUrl;
use App\Support\Http\UnsafeOutboundUrlException;
use Database\Factories\NotificationChannelFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * @property string $id
 * @property string $owner_type
 * @property string $owner_id
 * @property string $type
 * @property string $label
 * @property array<string, mixed> $config
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 * @property-read Model $owner
 * @property-read Collection<int, NotificationSubscription> $subscriptions
 */
class NotificationChannel extends Model
{
    /** @use HasFactory<NotificationChannelFactory> */
    use HasFactory, HasUlids;

    public const TYPE_SLACK = 'slack';

    /**
     * Slack channels come in two shapes. `oauth` rides a {@see SlackInstallation}
     * bot token and posts by channel id; anything else is the original pasted
     * incoming-webhook URL. Stored in `config['auth']` — absent means webhook, so
     * rows written before "Add to Slack" existed need no backfill.
     */
    public const SLACK_AUTH_OAUTH = 'oauth';

    public const TYPE_DISCORD = 'discord';

    /**
     * Discord's twin of {@see SLACK_AUTH_OAUTH}: `oauth` posts through the dply
     * bot in a connected guild, anything else is a pasted webhook URL. Absent
     * means webhook, so pre-existing rows need no backfill.
     */
    public const DISCORD_AUTH_OAUTH = 'oauth';

    public const TYPE_EMAIL = 'email';

    public const TYPE_TELEGRAM = 'telegram';

    /**
     * Telegram's twin of {@see SLACK_AUTH_OAUTH}: `connected` posts through the
     * deployment bot into a chat discovered via the /start claim check; anything
     * else is a hand-pasted bot token + chat ID. Absent means manual.
     */
    public const TELEGRAM_AUTH_CONNECTED = 'connected';

    public const TYPE_PUSHOVER = 'pushover';

    public const TYPE_MICROSOFT_TEAMS = 'microsoft_teams';

    public const TYPE_ROCKETCHAT = 'rocketchat';

    public const TYPE_GOOGLE_CHAT = 'google_chat';

    public const TYPE_MOBILE_APP = 'mobile_app';

    public const TYPE_INTERCOM = 'intercom';

    /**
     * Intercom recipient shapes, stored in `config['recipient_type']`. These map
     * onto the `to` object of Intercom's POST /messages: a user by e-mail or id,
     * a contact (lead) by id, or a bare address that Intercom resolves itself.
     */
    public const INTERCOM_TO_USER_EMAIL = 'user_email';

    public const INTERCOM_TO_USER_ID = 'user_id';

    public const INTERCOM_TO_CONTACT_ID = 'contact_id';

    public const INTERCOM_TO_EMAIL = 'email';

    public const TYPE_PAGERDUTY = 'pagerduty';

    public const TYPE_WEBHOOK = 'webhook';

    /** @return list<string> */
    public static function types(): array
    {
        return [
            self::TYPE_SLACK,
            self::TYPE_DISCORD,
            self::TYPE_EMAIL,
            self::TYPE_TELEGRAM,
            self::TYPE_PUSHOVER,
            self::TYPE_MICROSOFT_TEAMS,
            self::TYPE_ROCKETCHAT,
            self::TYPE_GOOGLE_CHAT,
            self::TYPE_MOBILE_APP,
            self::TYPE_INTERCOM,
            self::TYPE_PAGERDUTY,
            self::TYPE_WEBHOOK,
        ];
    }

    /**
     * Recipient shapes offered for {@see TYPE_INTERCOM}, in UI order.
     *
     * @return list<string>
     */
    public static function intercomRecipientTypes(): array
    {
        return [
            self::INTERCOM_TO_USER_EMAIL,
            self::INTERCOM_TO_USER_ID,
            self::INTERCOM_TO_CONTACT_ID,
            self::INTERCOM_TO_EMAIL,
        ];
    }

    public static function labelForIntercomRecipientType(string $recipientType): string
    {
        return match ($recipientType) {
            self::INTERCOM_TO_USER_ID => __('User ID'),
            self::INTERCOM_TO_CONTACT_ID => __('Contact ID'),
            self::INTERCOM_TO_EMAIL => __('E-mail address (lead or contact)'),
            default => __('User e-mail address'),
        };
    }

    /**
     * Types shown in UI dropdowns (subset of {@see types()} controlled by config).
     * Pass $preserveType so an existing channel keeps a type visible even if newly disabled.
     *
     * @return list<string>
     */
    public static function typesForUi(?string $preserveType = null): array
    {
        $configured = config('notification_channels.enabled_types', []);
        if (! is_array($configured)) {
            $configured = [];
        }

        $allowed = array_values(array_intersect(self::types(), $configured));

        if ($preserveType !== null
            && $preserveType !== ''
            && in_array($preserveType, self::types(), true)
            && ! in_array($preserveType, $allowed, true)) {
            $allowed[] = $preserveType;
        }

        usort($allowed, function (string $a, string $b): int {
            $order = array_flip(self::types());

            return ($order[$a] ?? 0) <=> ($order[$b] ?? 0);
        });

        return $allowed;
    }

    public static function labelForType(string $type): string
    {
        return match ($type) {
            self::TYPE_SLACK => 'Slack',
            self::TYPE_DISCORD => 'Discord',
            self::TYPE_EMAIL => __('E-mail address'),
            self::TYPE_TELEGRAM => 'Telegram',
            self::TYPE_PUSHOVER => 'Pushover',
            self::TYPE_MICROSOFT_TEAMS => __('Microsoft Teams'),
            self::TYPE_ROCKETCHAT => 'Rocket.Chat',
            self::TYPE_GOOGLE_CHAT => 'Google Chat',
            self::TYPE_MOBILE_APP => __('Mobile app'),
            self::TYPE_INTERCOM => 'Intercom',
            self::TYPE_PAGERDUTY => 'PagerDuty',
            self::TYPE_WEBHOOK => __('HTTP webhook'),
            default => $type,
        };
    }

    /**
     * Heroicon name per type, for the channel list.
     *
     * With ten types the text-only badge stopped being scannable — an operator
     * looking for "the PagerDuty one" was reading every row. Deliberately drawn
     * from the generic heroicon set rather than brand marks: we do not ship
     * vendor logos, and a shape that says "this is a pager" carries the meaning.
     */
    public static function iconForType(string $type): string
    {
        return match ($type) {
            self::TYPE_SLACK, self::TYPE_ROCKETCHAT => 'heroicon-o-chat-bubble-left-right',
            self::TYPE_DISCORD => 'heroicon-o-chat-bubble-oval-left-ellipsis',
            self::TYPE_EMAIL => 'heroicon-o-envelope',
            self::TYPE_TELEGRAM => 'heroicon-o-paper-airplane',
            self::TYPE_PUSHOVER, self::TYPE_MOBILE_APP => 'heroicon-o-device-phone-mobile',
            self::TYPE_MICROSOFT_TEAMS, self::TYPE_GOOGLE_CHAT => 'heroicon-o-chat-bubble-left-ellipsis',
            self::TYPE_INTERCOM => 'heroicon-o-lifebuoy',
            self::TYPE_PAGERDUTY => 'heroicon-o-bell-alert',
            default => 'heroicon-o-globe-alt',
        };
    }

    /**
     * Does this type page a human rather than post a message?
     *
     * Drives the "wakes on-call" marker in the UI. Two Slack channels differing
     * only in label are a nuisance; a PagerDuty channel someone mistook for a
     * chat channel is a 3am phone call.
     */
    public function isPaging(): bool
    {
        return $this->type === self::TYPE_PAGERDUTY;
    }

    /**
     * One-line, non-secret summary of where this channel actually delivers.
     *
     * The list previously showed label + type only, so two Slack channels were
     * indistinguishable without opening the edit form. Everything here is either
     * already-public (a chat name, the destination address) or reduced to a host
     * or a last-four — no token, key, or full webhook URL reaches the page.
     */
    public function describeDestination(): string
    {
        $cfg = $this->config;

        return match ($this->type) {
            self::TYPE_SLACK => $this->usesSlackOauth()
                ? (string) ($this->slackInstallation()->team_name ?? __('Connected workspace'))
                : self::describeWebhookHost($cfg['webhook_url'] ?? null),
            self::TYPE_DISCORD => $this->usesDiscordOauth()
                ? (string) ($this->discordInstallation()->guild_name ?? __('Connected server'))
                : self::describeWebhookHost($cfg['webhook_url'] ?? null),
            self::TYPE_EMAIL => (string) ($cfg['email'] ?? ''),
            self::TYPE_TELEGRAM => $this->usesTelegramConnected()
                ? (string) ($this->telegramInstallation()->chat_title ?? __('Connected chat'))
                : __('Chat :id', ['id' => (string) ($cfg['chat_id'] ?? '—')]),
            self::TYPE_PUSHOVER => __('User key …:tail', ['tail' => self::lastFour($cfg['user_key'] ?? null)]),
            self::TYPE_MICROSOFT_TEAMS, self::TYPE_ROCKETCHAT, self::TYPE_GOOGLE_CHAT => self::describeWebhookHost($cfg['webhook_url'] ?? null),
            self::TYPE_MOBILE_APP => __('Device (:platform)', ['platform' => (string) ($cfg['platform'] ?? '—')]),
            self::TYPE_INTERCOM => trim(((string) ($cfg['recipient'] ?? '')).' · '.mb_strtoupper((string) ($cfg['region'] ?? 'us')), ' ·'),
            self::TYPE_PAGERDUTY => __('Service key …:tail · :region', [
                'tail' => self::lastFour($cfg['routing_key'] ?? null),
                'region' => mb_strtoupper((string) ($cfg['region'] ?? 'us')),
            ]),
            self::TYPE_WEBHOOK => self::describeWebhookHost($cfg['url'] ?? null),
            default => '',
        };
    }

    /**
     * Host only. A Slack/Discord incoming-webhook URL is a bearer credential —
     * showing it in full would put it in screenshots and shoulder-surfing range.
     */
    private static function describeWebhookHost(mixed $url): string
    {
        if (! is_string($url) || $url === '') {
            return __('Not set');
        }

        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : __('Custom endpoint');
    }

    private static function lastFour(mixed $value): string
    {
        return is_string($value) && $value !== '' ? mb_substr($value, -4) : '????';
    }

    protected $fillable = [
        'owner_type',
        'owner_id',
        'type',
        'label',
        'config',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'config' => 'encrypted:array',
        ];
    }

    /** @return MorphTo<Model, $this> */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return HasMany<NotificationSubscription, $this> */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(NotificationSubscription::class);
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public function sendTest(?User $actor = null): array
    {
        $actorLabel = $actor !== null ? $actor->name : config('app.name');

        return match ($this->type) {
            self::TYPE_SLACK => $this->sendSlackTest($actorLabel),
            self::TYPE_DISCORD => $this->sendDiscordTest($actorLabel),
            self::TYPE_EMAIL => $this->sendEmailTest($actorLabel),
            self::TYPE_TELEGRAM => $this->sendTelegramTest($actorLabel),
            self::TYPE_PUSHOVER => $this->sendPushoverTest($actorLabel),
            self::TYPE_MICROSOFT_TEAMS => $this->sendMicrosoftTeamsTest($actorLabel),
            self::TYPE_ROCKETCHAT => $this->sendRocketchatTest($actorLabel),
            self::TYPE_GOOGLE_CHAT => $this->sendGoogleChatTest($actorLabel),
            self::TYPE_MOBILE_APP => $this->sendMobileAppTest($actorLabel),
            self::TYPE_INTERCOM => $this->sendIntercomTest($actorLabel),
            self::TYPE_PAGERDUTY => $this->sendPagerDutyTest($actorLabel),
            self::TYPE_WEBHOOK => $this->sendWebhookTest($actorLabel),
            default => ['ok' => false, 'message' => __('Unknown channel type.')],
        };
    }

    /**
     * @return array{ok: bool, message: string}
     */
    protected function sendSlackTest(string $actorLabel): array
    {
        $text = __(':app test notification (:label) from :actor', [
            'app' => config('app.name'),
            'label' => $this->label,
            'actor' => $actorLabel,
        ]);

        if ($this->usesSlackOauth()) {
            return $this->postSlackViaBotToken($text);
        }

        $url = $this->config['webhook_url'] ?? null;
        if (! is_string($url) || $url === '') {
            return ['ok' => false, 'message' => __('Slack webhook URL is missing.')];
        }

        $payload = ['text' => $text];

        $channel = $this->config['channel'] ?? null;
        if (is_string($channel) && $channel !== '') {
            $payload['channel'] = $channel;
        }

        try {
            $response = self::postToWebhookUrl($url, $payload);
        } catch (\Throwable $e) {
            return self::webhookFailure($e);
        }

        if (! $response->successful()) {
            return ['ok' => false, 'message' => __('Slack returned :status.', ['status' => $response->status()])];
        }

        return ['ok' => true, 'message' => __('Test message sent.')];
    }

    /** Whether this Slack channel posts through a connected workspace's bot token. */
    public function usesSlackOauth(): bool
    {
        return $this->type === self::TYPE_SLACK
            && ($this->config['auth'] ?? null) === self::SLACK_AUTH_OAUTH;
    }

    /** The connected workspace behind an OAuth Slack channel, if it still exists. */
    public function slackInstallation(): ?SlackInstallation
    {
        $id = $this->config['installation_id'] ?? null;
        if (! is_string($id) || $id === '') {
            return null;
        }

        return SlackInstallation::query()->find($id);
    }

    /**
     * Post through the workspace bot token.
     *
     * The disconnected-workspace case gets its own message on purpose: it is the
     * one failure an operator can fix themselves, and "Slack returned an error"
     * would send them hunting through Slack's admin UI instead of clicking
     * reconnect.
     *
     * @return array{ok: bool, message: string}
     */
    protected function postSlackViaBotToken(string $text): array
    {
        $installation = $this->slackInstallation();
        if (! $installation instanceof SlackInstallation) {
            return ['ok' => false, 'message' => __('The Slack workspace for this channel was disconnected. Reconnect it in Notifications settings.')];
        }

        $channelId = $this->config['channel_id'] ?? null;
        if (! is_string($channelId) || $channelId === '') {
            return ['ok' => false, 'message' => __('No Slack channel is selected.')];
        }

        $result = SlackWorkspaceClient::for($installation)->postMessage($channelId, $text);

        return $result['ok']
            ? ['ok' => true, 'message' => __('Test message sent.')]
            : ['ok' => false, 'message' => SlackWorkspaceClient::describeError($result['error'])];
    }

    /**
     * @return array{ok: bool, message: string}
     */
    protected function sendDiscordTest(string $actorLabel): array
    {
        $text = __('[:app] Test notification (:label) from :actor', [
            'app' => config('app.name'),
            'label' => $this->label,
            'actor' => $actorLabel,
        ]);

        if ($this->usesDiscordOauth()) {
            return $this->postDiscordViaBot($text);
        }

        $url = $this->config['webhook_url'] ?? null;
        if (! is_string($url) || $url === '') {
            return ['ok' => false, 'message' => __('Discord webhook URL is missing.')];
        }

        $body = ['content' => $text];

        try {
            $response = self::postToWebhookUrl($url, $body);
        } catch (\Throwable $e) {
            return self::webhookFailure($e);
        }

        if (! $response->successful()) {
            return ['ok' => false, 'message' => __('Discord returned :status.', ['status' => $response->status()])];
        }

        return ['ok' => true, 'message' => __('Test message sent.')];
    }

    /** Whether this Discord channel posts through the dply bot in a connected guild. */
    public function usesDiscordOauth(): bool
    {
        return $this->type === self::TYPE_DISCORD
            && ($this->config['auth'] ?? null) === self::DISCORD_AUTH_OAUTH;
    }

    /** The connected guild behind an OAuth Discord channel, if it still exists. */
    public function discordInstallation(): ?DiscordInstallation
    {
        $id = $this->config['installation_id'] ?? null;
        if (! is_string($id) || $id === '') {
            return null;
        }

        return DiscordInstallation::query()->find($id);
    }

    /**
     * @return array{ok: bool, message: string}
     */
    protected function postDiscordViaBot(string $text): array
    {
        if (! $this->discordInstallation() instanceof DiscordInstallation) {
            return ['ok' => false, 'message' => __('The Discord server for this channel was disconnected. Reconnect it in Notifications settings.')];
        }

        $channelId = $this->config['channel_id'] ?? null;
        if (! is_string($channelId) || $channelId === '') {
            return ['ok' => false, 'message' => __('No Discord channel is selected.')];
        }

        $result = DiscordGuildClient::make()->postMessage($channelId, $text);

        return $result['ok']
            ? ['ok' => true, 'message' => __('Test message sent.')]
            : ['ok' => false, 'message' => DiscordGuildClient::describeError($result['error'])];
    }

    /**
     * @return array{ok: bool, message: string}
     */
    protected function sendEmailTest(string $actorLabel): array
    {
        $to = $this->config['email'] ?? null;
        if (! is_string($to) || $to === '' || ! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'message' => __('Valid email address is required.')];
        }

        // Dispatch rather than Mail::to(...)->queue(): the facade resolves the
        // default mailer eagerly (building its transport) even when only queueing,
        // so a misconfigured mailer would crash this web request. The job defers
        // all mailer resolution to the worker. See SendNotificationChannelTestEmailJob.
        try {
            SendNotificationChannelTestEmailJob::dispatch($to, (string) $this->label, $actorLabel);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        return ['ok' => true, 'message' => __('Test email queued — it will arrive shortly.')];
    }

    /**
     * @return array{ok: bool, message: string}
     */
    protected function sendTelegramTest(string $actorLabel): array
    {
        $text = __('[:app] Test notification (:label) from :actor', [
            'app' => config('app.name'),
            'label' => $this->label,
            'actor' => $actorLabel,
        ]);

        if ($this->usesTelegramConnected()) {
            return $this->postTelegramViaBot($text);
        }

        $token = $this->config['bot_token'] ?? null;
        $chatId = $this->config['chat_id'] ?? null;
        if (! is_string($token) || $token === '' || ! is_string($chatId) || $chatId === '') {
            return ['ok' => false, 'message' => __('Telegram bot token and chat ID are required.')];
        }

        $url = 'https://api.telegram.org/bot'.rawurlencode($token).'/sendMessage';

        try {
            $response = Http::timeout(10)->asForm()->post($url, [
                'chat_id' => $chatId,
                'text' => $text,
            ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        if (! $response->successful()) {
            return ['ok' => false, 'message' => __('Telegram API returned :status.', ['status' => $response->status()])];
        }

        $json = $response->json();
        if (! is_array($json) || empty($json['ok'])) {
            return ['ok' => false, 'message' => __('Telegram rejected the request.')];
        }

        return ['ok' => true, 'message' => __('Test message sent.')];
    }

    /** Whether this Telegram channel posts through the deployment bot. */
    public function usesTelegramConnected(): bool
    {
        return $this->type === self::TYPE_TELEGRAM
            && ($this->config['auth'] ?? null) === self::TELEGRAM_AUTH_CONNECTED;
    }

    /** The connected chat behind a bot-backed Telegram channel, if it still exists. */
    public function telegramInstallation(): ?TelegramInstallation
    {
        $id = $this->config['installation_id'] ?? null;
        if (! is_string($id) || $id === '') {
            return null;
        }

        return TelegramInstallation::query()->find($id);
    }

    /**
     * @return array{ok: bool, message: string}
     */
    protected function postTelegramViaBot(string $text): array
    {
        $installation = $this->telegramInstallation();
        if (! $installation instanceof TelegramInstallation) {
            return ['ok' => false, 'message' => __('The Telegram chat for this channel was disconnected. Reconnect it in Notifications settings.')];
        }

        $result = TelegramBotClient::make()->sendMessage($installation->chat_id, $text);

        return $result['ok']
            ? ['ok' => true, 'message' => __('Test message sent.')]
            : ['ok' => false, 'message' => TelegramBotClient::describeError($result['error'])];
    }

    /**
     * @return array{ok: bool, message: string}
     */
    protected function sendPushoverTest(string $actorLabel): array
    {
        $appToken = $this->config['app_token'] ?? null;
        $userKey = $this->config['user_key'] ?? null;
        if (! is_string($appToken) || $appToken === '' || ! is_string($userKey) || $userKey === '') {
            return ['ok' => false, 'message' => __('Pushover application token and user key are required.')];
        }

        $message = __('[:app] Test notification (:label) from :actor', [
            'app' => config('app.name'),
            'label' => $this->label,
            'actor' => $actorLabel,
        ]);

        try {
            $response = Http::timeout(10)->asForm()->post('https://api.pushover.net/1/messages.json', [
                'token' => $appToken,
                'user' => $userKey,
                'message' => $message,
            ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        if (! $response->successful()) {
            return ['ok' => false, 'message' => __('Pushover returned :status.', ['status' => $response->status()])];
        }

        $json = $response->json();
        if (! is_array($json) || empty($json['status'])) {
            return ['ok' => false, 'message' => __('Pushover rejected the request.')];
        }

        return ['ok' => true, 'message' => __('Test message sent.')];
    }

    /**
     * @return array{ok: bool, message: string}
     */
    /**
     * Build the Teams Adaptive Card for this channel.
     *
     * Shared by the test button and real delivery, same as the Intercom and
     * PagerDuty builders, so the two cannot drift.
     */
    public function microsoftTeamsMessageFor(string $subject, string $text = '', ?string $actionUrl = null, ?string $actionLabel = null): MicrosoftTeamsMessage
    {
        $message = MicrosoftTeamsMessage::create()
            ->to((string) ($this->config['webhook_url'] ?? ''))
            ->title($subject)
            // Teams shows this in the activity feed and the toast, where the
            // card body is not rendered at all.
            ->summary($subject);

        foreach (preg_split('/\R{2,}/', $text) ?: [] as $paragraph) {
            $message->content(trim($paragraph));
        }

        if ($actionUrl !== null && $actionUrl !== '') {
            $message->button($actionLabel ?: __('Open in Dply'), $actionUrl);
        }

        return $message;
    }

    /**
     * @return array{ok: bool, message: string}
     */
    protected function sendMicrosoftTeamsTest(string $actorLabel): array
    {
        $url = $this->config['webhook_url'] ?? null;

        if (! is_string($url) || $url === '') {
            return ['ok' => false, 'message' => __('Microsoft Teams workflow URL is missing.')];
        }

        // Caught here as well as in the client so the operator gets the real
        // explanation on the button they just pressed.
        if (MicrosoftTeamsClient::isRetiredConnectorUrl($url)) {
            return ['ok' => false, 'message' => MicrosoftTeamsClient::describeError('retired_connector')];
        }

        $message = $this->microsoftTeamsMessageFor(
            __('Test notification from :app', ['app' => config('app.name')]),
            __('Channel ":label", triggered by :actor.', ['label' => $this->label, 'actor' => $actorLabel]),
        )->type('success');

        $result = (new MicrosoftTeamsClient)->send($url, $message->toArray());

        if (! $result['ok']) {
            return ['ok' => false, 'message' => MicrosoftTeamsClient::describeError($result['error'])];
        }

        return ['ok' => true, 'message' => __('Test message sent.')];
    }

    /**
     * @return array{ok: bool, message: string}
     */
    protected function sendRocketchatTest(string $actorLabel): array
    {
        return $this->sendJsonTextWebhookTest(
            $this->config['webhook_url'] ?? null,
            __('[:app] Test notification (:label) from :actor', [
                'app' => config('app.name'),
                'label' => $this->label,
                'actor' => $actorLabel,
            ]),
            __('Rocket.Chat webhook URL is missing.'),
            'Rocket.Chat'
        );
    }

    /**
     * @return array{ok: bool, message: string}
     */
    protected function sendGoogleChatTest(string $actorLabel): array
    {
        return $this->sendJsonTextWebhookTest(
            $this->config['webhook_url'] ?? null,
            __('[:app] Test notification (:label) from :actor', [
                'app' => config('app.name'),
                'label' => $this->label,
                'actor' => $actorLabel,
            ]),
            __('Google Chat webhook URL is missing.'),
            'Google Chat'
        );
    }

    /**
     * @return array{ok: bool, message: string}
     */
    protected function sendJsonTextWebhookTest(?string $url, string $text, string $missingUrlMessage, string $providerLabel): array
    {
        if (! is_string($url) || $url === '') {
            return ['ok' => false, 'message' => $missingUrlMessage];
        }

        try {
            $response = self::postToWebhookUrl($url, ['text' => $text]);
        } catch (\Throwable $e) {
            return self::webhookFailure($e);
        }

        if (! $response->successful()) {
            return ['ok' => false, 'message' => __(':provider returned :status.', [
                'provider' => $providerLabel,
                'status' => $response->status(),
            ])];
        }

        return ['ok' => true, 'message' => __('Test message sent.')];
    }

    /**
     * Build the Intercom message for this channel from its encrypted config.
     *
     * Both the test button and real delivery come through here so the two can
     * never drift — the failure mode we're avoiding is a channel that passes its
     * test and then silently never delivers, which is what TYPE_MOBILE_APP does
     * today by having a test arm and no delivery arm.
     */
    public function intercomMessageFor(string $body, ?string $subject = null): IntercomMessage
    {
        $cfg = $this->config;

        $message = IntercomMessage::create($body)
            ->token((string) ($cfg['access_token'] ?? ''))
            ->region((string) ($cfg['region'] ?? 'us'))
            ->from((string) ($cfg['admin_id'] ?? ''));

        $recipient = (string) ($cfg['recipient'] ?? '');

        match ($cfg['recipient_type'] ?? self::INTERCOM_TO_USER_EMAIL) {
            self::INTERCOM_TO_USER_ID => $message->toUserId($recipient),
            self::INTERCOM_TO_CONTACT_ID => $message->toContactId($recipient),
            self::INTERCOM_TO_EMAIL => $message->toEmail($recipient),
            default => $message->toUserEmail($recipient),
        };

        if (($cfg['message_type'] ?? IntercomMessage::TYPE_INAPP) === IntercomMessage::TYPE_EMAIL) {
            // Intercom rejects an email message with no subject, so fall back to
            // the configured one and then to the app name rather than 400.
            $message->email()->subject(
                $subject !== null && $subject !== ''
                    ? $subject
                    : (string) ($cfg['subject'] ?? config('app.name'))
            );

            $template = $cfg['template'] ?? IntercomMessage::TEMPLATE_PLAIN;
            $template === IntercomMessage::TEMPLATE_PERSONAL ? $message->personal() : $message->plain();
        } else {
            // In-app messages sit unread until the contact next opens the
            // Messenger unless the conversation is opened up front.
            $message->inapp()->createConversationWithoutContactReply();
        }

        return $message;
    }

    /**
     * @return array{ok: bool, message: string}
     */
    protected function sendIntercomTest(string $actorLabel): array
    {
        $cfg = $this->config;
        $token = $cfg['access_token'] ?? null;
        $adminId = $cfg['admin_id'] ?? null;
        $recipient = $cfg['recipient'] ?? null;

        if (! is_string($token) || $token === '') {
            return ['ok' => false, 'message' => __('Intercom access token is missing.')];
        }

        if (! is_string($adminId) || $adminId === '') {
            return ['ok' => false, 'message' => __('Intercom admin ID is missing.')];
        }

        if (! is_string($recipient) || $recipient === '') {
            return ['ok' => false, 'message' => __('Intercom recipient is missing.')];
        }

        $subject = __('Test from :app', ['app' => config('app.name')]);
        $text = __(':app test notification for ":label", sent by :actor.', [
            'app' => config('app.name'),
            'label' => $this->label,
            'actor' => $actorLabel,
        ]);

        $message = $this->intercomMessageFor($text, $subject);

        $result = IntercomClient::make($message->getToken(), $message->getRegion())
            ->postMessage($message->toArray());

        if (! $result['ok']) {
            return ['ok' => false, 'message' => IntercomClient::describeError($result['error'])];
        }

        return ['ok' => true, 'message' => __('Test message sent.')];
    }

    /**
     * Build the PagerDuty event for this channel.
     *
     * As with Intercom, the test button and real delivery share one builder so
     * they cannot drift.
     *
     * @param  array<string, mixed>  $context  Optional severity / dedup_key / source
     *                                         from the originating NotificationEvent.
     */
    public function pagerDutyMessageFor(string $summary, string $details = '', ?string $actionUrl = null, array $context = []): PagerDutyMessage
    {
        $cfg = $this->config;

        $severity = isset($context['severity'])
            ? PagerDutyMessage::severityFromEventSeverity((string) $context['severity'])
            : (string) ($cfg['default_severity'] ?? PagerDutyMessage::SEVERITY_ERROR);

        $message = PagerDutyMessage::create()
            ->setRoutingKey((string) ($cfg['routing_key'] ?? ''))
            ->region((string) ($cfg['region'] ?? 'us'))
            ->setSummary($summary)
            // Default source is the web node's hostname, which is never the
            // thing that broke — prefer the resource the event is about.
            ->setSource((string) ($context['source'] ?? $cfg['source'] ?? config('app.name')))
            ->setSeverity($severity)
            ->setTimestamp(now()->toIso8601String())
            ->setClient((string) config('app.name'));

        if (is_string($actionUrl) && $actionUrl !== '') {
            $message->setClientUrl($actionUrl)->addLink($actionUrl, __('Open in Dply'));
        }

        // A stable dedup key is what turns a flapping server into one incident
        // instead of a wall of them, and it is the handle a resolve event needs
        // later. Without one PagerDuty mints a fresh incident per event.
        if (isset($context['dedup_key']) && is_string($context['dedup_key']) && $context['dedup_key'] !== '') {
            $message->setDedupKey($context['dedup_key']);
        }

        if (is_string($cfg['component'] ?? null) && $cfg['component'] !== '') {
            $message->setComponent((string) $cfg['component']);
        }

        if (is_string($cfg['group'] ?? null) && $cfg['group'] !== '') {
            $message->setGroup((string) $cfg['group']);
        }

        if (is_string($context['event_key'] ?? null) && $context['event_key'] !== '') {
            $message->setClass((string) $context['event_key']);
        }

        if ($details !== '') {
            $message->addCustomDetail('details', $details);
        }

        return $message;
    }

    /**
     * @return array{ok: bool, message: string}
     */
    protected function sendPagerDutyTest(string $actorLabel): array
    {
        $routingKey = $this->config['routing_key'] ?? null;

        if (! is_string($routingKey) || $routingKey === '') {
            return ['ok' => false, 'message' => __('PagerDuty integration key is missing.')];
        }

        $summary = __(':app test alert for ":label", sent by :actor.', [
            'app' => config('app.name'),
            'label' => $this->label,
            'actor' => $actorLabel,
        ]);

        // Deliberately `info`, whatever the channel's default severity is. A
        // test should prove the wiring without waking whoever is on call.
        $message = $this->pagerDutyMessageFor($summary, '', null, [
            'severity' => PagerDutyMessage::SEVERITY_INFO,
            'source' => config('app.name'),
            'dedup_key' => 'dply-test:'.$this->id,
        ]);

        $result = PagerDutyClient::make($message->getRegion())->enqueue($message->toArray());

        if (! $result['ok']) {
            return ['ok' => false, 'message' => PagerDutyClient::describeError($result['error'])];
        }

        return ['ok' => true, 'message' => __('Test alert sent. It will show on the PagerDuty service as an info-level incident.')];
    }

    /**
     * @return array{ok: bool, message: string}
     */
    protected function sendMobileAppTest(string $actorLabel): array
    {
        $token = $this->config['device_token'] ?? null;
        $platform = $this->config['platform'] ?? null;
        if (! is_string($token) || $token === '' || ! is_string($platform) || ! in_array($platform, ['ios', 'android'], true)) {
            return ['ok' => false, 'message' => __('Device token and platform (iOS or Android) are required.')];
        }

        return [
            'ok' => true,
            'message' => __('Device registered. Push delivery is available when the dply mobile app uses this token.'),
        ];
    }

    /**
     * @return array{ok: bool, message: string}
     */
    protected function sendWebhookTest(string $actorLabel): array
    {
        $url = $this->config['url'] ?? null;
        if (! is_string($url) || $url === '') {
            return ['ok' => false, 'message' => __('Webhook URL is missing.')];
        }

        $payload = [
            'event' => 'notification_channel.test',
            'label' => $this->label,
            'app' => config('app.name'),
            'actor' => $actorLabel,
            'sent_at' => now()->toIso8601String(),
        ];

        try {
            $response = $this->postSignedWebhook($url, $payload, (string) Str::ulid());
        } catch (\Throwable $e) {
            return self::webhookFailure($e);
        }

        if (! $response->successful()) {
            return ['ok' => false, 'message' => __('Endpoint returned :status.', ['status' => $response->status()])];
        }

        return ['ok' => true, 'message' => __('Test message sent.')];
    }

    /**
     * Deliver a short operational alert (insights, health, etc.) to this channel.
     *
     * @param  array<string, mixed>  $context  Optional metadata about the originating
     *                                         event. Chat-shaped channels ignore it
     *                                         entirely; PagerDuty needs it to set a
     *                                         real severity and a stable dedup key, so
     *                                         a flapping resource updates one incident
     *                                         instead of opening dozens. Recognised
     *                                         keys: severity, dedup_key, source,
     *                                         event_key. Defaults to [] so the six
     *                                         existing call sites are unaffected.
     */
    public function sendOperationalMessage(string $subject, string $text, ?string $actionUrl = null, ?string $actionLabel = null, array $context = []): void
    {
        $lines = array_filter([$subject, $text, $actionUrl && $actionLabel ? $actionLabel.': '.$actionUrl : $actionUrl]);
        $full = implode("\n\n", $lines);

        try {
            match ($this->type) {
                self::TYPE_SLACK => $this->deliverSlackPlain($full),
                self::TYPE_DISCORD => $this->deliverDiscordPlain($full),
                self::TYPE_EMAIL => $this->deliverEmail($subject, $text, $actionUrl, $actionLabel),
                self::TYPE_TELEGRAM => $this->deliverTelegramPlain($full),
                self::TYPE_PUSHOVER => $this->deliverPushoverPlain($subject, $full),
                self::TYPE_MICROSOFT_TEAMS => $this->deliverTeamsPlain($subject, $full),
                self::TYPE_ROCKETCHAT => $this->deliverRocketchatPlain($full),
                self::TYPE_GOOGLE_CHAT => $this->deliverGoogleChatPlain($full),
                self::TYPE_INTERCOM => $this->deliverIntercomPlain($subject, $full),
                self::TYPE_PAGERDUTY => $this->deliverPagerDutyAlert($subject, $text, $actionUrl, $context),
                self::TYPE_WEBHOOK => $this->deliverWebhookInsight($subject, $text, $actionUrl, $context),
                default => null,
            };
        } catch (\Throwable $e) {
            Log::warning('notification_channel.operational_failed', [
                'channel_id' => $this->id,
                'type' => $this->type,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function deliverSlackPlain(string $text): void
    {
        if ($this->usesSlackOauth()) {
            $result = $this->postSlackViaBotToken($text);
            if (! $result['ok']) {
                Log::warning('notification_channel.slack_post_failed', [
                    'channel_id' => $this->id,
                    'message' => $result['message'],
                ]);
            }

            return;
        }

        $url = $this->config['webhook_url'] ?? null;
        if (! is_string($url) || $url === '') {
            return;
        }

        $payload = ['text' => $text];
        $channel = $this->config['channel'] ?? null;
        if (is_string($channel) && $channel !== '') {
            $payload['channel'] = $channel;
        }

        self::postToWebhookUrl($url, $payload);
    }

    protected function deliverDiscordPlain(string $text): void
    {
        if ($this->usesDiscordOauth()) {
            $result = $this->postDiscordViaBot($text);
            if (! $result['ok']) {
                Log::warning('notification_channel.discord_post_failed', [
                    'channel_id' => $this->id,
                    'message' => $result['message'],
                ]);
            }

            return;
        }

        $url = $this->config['webhook_url'] ?? null;
        if (! is_string($url) || $url === '') {
            return;
        }

        self::postToWebhookUrl($url, ['content' => mb_substr($text, 0, 1900)]);
    }

    protected function deliverEmail(string $subject, string $body, ?string $actionUrl = null, ?string $actionLabel = null): void
    {
        $to = $this->config['email'] ?? null;
        if (! is_string($to) || $to === '' || ! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        // The body arrives as newline-separated lines (e.g. "Server: x\nAccount: y");
        // render each as its own paragraph in the branded template.
        $lines = array_values(array_filter(array_map(
            static fn (string $line): string => trim($line),
            explode("\n", $body),
        ), static fn (string $line): bool => $line !== ''));

        Mail::to($to)->queue(new NotificationChannelMail(
            heading: $subject,
            bodyLines: $lines,
            actionUrl: $actionUrl,
            actionLabel: $actionLabel,
            subjectLine: $subject,
        ));
    }

    protected function deliverTelegramPlain(string $text): void
    {
        if ($this->usesTelegramConnected()) {
            $result = $this->postTelegramViaBot($text);
            if (! $result['ok']) {
                Log::warning('notification_channel.telegram_post_failed', [
                    'channel_id' => $this->id,
                    'message' => $result['message'],
                ]);
            }

            return;
        }

        $token = $this->config['bot_token'] ?? null;
        $chatId = $this->config['chat_id'] ?? null;
        if (! is_string($token) || $token === '' || ! is_string($chatId) || $chatId === '') {
            return;
        }

        $url = 'https://api.telegram.org/bot'.rawurlencode($token).'/sendMessage';
        Http::timeout(10)->asForm()->post($url, [
            'chat_id' => $chatId,
            'text' => mb_substr($text, 0, 4000),
        ]);
    }

    protected function deliverPushoverPlain(string $title, string $message): void
    {
        $appToken = $this->config['app_token'] ?? null;
        $userKey = $this->config['user_key'] ?? null;
        if (! is_string($appToken) || $appToken === '' || ! is_string($userKey) || $userKey === '') {
            return;
        }

        Http::timeout(10)->asForm()->post('https://api.pushover.net/1/messages.json', [
            'token' => $appToken,
            'user' => $userKey,
            'title' => mb_substr($title, 0, 250),
            'message' => mb_substr($message, 0, 1000),
        ]);
    }

    /**
     * Was a MessageCard posted to an Office 365 connector. Microsoft retired
     * connectors between 18 and 22 May 2026, so that payload and that URL both
     * stopped working; this now posts an Adaptive Card to a Power Automate
     * Workflows webhook.
     */
    protected function deliverTeamsPlain(string $title, string $text): void
    {
        $url = $this->config['webhook_url'] ?? null;
        if (! is_string($url) || $url === '') {
            return;
        }

        $result = (new MicrosoftTeamsClient)->send(
            $url,
            $this->microsoftTeamsMessageFor($title, $text)->toArray()
        );

        if (! $result['ok']) {
            Log::warning('notification_channel.microsoft_teams_post_failed', [
                'channel_id' => $this->id,
                'message' => MicrosoftTeamsClient::describeError($result['error']),
            ]);
        }
    }

    protected function deliverRocketchatPlain(string $text): void
    {
        $url = $this->config['webhook_url'] ?? null;
        if (! is_string($url) || $url === '') {
            return;
        }

        self::postToWebhookUrl($url, ['text' => $text]);
    }

    protected function deliverGoogleChatPlain(string $text): void
    {
        $url = $this->config['webhook_url'] ?? null;
        if (! is_string($url) || $url === '') {
            return;
        }

        self::postToWebhookUrl($url, ['text' => $text]);
    }

    protected function deliverIntercomPlain(string $subject, string $text): void
    {
        $cfg = $this->config;
        $token = $cfg['access_token'] ?? null;
        $adminId = $cfg['admin_id'] ?? null;
        $recipient = $cfg['recipient'] ?? null;

        if (! is_string($token) || $token === ''
            || ! is_string($adminId) || $adminId === ''
            || ! is_string($recipient) || $recipient === '') {
            return;
        }

        $message = $this->intercomMessageFor($text, $subject);

        $result = IntercomClient::make($message->getToken(), $message->getRegion())
            ->postMessage($message->toArray());

        if (! $result['ok']) {
            Log::warning('notification_channel.intercom_post_failed', [
                'channel_id' => $this->id,
                'message' => IntercomClient::describeError($result['error']),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    protected function deliverPagerDutyAlert(string $subject, string $text, ?string $actionUrl, array $context = []): void
    {
        $routingKey = $this->config['routing_key'] ?? null;
        if (! is_string($routingKey) || $routingKey === '') {
            return;
        }

        $message = $this->pagerDutyMessageFor($subject, $text, $actionUrl, $context);
        $result = PagerDutyClient::make($message->getRegion())->enqueue($message->toArray());

        if (! $result['ok']) {
            Log::warning('notification_channel.pagerduty_enqueue_failed', [
                'channel_id' => $this->id,
                'message' => PagerDutyClient::describeError($result['error']),
            ]);
        }
    }

    /**
     * Close the incident a previous alert opened, matched on the same dedup key.
     *
     * Separate from sendOperationalMessage() because resolving is not "sending a
     * message" — only PagerDuty has the concept, and callers must opt in when
     * they actually know a condition cleared.
     */
    public function resolvePagerDutyAlert(string $dedupKey): void
    {
        if ($this->type !== self::TYPE_PAGERDUTY || $dedupKey === '') {
            return;
        }

        $routingKey = $this->config['routing_key'] ?? null;
        if (! is_string($routingKey) || $routingKey === '') {
            return;
        }

        $message = PagerDutyMessage::create()
            ->setRoutingKey($routingKey)
            ->region((string) ($this->config['region'] ?? 'us'))
            ->setDedupKey($dedupKey)
            ->resolve();

        try {
            $result = PagerDutyClient::make($message->getRegion())->enqueue($message->toArray());
        } catch (\Throwable $e) {
            Log::warning('notification_channel.pagerduty_resolve_failed', [
                'channel_id' => $this->id,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        if (! $result['ok']) {
            Log::warning('notification_channel.pagerduty_resolve_failed', [
                'channel_id' => $this->id,
                'message' => PagerDutyClient::describeError($result['error']),
            ]);
        }
    }

    /**
     * Queued, signed and retried: see DeliverNotificationWebhookJob.
     *
     * @param  array<string, mixed>  $context  From NotificationRoutingResolver::alertContextFor.
     */
    protected function deliverWebhookInsight(string $subject, string $text, ?string $actionUrl, array $context = []): void
    {
        $url = $this->config['url'] ?? null;
        if (! is_string($url) || $url === '') {
            return;
        }

        $event = is_string($context['event_key'] ?? null) && $context['event_key'] !== ''
            ? $context['event_key']
            : 'notification';

        DeliverNotificationWebhookJob::dispatch($this->id, [
            'event' => $event,
            'severity' => is_string($context['severity'] ?? null) ? $context['severity'] : null,
            'source' => is_string($context['source'] ?? null) ? $context['source'] : null,
            'dedup_key' => is_string($context['dedup_key'] ?? null) ? $context['dedup_key'] : null,
            'subject' => $subject,
            'text' => $text,
            'action_url' => $actionUrl,
            'sent_at' => now()->toIso8601String(),
        ], (string) Str::ulid());
    }

    /**
     * Per-channel HMAC secret for the X-Dply-Signature header. Derived from
     * APP_KEY, so it needs no storage and is shown on the channel's edit form;
     * rotating APP_KEY changes every channel's secret.
     */
    public static function webhookSigningSecret(string $channelId): string
    {
        return hash_hmac('sha256', 'notification-webhook:'.$channelId, (string) config('app.key'));
    }

    /**
     * POST $payload signed over the exact bytes sent (`t=<unix>,v1=<hex>` of
     * `<unix>.<body>`, as OutboundWebhookSignature). Custom headers go first so
     * they cannot override the signature headers.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws UnsafeOutboundUrlException
     */
    public function postSignedWebhook(string $url, array $payload, string $deliveryId): Response
    {
        $body = (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $ts = time();

        return self::postToWebhookUrl($url, $body, [
            ...$this->webhookHeaders(),
            'X-Dply-Event' => (string) ($payload['event'] ?? ''),
            'X-Dply-Delivery-Id' => $deliveryId,
            'X-Dply-Timestamp' => (string) $ts,
            'X-Dply-Signature' => OutboundWebhookSignature::header(self::webhookSigningSecret($this->id), $ts, $body),
        ]);
    }

    /**
     * POST JSON to an operator-supplied URL: resolved and pinned to a public
     * address with redirects off, so a channel cannot aim the control plane at
     * internal services (SSRF).
     *
     * A string payload is sent as-is (already-encoded JSON, e.g. a signed body).
     *
     * @param  array<string, mixed>|string  $payload
     * @param  array<string, string>  $headers
     *
     * @throws UnsafeOutboundUrlException
     */
    public static function postToWebhookUrl(string $url, array|string $payload, array $headers = []): Response
    {
        $safe = PublicOutboundUrl::parse($url);
        $request = Http::timeout(10)
            ->withOptions($safe->httpClientOptions())
            ->withHeaders($headers);

        return is_string($payload)
            ? $request->withBody($payload, 'application/json')->post($safe->url)
            : $request->asJson()->post($safe->url, $payload);
    }

    /**
     * The test button's answer for a failed post. Our own URL refusal is shown;
     * transport errors are logged, not echoed, so the button is no probe.
     *
     * @return array{ok: false, message: string}
     */
    protected static function webhookFailure(\Throwable $e): array
    {
        if ($e instanceof UnsafeOutboundUrlException) {
            return ['ok' => false, 'message' => __('That URL is not allowed: :reason', ['reason' => $e->getMessage()])];
        }

        Log::warning('notification_channel.webhook_test_failed', ['error' => $e->getMessage()]);

        return ['ok' => false, 'message' => __('Could not reach the endpoint.')];
    }

    /** @return array<string, string> */
    protected function webhookHeaders(): array
    {
        $headers = $this->config['headers'] ?? null;

        return is_array($headers) ? array_map('strval', array_filter($headers, 'is_scalar')) : [];
    }
}
