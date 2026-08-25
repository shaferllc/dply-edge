<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Livewire\Sites\EdgeSettings;
use App\Models\Server;
use App\Models\Site;
use App\Support\Livewire\RendersLivewirePage;
use Illuminate\Support\Facades\Gate;

class SiteWorkspaceController
{
    public function __invoke(Server $server, Site $site, ?string $section = null): mixed
    {
        abort_unless($site->server_id === $server->id, 404);
        Gate::authorize('view', $site);

        return RendersLivewirePage::render(EdgeSettings::class, [
            'server' => $server,
            'site' => $site,
            'section' => ($section === null || $section === '') ? 'general' : $section,
        ]);
    }
}
