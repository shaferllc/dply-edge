<?php

namespace App\Livewire\Organizations;

use App\Livewire\Concerns\ManagesNotificationChannels;
use App\Models\Organization;
use App\Support\NotificationSubscriptionMatrix;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Laravel\Head\Facades\Head;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Org Notifications — "routing matrix" (redesign 2026-09-27): rows are events,
 * columns are the org's channels, each cell an org-wide
 * {@see \App\Models\NotificationSubscription} (subscribable = the Organization),
 * which NotificationRoutingResolver fans out for every site in the org.
 */
#[Layout('layouts.app')]
class NotificationChannels extends Component
{
    use ManagesNotificationChannels;

    /**
     * Matrix rows: event key => [label, one-line help]. Only events that are
     * published with an org-scoped subject, so an org-wide subscription can
     * actually fire. Left out on purpose: account.git_token.unhealthy (published
     * with no subject, so it only reaches the bell) and the VM-era site.* keys
     * nothing publishes any more. Any new edge.* key in the config appears too,
     * under its config label.
     */
    private const EVENTS = [
        'edge.deploy.failed' => ['Deploy failed', 'Build or publish error'],
        'edge.deploy.succeeded' => ['Deploy succeeded', 'Every production deploy'],
        'edge.deploy.duration_regressed' => ['Deploy got slower', 'Build time jumped against recent deploys'],
        'site.uptime.down' => ['App down', 'Uptime check failed, and again on recovery'],
        'site.uptime.degraded' => ['App slow', 'Uptime check responses are slow'],
        'site.ssl.expiring' => ['Certificate expiring', 'A certificate is close to expiry'],
        'edge.domain.failing' => ['Custom domain failing', 'Domain verification keeps failing'],
        'edge.domain.verified' => ['Custom domain verified', 'A custom domain went live'],
        'edge.usage.over_budget' => ['Usage alert', 'Spend passed your budget or trial credit'],
        'edge.rum.breach' => ['Real-user metric breached', 'A web-vitals threshold was crossed'],
        'edge.workers.failed_jobs' => ['Queue jobs failing', 'Jobs are landing in failed jobs'],
        'edge.workers.crashing' => ['Queue workers crashing', 'Workers keep exiting'],
        'edge.database.disk_filling' => ['Database disk filling', 'Over 80% full'],
        'edge.database.connections_high' => ['Database connections high', 'Near the connection limit'],
        'site.errors.operation_failed' => ['Operation failed', 'A background app operation errored'],
        'account.provider_credential.unhealthy' => ['Cloud API token rejected', 'A provider stopped accepting a credential'],
    ];

    public Organization $organization;

    public function mount(Organization $organization): void
    {
        $this->organization = $organization;
        $this->authorize('viewNotificationChannels', $organization);
        Head::title($organization->name.' · '.__('Notification channels'));
        $this->syncNotificationChannelTypeDefaults();
    }

    protected function owner(): Organization
    {
        return $this->organization;
    }

    /**
     * @return list<array{key: string, label: string, help: string}>
     */
    public static function routingEvents(): array
    {
        $known = [];
        foreach ((array) config('notification_events.categories', []) as $category) {
            foreach (array_keys((array) ($category['events'] ?? [])) as $key) {
                $known[(string) $key] = true;
            }
        }

        $rows = [];
        foreach (self::EVENTS as $key => [$label, $help]) {
            if (isset($known[$key])) {
                $rows[] = ['key' => $key, 'label' => __($label), 'help' => __($help)];
            }
        }
        foreach ((array) config('notification_events.categories.edge.events', []) as $key => $label) {
            if (! array_key_exists((string) $key, self::EVENTS)) {
                $rows[] = ['key' => (string) $key, 'label' => (string) $label, 'help' => ''];
            }
        }

        return $rows;
    }

    /**
     * @return list<string>
     */
    private function routingKeys(): array
    {
        return array_column(self::routingEvents(), 'key');
    }

    /** One cell click: add or remove the org-wide subscription, saved immediately. */
    public function toggleRoute(string $channelId, string $eventKey): void
    {
        Gate::authorize('manageNotificationChannels', $this->organization);

        $keys = $this->routingKeys();
        abort_unless(in_array($eventKey, $keys, true), 404);
        $channels = $this->owner()->notificationChannels()->whereKey($channelId)->get();
        abort_if($channels->isEmpty(), 404);

        $current = NotificationSubscriptionMatrix::load(Organization::class, (string) $this->organization->id, $keys, $channels)[$channelId] ?? [];
        $on = ! in_array($eventKey, $current, true);
        $desired = $on ? [...$current, $eventKey] : array_values(array_diff($current, [$eventKey]));

        $result = NotificationSubscriptionMatrix::save(Organization::class, (string) $this->organization->id, $keys, $channels, [$channelId => $desired]);

        if ($result['changed'] > 0) {
            audit_log($this->organization, Auth::user(), 'organization.notifications.subscriptions_updated', $channels->first(), null, [
                'event_key' => $eventKey,
                'routed' => $on,
            ]);
        }

        unset($this->channels);
    }

    /** Edit lives in a modal here (the matrix has no row to expand inline). */
    public function editChannel(string $id): void
    {
        $this->startEdit($id);
        $this->dispatch('open-modal', 'org-edit-channel-modal');
    }

    public function cancelEdit(): void
    {
        $this->editing_id = null;
        $this->resetErrorBag();
        $this->dispatch('close-modal', 'org-edit-channel-modal');
    }

    /**
     * @return array<string, mixed>
     */
    protected function notificationChannelsViewData(): array
    {
        $events = self::routingEvents();

        return [
            'organization' => $this->organization,
            'events' => $events,
            'matrix' => NotificationSubscriptionMatrix::load(
                Organization::class,
                (string) $this->organization->id,
                array_column($events, 'key'),
                $this->channels,
            ),
        ];
    }

    public function render(): View
    {
        return $this->renderNotificationChannelsView('livewire.organizations.notifications');
    }
}
