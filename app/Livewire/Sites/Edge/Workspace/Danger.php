<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Edge\Workspace;

use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Livewire\Concerns\Edge\ManagesEdgeDanger;
use App\Livewire\Concerns\Edge\MountsEdgeWorkspaceSection;
use App\Models\EdgeDatabase;
use App\Models\EdgeQueue;
use App\Models\Server;
use App\Models\Site;
use App\Modules\Edge\Jobs\TeardownEdgeSiteJob;
use App\Support\Sites\EdgeSiteViewData;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class Danger extends Component
{
    use DispatchesToastNotifications;
    use ManagesEdgeDanger;
    use MountsEdgeWorkspaceSection;

    public function mount(Server $server, Site $site): void
    {
        $this->mountEdgeWorkspaceSection($server, $site);
    }

    public function watchTeardown(): void
    {
        if ($this->site->status !== Site::STATUS_EDGE_DELETING) {
            return;
        }

        if (Site::query()->whereKey($this->site->id)->doesntExist()) {
            $this->redirect(route('dashboard'), navigate: true);

            return;
        }

        $this->site->refresh();
    }

    public function render(): View
    {
        return view('livewire.sites.edge.workspace.danger', array_merge(
            EdgeSiteViewData::context($this->site, 'danger'),
            [
                'server' => $this->server,
                'site' => $this->site,
                'inventory' => $this->inventory(),
                'teardownSteps' => TeardownEdgeSiteJob::STEPS,
            ],
        ));
    }

    /**
     * What delete removes and what the organization keeps, for the danger page.
     *
     * @return array<string, mixed>
     */
    private function inventory(): array
    {
        $meta = $this->site->edgeMeta();
        $domains = $meta['routing']['custom_domains'] ?? [];

        return [
            'deployments' => $this->site->edgeDeployments()->count(),
            'previews' => Site::query()
                ->where('organization_id', $this->site->organization_id)
                ->whereJsonContains('meta->edge->preview_parent_site_id', $this->site->id)
                ->count(),
            'domains' => is_array($domains) ? array_keys($domains) : [],
            'repo' => (string) ($meta['source']['repo'] ?? ''),
            'webhook' => ($meta['webhook']['hook_id'] ?? null) !== null,
            'databases' => EdgeDatabase::query()->where('organization_id', $this->site->organization_id)->count(),
            'queues' => EdgeQueue::query()->where('organization_id', $this->site->organization_id)->count(),
            'paused' => ! empty($meta['paused_at']),
            'teardown' => is_array($meta['teardown'] ?? null) ? $meta['teardown'] : [],
        ];
    }
}
