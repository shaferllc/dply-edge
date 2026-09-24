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

class RateLimits extends Component
{
    use DispatchesToastNotifications;
    use MountsEdgeWorkspaceSection;
    use PublishesEdgeHostMap;

    public bool $enabled = false;

    /** @var list<array{path: string, limit: int, window_seconds: int, action: string}> */
    public array $rules = [];

    public function mount(Server $server, Site $site): void
    {
        $this->mountEdgeWorkspaceSection($server, $site);
        $cfg = is_array($site->edgeMeta()['rate_limit'] ?? null) ? $site->edgeMeta()['rate_limit'] : [];
        $this->enabled = (bool) ($cfg['enabled'] ?? false);
        $rules = is_array($cfg['rules'] ?? null) ? $cfg['rules'] : [];
        $this->rules = $rules !== [] ? array_values(array_map(fn ($r) => [
            'path' => (string) ($r['path'] ?? '/*'),
            'limit' => (int) ($r['limit'] ?? 60),
            'window_seconds' => (int) ($r['window_seconds'] ?? 60),
            'action' => in_array(($r['action'] ?? 'block'), ['block', 'challenge'], true) ? (string) $r['action'] : 'block',
        ], $rules)) : [[
            'path' => '/*',
            'limit' => 120,
            'window_seconds' => 60,
            'action' => 'block',
        ]];
    }

    public function addRule(): void
    {
        $this->rules[] = ['path' => '/*', 'limit' => 60, 'window_seconds' => 60, 'action' => 'block'];
    }

    public function removeRule(int $index): void
    {
        unset($this->rules[$index]);
        $this->rules = array_values($this->rules);
    }

    public function save(): void
    {
        $this->authorize('update', $this->site);
        if (! $this->isManagedEdgeDelivery()) {
            $this->toastError(__('Rate limits require Dply-hosted Edge delivery.'));

            return;
        }

        $this->site->mergeEdgeMeta([
            'rate_limit' => [
                'enabled' => $this->enabled,
                'rules' => $this->rules,
            ],
        ]);
        $this->site->save();
        $this->republishEdgeHostMap();
        $this->toastSuccess(__('Rate limits saved.'));
    }

    public function render(): View
    {
        return view('livewire.sites.edge.workspace.rate-limits', array_merge(
            EdgeSiteViewData::context($this->site, 'rate-limits'),
            [
                'server' => $this->server,
                'site' => $this->site,
                'managedDelivery' => $this->isManagedEdgeDelivery(),
            ],
        ));
    }
}
