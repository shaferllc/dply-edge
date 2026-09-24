<?php

declare(strict_types=1);

namespace App\Modules\Edge\Livewire;

use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Models\EdgeDeployment;
use App\Models\EdgeUsageSnapshot;
use App\Models\Organization;
use App\Models\Site;
use App\Modules\Billing\Services\EdgeOrganizationUsageReader;
use App\Modules\Edge\Jobs\TeardownEdgeSiteJob;
use App\Modules\Edge\Services\EdgeSiteCanceller;
use App\Support\Edge\EdgeIndexRow;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Org-scoped index of dply Edge sites — static/SSG apps on the
 * managed edge delivery plane (git → build → R2 + CF Worker).
 * The route group gates on {@see surface.edge}, so the off-state is a
 * straight 404; this component only runs when Edge is active for the org.
 */
class Index extends Component
{
    use DispatchesToastNotifications;

    /**
     * Filter the table by one of:
     *   - 'all': everything
     *   - 'failed' / 'provisioning': site status buckets
     *   - 'previews': ephemeral preview deploys only
     */
    #[Url]
    public string $filter = 'all';

    public ?string $confirmingDeleteSiteId = null;

    public string $deleteMode = 'now';

    public string $scheduledDeleteAt = '';

    /**
     * When non-null, the quick-look modal renders with the BuildJourney for
     * this site's latest deployment. Lets operators peek at a site's current
     * provisioning/build state without leaving the Edge index.
     */
    public ?string $quickLookSiteId = null;

    public function openQuickLookModal(string $siteId): void
    {
        $this->quickLookSiteId = $siteId;
        // x-modal is event-driven (listens for window-level `open-modal` with
        // a `name` payload). Setting the state alone doesn't toggle the
        // panel's `show` flag.
        $this->dispatch('open-modal', 'quick-look-edge-site');
    }

    public function closeQuickLookModal(): void
    {
        $this->quickLookSiteId = null;
        $this->dispatch('close-modal', 'quick-look-edge-site');
    }

    public function render(): View
    {
        $org = auth()->user()?->currentOrganization();
        abort_if($org === null, 403);

        $allSites = $this->edgeSitesQuery($org)
            ->with('server:id,name')
            ->orderByDesc('created_at')
            ->get();

        $parentOf = fn (Site $s) => $s->edgeMeta()['preview_parent_site_id'] ?? null;
        $isPreview = fn (Site $s) => ! empty($parentOf($s));

        $filtered = match ($this->filter) {
            'failed' => $allSites->where('status', Site::STATUS_EDGE_FAILED)->values(),
            'provisioning' => $allSites->where('status', Site::STATUS_EDGE_PROVISIONING)->values(),
            'previews' => $allSites->filter($isPreview)->values(),
            default => $allSites,
        };

        // Nest previews under their parent so the table reads as a tree:
        // each parent's preview children get hoisted up to sit immediately
        // beneath it. Skip nesting in the previews-only filter — there
        // are no parents in scope to nest under.
        $previewChildIds = [];
        if ($this->filter !== 'previews') {
            $previewsByParent = $filtered->filter($isPreview)->groupBy(fn (Site $s) => (string) $parentOf($s));
            $assignedChildIds = [];
            $ordered = collect();

            foreach ($filtered as $site) {
                if ($isPreview($site)) {
                    continue;
                }
                $ordered->push($site);
                $children = $previewsByParent->get((string) $site->id, collect());
                foreach ($children as $child) {
                    $ordered->push($child);
                    $previewChildIds[] = (string) $child->id;
                    $assignedChildIds[$child->id] = true;
                }
            }

            // Orphan previews (parent not in the filtered set — e.g.
            // parent deleted, or cross-org) get tacked on at the end so
            // they don't silently disappear.
            foreach ($filtered as $site) {
                if (! $isPreview($site) || isset($assignedChildIds[$site->id])) {
                    continue;
                }
                $ordered->push($site);
            }

            $sites = $ordered->values();
        } else {
            $sites = $filtered;
        }

        $deleteCandidate = null;
        if (is_string($this->confirmingDeleteSiteId) && $this->confirmingDeleteSiteId !== '') {
            $deleteCandidate = $allSites->firstWhere('id', $this->confirmingDeleteSiteId);
        }

        // Resolve the quick-look target's latest deployment so the modal can
        // mount the BuildJourney component without a second round-trip. For
        // a fully-provisioned site we also pull a small stats bundle so the
        // modal shows useful info (last deploy, recent build counts) instead
        // of a static "already live" message.
        $quickLookSite = null;
        $quickLookDeployment = null;
        $quickLookDeploymentId = null;
        $quickLookStats = null;
        if (is_string($this->quickLookSiteId) && $this->quickLookSiteId !== '') {
            $quickLookSite = $allSites->firstWhere('id', $this->quickLookSiteId);
            if ($quickLookSite !== null) {
                $quickLookDeployment = EdgeDeployment::query()
                    ->where('site_id', $quickLookSite->id)
                    ->orderByDesc('created_at')
                    ->first(['id', 'status', 'git_branch', 'git_commit', 'published_at', 'failed_at', 'failure_reason', 'created_at']);
                $quickLookDeploymentId = $quickLookDeployment?->id;

                $isInFlight = $quickLookDeployment !== null && in_array($quickLookDeployment->status, [
                    EdgeDeployment::STATUS_BUILDING,
                    EdgeDeployment::STATUS_PUBLISHING,
                ], true);

                if (! $isInFlight) {
                    $counts = EdgeDeployment::query()
                        ->where('site_id', $quickLookSite->id)
                        ->selectRaw('COUNT(*) AS total')
                        ->selectRaw('COUNT(CASE WHEN status = ? THEN 1 END) AS live', [EdgeDeployment::STATUS_LIVE])
                        ->selectRaw('COUNT(CASE WHEN status = ? THEN 1 END) AS failed', [EdgeDeployment::STATUS_FAILED])
                        ->first();
                    $quickLookStats = [
                        'total_deploys' => (int) ($counts->total ?? 0),
                        'live_deploys' => (int) ($counts->live ?? 0),
                        'failed_deploys' => (int) ($counts->failed ?? 0),
                        'latest' => $quickLookDeployment,
                    ];
                }
            }
        }

        $user = auth()->user();
        $previewChildLookup = array_flip($previewChildIds);
        $statsBySite = $this->statsBySite($allSites->pluck('id')->all());
        $rows = $sites->map(
            fn (Site $site): EdgeIndexRow => EdgeIndexRow::fromSite(
                $site,
                isset($previewChildLookup[(string) $site->id]),
                $user,
                $statsBySite[(string) $site->id] ?? [],
            ),
        );

        return view('livewire.edge.index', [
            'org' => $org,
            'edgeEnabled' => true,
            'rows' => $rows,
            'hasSitesInScope' => $allSites->isNotEmpty(),
            'deleteCandidate' => $deleteCandidate,
            'quickLookSite' => $quickLookSite,
            'quickLookDeploymentId' => $quickLookDeploymentId,
            'quickLookStats' => $quickLookStats,
            'totals' => [
                'all' => $allSites->count(),
                'active' => $allSites->where('status', Site::STATUS_EDGE_ACTIVE)->count(),
                'failed' => $allSites->where('status', Site::STATUS_EDGE_FAILED)->count(),
                'provisioning' => $allSites->where('status', Site::STATUS_EDGE_PROVISIONING)->count(),
                'previews' => $allSites->filter($isPreview)->count(),
            ],
        ])->layout('layouts.app');
    }

    public function openDeleteSiteModal(string $siteId): void
    {
        $site = $this->edgeSitesQueryForCurrentOrganization()->find($siteId);
        if (! $site instanceof Site) {
            $this->toastError(__('That Edge site could not be found.'));

            return;
        }

        $this->authorize('delete', $site);

        $this->confirmingDeleteSiteId = (string) $site->id;
        $this->deleteMode = 'now';
        $this->scheduledDeleteAt = now()->addDay()->startOfHour()->format('Y-m-d\TH:i');
        $this->resetValidation();
        $this->dispatch('open-modal', 'edge-index-delete-site-confirmation');
    }

    public function closeDeleteSiteModal(): void
    {
        $this->confirmingDeleteSiteId = null;
        $this->deleteMode = 'now';
        $this->scheduledDeleteAt = '';
        $this->resetValidation();
        $this->dispatch('close-modal', 'edge-index-delete-site-confirmation');
    }

    public function deleteSite(EdgeSiteCanceller $canceller): void
    {
        if (! is_string($this->confirmingDeleteSiteId) || $this->confirmingDeleteSiteId === '') {
            return;
        }

        $site = $this->edgeSitesQueryForCurrentOrganization()->find($this->confirmingDeleteSiteId);
        if (! $site instanceof Site) {
            $this->closeDeleteSiteModal();
            $this->toastError(__('That Edge site could not be found.'));

            return;
        }

        $this->authorize('delete', $site);

        $siteName = $site->name;
        $org = $site->organization;
        if ($this->deleteMode === 'in_30') {
            $scheduledFor = now()->addMinutes(30);
            $this->scheduleEdgeSiteDeletion($site, $scheduledFor);
            if ($org) {
                audit_log($org, auth()->user(), 'site.edge.deletion_scheduled', $site, null, [
                    'site_name' => $siteName,
                    'scheduled_for' => $scheduledFor->toIso8601String(),
                ]);
            }
            $this->closeDeleteSiteModal();
            $this->toastSuccess(__(':name will be deleted in 30 minutes.', ['name' => $siteName]));
            $this->redirect(route('dashboard'), navigate: true);

            return;
        }

        if ($this->deleteMode === 'scheduled') {
            $this->validate([
                'scheduledDeleteAt' => ['required', 'date'],
            ]);

            $scheduledFor = Carbon::parse($this->scheduledDeleteAt, config('app.timezone'));
            if ($scheduledFor->lte(now())) {
                $this->addError('scheduledDeleteAt', __('Choose a date and time in the future.'));

                return;
            }

            $this->scheduleEdgeSiteDeletion($site, $scheduledFor);
            if ($org) {
                audit_log($org, auth()->user(), 'site.edge.deletion_scheduled', $site, null, [
                    'site_name' => $siteName,
                    'scheduled_for' => $scheduledFor->toIso8601String(),
                ]);
            }
            $this->closeDeleteSiteModal();
            $this->toastSuccess(__(':name is scheduled for deletion on :date.', [
                'name' => $siteName,
                'date' => $scheduledFor->timezone(config('app.timezone'))->format('M j, Y g:i A'),
            ]));
            $this->redirect(route('dashboard'), navigate: true);

            return;
        }

        $snapshot = [
            'site_id' => (string) $site->id,
            'site_name' => $siteName,
        ];
        $canceller->cancel($site, sync: true);
        if ($org) {
            audit_log($org, auth()->user(), 'site.edge.deleted', null, $snapshot, null);
        }
        $this->closeDeleteSiteModal();
        $this->toastSuccess(__('Deleted Edge site ":name".', ['name' => $siteName]));
        $this->redirect(route('dashboard'), navigate: true);
    }

    private function scheduleEdgeSiteDeletion(Site $site, Carbon $scheduledFor): void
    {
        $meta = is_array($site->meta) ? $site->meta : [];
        $edgeMeta = is_array($meta['edge'] ?? null) ? $meta['edge'] : [];
        $edgeMeta['scheduled_deletion_at'] = $scheduledFor->toIso8601String();
        $meta['edge'] = $edgeMeta;

        $site->update(['meta' => $meta]);

        TeardownEdgeSiteJob::dispatch((string) $site->id)->delay($scheduledFor);
    }

    /**
     * @return Builder<Site>
     */
    private function edgeSitesQueryForCurrentOrganization(): Builder
    {
        $org = auth()->user()?->currentOrganization();
        abort_if(! $org instanceof Organization, 403);

        return $this->edgeSitesQuery($org);
    }

    /**
     * @param  list<mixed>  $siteIds
     * @return array<string, array{requests: int, bytes: int, published_at: mixed}>
     */
    private function statsBySite(array $siteIds): array
    {
        if ($siteIds === []) {
            return [];
        }

        [$periodStart, $periodEnd] = app(EdgeOrganizationUsageReader::class)->currentMonthWindow();
        $usage = EdgeUsageSnapshot::query()
            ->whereIn('site_id', $siteIds)
            ->where('period_start', '>=', $periodStart->toDateString())
            ->where('period_start', '<=', $periodEnd->toDateString())
            ->groupBy('site_id')
            ->selectRaw('site_id, COALESCE(SUM(requests), 0) AS requests, COALESCE(SUM(bytes_egress), 0) AS bytes_egress')
            ->get()
            ->keyBy(fn ($row) => (string) $row->site_id);

        $published = EdgeDeployment::query()
            ->whereIn('site_id', $siteIds)
            ->whereNotNull('published_at')
            ->groupBy('site_id')
            ->selectRaw('site_id, MAX(published_at) AS published_at')
            ->pluck('published_at', 'site_id');

        $stats = [];
        foreach ($siteIds as $siteId) {
            $id = (string) $siteId;
            $row = $usage->get($id);
            $stats[$id] = [
                'requests' => (int) ($row->requests ?? 0),
                'bytes' => (int) ($row->bytes_egress ?? 0),
                'published_at' => $published->get($siteId) ?? $published->get($id),
            ];
        }

        return $stats;
    }

    /**
     * @return Builder<Site>
     */
    private function edgeSitesQuery(Organization $organization): Builder
    {
        // Shared with the Edge quota via Site::countsAsEdgeApp(), so a row can
        // never count against the ceiling without also appearing in this list.
        return Site::query()
            ->where('organization_id', $organization->id)
            ->edgeListed();
    }
}
