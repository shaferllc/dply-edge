<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Edge\Workspace;

use App\Livewire\Concerns\CreatesNotificationChannelInline;
use App\Livewire\Concerns\Edge\ManagesEdgeAlertsNotifications;
use App\Livewire\Concerns\Edge\MountsEdgeWorkspaceSection;
use App\Models\EdgeDeployment;
use App\Models\NotificationChannel;
use App\Models\NotificationEvent;
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

    /** Rule key open in the edit modal ({@see self::RULES}), or null. */
    public ?string $editingRule = null;

    /**
     * One row per event family: `edge.<family>.*` keys, read as a sentence.
     * Keys outside these families land in an "other" rule so nothing is hidden.
     *
     * @var array<string, string>
     */
    private const RULES = [
        'deploy' => 'When a deploy fails, slows down or succeeds',
        'domain' => 'When a custom domain verifies or starts failing',
        'rum' => 'When a real-user metric crosses a threshold',
        'usage' => 'When usage goes over budget',
        'workers' => 'When queue jobs fail or workers keep exiting',
        'database' => 'When a database fills up or nears its connection limit',
        'other' => 'Other Edge events',
    ];

    public function mount(Server $server, Site $site): void
    {
        $this->mountEdgeWorkspaceSection($server, $site);
        $this->hydrateEdgeAlertNotificationPreferences();
        $this->loadThresholds();
    }

    private function loadThresholds(): void
    {
        $effective = EdgeEffectiveAlerts::for($this->site);
        $this->lcp_enabled = $effective['lcp_p75_ms']['enabled'];
        $this->lcp_threshold = (int) $effective['lcp_p75_ms']['threshold'];
        $this->err_rate_enabled = $effective['error_rate']['enabled'];
        $this->err_rate_threshold = (float) $effective['error_rate']['threshold'];
        $this->err_count_enabled = $effective['five_xx_count']['enabled'];
        $this->err_count_threshold = (int) $effective['five_xx_count']['threshold'];
    }

    /** Open the modal for one rule, starting from what is saved (drops unsaved edits). */
    public function editRule(string $rule): void
    {
        abort_unless(array_key_exists($rule, self::RULES), 404);

        $this->loadEdgeAlertNotificationPreferences();
        $this->loadThresholds();
        $this->resetErrorBag();
        $this->editingRule = $rule;
        $this->dispatch('open-modal', 'edge-alert-rule');
    }

    public function saveRule(): void
    {
        $this->authorize('update', $this->site);
        if ($this->editingRule === 'rum') {
            $this->validate();
        }

        if (! $this->persistEdgeAlertNotificationSubscriptions()) {
            return;
        }
        if ($this->editingRule === 'rum') {
            $this->persistThresholds();
        }

        $this->editingRule = null;
        $this->dispatch('close-modal', 'edge-alert-rule');
        $this->toastSuccess(__('Alert saved.'));
    }

    public function cancelRule(): void
    {
        $this->loadEdgeAlertNotificationPreferences();
        $this->loadThresholds();
        $this->editingRule = null;
        $this->dispatch('close-modal', 'edge-alert-rule');
    }

    private function persistThresholds(): void
    {
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
    }

    /**
     * Rules for the view: sentence, its events (key => label), and the
     * channel labels subscribed to any of them.
     *
     * @param  iterable<NotificationChannel>  $channels
     * @return array<string, array{sentence: string, events: array<string, string>, to: list<string>}>
     */
    private function alertRules(iterable $channels): array
    {
        $rules = [];
        foreach (EdgeSiteNotificationKeys::eventLabels() as $key => $label) {
            $family = explode('.', $key)[1] ?? 'other';
            $rule = array_key_exists($family, self::RULES) ? $family : 'other';
            $rules[$rule] ??= ['sentence' => __(self::RULES[$rule]), 'events' => [], 'to' => []];
            $rules[$rule]['events'][$key] = $label;
        }

        if (isset($rules['rum'])) {
            $parts = array_filter([
                $this->lcp_enabled ? __('LCP p75 goes over :n ms', ['n' => number_format($this->lcp_threshold)]) : null,
                $this->err_rate_enabled ? __('the 5xx rate goes over :n%', ['n' => rtrim(rtrim(number_format($this->err_rate_threshold, 1), '0'), '.')]) : null,
                $this->err_count_enabled ? __('5xx responses go over :n an hour', ['n' => number_format($this->err_count_threshold)]) : null,
            ]);
            if ($parts !== []) {
                $rules['rum']['sentence'] = __('When').' '.implode(' '.__('or').' ', $parts);
            }
        }

        foreach ($rules as $key => $rule) {
            foreach ($channels as $channel) {
                if (array_intersect(array_keys($rule['events']), (array) ($this->channelEventSelections[$channel->id] ?? [])) !== []) {
                    $rules[$key]['to'][] = (string) $channel->label;
                }
            }
        }

        return array_replace(array_intersect_key(self::RULES, $rules), $rules);
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

        $channels = AssignableNotificationChannels::forUser(auth()->user(), $this->site->organization);
        $eventKeys = EdgeSiteNotificationKeys::eventKeys();
        $routed = collect($this->channelEventSelections)->flatten()->intersect($eventKeys)->unique();

        return view('livewire.sites.edge.workspace.alerts', array_merge(
            EdgeSiteViewData::context($this->site, 'alerts'),
            [
                'server' => $this->server,
                'site' => $this->site,
                'repoAlerts' => $repoAlerts,
                'sourcePath' => $sourcePath,
                'assignableNotificationChannels' => $channels,
                'rules' => $this->alertRules($channels),
                'routedCount' => $routed->count(),
                'eventCount' => count($eventKeys),
                'routedChannels' => $channels->filter(fn ($c) => array_intersect($eventKeys, (array) ($this->channelEventSelections[$c->id] ?? [])) !== [])->pluck('label')->values(),
                'recentAlerts' => NotificationEvent::query()
                    ->where('subject_type', Site::class)
                    ->where('subject_id', $this->site->id)
                    ->where('event_key', 'like', 'edge.%')
                    ->where('created_at', '>=', now()->subDays(30))
                    ->latest()
                    ->limit(5)
                    ->get(['id', 'event_key', 'title', 'severity', 'created_at']),
            ],
        ));
    }
}
