<?php

namespace App\Livewire\Organizations;

use App\Livewire\Concerns\ManagesNotificationChannels;
use App\Models\Organization;
use Illuminate\Contracts\View\View;
use Laravel\Head\Facades\Head;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class NotificationChannels extends Component
{
    use ManagesNotificationChannels;

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
     * @return array<string, mixed>
     */
    protected function notificationChannelsViewData(): array
    {
        return [
            'pageTitle' => __('Notification channels'),
            // The old copy described the plumbing ("webhooks and chat
            // destinations") rather than what the page is for. Lead with the
            // outcome, and name the scope benefit that makes an org channel
            // different from a personal one.
            'intro' => __('Where this organization\'s alerts go — chat, email, pagers, and webhooks. Channels added here are shared: any admin can route events to them, and they outlive individual accounts.'),
            'breadcrumbs' => [
                ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
                ['label' => $this->organization->name, 'href' => route('organizations.show', $this->organization), 'icon' => 'building-office-2'],
                ['label' => __('Notification channels'), 'icon' => 'bell-alert'],
            ],
            'backUrl' => null,
            'backLabel' => null,
            'organization' => $this->organization,
            'useOrgShell' => true,
            'orgShellSection' => 'notifications',
            'showBulkAssign' => false,
            'currentOrganization' => null,
            'organizationChannels' => collect(),
            'teamChannelGroups' => collect(),
        ];
    }

    public function render(): View
    {
        return $this->renderNotificationChannelsView();
    }
}
