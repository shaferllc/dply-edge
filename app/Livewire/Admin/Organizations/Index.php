<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Organizations;

use App\Livewire\Admin\Concerns\AuthorizesPlatformAdmin;
use App\Models\Organization;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.admin')]
class Index extends Component
{
    use AuthorizesPlatformAdmin;
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public function mount(): void
    {
        $this->mountAuthorizesPlatformAdmin();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $this->authorizePlatformAdmin();

        $organizations = Organization::query()
            ->withCount(['servers', 'sites'])
            ->when($this->search !== '', function ($query) {
                $term = '%'.$this->search.'%';
                $query->where(function ($q) use ($term) {
                    $q->where('name', 'like', $term)
                        ->orWhere('slug', 'like', $term)
                        ->orWhere('email', 'like', $term);
                });
            })
            ->orderBy('name')
            ->paginate(20);

        return view('livewire.admin.organizations.index', [
            'organizations' => $organizations,
        ]);
    }
}
