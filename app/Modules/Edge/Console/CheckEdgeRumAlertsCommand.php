<?php

declare(strict_types=1);

namespace App\Modules\Edge\Console;

use App\Models\EdgeAccessLog;
use App\Models\EdgeWebVital;
use App\Models\Site;
use App\Modules\Edge\Support\EdgeEffectiveAlerts;
use App\Modules\Notifications\Services\NotificationPublisher;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * RUM alerting (P58). Hourly sweep across Edge sites with thresholds
 * from dply.yaml `alerts:` merged with dashboard `edgeMeta.alerts`
 * ({@see EdgeEffectiveAlerts}). Compares the last hour's data against
 * each enabled threshold and publishes a notification event when a
 * breach is observed. Per-site dedupe via cache key so a sustained
 * breach doesn't pager-spam every hour.
 *
 * Threshold shape:
 *   {
 *     "lcp_p75_ms": { "enabled": true, "threshold": 2500 },
 *     "error_rate": { "enabled": true, "threshold": 5 },   // percent
 *     "five_xx_count": { "enabled": true, "threshold": 50 }
 *   }
 */
class CheckEdgeRumAlertsCommand extends Command
{
    protected $signature = 'dply:check-edge-rum-alerts {--site=}';

    protected $description = 'Compare each Edge site\'s last-hour metrics to configured alert thresholds, publish breach notifications.';

    public function handle(NotificationPublisher $publisher): int
    {
        $since = now()->subHour();
        $query = Site::query()->where('status', Site::STATUS_EDGE_ACTIVE);
        if ($this->option('site')) {
            $query->where('id', $this->option('site'));
        }

        $checked = 0;
        $breached = 0;
        foreach ($query->cursor() as $site) {
            if (! $site->usesEdgeRuntime() || $site->isEdgePreview()) {
                continue;
            }
            $alerts = EdgeEffectiveAlerts::for($site);
            if (! EdgeEffectiveAlerts::anyEnabled($alerts)) {
                continue;
            }

            $checked++;
            foreach ($this->evaluateBreaches($site, $alerts, $since) as $breach) {
                $breached++;
                $this->publishBreach($publisher, $site, $breach);
            }
        }

        $this->info("Checked {$checked} site(s); {$breached} breach(es) published.");

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $alerts
     * @return iterable<array{kind: string, label: string, observed: float|int, threshold: float|int, unit: string}>
     */
    private function evaluateBreaches(Site $site, array $alerts, Carbon $since): iterable
    {
        $lcp = is_array($alerts['lcp_p75_ms'] ?? null) ? $alerts['lcp_p75_ms'] : null;
        if ($lcp !== null && ($lcp['enabled'] ?? false) && isset($lcp['threshold'])) {
            $threshold = (int) $lcp['threshold'];
            $p75 = $this->p75LcpMs($site->id, $since);
            if ($p75 !== null && $p75 > $threshold) {
                yield [
                    'kind' => 'lcp_p75_ms',
                    'label' => 'LCP p75 above threshold',
                    'observed' => $p75,
                    'threshold' => $threshold,
                    'unit' => 'ms',
                ];
            }
        }

        $errorRate = is_array($alerts['error_rate'] ?? null) ? $alerts['error_rate'] : null;
        if ($errorRate !== null && ($errorRate['enabled'] ?? false) && isset($errorRate['threshold'])) {
            $threshold = (float) $errorRate['threshold'];
            $rate = $this->errorRatePct($site->id, $since);
            if ($rate !== null && $rate > $threshold) {
                yield [
                    'kind' => 'error_rate',
                    'label' => '5xx error rate above threshold',
                    'observed' => round($rate, 2),
                    'threshold' => $threshold,
                    'unit' => '%',
                ];
            }
        }

        $fiveXx = is_array($alerts['five_xx_count'] ?? null) ? $alerts['five_xx_count'] : null;
        if ($fiveXx !== null && ($fiveXx['enabled'] ?? false) && isset($fiveXx['threshold'])) {
            $threshold = (int) $fiveXx['threshold'];
            $count = $this->fiveXxCount($site->id, $since);
            if ($count > $threshold) {
                yield [
                    'kind' => 'five_xx_count',
                    'label' => '5xx response count above threshold',
                    'observed' => $count,
                    'threshold' => $threshold,
                    'unit' => 'requests',
                ];
            }
        }
    }

    private function p75LcpMs(string $siteId, Carbon $since): ?int
    {
        $samples = EdgeWebVital::query()
            ->where('site_id', $siteId)
            ->where('occurred_at', '>=', $since)
            ->whereNotNull('lcp_ms')
            ->pluck('lcp_ms')
            ->map(fn ($v) => (int) $v)
            ->sort()
            ->values();
        if ($samples->isEmpty()) {
            return null;
        }
        $index = (int) floor($samples->count() * 0.75);
        $index = min($index, $samples->count() - 1);

        return (int) $samples[$index];
    }

    private function errorRatePct(string $siteId, Carbon $since): ?float
    {
        $total = EdgeAccessLog::query()
            ->where('site_id', $siteId)
            ->where('occurred_at', '>=', $since)
            ->count();
        if ($total === 0) {
            return null;
        }
        $errors = EdgeAccessLog::query()
            ->where('site_id', $siteId)
            ->where('occurred_at', '>=', $since)
            ->whereBetween('status_code', [500, 599])
            ->count();

        return ($errors / $total) * 100;
    }

    private function fiveXxCount(string $siteId, Carbon $since): int
    {
        return EdgeAccessLog::query()
            ->where('site_id', $siteId)
            ->where('occurred_at', '>=', $since)
            ->whereBetween('status_code', [500, 599])
            ->count();
    }

    /**
     * @param  array{kind: string, label: string, observed: float|int, threshold: float|int, unit: string}  $breach
     */
    private function publishBreach(NotificationPublisher $publisher, Site $site, array $breach): void
    {
        // Per-site + per-kind cooldown so a sustained issue publishes
        // at most once per 6h. Threshold is configurable via env.
        $cooldownHours = max(1, (int) config('edge.rum_alerts.cooldown_hours', 6));
        $cacheKey = 'edge:rum-alerts:'.$site->id.':'.$breach['kind'];
        if (Cache::get($cacheKey)) {
            return;
        }

        try {
            $publisher->publish(
                eventKey: 'edge.rum.breach',
                subject: $site,
                title: $site->name.' — '.$breach['label'],
                body: sprintf(
                    'Observed %s%s vs. configured threshold %s%s over the last hour.',
                    (string) $breach['observed'],
                    $breach['unit'] === '%' ? '%' : ' '.$breach['unit'],
                    (string) $breach['threshold'],
                    $breach['unit'] === '%' ? '%' : ' '.$breach['unit'],
                ),
                url: route('sites.show', ['server' => $site->server_id, 'site' => $site->id, 'section' => 'traffic']),
                metadata: [
                    'kind' => $breach['kind'],
                    'observed' => $breach['observed'],
                    'threshold' => $breach['threshold'],
                    'unit' => $breach['unit'],
                ],
            );
            Cache::put($cacheKey, true, now()->addHours($cooldownHours));
        } catch (\Throwable $e) {
            Log::warning('Edge RUM alert publish failed', [
                'site_id' => $site->id,
                'kind' => $breach['kind'],
                'error' => $e->getMessage(),
            ]);
        }
    }
}
