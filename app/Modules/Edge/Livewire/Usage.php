<?php

declare(strict_types=1);

namespace App\Modules\Edge\Livewire;

use App\Models\EdgeUsageSnapshot;
use App\Models\Site;
use App\Modules\Billing\Services\EdgeOrganizationUsageReader;
use App\Modules\Billing\Services\EdgeSiteBillingAnalytics;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Org-wide Edge usage + billing dashboard.
 *
 * Single page that aggregates every billable Edge site in the current
 * org into one table: requests / bandwidth / R2 storage / estimated
 * cost month-to-date. Useful when an operator wants a "where is the
 * spend going?" view without clicking into each site's workspace.
 */
#[Layout('layouts.app')]
class Usage extends Component
{
    public function render(): View
    {
        $org = auth()->user()?->currentOrganization();
        abort_if($org === null, 403);

        // EdgeSiteBillingAnalytics::sitesForOrganization is strict
        // (active + dply_edge + age >= 1d + not preview). For the usage
        // dashboard we want EVERY edge site so the operator can see
        // what they have — including provisioning, failed, BYO, and
        // brand-new ones. Build a fuller row set manually.
        [$periodStart, $periodEnd] = app(EdgeOrganizationUsageReader::class)->currentMonthWindow();
        $allEdgeSites = $org->sites()
            ->with('server:id,name')
            ->whereNotNull('edge_backend')
            ->orderByDesc('created_at')
            ->get()
            // Drop sites that are functionally gone:
            //   - scheduled_deletion_at in edgeMeta (operator picked
            //     "delete now"/"in 30m"/scheduled — the row hangs around
            //     until the teardown job fires, but for billing they
            //     don't count).
            //   - server row missing (orphaned site, can't actually serve).
            ->filter(function (Site $site): bool {
                if (! empty($site->edgeMeta()['scheduled_deletion_at'] ?? null)) {
                    return false;
                }
                if ($site->server_id !== null && $site->server === null) {
                    return false;
                }

                return true;
            })
            ->values();

        $rows = [];
        foreach ($allEdgeSites as $site) {
            $perSite = app(EdgeSiteBillingAnalytics::class)->forSite($site);

            // forSite returns null for non-billable (failed/preview/recent).
            // Compute usage stats directly from EdgeUsageSnapshot so the
            // row shows real numbers (especially for previews — they
            // consume bandwidth + requests too, just don't carry a
            // platform fee).
            if ($perSite === null) {
                $totals = EdgeUsageSnapshot::query()
                    ->where('site_id', $site->id)
                    ->where('period_start', '>=', $periodStart->toDateString())
                    ->where('period_start', '<=', $periodEnd->toDateString())
                    ->selectRaw('COALESCE(SUM(requests), 0) AS requests, COALESCE(SUM(bytes_egress), 0) AS bytes_egress, COALESCE(MAX(r2_storage_bytes), 0) AS r2_storage_bytes')
                    ->first();

                $rows[] = [
                    'site_id' => (string) $site->id,
                    'server_id' => (string) $site->server_id,
                    'name' => (string) $site->name,
                    'hostname' => $site->edgeHostname(),
                    'status' => (string) $site->status,
                    'is_preview' => $site->isEdgePreview(),
                    'requests' => (int) ($totals->requests ?? 0),
                    'bytes_egress' => (int) ($totals->bytes_egress ?? 0),
                    'r2_storage_bytes' => (int) ($totals->r2_storage_bytes ?? 0),
                    'platform_cents' => 0,
                    'usage_cents' => 0,
                    'total_cents' => 0,
                    'billable' => false,
                ];

                continue;
            }
            $perSite['billable'] = true;
            $perSite['is_preview'] = false;
            $rows[] = $perSite;
        }
        // Billable first, then sort by total cost descending. Non-billable
        // sites fall to the bottom so the spend story is still readable.
        usort($rows, function (array $a, array $b): int {
            if (($a['billable'] ?? false) !== ($b['billable'] ?? false)) {
                return ($b['billable'] ?? false) <=> ($a['billable'] ?? false);
            }

            return ($b['total_cents'] ?? 0) <=> ($a['total_cents'] ?? 0);
        });

        // KPI strip totals are sums of the row values shown below — that
        // way the numbers always reconcile (top = sum(rows)). Using the
        // pooled EdgeOrganizationUsageReader estimate gave a different
        // number because per-site billing counts overages individually
        // while org-level pooling shares included quotas across sites.
        $billableSiteCount = count(array_filter($rows, fn ($r) => ($r['billable'] ?? false)));
        $platformSubtotalCents = (int) array_sum(array_column($rows, 'platform_cents'));
        $usageSubtotalCents = (int) array_sum(array_column($rows, 'usage_cents'));
        $orgGrandTotalCents = $platformSubtotalCents + $usageSubtotalCents;
        $totalRequests = (int) array_sum(array_column($rows, 'requests'));
        $totalBytes = (int) array_sum(array_column($rows, 'bytes_egress'));
        $totalR2 = (int) array_sum(array_column($rows, 'r2_storage_bytes'));

        return view('livewire.edge.usage', [
            'org' => $org,
            'edgeEnabled' => true,
            'rows' => $rows,
            'totals' => [
                'sites' => $billableSiteCount,
                'all_sites' => count($rows),
                'requests' => $totalRequests,
                'bytes_egress' => $totalBytes,
                'r2_storage_bytes' => $totalR2,
                'platform_cents' => $platformSubtotalCents,
                'usage_cents' => $usageSubtotalCents,
                'total_cents' => $orgGrandTotalCents,
            ],
            'window' => [
                'start' => $periodStart->toDateString(),
                'end' => $periodEnd->toDateString(),
            ],
        ]);
    }
}
