<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Livewire\Sites\EdgeSettings;
use App\Models\Site;
use App\Support\Livewire\RendersLivewirePage;
use Illuminate\Support\Facades\Gate;

class SiteWorkspaceController
{
    public function __invoke(Site $site, ?string $section = null): mixed
    {
        Gate::authorize('view', $site);

        // The Overview lives at the project URL; old /general and /overview
        // links land there.
        if (in_array($section, ['general', 'overview'], true) && request()->segment(3) === $section) {
            $query = request()->getQueryString();

            return redirect('/projects/'.$site->getRouteKey().($query ? '?'.$query : ''), 301);
        }

        return RendersLivewirePage::render(EdgeSettings::class, [
            'server' => $site->server,
            'site' => $site,
            'section' => ($section === null || $section === '') ? 'general' : $section,
        ]);
    }
}
