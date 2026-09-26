<?php

namespace App\Livewire\StatusPages;

use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Models\Incident;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteUptimeMonitor;
use App\Models\StatusPage;
use App\Models\StatusPageMonitor;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Manage extends Component
{
    use ConfirmsActionWithModal;
    use DispatchesToastNotifications;

    public StatusPage $statusPage;

    public string $editName = '';

    public string $editDescription = '';

    public bool $is_public = true;

    public string $monitorKind = 'server';

    public ?string $monitorId = null;

    /** @var string|null Site ULID when adding a site uptime monitor */
    public ?string $monitorSiteId = null;

    public ?string $monitorLabel = null;

    public string $incidentTitle = '';

    public string $incidentImpact = 'minor';

    public string $incidentMessage = '';

    /** @var array<string, string> */
    public array $updateBodies = [];

    public function mount(StatusPage $statusPage): void
    {
        if ($statusPage->organization_id !== auth()->user()->currentOrganization()?->id) {
            abort(404);
        }

        $this->authorize('view', $statusPage);

        $this->statusPage = $statusPage->load([
            'monitors.monitorable' => function ($morph) {
                $morph->morphWith([
                    Site::class => ['server'],
                    SiteUptimeMonitor::class => ['site'],
                ]);
            },
            'incidents.incidentUpdates.user',
        ]);

        $this->editName = $statusPage->name;
        $this->editDescription = (string) ($statusPage->description ?? '');
        $this->is_public = $statusPage->is_public;

        foreach ($this->statusPage->incidents as $incident) {
            $this->updateBodies[$incident->id] = '';
        }
    }

    public function saveDetails(): void
    {
        $this->authorize('update', $this->statusPage);

        $this->validate([
            'editName' => 'required|string|max:120',
            'editDescription' => 'nullable|string|max:2000',
            'is_public' => 'boolean',
        ]);

        $this->statusPage->update([
            'name' => $this->editName,
            'description' => $this->editDescription !== '' ? $this->editDescription : null,
            'is_public' => $this->is_public,
        ]);

        $this->statusPage->refresh();
        $this->toastSuccess(__('Status page updated.'));
    }

    public function updatedMonitorKind(): void
    {
        $this->monitorId = null;
        $this->monitorSiteId = null;
    }

    public function updatedMonitorSiteId(): void
    {
        $this->monitorId = null;
    }

    public function addMonitor(): void
    {
        $this->authorize('update', $this->statusPage);

        $this->validate([
            'monitorKind' => 'required|in:server,site,site_uptime',
            'monitorSiteId' => [
                Rule::requiredIf(fn (): bool => $this->monitorKind === 'site_uptime'),
                'nullable',
                'string',
            ],
            'monitorId' => ['required', 'string'],
            'monitorLabel' => 'nullable|string|max:120',
        ]);

        $orgId = $this->statusPage->organization_id;

        if ($this->monitorKind === 'server') {
            $server = Server::query()->where('organization_id', $orgId)->findOrFail($this->monitorId);
            $this->authorize('view', $server);
            $model = $server;
        } elseif ($this->monitorKind === 'site') {
            $site = Site::query()->where('organization_id', $orgId)->findOrFail($this->monitorId);
            $this->authorize('view', $site);
            $model = $site;
        } else {
            $site = Site::query()->where('organization_id', $orgId)->findOrFail($this->monitorSiteId);
            $this->authorize('view', $site);
            $model = SiteUptimeMonitor::query()
                ->where('site_id', $site->id)
                ->findOrFail($this->monitorId);
        }

        $exists = StatusPageMonitor::query()
            ->where('status_page_id', $this->statusPage->id)
            ->where('monitorable_type', $model::class)
            ->where('monitorable_id', $model->id)
            ->exists();

        if ($exists) {
            $this->addError('monitorId', __('Already on this status page.'));

            return;
        }

        $maxOrder = (int) StatusPageMonitor::query()->where('status_page_id', $this->statusPage->id)->max('sort_order');

        StatusPageMonitor::query()->create([
            'status_page_id' => $this->statusPage->id,
            'monitorable_type' => $model::class,
            'monitorable_id' => $model->id,
            'label' => $this->monitorLabel !== '' && $this->monitorLabel !== null ? $this->monitorLabel : null,
            'sort_order' => $maxOrder + 1,
        ]);

        $this->monitorId = null;
        $this->monitorSiteId = null;
        $this->monitorLabel = null;
        $this->statusPage->load([
            'monitors.monitorable' => function ($morph) {
                $morph->morphWith([
                    Site::class => ['server'],
                    SiteUptimeMonitor::class => ['site'],
                ]);
            },
        ]);
        $this->toastSuccess(__('Monitor added.'));
    }

    public function removeMonitor(string $monitorId): void
    {
        $this->authorize('update', $this->statusPage);

        $monitor = StatusPageMonitor::query()
            ->where('status_page_id', $this->statusPage->id)
            ->findOrFail($monitorId);

        $monitor->delete();
        $this->statusPage->load([
            'monitors.monitorable' => function ($morph) {
                $morph->morphWith([
                    Site::class => ['server'],
                    SiteUptimeMonitor::class => ['site'],
                ]);
            },
        ]);
        $this->toastSuccess(__('Monitor removed.'));
    }

    public function createIncident(): void
    {
        $this->authorize('update', $this->statusPage);

        $this->validate([
            'incidentTitle' => 'required|string|max:200',
            'incidentImpact' => 'required|in:none,minor,major,critical',
            'incidentMessage' => 'required|string|max:10000',
        ]);

        $incident = Incident::query()->create([
            'status_page_id' => $this->statusPage->id,
            'user_id' => auth()->id(),
            'title' => $this->incidentTitle,
            'impact' => $this->incidentImpact,
            'state' => Incident::STATE_INVESTIGATING,
            'started_at' => now(),
        ]);

        $incident->incidentUpdates()->create([
            'user_id' => auth()->id(),
            'body' => $this->incidentMessage,
        ]);

        $this->reset('incidentTitle', 'incidentMessage');
        $this->incidentImpact = 'minor';
        $this->updateBodies[$incident->id] = '';

        $this->statusPage->load('incidents.incidentUpdates.user');
        $this->toastSuccess(__('Incident created.'));
    }

    public function addIncidentUpdate(string $incidentId): void
    {
        $incident = Incident::query()->where('status_page_id', $this->statusPage->id)->findOrFail($incidentId);
        $this->authorize('update', $incident);

        $body = trim($this->updateBodies[$incidentId] ?? '');
        if ($body === '') {
            return;
        }

        $incident->incidentUpdates()->create([
            'user_id' => auth()->id(),
            'body' => $body,
        ]);

        $this->updateBodies[$incidentId] = '';
        $this->statusPage->load('incidents.incidentUpdates.user');
        $this->toastSuccess(__('Update posted.'));
    }

    public function setIncidentState(string $incidentId, string $state): void
    {
        $incident = Incident::query()->where('status_page_id', $this->statusPage->id)->findOrFail($incidentId);
        $this->authorize('update', $incident);

        $allowed = [
            Incident::STATE_INVESTIGATING,
            Incident::STATE_IDENTIFIED,
            Incident::STATE_MONITORING,
            Incident::STATE_RESOLVED,
        ];
        if (! in_array($state, $allowed, true)) {
            return;
        }

        $resolvedAt = $state === Incident::STATE_RESOLVED ? now() : null;

        $incident->update([
            'state' => $state,
            'resolved_at' => $resolvedAt,
        ]);

        $this->statusPage->load('incidents.incidentUpdates.user');
        $this->toastSuccess(__('Incident updated.'));
    }

    public function destroyPage(): void
    {
        $this->authorize('delete', $this->statusPage);

        $this->statusPage->delete();
        $this->toastSuccess(__('Status page deleted.'));

        $this->redirect(route('status-pages.index'), navigate: true);
    }

    public function render(): View
    {
        $orgId = $this->statusPage->organization_id;

        $servers = Server::query()->where('organization_id', $orgId)->orderBy('name')->get();
        $sites = Site::query()->where('organization_id', $orgId)->orderBy('name')->get();

        $uptimeMonitorsForPicker = collect();
        if ($this->monitorKind === 'site_uptime' && $this->monitorSiteId) {
            $uptimeMonitorsForPicker = SiteUptimeMonitor::query()
                ->where('site_id', $this->monitorSiteId)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get();
        }

        return view('livewire.status-pages.manage', [
            'servers' => $servers,
            'sites' => $sites,
            'uptimeMonitorsForPicker' => $uptimeMonitorsForPicker,
        ]);
    }
}
