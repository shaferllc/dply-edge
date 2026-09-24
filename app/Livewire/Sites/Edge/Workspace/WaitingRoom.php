<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Edge\Workspace;

use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Livewire\Concerns\Edge\MountsEdgeWorkspaceSection;
use App\Livewire\Concerns\Edge\PublishesEdgeHostMap;
use App\Models\Server;
use App\Models\Site;
use App\Support\Sites\EdgeSiteViewData;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class WaitingRoom extends Component
{
    use DispatchesToastNotifications;
    use MountsEdgeWorkspaceSection;
    use PublishesEdgeHostMap;

    public bool $enabled = false;

    public int $total_active_users = 200;

    public int $new_users_per_minute = 20;

    public int $session_duration_minutes = 30;

    public string $paths = '/*';

    public function mount(Server $server, Site $site): void
    {
        $this->mountEdgeWorkspaceSection($server, $site);
        $cfg = is_array($site->edgeMeta()['waiting_room'] ?? null) ? $site->edgeMeta()['waiting_room'] : [];
        $this->enabled = (bool) ($cfg['enabled'] ?? false);
        $this->total_active_users = max(1, (int) ($cfg['total_active_users'] ?? 200));
        $this->new_users_per_minute = max(1, (int) ($cfg['new_users_per_minute'] ?? 20));
        $this->session_duration_minutes = max(1, (int) ($cfg['session_duration_minutes'] ?? 30));
        $paths = is_array($cfg['paths'] ?? null) ? $cfg['paths'] : ['/*'];
        $this->paths = implode("\n", array_map('strval', $paths));
    }

    public function save(): void
    {
        $this->authorize('update', $this->site);
        if (! $this->isManagedEdgeDelivery()) {
            $this->toastError(__('Waiting room requires Dply-hosted Edge delivery.'));

            return;
        }

        $this->validate([
            'total_active_users' => ['required', 'integer', 'min:1', 'max:100000'],
            'new_users_per_minute' => ['required', 'integer', 'min:1', 'max:10000'],
            'session_duration_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'paths' => ['required', 'string', 'max:2000'],
        ]);

        $pathList = array_values(array_filter(array_map(
            static fn (string $line): string => trim($line),
            preg_split('/\r\n|\r|\n/', $this->paths) ?: [],
        )));

        $this->site->mergeEdgeMeta([
            'waiting_room' => [
                'enabled' => $this->enabled,
                'total_active_users' => $this->total_active_users,
                'new_users_per_minute' => $this->new_users_per_minute,
                'session_duration_minutes' => $this->session_duration_minutes,
                'paths' => $pathList !== [] ? $pathList : ['/*'],
            ],
        ]);
        $this->site->save();
        $this->republishEdgeHostMap();
        $this->toastSuccess(__('Waiting room saved.'));
    }

    public function render(): View
    {
        return view('livewire.sites.edge.workspace.waiting-room', array_merge(
            EdgeSiteViewData::context($this->site, 'waiting-room'),
            [
                'server' => $this->server,
                'site' => $this->site,
                'managedDelivery' => $this->isManagedEdgeDelivery(),
            ],
        ));
    }
}
