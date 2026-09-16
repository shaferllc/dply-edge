<?php

declare(strict_types=1);

namespace App\Livewire\Sites;

use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Livewire\Concerns\DismissesConsoleActionRun;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Livewire\Concerns\Edge\ManagesEdgeRedeploy;
use App\Livewire\Concerns\ManagesEdgeSiteProvisioning;
use App\Livewire\Concerns\MountsSiteWorkspace;
use App\Models\Server;
use App\Models\Site;
use App\Support\Sites\EdgeProvisioningViewData;
use App\Support\Sites\SiteSettingsViewData;
use App\Support\SiteSettingsSidebar;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

#[Layout('layouts.app')]
class EdgeSettings extends Component
{
    use ConfirmsActionWithModal;
    use DismissesConsoleActionRun;
    use DispatchesToastNotifications;
    use ManagesEdgeRedeploy;
    use ManagesEdgeSiteProvisioning;
    use MountsSiteWorkspace;

    #[Locked]
    public Server $server;

    #[Locked]
    public Site $site;

    public string $section = 'general';

    protected function consoleActionSubject(): Model
    {
        return $this->site;
    }

    public function mount(Server $server, Site $site, ?string $section = null): void
    {
        if (! $site->usesEdgeRuntime()) {
            abort(404);
        }

        if ($site->server_id !== $server->id) {
            abort(404);
        }

        if ($section === null || $section === '') {
            $section = 'general';
        }

        // BYO uses `/routing`; Edge's section is `edge-routing`. Domains merged
        // into Routing as ?tab=domains — alias old URLs.
        if ($section === 'routing' || $section === 'edge-domains') {
            $this->redirect(route('sites.show', [
                'server' => $server,
                'site' => $site,
                'section' => 'edge-routing',
                'tab' => $section === 'edge-domains' ? 'domains' : request()->query('tab', 'domains'),
            ]), navigate: true);

            return;
        }

        $querySection = request()->query('section');
        if (is_string($querySection) && $querySection !== '') {
            $rest = collect(request()->query())->except('section')->all();

            $this->redirect(route('sites.show', [
                'server' => $server,
                'site' => $site,
                'section' => $querySection,
                ...$rest,
            ]), navigate: true);

            return;
        }

        $allowed = array_column(SiteSettingsSidebar::items($site, $server), 'id');
        if (! in_array($section, $allowed, true)) {
            abort(404);
        }

        $this->section = $section;

        $this->mountSiteWorkspace($server, $site);
    }

    public function render(): View
    {
        if (! $this->site->isReadyForWorkspace()) {
            $this->site->load([
                'edgeDeployments' => fn ($query) => $query->limit(1),
            ]);
            $this->server->loadMissing('workspace');

            return view('livewire.sites.edge-provisioning', EdgeProvisioningViewData::for(
                $this->server,
                $this->site,
            ));
        }

        $this->server->loadMissing('workspace');

        return view('livewire.sites.edge-settings', SiteSettingsViewData::for(
            $this->server,
            $this->site,
            $this->section,
            null,
            [],
            auth()->user(),
        ));
    }

    protected function currentEdgeSection(): ?string
    {
        return $this->section;
    }
}
