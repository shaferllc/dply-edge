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

        if ($section === 'edge-delivery' && ($site->edgeMeta()['runtime_mode'] ?? '') === 'container') {
            $this->redirect(route('sites.show', [
                'server' => $server,
                'site' => $site,
                'section' => 'general',
            ]), navigate: true);

            return;
        }

        if ($section === 'edge-load-balancing') {
            $this->redirect(route('sites.show', [
                'server' => $server,
                'site' => $site,
                'section' => 'general',
            ]), navigate: true);

            return;
        }

        if ($section === 'edge-domains') {
            $this->redirect(route('sites.show', [
                'server' => $server,
                'site' => $site,
                'section' => 'routing',
                'tab' => 'domains',
            ]), navigate: true);

            return;
        }

        $legacySections = [
            'edge-alerts' => 'alerts',
            'edge-audit' => 'audit',
            'edge-billing' => 'billing',
            'bindings' => 'resources',
            'edge-bindings' => 'resources',
            'edge-bot-protection' => 'bot-protection',
            'edge-build' => 'build',
            'edge-container' => 'container',
            'edge-crons' => 'crons',
            'edge-delivery' => 'delivery',
            'edge-deploy-triggers' => 'deploy-triggers',
            'edge-deploys' => 'deploys',
            'edge-environment' => 'environment',
            'edge-error-pages' => 'error-pages',
            'edge-firewall' => 'firewall',
            'edge-forms' => 'forms',
            'edge-jobs' => 'jobs',
            'edge-logs' => 'logs',
            'edge-members' => 'members',
            'edge-previews' => 'previews',
            'edge-rate-limits' => 'rate-limits',
            'edge-routing' => 'routing',
            'edge-snippets' => 'snippets',
            'edge-tags' => 'tags',
            'edge-traffic' => 'traffic',
            'edge-waiting-room' => 'waiting-room',
        ];
        if (isset($legacySections[$section])) {
            $this->redirect(route('sites.show', [
                'server' => $server,
                'site' => $site,
                'section' => $legacySections[$section],
                ...collect(request()->query())->all(),
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
