<?php

declare(strict_types=1);

namespace App\Modules\Edge\Jobs;

use App\Models\EdgeDnsZone;
use App\Models\Site;
use App\Modules\Edge\Services\EdgeCustomDomainProvisioner;
use App\Modules\Edge\Services\EdgeDnsZones;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Every 5 minutes: see whether customers switched their nameservers to dply.
 * A zone that turns active finishes every hostname waiting under it; one
 * left pending past `edge.dns.pending_days` is dropped so a name can't be
 * held forever by someone who never owned it.
 */
class CheckEdgeDnsZonesJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public function handle(EdgeDnsZones $zones, EdgeCustomDomainProvisioner $provisioner): void
    {
        $staleBefore = now()->subDays(max(1, (int) config('edge.dns.pending_days', 14)));

        EdgeDnsZone::query()->where('status', EdgeDnsZone::STATUS_PENDING)->each(function (EdgeDnsZone $zone) use ($zones, $provisioner, $staleBefore): void {
            try {
                if ($zones->refresh($zone)) {
                    self::finishWaitingDomains($zone, $provisioner);

                    return;
                }

                if ($zone->created_at !== null && $zone->created_at->lt($staleBefore)) {
                    $zones->remove($zone);
                }
            } catch (Throwable $e) {
                Log::info('Edge DNS zone check failed.', ['zone' => $zone->name, 'error' => $e->getMessage()]);
            }
        });
    }

    /** Provision the org's attached hostnames under a zone that just went active. */
    public static function finishWaitingDomains(EdgeDnsZone $zone, EdgeCustomDomainProvisioner $provisioner): void
    {
        Site::query()
            ->where('organization_id', $zone->organization_id)
            ->whereNotNull('edge_backend')
            ->each(function (Site $site) use ($zone, $provisioner): void {
                if ($site->isEdgePreview()) {
                    return;
                }
                $domains = $site->edgeMeta()['routing']['custom_domains'] ?? [];
                foreach (is_array($domains) ? $domains : [] as $hostname => $info) {
                    if (is_string($hostname) && $zone->covers($hostname) && ($info['dns_status'] ?? null) !== 'ready') {
                        $provisioner->provision($site->fresh(), $hostname);
                    }
                }
            });
    }
}
