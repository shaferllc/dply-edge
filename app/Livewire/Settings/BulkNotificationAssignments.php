<?php

namespace App\Livewire\Settings;

use App\Livewire\Concerns\BuildsIntercomChannelInput;
use App\Livewire\Concerns\BuildsPagerDutyChannelInput;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Models\NotificationChannel;
use App\Models\NotificationSubscription;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Modules\Notifications\Channels\Intercom\IntercomMessage;
use App\Modules\Notifications\Channels\PagerDuty\PagerDutyMessage;
use App\Modules\Notifications\Services\AssignableNotificationChannels;
use App\Modules\Notifications\Services\MicrosoftTeamsClient;
use App\Support\NotificationSubscriptionRules;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.settings')]
class BulkNotificationAssignments extends Component
{
    use BuildsIntercomChannelInput;
    use BuildsPagerDutyChannelInput;
    use DispatchesToastNotifications;

    /** @var list<int|string> */
    public array $selected_channel_ids = [];

    /** @var list<string> */
    public array $selected_event_keys = [];

    /** @var list<int|string> */
    public array $selected_server_ids = [];

    /** @var list<int|string> */
    public array $selected_site_ids = [];

    public ?string $context_server_id = null;

    public ?string $context_site_id = null;

    public bool $showQuickNotificationChannelModal = false;

    public string $quick_new_owner_scope = 'personal';

    public string $quick_new_type = NotificationChannel::TYPE_SLACK;

    public string $quick_new_label = '';

    public string $quick_new_slack_webhook_url = '';

    public string $quick_new_slack_channel = '';

    public string $quick_new_discord_webhook_url = '';

    public string $quick_new_email_address = '';

    public string $quick_new_telegram_bot_token = '';

    public string $quick_new_telegram_chat_id = '';

    public string $quick_new_pushover_app_token = '';

    public string $quick_new_pushover_user_key = '';

    public string $quick_new_teams_webhook_url = '';

    public string $quick_new_rocketchat_webhook_url = '';

    public string $quick_new_google_chat_webhook_url = '';

    public string $quick_new_mobile_device_token = '';

    public string $quick_new_mobile_platform = 'ios';

    public string $quick_new_intercom_access_token = '';

    public string $quick_new_intercom_region = 'us';

    public string $quick_new_intercom_admin_id = '';

    public string $quick_new_intercom_recipient = '';

    public string $quick_new_intercom_recipient_type = NotificationChannel::INTERCOM_TO_USER_EMAIL;

    public string $quick_new_intercom_message_type = IntercomMessage::TYPE_INAPP;

    public string $quick_new_intercom_template = IntercomMessage::TEMPLATE_PLAIN;

    public string $quick_new_intercom_subject = '';

    public string $quick_new_pagerduty_routing_key = '';

    public string $quick_new_pagerduty_region = 'us';

    public string $quick_new_pagerduty_default_severity = PagerDutyMessage::SEVERITY_ERROR;

    public string $quick_new_pagerduty_source = '';

    public string $quick_new_pagerduty_component = '';

    public string $quick_new_pagerduty_group = '';

    public string $quick_new_webhook_url = '';

    public function mount(): void
    {
        $org = Auth::user()?->currentOrganization();
        $types = NotificationChannel::typesForUi();
        if ($types !== []) {
            $this->quick_new_type = $types[0];
        }
        $this->quick_new_owner_scope = $this->canManageOrganizationNotificationChannels() ? 'organization' : 'personal';
        $serverId = request()->string('server')->toString();
        $siteId = request()->string('site')->toString();

        if ($org && $serverId !== '' && Server::query()->where('organization_id', $org->id)->whereKey($serverId)->exists()) {
            $this->context_server_id = $serverId;
            $this->selected_server_ids = [$serverId];
        }

        if ($org && $siteId !== '' && Site::query()->where('organization_id', $org->id)->whereKey($siteId)->exists()) {
            $this->context_site_id = $siteId;
            $this->selected_site_ids = [$siteId];
        }
    }

    /**
     * @return Collection<int, NotificationChannel>
     */
    protected function channelsForUser()
    {
        return AssignableNotificationChannels::forUser(Auth::user(), Auth::user()->currentOrganization());
    }

    /**
     * @return Collection<int, Server>
     */
    protected function serversForCurrentOrg(?Organization $org)
    {
        if (! $org) {
            return collect();
        }

        return Server::query()
            ->where('organization_id', $org->id)
            ->orderBy('name')
            ->get()
            ->filter(fn (Server $s) => Gate::allows('view', $s));
    }

    /**
     * @return Collection<int, Site>
     */
    protected function sitesForCurrentOrg(?Organization $org)
    {
        if (! $org) {
            return collect();
        }

        return Site::query()
            ->where('organization_id', $org->id)
            ->orderBy('name')
            ->get()
            ->filter(fn (Site $s) => Gate::allows('view', $s));
    }

    public function selectAllChannels(): void
    {
        $this->selected_channel_ids = $this->channelsForUser()->pluck('id')->map(fn ($id) => (string) $id)->values()->all();
    }

    public function deselectAllChannels(): void
    {
        $this->selected_channel_ids = [];
    }

    public function selectAllEvents(): void
    {
        $keys = [];
        foreach (config('notification_events.categories', []) as $cat) {
            foreach ($cat['events'] as $k => $_) {
                $keys[] = $k;
            }
        }
        $this->selected_event_keys = $keys;
    }

    public function deselectAllEvents(): void
    {
        $this->selected_event_keys = [];
    }

    public function selectAllServers(): void
    {
        $org = Auth::user()->currentOrganization();
        $this->selected_server_ids = $this->serversForCurrentOrg($org)->pluck('id')->map(fn ($id) => (string) $id)->values()->all();
    }

    public function deselectAllServers(): void
    {
        $this->selected_server_ids = [];
    }

    public function selectAllSites(): void
    {
        $org = Auth::user()->currentOrganization();
        $this->selected_site_ids = $this->sitesForCurrentOrg($org)->pluck('id')->map(fn ($id) => (string) $id)->values()->all();
    }

    public function deselectAllSites(): void
    {
        $this->selected_site_ids = [];
    }

    public function canSubmitAssign(): bool
    {
        if ($this->selected_channel_ids === [] || $this->selected_event_keys === []) {
            return false;
        }

        if (Auth::user()->currentOrganization() === null) {
            return false;
        }

        $needsServers = false;
        $needsSites = false;
        foreach ($this->selected_event_keys as $event) {
            $class = NotificationSubscriptionRules::subscribableClassForEvent($event);
            if ($class === Server::class) {
                $needsServers = true;
            }
            if ($class === Site::class) {
                $needsSites = true;
            }
        }

        if ($needsServers && $this->selected_server_ids === []) {
            return false;
        }
        if ($needsSites && $this->selected_site_ids === []) {
            return false;
        }

        return true;
    }

    public function assign(): void
    {
        $this->resetErrorBag();
        $this->validate([
            'selected_channel_ids' => ['required', 'array', 'min:1'],
            'selected_channel_ids.*' => ['string', 'exists:notification_channels,id'],
            'selected_event_keys' => ['required', 'array', 'min:1'],
            'selected_event_keys.*' => ['string', 'max:80'],
            'selected_server_ids' => ['array'],
            'selected_server_ids.*' => ['string', 'exists:servers,id'],
            'selected_site_ids' => ['array'],
            'selected_site_ids.*' => ['string', 'exists:sites,id'],
        ], [], [
            'selected_channel_ids' => __('channels'),
            'selected_event_keys' => __('notification types'),
        ]);

        $allowedIds = $this->channelsForUser()->pluck('id')->all();
        foreach ($this->selected_channel_ids as $cid) {
            if (! in_array((string) $cid, $allowedIds, true)) {
                $this->addError('selected_channel_ids', __('Invalid channel selected.'));

                return;
            }
        }

        $validEvents = [];
        foreach (config('notification_events.categories', []) as $cat) {
            foreach ($cat['events'] as $k => $_) {
                $validEvents[] = $k;
            }
        }
        foreach ($this->selected_event_keys as $ek) {
            if (! in_array($ek, $validEvents, true)) {
                $this->addError('selected_event_keys', __('Invalid notification type.'));

                return;
            }
        }

        $needsServers = false;
        $needsSites = false;
        foreach ($this->selected_event_keys as $event) {
            $class = NotificationSubscriptionRules::subscribableClassForEvent($event);
            if ($class === Server::class) {
                $needsServers = true;
            }
            if ($class === Site::class) {
                $needsSites = true;
            }
        }

        if ($needsServers && $this->selected_server_ids === []) {
            $this->addError('selected_server_ids', __('Select at least one server for the chosen notification types.'));

            return;
        }
        if ($needsSites && $this->selected_site_ids === []) {
            $this->addError('selected_site_ids', __('Select at least one site for the chosen notification types.'));

            return;
        }

        $org = Auth::user()->currentOrganization();
        if (! $org) {
            $this->addError('selected_channel_ids', __('Choose a current organization (switch org in the header) to assign server or site targets.'));

            return;
        }

        $created = 0;

        DB::transaction(function () use (&$created, $org): void {
            foreach ($this->selected_channel_ids as $cid) {
                $channel = NotificationChannel::query()->findOrFail((string) $cid);
                Gate::authorize('manageNotificationChannels', $channel->owner);

                foreach ($this->selected_event_keys as $event) {
                    $class = NotificationSubscriptionRules::subscribableClassForEvent($event);
                    if ($class === Server::class) {
                        foreach ($this->selected_server_ids as $sid) {
                            $server = Server::query()->where('organization_id', $org->id)->findOrFail((string) $sid);
                            Gate::authorize('view', $server);
                            $row = NotificationSubscription::firstOrCreate([
                                'notification_channel_id' => $channel->id,
                                'subscribable_type' => Server::class,
                                'subscribable_id' => $server->id,
                                'event_key' => $event,
                            ]);
                            if ($row->wasRecentlyCreated) {
                                $created++;
                            }
                        }
                    } elseif ($class === Site::class) {
                        foreach ($this->selected_site_ids as $siteId) {
                            $site = Site::query()->where('organization_id', $org->id)->findOrFail((string) $siteId);
                            Gate::authorize('view', $site);
                            $row = NotificationSubscription::firstOrCreate([
                                'notification_channel_id' => $channel->id,
                                'subscribable_type' => Site::class,
                                'subscribable_id' => $site->id,
                                'event_key' => $event,
                            ]);
                            if ($row->wasRecentlyCreated) {
                                $created++;
                            }
                        }
                    }
                }
            }
        });

        $this->toastSuccess(__('Assignments saved. :count new subscription(s) added.', ['count' => $created]));
    }

    public function createQuickNotificationChannel(): void
    {
        $owner = $this->quickNotificationChannelOwner();

        Gate::authorize('manageNotificationChannels', $owner);

        $rules = array_merge(
            [
                'quick_new_type' => ['required', 'string', Rule::in(NotificationChannel::typesForUi())],
                'quick_new_owner_scope' => ['required', 'string', Rule::in($this->quickAddOwnerScopes())],
            ],
            $this->quickChannelValidationRulesForType($this->quick_new_type)
        );

        $this->validate($rules, [], $this->quickChannelValidationAttributes());

        $channel = $owner->notificationChannels()->create([
            'type' => $this->quick_new_type,
            'label' => $this->quick_new_label,
            'config' => $this->quickChannelConfigFromInput(),
        ]);

        $this->selected_channel_ids = array_values(array_unique([
            ...$this->selected_channel_ids,
            (string) $channel->id,
        ]));

        $this->resetQuickNotificationChannelFields();
        $this->showQuickNotificationChannelModal = false;
        $this->toastSuccess(__('Channel created and selected for assignment.'));
    }

    public function openQuickNotificationChannelModal(): void
    {
        $this->resetErrorBag();
        $this->showQuickNotificationChannelModal = true;
    }

    public function closeQuickNotificationChannelModal(): void
    {
        $this->showQuickNotificationChannelModal = false;
    }

    protected function quickNotificationChannelOwner(): User|Organization
    {
        $org = Auth::user()?->currentOrganization();

        if ($this->quick_new_owner_scope === 'organization' && $org && $this->canManageOrganizationNotificationChannels()) {
            return $org;
        }

        return Auth::user();
    }

    protected function canManageOrganizationNotificationChannels(): bool
    {
        $org = Auth::user()?->currentOrganization();

        return $org instanceof Organization && Gate::allows('manageNotificationChannels', $org);
    }

    /**
     * @return list<string>
     */
    protected function quickAddOwnerScopes(): array
    {
        return $this->canManageOrganizationNotificationChannels()
            ? ['personal', 'organization']
            : ['personal'];
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function quickChannelValidationRulesForType(string $type): array
    {
        $base = [
            'quick_new_label' => ['required', 'string', 'max:255'],
        ];

        return match ($type) {
            NotificationChannel::TYPE_SLACK => $base + [
                'quick_new_slack_webhook_url' => ['required', 'url', 'max:2000'],
                'quick_new_slack_channel' => ['nullable', 'string', 'max:255'],
            ],
            NotificationChannel::TYPE_DISCORD => $base + [
                'quick_new_discord_webhook_url' => ['required', 'url', 'max:2000'],
            ],
            NotificationChannel::TYPE_EMAIL => $base + [
                'quick_new_email_address' => ['required', 'email:rfc', 'max:255'],
            ],
            NotificationChannel::TYPE_TELEGRAM => $base + [
                'quick_new_telegram_bot_token' => ['required', 'string', 'max:255'],
                'quick_new_telegram_chat_id' => ['required', 'string', 'max:255'],
            ],
            NotificationChannel::TYPE_PUSHOVER => $base + [
                'quick_new_pushover_app_token' => ['required', 'string', 'max:255'],
                'quick_new_pushover_user_key' => ['required', 'string', 'max:255'],
            ],
            NotificationChannel::TYPE_MICROSOFT_TEAMS => $base + [
                'quick_new_teams_webhook_url' => ['required', 'url', 'max:2000', MicrosoftTeamsClient::urlRule()],
            ],
            NotificationChannel::TYPE_ROCKETCHAT => $base + [
                'quick_new_rocketchat_webhook_url' => ['required', 'url', 'max:2000'],
            ],
            NotificationChannel::TYPE_GOOGLE_CHAT => $base + [
                'quick_new_google_chat_webhook_url' => ['required', 'url', 'max:2000'],
            ],
            NotificationChannel::TYPE_MOBILE_APP => $base + [
                'quick_new_mobile_device_token' => ['required', 'string', 'max:4000'],
                'quick_new_mobile_platform' => ['required', 'string', 'in:ios,android'],
            ],
            NotificationChannel::TYPE_INTERCOM => $base + $this->intercomValidationRules('quick_new_'),
            NotificationChannel::TYPE_PAGERDUTY => $base + $this->pagerDutyValidationRules('quick_new_'),
            default => $base + [
                'quick_new_webhook_url' => ['required', 'url', 'max:2000'],
            ],
        };
    }

    /**
     * @return array<string, string>
     */
    protected function quickChannelValidationAttributes(): array
    {
        return $this->intercomValidationAttributes('quick_new_') + $this->pagerDutyValidationAttributes('quick_new_') + [
            'quick_new_owner_scope' => __('owner'),
            'quick_new_type' => __('type'),
            'quick_new_label' => __('label'),
            'quick_new_slack_webhook_url' => __('webhook URL'),
            'quick_new_slack_channel' => __('channel'),
            'quick_new_discord_webhook_url' => __('webhook URL'),
            'quick_new_email_address' => __('email address'),
            'quick_new_telegram_bot_token' => __('bot token'),
            'quick_new_telegram_chat_id' => __('chat ID'),
            'quick_new_pushover_app_token' => __('application token'),
            'quick_new_pushover_user_key' => __('user key'),
            'quick_new_teams_webhook_url' => __('webhook URL'),
            'quick_new_rocketchat_webhook_url' => __('webhook URL'),
            'quick_new_google_chat_webhook_url' => __('webhook URL'),
            'quick_new_mobile_device_token' => __('device token'),
            'quick_new_mobile_platform' => __('platform'),
            'quick_new_webhook_url' => __('URL'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function quickChannelConfigFromInput(): array
    {
        return match ($this->quick_new_type) {
            NotificationChannel::TYPE_SLACK => array_filter([
                'webhook_url' => $this->quick_new_slack_webhook_url,
                'channel' => trim($this->quick_new_slack_channel) ?: null,
            ], fn ($value) => $value !== null && $value !== ''),
            NotificationChannel::TYPE_DISCORD => ['webhook_url' => $this->quick_new_discord_webhook_url],
            NotificationChannel::TYPE_EMAIL => ['email' => $this->quick_new_email_address],
            NotificationChannel::TYPE_TELEGRAM => [
                'bot_token' => $this->quick_new_telegram_bot_token,
                'chat_id' => $this->quick_new_telegram_chat_id,
            ],
            NotificationChannel::TYPE_PUSHOVER => [
                'app_token' => $this->quick_new_pushover_app_token,
                'user_key' => $this->quick_new_pushover_user_key,
            ],
            NotificationChannel::TYPE_MICROSOFT_TEAMS => ['webhook_url' => $this->quick_new_teams_webhook_url],
            NotificationChannel::TYPE_ROCKETCHAT => ['webhook_url' => $this->quick_new_rocketchat_webhook_url],
            NotificationChannel::TYPE_GOOGLE_CHAT => ['webhook_url' => $this->quick_new_google_chat_webhook_url],
            NotificationChannel::TYPE_MOBILE_APP => [
                'device_token' => $this->quick_new_mobile_device_token,
                'platform' => $this->quick_new_mobile_platform,
            ],
            NotificationChannel::TYPE_INTERCOM => $this->intercomConfigFromInput('quick_new_'),
            NotificationChannel::TYPE_PAGERDUTY => $this->pagerDutyConfigFromInput('quick_new_'),
            default => ['url' => $this->quick_new_webhook_url],
        };
    }

    protected function resetQuickNotificationChannelFields(): void
    {
        $this->quick_new_label = '';
        $this->quick_new_slack_webhook_url = '';
        $this->quick_new_slack_channel = '';
        $this->quick_new_discord_webhook_url = '';
        $this->quick_new_email_address = '';
        $this->quick_new_telegram_bot_token = '';
        $this->quick_new_telegram_chat_id = '';
        $this->quick_new_pushover_app_token = '';
        $this->quick_new_pushover_user_key = '';
        $this->quick_new_teams_webhook_url = '';
        $this->quick_new_rocketchat_webhook_url = '';
        $this->quick_new_google_chat_webhook_url = '';
        $this->quick_new_mobile_device_token = '';
        $this->quick_new_mobile_platform = 'ios';
        $this->quick_new_intercom_access_token = '';
        $this->quick_new_intercom_region = 'us';
        $this->quick_new_intercom_admin_id = '';
        $this->quick_new_intercom_recipient = '';
        $this->quick_new_intercom_recipient_type = NotificationChannel::INTERCOM_TO_USER_EMAIL;
        $this->quick_new_intercom_message_type = IntercomMessage::TYPE_INAPP;
        $this->quick_new_intercom_template = IntercomMessage::TEMPLATE_PLAIN;
        $this->quick_new_intercom_subject = '';
        $this->quick_new_pagerduty_routing_key = '';
        $this->quick_new_pagerduty_region = 'us';
        $this->quick_new_pagerduty_default_severity = PagerDutyMessage::SEVERITY_ERROR;
        $this->quick_new_pagerduty_source = '';
        $this->quick_new_pagerduty_component = '';
        $this->quick_new_pagerduty_group = '';
        $this->quick_new_webhook_url = '';
    }

    public function render(): View
    {
        $org = Auth::user()->currentOrganization();

        return view('livewire.settings.bulk-notification-assignments', [
            'assignableChannels' => $this->channelsForUser(),
            'eventCatalog' => config('notification_events.categories', []),
            'servers' => $this->serversForCurrentOrg($org),
            'sites' => $this->sitesForCurrentOrg($org),
            'currentOrganization' => $org,
            'contextServer' => $this->context_server_id ? Server::query()->find($this->context_server_id) : null,
            'contextSite' => $this->context_site_id ? Site::query()->find($this->context_site_id) : null,
            'quickAddTypes' => NotificationChannel::typesForUi(),
            'canManageOrganizationNotificationChannels' => $this->canManageOrganizationNotificationChannels(),
        ]);
    }
}
