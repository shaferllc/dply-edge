<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Livewire\Admin\Concerns\AuthorizesPlatformAdmin;
use App\Models\Organization;
use App\Modules\Edge\Support\EdgeContainerConnections;
use Illuminate\Contracts\View\View;
use Laravel\Pennant\Feature;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Localhost only: turn the resource feature flags (Pennant, per
 * organization) on and off. Elsewhere use php artisan dply:feature.
 */
#[Layout('layouts.admin')]
class FeatureFlags extends Component
{
    use AuthorizesPlatformAdmin;

    public string $search = '';

    public function mount(): void
    {
        abort_unless(app()->isLocal(), 404);
        $this->mountAuthorizesPlatformAdmin();
    }

    public function toggle(string $organizationId, string $kind): void
    {
        $this->guard($kind);
        $organization = Organization::query()->findOrFail($organizationId);
        $flag = EdgeContainerConnections::flag($kind);
        Feature::for($organization)->active($flag)
            ? Feature::for($organization)->deactivate($flag)
            : Feature::for($organization)->activate($flag);
    }

    /** One flag on or off for every organization. */
    public function setForAll(string $kind, bool $on): void
    {
        $this->guard($kind);
        $flag = EdgeContainerConnections::flag($kind);
        foreach (Organization::query()->cursor() as $organization) {
            $on ? Feature::for($organization)->activate($flag) : Feature::for($organization)->deactivate($flag);
        }
    }

    private function guard(string $kind): void
    {
        abort_unless(app()->isLocal(), 404);
        $this->authorizePlatformAdmin();
        abort_unless(in_array($kind, EdgeContainerConnections::FLAGGED, true), 404);
    }

    public function render(): View
    {
        $organizations = Organization::query()
            ->when($this->search !== '', fn ($q) => $q->where(fn ($q) => $q->where('name', 'ilike', '%'.$this->search.'%')->orWhere('slug', 'ilike', '%'.$this->search.'%')))
            ->orderBy('name')
            ->limit(100)
            ->get(['id', 'name', 'slug']);
        $flags = array_map(EdgeContainerConnections::flag(...), EdgeContainerConnections::FLAGGED);

        return view('livewire.admin.feature-flags', [
            'organizations' => $organizations,
            'kinds' => collect(EdgeContainerConnections::FLAGGED)->mapWithKeys(fn (string $k): array => [$k => EdgeContainerConnections::KINDS[$k]['label']])->all(),
            'states' => $organizations->mapWithKeys(fn (Organization $o): array => [$o->id => Feature::for($o)->values($flags)])->all(),
        ]);
    }
}
