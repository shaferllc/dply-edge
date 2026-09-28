<?php

namespace App\Livewire\Status;

use App\Models\Server;
use App\Models\Site;
use App\Models\SiteUptimeMonitor;
use App\Models\StatusPage;
use App\Models\StatusPageMonitor;
use App\Services\Status\MonitorOperationalState;
use Illuminate\Support\Collection;
use Illuminate\View\View as IlluminateView;
use Laravel\Head\Facades\Head;
use Livewire\Component;

class PublicPage extends Component
{
    public StatusPage $statusPage;

    public function mount(StatusPage $statusPage): void
    {
        if (! $statusPage->is_public) {
            abort(404);
        }

        Head::title($statusPage->name.' '.__('status'))
            ->description($statusPage->description ?: __(':name system status, incidents and uptime.', ['name' => $statusPage->name]));

        $this->statusPage = $statusPage->load([
            'monitors.monitorable' => function ($morph) {
                $morph->morphWith([
                    Site::class => ['server'],
                    SiteUptimeMonitor::class => ['site'],
                ]);
            },
            'incidents' => fn ($q) => $q->orderByDesc('started_at')->with(['incidentUpdates.user']),
        ]);
    }

    public function render(): IlluminateView
    {
        $resolver = app(MonitorOperationalState::class);

        /** @var Collection<int, array{monitor: StatusPageMonitor, state: string, label: string}> $rows */
        $rows = $this->statusPage->monitors->map(function ($monitor) use ($resolver) {
            $m = $monitor->monitorable;
            if (! $m instanceof Server && ! $m instanceof Site && ! $m instanceof SiteUptimeMonitor) {
                return [
                    'monitor' => $monitor,
                    'state' => MonitorOperationalState::UNKNOWN,
                    'label' => $monitor->displayLabel(),
                ];
            }

            $state = $resolver->state($m);

            return [
                'monitor' => $monitor,
                'state' => $state,
                'label' => $monitor->displayLabel(),
            ];
        });

        $openIncidents = $this->statusPage->incidents->filter(fn ($i) => $i->resolved_at === null);

        $worstMonitor = $rows->pluck('state')->contains(MonitorOperationalState::OUTAGE)
            ? MonitorOperationalState::OUTAGE
            : ($rows->pluck('state')->contains(MonitorOperationalState::DEGRADED)
                ? MonitorOperationalState::DEGRADED
                : ($rows->pluck('state')->contains(MonitorOperationalState::UNKNOWN)
                    ? MonitorOperationalState::UNKNOWN
                    : MonitorOperationalState::OPERATIONAL));

        $banner = 'operational';
        if ($openIncidents->isNotEmpty()) {
            $banner = $openIncidents->pluck('impact')->contains('critical') || $openIncidents->pluck('impact')->contains('major')
                ? 'incident_major'
                : 'incident';
        } elseif ($worstMonitor === MonitorOperationalState::OUTAGE) {
            $banner = 'outage';
        } elseif ($worstMonitor === MonitorOperationalState::DEGRADED) {
            // Unknown (not checked yet, or stale) is shown per row; it is not
            // evidence of degradation, so it doesn't change the banner.
            $banner = 'degraded';
        }

        return view('livewire.status.public-page', [
            'rows' => $rows,
            'openIncidents' => $openIncidents,
            'resolver' => $resolver,
            'banner' => $banner,
        ])->layout('layouts.status-public', [
            'title' => $this->statusPage->name.' · '.config('app.name'),
        ]);
    }
}
