<?php

namespace App\Livewire\Teams;

use App\Livewire\Concerns\ManagesNotificationChannels;
use App\Models\Organization;
use App\Models\Team;
use Illuminate\Contracts\View\View;
use Laravel\Head\Facades\Head;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class NotificationChannels extends Component
{
    use ManagesNotificationChannels;

    public Organization $organization;

    public Team $team;

    public function mount(Organization $organization, Team $team): void
    {
        abort_unless($team->organization_id === $organization->id, 404);
        $this->organization = $organization;
        $this->team = $team;
        $this->authorize('viewNotificationChannels', $team);
        Head::title($organization->name.' · '.$team->name.' · '.__('Notification channels'));
        $this->syncNotificationChannelTypeDefaults();
    }

    protected function owner(): Team
    {
        return $this->team;
    }

    /**
     * @return array<string, mixed>
     */
    protected function notificationChannelsViewData(): array
    {
        return [
            'pageTitle' => __('Notification channels'),
            'intro' => __('Destinations for team-scoped notifications. Team and organization admins can manage channels.'),
            'breadcrumbs' => [
                ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
                ['label' => $this->organization->name, 'href' => route('organizations.show', $this->organization), 'icon' => 'building-office-2'],
                ['label' => __('Teams'), 'href' => route('organizations.teams', $this->organization), 'icon' => 'rectangle-group'],
                ['label' => $this->team->name, 'icon' => 'user-group'],
                ['label' => __('Notification channels'), 'icon' => 'bell-alert'],
            ],
            'backUrl' => null,
            'backLabel' => null,
            'organization' => $this->organization,
            'useOrgShell' => true,
            'orgShellSection' => 'teams',
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
