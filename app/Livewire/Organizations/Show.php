<?php

namespace App\Livewire\Organizations;

use App\Models\Organization;
use Illuminate\Contracts\View\View;
use Laravel\Head\Facades\Head;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Show extends Component
{
    public Organization $organization;

    public function mount(Organization $organization): void
    {
        $this->authorize('view', $organization);
        Head::title($organization->name);
        $this->organization = $organization;
        // The route-bound model is already fresh — only the relations need
        // loading. Skipping fresh() here avoids a duplicate organizations SELECT.
        $this->refreshOrganization(fresh: false);
    }

    protected function refreshOrganization(bool $fresh = true): void
    {
        $this->organization = ($fresh ? $this->organization->fresh() : $this->organization)
            ->loadCount(['servers', 'sites'])
            ->load([
                'users',
                'teams',
                'invitations' => fn ($q) => $q->where('expires_at', '>', now()),
                'apiTokens',
                'notificationChannels',
            ]);
    }

    public function render(): View
    {
        return view('livewire.organizations.show');
    }
}
