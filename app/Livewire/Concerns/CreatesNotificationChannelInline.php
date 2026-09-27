<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Models\NotificationChannel;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use App\Modules\Notifications\Channels\Intercom\IntercomMessage;
use App\Modules\Notifications\Channels\PagerDuty\PagerDutyMessage;
use App\Modules\Notifications\Services\AssignableNotificationChannels;
use App\Modules\Notifications\Services\MicrosoftTeamsClient;
use App\Rules\PubliclyRoutableUrl;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Drop-in trait that gives a Livewire host the same "create notification channel"
 * form as `Settings → Notifications`, but rendered inline as a modal so operators
 * can add a channel without leaving the current page.
 *
 * Use case: any place that lists "assignable" notification channels (server
 * subscription form, project routing form, site uptime monitor setup) and gets
 * blocked when the operator has no channels yet. Drop this trait in, render the
 * partial `livewire.partials.create-notification-channel-modal`, and add a
 * trigger button that calls `openCreateChannelModal()`.
 *
 * After a successful create, the trait dispatches a `notification-channel-created`
 * Livewire event with `channelId` so the host can refresh its channel list and
 * (optionally) auto-select the new row. Listen via `#[On('notification-channel-created')]`.
 *
 * The partial reuses `livewire.settings.partials.notification-channel-fields`
 * with the `new_` prefix, so adding a new channel kind is a one-place change in
 * the central partial. Validation mirrors `ManagesNotificationChannels` exactly.
 *
 * Owner resolution: defaults to the current user (personal channels). Hosts that
 * route channels through an organization or team can override
 * `creatableChannelOwner()` to return the right subject. The model's
 * `manageNotificationChannels` policy gates whether the modal opens at all.
 */
trait CreatesNotificationChannelInline
{
    use BuildsIntercomChannelInput;
    use BuildsPagerDutyChannelInput;
    use DispatchesToastNotifications;
    use ResolvesDiscordGuilds;
    use ResolvesSlackWorkspaces;
    use ResolvesTelegramChats;

    /** Modal open/close state. Bound to the partial's `@if`. */
    public bool $createChannelModalOpen = false;

    public string $new_type = NotificationChannel::TYPE_EMAIL;

    public string $new_label = '';

    public string $new_slack_webhook_url = '';

    public string $new_slack_channel = '';

    public string $new_discord_webhook_url = '';

    public string $new_email_address = '';

    public string $new_telegram_bot_token = '';

    public string $new_telegram_chat_id = '';

    public string $new_pushover_app_token = '';

    public string $new_pushover_user_key = '';

    public string $new_teams_webhook_url = '';

    public string $new_rocketchat_webhook_url = '';

    public string $new_google_chat_webhook_url = '';

    public string $new_mobile_device_token = '';

    public string $new_mobile_platform = 'ios';

    public string $new_intercom_access_token = '';

    public string $new_intercom_region = 'us';

    public string $new_intercom_admin_id = '';

    public string $new_intercom_recipient = '';

    public string $new_intercom_recipient_type = NotificationChannel::INTERCOM_TO_USER_EMAIL;

    public string $new_intercom_message_type = IntercomMessage::TYPE_INAPP;

    public string $new_intercom_template = IntercomMessage::TEMPLATE_PLAIN;

    public string $new_intercom_subject = '';

    public string $new_pagerduty_routing_key = '';

    public string $new_pagerduty_region = 'us';

    public string $new_pagerduty_default_severity = PagerDutyMessage::SEVERITY_ERROR;

    public string $new_pagerduty_source = '';

    public string $new_pagerduty_component = '';

    public string $new_pagerduty_group = '';

    public string $new_webhook_url = '';

    /** Id of the channel a test notification is currently in flight for (UI spinner). */
    public ?string $testingChannelId = null;

    /**
     * Send a test notification to one of the assignable channels listed in the
     * subscription matrix, so operators can confirm a channel is wired up before
     * routing real events to it. Mirrors {@see ManagesNotificationChannels::sendTest},
     * but resolves the channel by id and gates on the channel policy so it works for
     * personal, organization, and team-owned channels alike (the matrix mixes all three).
     */
    public function sendTestChannelNotification(string|int $id): void
    {
        $channel = NotificationChannel::findOrFail($id);
        Gate::authorize('update', $channel);

        $this->testingChannelId = (string) $channel->id;
        $result = $channel->sendTest(Auth::user());
        $this->testingChannelId = null;

        // Bind the morphTo result to a local first — `instanceof` narrows a
        // variable, but not a relation property fetch re-read in each arm.
        $owner = $channel->owner;
        $org = match (true) {
            $owner instanceof Organization => $owner,
            $owner instanceof Team => $owner->organization,
            default => Auth::user()?->currentOrganization(),
        };
        if ($org !== null) {
            audit_log($org, Auth::user(), 'notification_channel.test_sent', $channel, null, [
                'channel_id' => (string) $channel->id,
                'type' => $channel->type,
                'label' => $channel->label,
                'result' => $result['ok'] ? 'success' : 'failed',
                'message' => $result['message'],
                'surface' => 'subscription_matrix',
            ]);
        }

        if ($result['ok']) {
            $this->toastSuccess($result['message']);
        } else {
            $this->toastError($result['message']);
        }
    }

    /**
     * Subject the new channel attaches to. Defaults to the signed-in user
     * (personal channels). Hosts can override to return an Organization / Team
     * when channels should be org/team-scoped.
     */
    protected function creatableChannelOwner(): User|Organization|Team
    {
        $user = Auth::user();
        if ($user === null) {
            throw new \RuntimeException('No authenticated user for channel creation.');
        }

        return $user;
    }

    /** {@see ResolvesSlackWorkspaces} — Slack installs hang off the same owner as the channels. */
    protected function channelIntegrationOwner(): User|Organization|Team
    {
        return $this->creatableChannelOwner();
    }

    public function openCreateChannelModal(): void
    {
        Gate::authorize('manageNotificationChannels', $this->creatableChannelOwner());

        $allowedTypes = NotificationChannel::typesForUi();
        if ($allowedTypes === []) {
            $this->toastError(__('No notification channel types are enabled on this installation.'));

            return;
        }
        if (! in_array($this->new_type, $allowedTypes, true)) {
            $this->new_type = $allowedTypes[0];
        }

        $this->resetNewChannelFields();
        $this->syncSlackModeDefault();
        $this->syncDiscordModeDefault();
        $this->syncTelegramModeDefault();
        $this->resetErrorBag();
        $this->createChannelModalOpen = true;
        $this->dispatch('open-modal', 'create-notification-channel-modal');
    }

    public function cancelCreateChannelModal(): void
    {
        $this->createChannelModalOpen = false;
        $this->dispatch('close-modal', 'create-notification-channel-modal');
    }

    public function submitCreateChannelInline(): void
    {
        $owner = $this->creatableChannelOwner();
        Gate::authorize('manageNotificationChannels', $owner);

        $rules = array_merge(
            ['new_type' => ['required', 'string', Rule::in(NotificationChannel::typesForUi())]],
            $this->newChannelValidationRules($this->new_type),
        );
        $this->validate($rules, [], array_merge(
            ['new_type' => __('type')],
            $this->newChannelValidationAttributes(),
        ));

        $channel = $owner->notificationChannels()->create([
            'type' => $this->new_type,
            'label' => $this->new_label,
            'config' => $this->newChannelConfigFromInput($this->new_type),
        ]);

        // Drop request memo so hosts that re-query assignable channels see the new row.
        AssignableNotificationChannels::flushMemo(
            Auth::id() !== null ? (string) Auth::id() : null,
        );

        $org = match (true) {
            $owner instanceof Organization => $owner,
            $owner instanceof Team => $owner->organization,
            default => Auth::user()?->currentOrganization(),
        };
        if ($org !== null) {
            audit_log($org, Auth::user(), 'notification_channel.created', $channel, null, [
                'channel_id' => (string) $channel->id,
                'type' => $channel->type,
                'label' => $channel->label,
                'surface' => 'inline_modal',
            ]);
        }

        $this->resetNewChannelFields();
        $this->createChannelModalOpen = false;
        $this->dispatch('close-modal', 'create-notification-channel-modal');
        $this->toastSuccess(__('Channel ":label" created.', ['label' => $channel->label]));

        // Notify the host so it can refresh its assignable-channels list and
        // optionally auto-select the new channel in any picker.
        $this->dispatch('notification-channel-created', channelId: (string) $channel->id);
    }

    protected function resetNewChannelFields(): void
    {
        $this->new_label = '';
        $this->new_slack_webhook_url = '';
        $this->new_slack_channel = '';
        $this->new_slack_channel_id = '';
        $this->new_discord_webhook_url = '';
        $this->new_discord_channel_id = '';
        $this->new_email_address = '';
        $this->new_telegram_bot_token = '';
        $this->new_telegram_chat_id = '';
        $this->new_pushover_app_token = '';
        $this->new_pushover_user_key = '';
        $this->new_teams_webhook_url = '';
        $this->new_rocketchat_webhook_url = '';
        $this->new_google_chat_webhook_url = '';
        $this->new_mobile_device_token = '';
        $this->new_mobile_platform = 'ios';
        $this->new_intercom_access_token = '';
        $this->new_intercom_region = 'us';
        $this->new_intercom_admin_id = '';
        $this->new_intercom_recipient = '';
        $this->new_intercom_recipient_type = NotificationChannel::INTERCOM_TO_USER_EMAIL;
        $this->new_intercom_message_type = IntercomMessage::TYPE_INAPP;
        $this->new_intercom_template = IntercomMessage::TEMPLATE_PLAIN;
        $this->new_intercom_subject = '';
        $this->new_pagerduty_routing_key = '';
        $this->new_pagerduty_region = 'us';
        $this->new_pagerduty_default_severity = PagerDutyMessage::SEVERITY_ERROR;
        $this->new_pagerduty_source = '';
        $this->new_pagerduty_component = '';
        $this->new_pagerduty_group = '';
        $this->new_webhook_url = '';
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function newChannelValidationRules(string $type): array
    {
        $base = ['new_label' => ['required', 'string', 'max:160']];

        return match ($type) {
            NotificationChannel::TYPE_SLACK => $base + ($this->slackMode('new_') === 'oauth'
                ? [
                    'new_slack_installation_id' => ['required', 'string', 'max:26'],
                    'new_slack_channel_id' => ['required', 'string', 'max:64'],
                ]
                : [
                    'new_slack_webhook_url' => ['required', 'string', 'url:http,https', 'max:2048', new PubliclyRoutableUrl],
                    'new_slack_channel' => ['nullable', 'string', 'max:120'],
                ]),
            NotificationChannel::TYPE_DISCORD => $base + ($this->discordMode('new_') === 'oauth'
                ? [
                    'new_discord_installation_id' => ['required', 'string', 'max:26'],
                    'new_discord_channel_id' => ['required', 'string', 'max:64'],
                ]
                : [
                    'new_discord_webhook_url' => ['required', 'string', 'url:http,https', 'max:2048', new PubliclyRoutableUrl],
                ]),
            NotificationChannel::TYPE_EMAIL => $base + [
                'new_email_address' => ['required', 'string', 'email', 'max:254'],
            ],
            NotificationChannel::TYPE_TELEGRAM => $base + ($this->telegramMode('new_') === 'connected'
                ? [
                    'new_telegram_installation_id' => ['required', 'string', 'max:26'],
                ]
                : [
                    'new_telegram_bot_token' => ['required', 'string', 'max:512'],
                    'new_telegram_chat_id' => ['required', 'string', 'max:64'],
                ]),
            NotificationChannel::TYPE_PUSHOVER => $base + [
                'new_pushover_app_token' => ['required', 'string', 'max:64'],
                'new_pushover_user_key' => ['required', 'string', 'max:64'],
            ],
            NotificationChannel::TYPE_MICROSOFT_TEAMS => $base + [
                'new_teams_webhook_url' => ['required', 'string', 'url:http,https', 'max:2048', new PubliclyRoutableUrl, MicrosoftTeamsClient::urlRule()],
            ],
            NotificationChannel::TYPE_ROCKETCHAT => $base + [
                'new_rocketchat_webhook_url' => ['required', 'string', 'url:http,https', 'max:2048', new PubliclyRoutableUrl],
            ],
            NotificationChannel::TYPE_GOOGLE_CHAT => $base + [
                'new_google_chat_webhook_url' => ['required', 'string', 'url:http,https', 'max:2048', new PubliclyRoutableUrl],
            ],
            NotificationChannel::TYPE_MOBILE_APP => $base + [
                'new_mobile_device_token' => ['required', 'string', 'max:4096'],
                'new_mobile_platform' => ['required', 'string', 'in:ios,android'],
            ],
            NotificationChannel::TYPE_INTERCOM => $base + $this->intercomValidationRules('new_'),
            NotificationChannel::TYPE_PAGERDUTY => $base + $this->pagerDutyValidationRules('new_'),
            NotificationChannel::TYPE_WEBHOOK => $base + [
                'new_webhook_url' => ['required', 'string', 'url:http,https', 'max:2048', new PubliclyRoutableUrl],
            ],
            default => $base,
        };
    }

    /**
     * @return array<string, string>
     */
    protected function newChannelValidationAttributes(): array
    {
        return $this->intercomValidationAttributes('new_') + $this->pagerDutyValidationAttributes('new_') + [
            'new_label' => __('label'),
            'new_slack_webhook_url' => __('webhook URL'),
            'new_slack_channel' => __('channel'),
            'new_slack_installation_id' => __('Slack workspace'),
            'new_slack_channel_id' => __('Slack channel'),
            'new_discord_webhook_url' => __('webhook URL'),
            'new_discord_installation_id' => __('Discord server'),
            'new_discord_channel_id' => __('Discord channel'),
            'new_email_address' => __('email'),
            'new_telegram_bot_token' => __('bot token'),
            'new_telegram_chat_id' => __('chat ID'),
            'new_telegram_installation_id' => __('Telegram chat'),
            'new_pushover_app_token' => __('application token'),
            'new_pushover_user_key' => __('user key'),
            'new_teams_webhook_url' => __('webhook URL'),
            'new_rocketchat_webhook_url' => __('webhook URL'),
            'new_google_chat_webhook_url' => __('webhook URL'),
            'new_mobile_device_token' => __('device token'),
            'new_mobile_platform' => __('platform'),
            'new_webhook_url' => __('URL'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function newChannelConfigFromInput(string $type): array
    {
        return match ($type) {
            NotificationChannel::TYPE_SLACK => $this->slackMode('new_') === 'oauth'
                ? $this->slackOauthConfigFromInput('new_')
                : [
                    'webhook_url' => $this->new_slack_webhook_url,
                    'channel' => $this->new_slack_channel !== '' ? $this->new_slack_channel : null,
                ],
            NotificationChannel::TYPE_DISCORD => $this->discordMode('new_') === 'oauth'
                ? $this->discordOauthConfigFromInput('new_')
                : [
                    'webhook_url' => $this->new_discord_webhook_url,
                ],
            NotificationChannel::TYPE_EMAIL => [
                'email' => $this->new_email_address,
            ],
            NotificationChannel::TYPE_TELEGRAM => $this->telegramMode('new_') === 'connected'
                ? $this->telegramConnectedConfigFromInput('new_')
                : [
                    'bot_token' => $this->new_telegram_bot_token,
                    'chat_id' => $this->new_telegram_chat_id,
                ],
            NotificationChannel::TYPE_PUSHOVER => [
                'app_token' => $this->new_pushover_app_token,
                'user_key' => $this->new_pushover_user_key,
            ],
            NotificationChannel::TYPE_MICROSOFT_TEAMS => [
                'webhook_url' => $this->new_teams_webhook_url,
            ],
            NotificationChannel::TYPE_ROCKETCHAT => [
                'webhook_url' => $this->new_rocketchat_webhook_url,
            ],
            NotificationChannel::TYPE_GOOGLE_CHAT => [
                'webhook_url' => $this->new_google_chat_webhook_url,
            ],
            NotificationChannel::TYPE_MOBILE_APP => [
                'device_token' => $this->new_mobile_device_token,
                'platform' => $this->new_mobile_platform,
            ],
            NotificationChannel::TYPE_INTERCOM => $this->intercomConfigFromInput('new_'),
            NotificationChannel::TYPE_PAGERDUTY => $this->pagerDutyConfigFromInput('new_'),
            NotificationChannel::TYPE_WEBHOOK => [
                'url' => $this->new_webhook_url,
            ],
            default => [],
        };
    }
}
