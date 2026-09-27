<?php

declare(strict_types=1);

namespace App\Modules\Edge\Jobs;

use App\Models\Site;
use App\Modules\Edge\Services\EdgeCustomDomainProvisioner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Every 15 minutes: verify pending domains, keep re-checking failed ones with
 * backoff (DNS added late completes on its own), and poll SaaS cert issuance.
 */
class VerifyEdgeCustomDomainsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function handle(EdgeCustomDomainProvisioner $provisioner): void
    {
        Site::query()
            ->whereNotNull('edge_backend')
            ->chunkById(50, function ($sites) use ($provisioner): void {
                foreach ($sites as $site) {
                    if (! $site->usesEdgeRuntime()) {
                        continue;
                    }

                    $routing = is_array($site->edgeMeta()['routing'] ?? null) ? $site->edgeMeta()['routing'] : [];
                    $domains = is_array($routing['custom_domains'] ?? null) ? $routing['custom_domains'] : [];

                    foreach ($domains as $hostname => $info) {
                        if (! is_string($hostname) || $hostname === '') {
                            continue;
                        }
                        if (! is_array($info)) {
                            continue;
                        }

                        $dnsStatus = $info['dns_status'] ?? null;
                        if ($dnsStatus === 'pending' || ($dnsStatus === 'failed' && self::failedRecheckDue($info))) {
                            $provisioner->verify($site->fresh(), $hostname);

                            continue;
                        }

                        // Phase 3b: poll SaaS cert issuance (and recover transient failures).
                        $sslStatus = $info['ssl_status'] ?? null;
                        if ($dnsStatus === 'ready' && in_array($sslStatus, [null, 'pending', 'failed'], true)) {
                            $provisioner->syncCustomHostnameSsl($site->fresh(), $hostname);
                        }
                    }
                }
            });
    }

    /** Failed domains are re-checked for this long after they were attached. */
    public const FAILED_RECHECK_HOURS = 72;

    /**
     * Backoff by age: every run for the first hour, hourly for the first day,
     * then every 6 hours until FAILED_RECHECK_HOURS. A row without
     * attached_at predates the timestamp and is left for a manual Verify.
     *
     * @param  array<string, mixed>  $info
     */
    public static function failedRecheckDue(array $info): bool
    {
        if (! is_string($info['attached_at'] ?? null) || $info['attached_at'] === '') {
            return false;
        }
        try {
            $attached = Carbon::parse($info['attached_at']);
            $checked = is_string($info['verified_at'] ?? null) ? Carbon::parse($info['verified_at']) : null;
        } catch (Throwable) {
            return false;
        }

        $ageHours = $attached->diffInHours(now());
        if ($ageHours >= self::FAILED_RECHECK_HOURS) {
            return false;
        }

        $intervalMinutes = match (true) {
            $ageHours < 1 => 0,
            $ageHours < 24 => 60,
            default => 360,
        };

        return $checked === null || $checked->diffInMinutes(now()) >= $intervalMinutes;
    }
}
