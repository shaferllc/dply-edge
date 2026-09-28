<?php

namespace App\Livewire\Organizations;

use App\Models\Organization;
use Laravel\Head\Facades\Head;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Teams live on the People page (Members) since the org redesign 2026-09-27.
 * The route `organizations.teams` stays for its many call sites and lands on
 * People with a team selected: `?team=` if given, else the first team.
 */
#[Layout('layouts.app')]
class Teams extends Component
{
    public function mount(Organization $organization): void
    {
        $this->authorize('view', $organization);
        Head::title($organization->name.' · '.__('Teams'));

        $team = (string) request()->query('team', '');
        if ($team === '') {
            $team = (string) ($organization->teams()->orderBy('name')->value('id') ?? '');
        }

        $this->redirectRoute('organizations.members', array_filter([
            'organization' => $organization,
            'team' => $team,
        ]));
    }

    public function render(): string
    {
        return '<div></div>';
    }
}
