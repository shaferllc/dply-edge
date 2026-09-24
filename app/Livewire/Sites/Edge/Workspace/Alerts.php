<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Edge\Workspace;

use App\Livewire\Concerns\CreatesNotificationChannelInline;
use App\Livewire\Concerns\Edge\ManagesEdgeAlertsNotifications;
use App\Livewire\Concerns\Edge\MountsEdgeWorkspaceSection;
use App\Models\EdgeDeployment;
use App\Models\Server;
use App\Models\Site;
use App\Modules\Edge\Support\EdgeEffectiveAlerts;
use App\Modules\Notifications\Services\AssignableNotificationChannels;
use App\Support\EdgeSiteNotificationKeys;
use App\Support\Sites\EdgeSiteViewData;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * RUM-style alerting + channel subscriptions for Edge events.
 * Thresholds merge with dply.yaml `alerts:` ({@see EdgeEffectiveAlerts});
 * CheckEdgeRumAlertsCommand publishes `edge.rum.breach` when crossed.
 * Channel routing uses the same subscription matrix as BYO site Notifications.
 */
class Alerts extends Component
{
    use CreatesNotificationChannelInline;
    use ManagesEdgeAlertsNotifications;
    use MountsEdgeWorkspaceSection;

    public bool $lcp_enabled = false;

    #[Validate('nullable|integer|min:100|max:60000')]
    public int $lcp_threshold = 2500;

    public bool $err_rate_enabled = false;

    #[Validate('nullable|numeric|min:0.1|max:100')]
    public float $err_rate_threshold = 5.0;

    public bool $err_count_enabled = false;

    #[Validate('nullable|integer|min:1|max:1000000')]
    public int $err_count_threshold = 50;

    public function mount(Server $server, Site $site): void
    {
        $this->mountEdgeWorkspaceSection($server, $site);
        $this->hydrateEdgeAlertNotificationPreferences();

        $effective = EdgeEffectiveAlerts::for($site);
        $this->lcp_enabled = $effective['lcp_p75_ms']['enabled'];
        $this->lcp_threshold = (int) $effective['lcp_p75_ms']['threshold'];
        $this->err_rate_enabled = $effective['error_rate']['enabled'];
        $this->err_rate_threshold = (float) $effective['error_rate']['threshold'];
        $this->err_count_enabled = $effective['five_xx_count']['enabled'];
        $this->err_count_threshold = (int) $effective['five_xx_count']['threshold'];
    }

    public function save(): void
    {
        $this->authorize('update', $this->site);
        $this->validate();

        $previous = is_array($this->site->edgeMeta()['alerts'] ?? null) ? $this->site->edgeMeta()['alerts'] : [];

        $this->site->mergeEdgeMeta([
            'alerts' => [
                'lcp_p75_ms' => ['enabled' => $this->lcp_enabled, 'threshold' => $this->lcp_threshold],
                'error_rate' => ['enabled' => $this->err_rate_enabled, 'threshold' => $this->err_rate_threshold],
                'five_xx_count' => ['enabled' => $this->err_count_enabled, 'threshold' => $this->err_count_threshold],
            ],
        ]);
        $this->site->save();

        audit_log(
            $this->site->organization,
            auth()->user(),
            'site.edge.alerts.updated',
            $this->site,
            ['alerts' => $previous],
            ['alerts' => $this->site->edgeMeta()['alerts']],
        );

        $this->toastSuccess(__('Alert thresholds saved.'));
    }

    public function render(): View
    {
        $latestLive = EdgeDeployment::query()
            ->where('site_id', $this->site->id)
            ->where('status', EdgeDeployment::STATUS_LIVE)
            ->latest('id')
            ->first()
            ?: EdgeDeployment::query()
                ->where('site_id', $this->site->id)
                ->whereNotNull('repo_config')
                ->latest('id')
                ->first();

        $repoAlerts = [];
        $sourcePath = 'dply.yaml';
        if ($latestLive !== null && is_array($latestLive->repo_config)) {
            $repoAlerts = is_array($latestLive->repo_config['alerts'] ?? null) ? $latestLive->repo_config['alerts'] : [];
            $sourcePath = is_string($latestLive->repo_config['source_path'] ?? null)
                ? (string) $latestLive->repo_config['source_path']
                : 'dply.yaml';
        }

        return view('livewire.sites.edge.workspace.alerts', array_merge(
            EdgeSiteViewData::context($this->site, 'alerts'),
            [
                'server' => $this->server,
                'site' => $this->site,
                'repoAlerts' => $repoAlerts,
                'sourcePath' => $sourcePath,
                'assignableNotificationChannels' => AssignableNotificationChannels::forUser(
                    auth()->user(),
                    $this->site->organization,
                ),
                'notificationEventGroups' => EdgeSiteNotificationKeys::eventGroups(),
            ],
        ));
    }
}
