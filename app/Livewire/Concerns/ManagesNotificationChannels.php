<?php

namespace App\Livewire\Concerns;

use App\Models\NotificationChannel;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use App\Modules\Notifications\Channels\Intercom\IntercomMessage;
use App\Modules\Notifications\Channels\PagerDuty\PagerDutyMessage;
use App\Modules\Notifications\Services\MicrosoftTeamsClient;
use App\Rules\PubliclyRoutableUrl;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * @phpstan-require-extends Component
 *
 * @property-read Collection<int, NotificationChannel> $channels Livewire computed (access as $this->channels; do not invoke $this->channels()).
 */
trait ManagesNotificationChannels
{
    use BuildsIntercomChannelInput;
    use BuildsPagerDutyChannelInput;
    use ConfirmsActionWithModal;
    use DispatchesToastNotifications;
    use ResolvesDiscordGuilds;
    use ResolvesSlackWorkspaces;
    use ResolvesTelegramChats;

    public string $new_type = NotificationChannel::TYPE_SLACK;

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

    public string $search = '';

    public ?string $editing_id = null;

    public string $edit_type = NotificationChannel::TYPE_SLACK;

    public string $edit_label = '';

    public string $edit_slack_webhook_url = '';

    public string $edit_slack_channel = '';

    public string $edit_discord_webhook_url = '';

    public string $edit_email_address = '';

    public string $edit_telegram_bot_token = '';

    public string $edit_telegram_chat_id = '';

    public string $edit_pushover_app_token = '';

    public string $edit_pushover_user_key = '';

    public string $edit_teams_webhook_url = '';

    public string $edit_rocketchat_webhook_url = '';

    public string $edit_google_chat_webhook_url = '';

    public string $edit_mobile_device_token = '';

    public string $edit_mobile_platform = 'ios';

    public string $edit_intercom_access_token = '';

    public string $edit_intercom_region = 'us';

    public string $edit_intercom_admin_id = '';

    public string $edit_intercom_recipient = '';

    public string $edit_intercom_recipient_type = NotificationChannel::INTERCOM_TO_USER_EMAIL;

    public string $edit_intercom_message_type = IntercomMessage::TYPE_INAPP;

    public string $edit_intercom_template = IntercomMessage::TEMPLATE_PLAIN;

    public string $edit_intercom_subject = '';

    public string $edit_pagerduty_routing_key = '';

    public string $edit_pagerduty_region = 'us';

    public string $edit_pagerduty_default_severity = PagerDutyMessage::SEVERITY_ERROR;

    public string $edit_pagerduty_source = '';

    public string $edit_pagerduty_component = '';

    public string $edit_pagerduty_group = '';

    public string $edit_webhook_url = '';

    public ?string $testing_id = null;

    /**
     * @return User|Organization|Team
     */
    abstract protected function owner(): Model;

    /**
     * @return array<string, mixed>
     */
    abstract protected function notificationChannelsViewData(): array;

    /** {@see ResolvesSlackWorkspaces} — Slack installs hang off the same owner as the channels. */
    protected function channelIntegrationOwner(): User|Organization|Team
    {
        // No @var here: owner() is already typed, and each host component
        // narrows it further (Organization / Team / User), so re-declaring the
        // union widened it against the native type.
        return $this->owner();
    }

    /**
     * Call from each component's mount() so the default type matches config-enabled types.
     */
    protected function syncNotificationChannelTypeDefaults(): void
    {
        $allowed = NotificationChannel::typesForUi();
        if ($allowed === []) {
            return;
        }

        if (! in_array($this->new_type, $allowed, true)) {
            $this->new_type = $allowed[0];
        }

        $this->syncSlackModeDefault();
        $this->syncDiscordModeDefault();
        $this->syncTelegramModeDefault();
    }

    public function updatedSearch(): void
    {
        unset($this->channels);
    }

    /**
     * @return Collection<int, NotificationChannel>
     */
    #[Computed]
    public function channels(): Collection
    {
        $q = $this->owner()->notificationChannels()->withCount('subscriptions')->orderBy('label');
        $s = trim($this->search);
        if ($s !== '') {
            $q->where('label', 'like', '%'.$s.'%');
        }

        return $q->get();
    }

    public function canManage(): bool
    {
        return Gate::allows('manageNotificationChannels', $this->owner());
    }

    public function createChannel(): void
    {
        Gate::authorize('manageNotificationChannels', $this->owner());
        $this->resetErrorBag();

        $rules = array_merge(
            [
                'new_type' => ['required', 'string', Rule::in(NotificationChannel::typesForUi())],
            ],
            $this->validationRulesForType($this->new_type, 'new_')
        );
        $this->validate($rules, [], array_merge(['new_type' => __('type')], $this->validationAttributes('new_')));

        $config = $this->configFromInput($this->new_type, 'new_');
        $channel = $this->owner()->notificationChannels()->create([
            'type' => $this->new_type,
            'label' => $this->new_label,
            'config' => $config,
        ]);

        $this->recordChannelAudit('notification_channel.created', $channel, null, [
            'channel_id' => (string) $channel->id,
            'type' => $channel->type,
            'label' => $channel->label,
        ]);

        $this->resetNewChannelFields();
        unset($this->channels);
        $this->dispatch('close-modal', 'settings-create-channel-modal');
        $this->toastSuccess(__('Channel created.'));
    }

    public function openCreateChannelModal(): void
    {
        Gate::authorize('manageNotificationChannels', $this->owner());
        $this->resetErrorBag();
        $this->resetNewChannelFields();
        $this->syncSlackModeDefault();
        $this->syncDiscordModeDefault();
        $this->syncTelegramModeDefault();
        $this->dispatch('open-modal', 'settings-create-channel-modal');
    }

    public function closeCreateChannelModal(): void
    {
        $this->resetErrorBag();
        $this->resetNewChannelFields();
        $this->dispatch('close-modal', 'settings-create-channel-modal');
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

    public function startEdit(string|int $id): void
    {
        $channel = $this->owner()->notificationChannels()->findOrFail($id);
        Gate::authorize('update', $channel);
        $this->editing_id = $channel->id;
        $this->edit_type = $channel->type;
        $this->edit_label = $channel->label;
        $cfg = $channel->config;
        $this->clearEditChannelFields();
        if ($channel->type === NotificationChannel::TYPE_SLACK) {
            if ($channel->usesSlackOauth()) {
                $this->edit_slack_mode = 'oauth';
                $this->edit_slack_installation_id = (string) ($cfg['installation_id'] ?? '');
                $this->edit_slack_channel_id = (string) ($cfg['channel_id'] ?? '');
            } else {
                $this->edit_slack_mode = 'webhook';
                $this->edit_slack_webhook_url = (string) ($cfg['webhook_url'] ?? '');
                $this->edit_slack_channel = (string) ($cfg['channel'] ?? '');
            }
        } elseif ($channel->type === NotificationChannel::TYPE_DISCORD) {
            if ($channel->usesDiscordOauth()) {
                $this->edit_discord_mode = 'oauth';
                $this->edit_discord_installation_id = (string) ($cfg['installation_id'] ?? '');
                $this->edit_discord_channel_id = (string) ($cfg['channel_id'] ?? '');
            } else {
                $this->edit_discord_mode = 'webhook';
                $this->edit_discord_webhook_url = (string) ($cfg['webhook_url'] ?? '');
            }
        } elseif ($channel->type === NotificationChannel::TYPE_EMAIL) {
            $this->edit_email_address = (string) ($cfg['email'] ?? '');
        } elseif ($channel->type === NotificationChannel::TYPE_TELEGRAM) {
            if ($channel->usesTelegramConnected()) {
                $this->edit_telegram_mode = 'connected';
                $this->edit_telegram_installation_id = (string) ($cfg['installation_id'] ?? '');
            } else {
                $this->edit_telegram_mode = 'manual';
                $this->edit_telegram_bot_token = (string) ($cfg['bot_token'] ?? '');
                $this->edit_telegram_chat_id = (string) ($cfg['chat_id'] ?? '');
            }
        } elseif ($channel->type === NotificationChannel::TYPE_PUSHOVER) {
            $this->edit_pushover_app_token = (string) ($cfg['app_token'] ?? '');
            $this->edit_pushover_user_key = (string) ($cfg['user_key'] ?? '');
        } elseif ($channel->type === NotificationChannel::TYPE_MICROSOFT_TEAMS) {
            $this->edit_teams_webhook_url = (string) ($cfg['webhook_url'] ?? '');
        } elseif ($channel->type === NotificationChannel::TYPE_ROCKETCHAT) {
            $this->edit_rocketchat_webhook_url = (string) ($cfg['webhook_url'] ?? '');
        } elseif ($channel->type === NotificationChannel::TYPE_GOOGLE_CHAT) {
            $this->edit_google_chat_webhook_url = (string) ($cfg['webhook_url'] ?? '');
        } elseif ($channel->type === NotificationChannel::TYPE_MOBILE_APP) {
            $this->edit_mobile_device_token = (string) ($cfg['device_token'] ?? '');
            $this->edit_mobile_platform = (string) ($cfg['platform'] ?? 'ios');
        } elseif ($channel->type === NotificationChannel::TYPE_INTERCOM) {
            $this->edit_intercom_access_token = (string) ($cfg['access_token'] ?? '');
            $this->edit_intercom_region = (string) ($cfg['region'] ?? 'us');
            $this->edit_intercom_admin_id = (string) ($cfg['admin_id'] ?? '');
            $this->edit_intercom_recipient = (string) ($cfg['recipient'] ?? '');
            $this->edit_intercom_recipient_type = (string) ($cfg['recipient_type'] ?? NotificationChannel::INTERCOM_TO_USER_EMAIL);
            $this->edit_intercom_message_type = (string) ($cfg['message_type'] ?? IntercomMessage::TYPE_INAPP);
            $this->edit_intercom_template = (string) ($cfg['template'] ?? IntercomMessage::TEMPLATE_PLAIN);
            $this->edit_intercom_subject = (string) ($cfg['subject'] ?? '');
        } elseif ($channel->type === NotificationChannel::TYPE_PAGERDUTY) {
            $this->edit_pagerduty_routing_key = (string) ($cfg['routing_key'] ?? '');
            $this->edit_pagerduty_region = (string) ($cfg['region'] ?? 'us');
            $this->edit_pagerduty_default_severity = (string) ($cfg['default_severity'] ?? PagerDutyMessage::SEVERITY_ERROR);
            $this->edit_pagerduty_source = (string) ($cfg['source'] ?? '');
            $this->edit_pagerduty_component = (string) ($cfg['component'] ?? '');
            $this->edit_pagerduty_group = (string) ($cfg['group'] ?? '');
        } elseif ($channel->type === NotificationChannel::TYPE_WEBHOOK) {
            $this->edit_webhook_url = (string) ($cfg['url'] ?? '');
        }
    }

    protected function clearEditChannelFields(): void
    {
        $this->edit_slack_webhook_url = '';
        $this->edit_slack_channel = '';
        $this->edit_slack_channel_id = '';
        $this->edit_discord_webhook_url = '';
        $this->edit_discord_channel_id = '';
        $this->edit_email_address = '';
        $this->edit_telegram_bot_token = '';
        $this->edit_telegram_chat_id = '';
        $this->edit_pushover_app_token = '';
        $this->edit_pushover_user_key = '';
        $this->edit_teams_webhook_url = '';
        $this->edit_rocketchat_webhook_url = '';
        $this->edit_google_chat_webhook_url = '';
        $this->edit_mobile_device_token = '';
        $this->edit_mobile_platform = 'ios';
        $this->edit_intercom_access_token = '';
        $this->edit_intercom_region = 'us';
        $this->edit_intercom_admin_id = '';
        $this->edit_intercom_recipient = '';
        $this->edit_intercom_recipient_type = NotificationChannel::INTERCOM_TO_USER_EMAIL;
        $this->edit_intercom_message_type = IntercomMessage::TYPE_INAPP;
        $this->edit_intercom_template = IntercomMessage::TEMPLATE_PLAIN;
        $this->edit_intercom_subject = '';
        $this->edit_pagerduty_routing_key = '';
        $this->edit_pagerduty_region = 'us';
        $this->edit_pagerduty_default_severity = PagerDutyMessage::SEVERITY_ERROR;
        $this->edit_pagerduty_source = '';
        $this->edit_pagerduty_component = '';
        $this->edit_pagerduty_group = '';
        $this->edit_webhook_url = '';
    }

    public function cancelEdit(): void
    {
        $this->editing_id = null;
        $this->resetErrorBag();
    }

    public function saveEdit(): void
    {
        $channel = $this->owner()->notificationChannels()->findOrFail($this->editing_id);
        Gate::authorize('update', $channel);
        $this->resetErrorBag();

        $rules = array_merge(
            [
                'edit_type' => ['required', 'string', Rule::in(NotificationChannel::typesForUi($this->edit_type))],
            ],
            $this->validationRulesForType($this->edit_type, 'edit_')
        );
        $this->validate($rules, [], array_merge(['edit_type' => __('type')], $this->validationAttributes('edit_')));

        $oldSnapshot = [
            'channel_id' => (string) $channel->id,
            'type' => $channel->type,
            'label' => $channel->label,
        ];
        $channel->update([
            'type' => $this->edit_type,
            'label' => $this->edit_label,
            'config' => $this->configFromInput($this->edit_type, 'edit_'),
        ]);

        $this->recordChannelAudit('notification_channel.updated', $channel, $oldSnapshot, [
            'channel_id' => (string) $channel->id,
            'type' => $channel->type,
            'label' => $channel->label,
        ]);

        $this->cancelEdit();
        unset($this->channels);
        $this->toastSuccess(__('Channel updated.'));
    }

    public function deleteChannel(string|int $id): void
    {
        $channel = $this->owner()->notificationChannels()->findOrFail($id);
        Gate::authorize('delete', $channel);
        $snapshot = [
            'channel_id' => (string) $channel->id,
            'type' => $channel->type,
            'label' => $channel->label,
        ];
        $channel->delete();
        $this->recordChannelAudit('notification_channel.deleted', null, $snapshot, null);
        unset($this->channels);
        $this->toastSuccess(__('Channel removed.'));
    }

    public function sendTest(string|int $id): void
    {
        $channel = $this->owner()->notificationChannels()->findOrFail($id);
        Gate::authorize('update', $channel);
        $this->testing_id = (string) $id;
        $result = $channel->sendTest(Auth::user());
        $this->testing_id = null;

        $this->recordChannelAudit('notification_channel.test_sent', $channel, null, [
            'channel_id' => (string) $channel->id,
            'type' => $channel->type,
            'label' => $channel->label,
            'result' => $result['ok'] ? 'success' : 'failed',
            'message' => (string) $result['message'],
        ]);

        if ($result['ok']) {
            $this->toastSuccess($result['message']);
        } else {
            $this->toastError($result['message']);
        }
    }

    /**
     * Resolve the organization that should own this channel's audit entry and
     * dispatch the log. Channels owned directly by an Organization or Team
     * route to that org; user-owned (personal) channels route to the user's
     * current org so the action surfaces alongside their other audit events.
     *
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     */
    protected function recordChannelAudit(string $action, ?NotificationChannel $subject, ?array $oldValues, ?array $newValues): void
    {
        $owner = $this->owner();
        $org = match (true) {
            $owner instanceof Organization => $owner,
            $owner instanceof Team => $owner->organization,
            default => Auth::user()?->currentOrganization(),
        };
        if (! $org instanceof Organization) {
            return;
        }
        audit_log($org, Auth::user(), $action, $subject, $oldValues, $newValues);
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(string $prefix): array
    {
        $p = $prefix;

        return $this->intercomValidationAttributes($p) + $this->pagerDutyValidationAttributes($p) + [
            $p.'label' => __('label'),
            $p.'slack_webhook_url' => __('webhook URL'),
            $p.'slack_channel' => __('channel'),
            $p.'slack_installation_id' => __('Slack workspace'),
            $p.'slack_channel_id' => __('Slack channel'),
            $p.'discord_webhook_url' => __('webhook URL'),
            $p.'discord_installation_id' => __('Discord server'),
            $p.'discord_channel_id' => __('Discord channel'),
            $p.'email_address' => __('email address'),
            $p.'telegram_bot_token' => __('bot token'),
            $p.'telegram_chat_id' => __('chat ID'),
            $p.'telegram_installation_id' => __('Telegram chat'),
            $p.'pushover_app_token' => __('application token'),
            $p.'pushover_user_key' => __('user key'),
            $p.'teams_webhook_url' => __('webhook URL'),
            $p.'rocketchat_webhook_url' => __('webhook URL'),
            $p.'google_chat_webhook_url' => __('webhook URL'),
            $p.'mobile_device_token' => __('device token'),
            $p.'mobile_platform' => __('platform'),
            $p.'webhook_url' => __('URL'),
        ];
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function validationRulesForType(string $type, string $prefix): array
    {
        $labelKey = $prefix.'label';

        $base = [
            $labelKey => ['required', 'string', 'max:160'],
        ];

        return match ($type) {
            NotificationChannel::TYPE_SLACK => $base + ($this->slackMode($prefix) === 'oauth'
                ? [
                    $prefix.'slack_installation_id' => ['required', 'string', 'max:26'],
                    $prefix.'slack_channel_id' => ['required', 'string', 'max:64'],
                ]
                : [
                    $prefix.'slack_webhook_url' => ['required', 'string', 'url:http,https', 'max:2048', new PubliclyRoutableUrl],
                    $prefix.'slack_channel' => ['nullable', 'string', 'max:120'],
                ]),
            NotificationChannel::TYPE_DISCORD => $base + ($this->discordMode($prefix) === 'oauth'
                ? [
                    $prefix.'discord_installation_id' => ['required', 'string', 'max:26'],
                    $prefix.'discord_channel_id' => ['required', 'string', 'max:64'],
                ]
                : [
                    $prefix.'discord_webhook_url' => ['required', 'string', 'url:http,https', 'max:2048', new PubliclyRoutableUrl],
                ]),
            NotificationChannel::TYPE_EMAIL => $base + [
                $prefix.'email_address' => ['required', 'string', 'email', 'max:254'],
            ],
            NotificationChannel::TYPE_TELEGRAM => $base + ($this->telegramMode($prefix) === 'connected'
                ? [
                    $prefix.'telegram_installation_id' => ['required', 'string', 'max:26'],
                ]
                : [
                    $prefix.'telegram_bot_token' => ['required', 'string', 'max:512'],
                    $prefix.'telegram_chat_id' => ['required', 'string', 'max:64'],
                ]),
            NotificationChannel::TYPE_PUSHOVER => $base + [
                $prefix.'pushover_app_token' => ['required', 'string', 'max:64'],
                $prefix.'pushover_user_key' => ['required', 'string', 'max:64'],
            ],
            NotificationChannel::TYPE_MICROSOFT_TEAMS => $base + [
                $prefix.'teams_webhook_url' => ['required', 'string', 'url:http,https', 'max:2048', new PubliclyRoutableUrl, MicrosoftTeamsClient::urlRule()],
            ],
            NotificationChannel::TYPE_ROCKETCHAT => $base + [
                $prefix.'rocketchat_webhook_url' => ['required', 'string', 'url:http,https', 'max:2048', new PubliclyRoutableUrl],
            ],
            NotificationChannel::TYPE_GOOGLE_CHAT => $base + [
                $prefix.'google_chat_webhook_url' => ['required', 'string', 'url:http,https', 'max:2048', new PubliclyRoutableUrl],
            ],
            NotificationChannel::TYPE_MOBILE_APP => $base + [
                $prefix.'mobile_device_token' => ['required', 'string', 'max:4096'],
                $prefix.'mobile_platform' => ['required', 'string', 'in:ios,android'],
            ],
            NotificationChannel::TYPE_INTERCOM => $base + $this->intercomValidationRules($prefix),
            NotificationChannel::TYPE_PAGERDUTY => $base + $this->pagerDutyValidationRules($prefix),
            NotificationChannel::TYPE_WEBHOOK => $base + [
                $prefix.'webhook_url' => ['required', 'string', 'url:http,https', 'max:2048', new PubliclyRoutableUrl],
            ],
            default => $base,
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected function configFromInput(string $type, string $prefix): array
    {
        return match ($type) {
            NotificationChannel::TYPE_SLACK => $this->slackMode($prefix) === 'oauth'
                ? $this->slackOauthConfigFromInput($prefix)
                : [
                    'webhook_url' => $this->{$prefix.'slack_webhook_url'},
                    'channel' => $this->{$prefix.'slack_channel'} ?: null,
                ],
            NotificationChannel::TYPE_DISCORD => $this->discordMode($prefix) === 'oauth'
                ? $this->discordOauthConfigFromInput($prefix)
                : [
                    'webhook_url' => $this->{$prefix.'discord_webhook_url'},
                ],
            NotificationChannel::TYPE_EMAIL => [
                'email' => $this->{$prefix.'email_address'},
            ],
            NotificationChannel::TYPE_TELEGRAM => $this->telegramMode($prefix) === 'connected'
                ? $this->telegramConnectedConfigFromInput($prefix)
                : [
                    'bot_token' => $this->{$prefix.'telegram_bot_token'},
                    'chat_id' => $this->{$prefix.'telegram_chat_id'},
                ],
            NotificationChannel::TYPE_PUSHOVER => [
                'app_token' => $this->{$prefix.'pushover_app_token'},
                'user_key' => $this->{$prefix.'pushover_user_key'},
            ],
            NotificationChannel::TYPE_MICROSOFT_TEAMS => [
                'webhook_url' => $this->{$prefix.'teams_webhook_url'},
            ],
            NotificationChannel::TYPE_ROCKETCHAT => [
                'webhook_url' => $this->{$prefix.'rocketchat_webhook_url'},
            ],
            NotificationChannel::TYPE_GOOGLE_CHAT => [
                'webhook_url' => $this->{$prefix.'google_chat_webhook_url'},
            ],
            NotificationChannel::TYPE_MOBILE_APP => [
                'device_token' => $this->{$prefix.'mobile_device_token'},
                'platform' => $this->{$prefix.'mobile_platform'},
            ],
            NotificationChannel::TYPE_INTERCOM => $this->intercomConfigFromInput($prefix),
            NotificationChannel::TYPE_PAGERDUTY => $this->pagerDutyConfigFromInput($prefix),
            NotificationChannel::TYPE_WEBHOOK => [
                'url' => $this->{$prefix.'webhook_url'},
            ],
            default => [],
        };
    }

    public function renderNotificationChannelsView(string $view = 'livewire.settings.notification-channels'): View
    {
        return view($view, array_merge([
            'backUrl' => null,
            'backLabel' => null,
            'useOrgShell' => false,
            'organization' => null,
            'orgShellSection' => 'notifications',
        ], $this->notificationChannelsViewData(), [
            'channels' => $this->channels,
            'canManage' => $this->canManage(),
            'types' => NotificationChannel::typesForUi(),
            'typesForEdit' => NotificationChannel::typesForUi($this->editing_id ? $this->edit_type : null),
        ]));
    }
}
