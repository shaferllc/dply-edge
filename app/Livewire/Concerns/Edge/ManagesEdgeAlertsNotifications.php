<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Edge;

use App\Livewire\Concerns\CreatesNotificationChannelInline;
use App\Models\Site;
use App\Modules\Notifications\Services\AssignableNotificationChannels;
use App\Support\EdgeSiteNotificationKeys;
use App\Support\NotificationSubscriptionMatrix;
use Livewire\Attributes\On;

/**
 * Channel subscription matrix on Edge → Alerts. Same persistence stack as BYO
 * site Notifications ({@see NotificationSubscription} + matrix load/save).
 * Pair with {@see CreatesNotificationChannelInline} on the host component.
 *
 * @property Site $site
 */
trait ManagesEdgeAlertsNotifications
{
    /**
     * Per-channel event routing: channel id → list of subscribed edge.* keys.
     *
     * @var array<string, list<string>>
     */
    public array $channelEventSelections = [];

    protected function hydrateEdgeAlertNotificationPreferences(): void
    {
        $this->loadEdgeAlertNotificationPreferences();
    }

    #[On('notification-channel-created')]
    public function onEdgeAlertNotificationChannelCreated(string $channelId = ''): void
    {
        $this->loadEdgeAlertNotificationPreferences();
    }

    protected function loadEdgeAlertNotificationPreferences(): void
    {
        $this->channelEventSelections = NotificationSubscriptionMatrix::load(
            Site::class,
            (string) $this->site->id,
            EdgeSiteNotificationKeys::eventKeys(),
            AssignableNotificationChannels::forUser(auth()->user(), $this->site->organization),
        );
    }

    /** Save the matrix without a toast; false (after an error toast) when the user may not. */
    protected function persistEdgeAlertNotificationSubscriptions(): bool
    {
        $this->authorize('update', $this->site);

        if (auth()->user()?->currentOrganization()?->userIsDeployer(auth()->user())) {
            $this->toastError(__('Deployers cannot change notification subscriptions.'));

            return false;
        }

        $changed = NotificationSubscriptionMatrix::save(
            Site::class,
            (string) $this->site->id,
            EdgeSiteNotificationKeys::eventKeys(),
            AssignableNotificationChannels::forUser(auth()->user(), auth()->user()?->currentOrganization()),
            $this->channelEventSelections,
        );

        $this->loadEdgeAlertNotificationPreferences();

        if ($changed['changed'] > 0 && $this->site->organization) {
            audit_log(
                $this->site->organization,
                auth()->user(),
                'site.notifications.subscriptions_updated',
                $this->site,
                null,
                [
                    'added' => $changed['added'],
                    'removed' => $changed['removed'],
                    'scope' => 'edge',
                ],
            );
        }

        return true;
    }
}
