<?php

namespace App\Livewire\StatusPages;

use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Models\StatusPage;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Index extends Component
{
    use DispatchesToastNotifications;

    public string $name = '';

    public string $description = '';

    public function openCreateStatusPageModal(): void
    {
        $this->authorize('create', StatusPage::class);

        $this->name = '';
        $this->description = '';
        $this->resetValidation(['name', 'description']);
        $this->dispatch('open-modal', 'create-status-page-modal');
    }

    public function closeCreateStatusPageModal(): void
    {
        $this->name = '';
        $this->description = '';
        $this->resetValidation(['name', 'description']);
        $this->dispatch('close-modal', 'create-status-page-modal');
    }

    public function createPage(): void
    {
        $this->authorize('create', StatusPage::class);

        $user = auth()->user();
        $org = $user->currentOrganization();
        if (! $org) {
            $this->toastError(__('Select an organization first.'));

            return;
        }

        $this->validate([
            'name' => 'required|string|max:120',
            'description' => 'nullable|string|max:2000',
        ]);

        $org->statusPages()->create([
            'user_id' => $user->id,
            'name' => $this->name,
            'description' => $this->description !== '' ? $this->description : null,
            'is_public' => true,
        ]);

        $this->reset('name', 'description');
        $this->toastSuccess(__('Status page created.'));
        $this->dispatch('close-modal', 'create-status-page-modal');
    }

    public function render(): View
    {
        $org = auth()->user()->currentOrganization();
        $pages = $org
            ? $org->statusPages()->orderBy('name')->get()
            : collect();

        return view('livewire.status-pages.index', [
            'pages' => $pages,
            'pagesTotal' => $pages->count(),
            'hasOrganization' => $org !== null,
            'canCreateStatusPage' => auth()->user()?->can('create', StatusPage::class) ?? false,
        ]);
    }
}
