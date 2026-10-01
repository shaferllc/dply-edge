<?php

namespace App\Services\Status;

use App\Models\Server;
use App\Models\Site;
use App\Models\SiteUptimeMonitor;

class MonitorOperationalState
{
    public const OPERATIONAL = 'operational';

    public const DEGRADED = 'degraded';

    public const OUTAGE = 'outage';

    public const UNKNOWN = 'unknown';

    /** A container app that is asleep: fine, and not woken to be checked. */
    public const ASLEEP = 'asleep';

    /**
     * @return self::OPERATIONAL|self::DEGRADED|self::OUTAGE|self::UNKNOWN|self::ASLEEP
     */
    public function state(Server|Site|SiteUptimeMonitor $model): string
    {
        if ($model instanceof SiteUptimeMonitor) {
            return $this->stateForSiteUptimeMonitor($model);
        }

        if ($model instanceof Site) {
            return $this->stateForSite($model);
        }

        return $this->stateForServer($model);
    }

    public function label(string $state): string
    {
        return match ($state) {
            self::OPERATIONAL => __('Operational'),
            self::DEGRADED => __('Degraded'),
            self::OUTAGE => __('Outage'),
            self::ASLEEP => __('Asleep'),
            default => __('Unknown'),
        };
    }

    /**
     * @return self::OPERATIONAL|self::DEGRADED|self::OUTAGE|self::UNKNOWN|self::ASLEEP
     */
    private function stateForSiteUptimeMonitor(SiteUptimeMonitor $monitor): string
    {
        $checked = $monitor->last_checked_at;
        if ($checked === null) {
            return self::UNKNOWN;
        }

        // Use the monitor's effective cadence (down monitors are probed on the
        // slower down-interval), so a backed-off outage doesn't read as stale.
        $minutes = $monitor->effectiveCheckIntervalMinutes();
        $mult = max(1, (int) config('site_uptime.stale_check_multiplier', 2));
        $staleAfterMinutes = $minutes * $mult;

        if ($checked->lt(now()->subMinutes($staleAfterMinutes))) {
            return self::UNKNOWN;
        }

        // last_state carries the finer operational state (a slow-but-up monitor
        // reads DEGRADED while last_ok stays true); fall back to last_ok for
        // rows checked before last_state existed.
        if (in_array($monitor->last_state, [self::OPERATIONAL, self::DEGRADED, self::OUTAGE, self::ASLEEP], true)) {
            return $monitor->last_state;
        }

        if ($monitor->last_ok) {
            return self::OPERATIONAL;
        }

        return self::OUTAGE;
    }

    /**
     * @return self::OPERATIONAL|self::DEGRADED|self::OUTAGE|self::UNKNOWN|self::ASLEEP
     */
    private function stateForServer(Server $server): string
    {
        if ($server->health_status === Server::HEALTH_REACHABLE) {
            return self::OPERATIONAL;
        }
        if ($server->health_status === Server::HEALTH_UNREACHABLE) {
            return self::OUTAGE;
        }

        return self::UNKNOWN;
    }

    /**
     * @return self::OPERATIONAL|self::DEGRADED|self::OUTAGE|self::UNKNOWN|self::ASLEEP
     */
    private function stateForSite(Site $site): string
    {
        // An Edge app's owner Server is a vestigial row with no health, so the
        // app's own uptime checks are the signal: the worst checked state wins.
        $site->loadMissing('uptimeMonitors');
        if ($site->uptimeMonitors->isNotEmpty()) {
            $states = $site->uptimeMonitors->map(fn (SiteUptimeMonitor $m): string => $this->stateForSiteUptimeMonitor($m));
            foreach ([self::OUTAGE, self::DEGRADED, self::OPERATIONAL, self::ASLEEP] as $state) {
                if ($states->contains($state)) {
                    return $state;
                }
            }

            return self::UNKNOWN;
        }

        $server = $site->relationLoaded('server') ? $site->server : $site->server()->first();
        if (! $server instanceof Server) {
            return self::UNKNOWN;
        }

        if ($server->health_status === Server::HEALTH_UNREACHABLE) {
            return self::OUTAGE;
        }

        if ($site->status === Site::STATUS_ERROR) {
            return self::DEGRADED;
        }

        if ($site->isReadyForTraffic() && $server->health_status === Server::HEALTH_REACHABLE) {
            return self::OPERATIONAL;
        }

        if ($server->health_status === Server::HEALTH_REACHABLE) {
            return self::DEGRADED;
        }

        return self::UNKNOWN;
    }
}
