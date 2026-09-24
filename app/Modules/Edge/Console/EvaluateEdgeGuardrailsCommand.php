<?php

declare(strict_types=1);

namespace App\Modules\Edge\Console;

use App\Models\Site;
use App\Modules\Edge\Services\EdgeGuardrailStatus;
use App\Modules\Edge\Services\EdgeUsageGuardrail;
use App\Modules\Notifications\Services\NotificationPublisher;
use Illuminate\Console\Command;
use Throwable;

/**
 * Daily evaluator for the per-site monthly request + egress guardrail.
 *
 *   php artisan dply:edge:evaluate-guardrails
 *   php artisan dply:edge:evaluate-guardrails --site=01ks...
 *   php artisan dply:edge:evaluate-guardrails --dry-run
 *
 * Iterates active Edge sites, recomputes state from EdgeUsageSnapshot
 * totals, persists onto site.edgeMeta.guardrail, and fires the
 * `edge.usage.over_budget` notification only on transitions INTO warn/over
 * (no re-notify if the state hasn't moved, no notify on recovery).
 */
class EvaluateEdgeGuardrailsCommand extends Command
{
    protected $signature = 'dply:edge:evaluate-guardrails
                            {--site= : Evaluate a single site by ID instead of all active edge sites}
                            {--dry-run : Compute + report without persisting or firing notifications}';

    protected $description = 'Evaluate the per-site monthly usage guardrail and notify on warn/over transitions.';

    public function handle(EdgeUsageGuardrail $guardrail, NotificationPublisher $notifier): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $siteId = $this->option('site');

        $query = Site::query()
            ->whereIn('status', [
                Site::STATUS_EDGE_PROVISIONING,
                Site::STATUS_EDGE_ACTIVE,
                Site::STATUS_EDGE_FAILED,
            ])
            ->whereNotNull('edge_backend');

        if (is_string($siteId) && $siteId !== '') {
            $query->whereKey($siteId);
        }

        $count = 0;
        $transitions = 0;

        $query->chunkById(50, function ($sites) use ($guardrail, $notifier, $dryRun, &$count, &$transitions): void {
            foreach ($sites as $site) {
                $count++;
                try {
                    $status = $guardrail->evaluate($site);

                    if ($dryRun) {
                        $this->line(sprintf(
                            '  [dry-run] %s — %s (req %d%%, bytes %d%%)',
                            $site->id,
                            $status->state,
                            $status->requestsPercent(),
                            $status->bytesPercent(),
                        ));

                        continue;
                    }

                    $previous = $site->updateEdgeGuardrail($status->meta());

                    if ($this->shouldNotify($previous, $status->state)) {
                        $transitions++;
                        $this->dispatchTransition($notifier, $site, $status);
                    }
                } catch (Throwable $e) {
                    $this->warn(sprintf('  ! %s — evaluator failed: %s', $site->id, $e->getMessage()));
                }
            }
        });

        $this->info(sprintf(
            '%s %d site(s)%s',
            $dryRun ? '[dry-run] evaluated' : 'Evaluated',
            $count,
            $dryRun ? '' : ", {$transitions} transition(s) notified",
        ));

        return self::SUCCESS;
    }

    /**
     * Only fan out when the state has just moved INTO warn or over.
     * Recoveries (over→warn, warn→ok, etc.) stay silent — surfacing a
     * "you're back under quota" alert tends to annoy more than help, and
     * the dashboard banner clears on its own.
     */
    private function shouldNotify(?string $previous, string $current): bool
    {
        if (! in_array($current, [EdgeGuardrailStatus::STATE_WARN, EdgeGuardrailStatus::STATE_OVER], true)) {
            return false;
        }

        return $previous !== $current;
    }

    private function dispatchTransition(NotificationPublisher $notifier, Site $site, EdgeGuardrailStatus $status): void
    {
        $title = $status->isOver()
            ? __('Edge site over monthly quota: :name', ['name' => $site->name])
            : __('Edge site approaching monthly quota: :name', ['name' => $site->name]);

        $body = sprintf(
            'Requests %d%% of cap (%d / %d) · Bandwidth %d%% of cap (%s / %s)',
            $status->requestsPercent(),
            $status->requests,
            $status->requestsCap,
            $status->bytesPercent(),
            $this->humanBytes($status->bytesEgress),
            $this->humanBytes($status->bytesEgressCap),
        );

        try {
            $notifier->publish(
                eventKey: 'edge.usage.over_budget',
                subject: $site,
                title: $title,
                body: $body,
                url: route('sites.show', [
                    'server' => $site->server_id,
                    'site' => $site->id,
                    'section' => 'billing',
                ]),
                metadata: $status->meta(),
            );
        } catch (Throwable $e) {
            // Notification fan-out is best-effort — log + move on so one
            // broken channel doesn't poison the rest of the run.
            $this->warn(sprintf('  ! %s — notify failed: %s', $site->id, $e->getMessage()));
        }
    }

    private function humanBytes(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = (int) min(count($units) - 1, floor(log($bytes, 1024)));

        return sprintf('%.1f %s', $bytes / (1024 ** $i), $units[$i]);
    }
}
