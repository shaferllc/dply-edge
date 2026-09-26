<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Organizations;

use App\Livewire\Admin\Concerns\AuthorizesPlatformAdmin;
use App\Models\Organization;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.admin')]
class Show extends Component
{
    use AuthorizesPlatformAdmin;

    public Organization $organization;

    public function mount(Organization $organization): void
    {
        $this->mountAuthorizesPlatformAdmin();
        $this->organization = $organization;
    }

    public function render(): View
    {
        $this->authorizePlatformAdmin();

        return view('livewire.admin.organizations.show', [
            'organization' => $this->organization,
            'members' => $this->organization->users()->orderBy('name')->get(),
        ]);
    }
}
