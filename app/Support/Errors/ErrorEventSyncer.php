<?php

declare(strict_types=1);

namespace App\Support\Errors;

use App\Models\ConsoleAction;
use App\Models\ErrorEvent;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Captures failed sources into {@see ErrorEvent} rows by scanning the source
 * tables. This is a sweeper, not an Eloquent observer, on purpose: most
 * failures are persisted via the query builder (e.g. {@see WritesConsoleAction::failConsoleAction()}
 * does `DB::table('console_actions')->update(...)`), which bypasses model
 * events — an observer would silently miss them. Polling the source tables
 * captures every failure regardless of how it was written.
 *
 * Idempotent: the recorder upserts on (source_type, source_id), and we skip
 * sources that already have an event, so re-running (scheduled sweep or
 * backfill over the same window) never duplicates.
 */
class ErrorEventSyncer
{
    public function __construct(
        private readonly ErrorEventRecorder $recorder,
    ) {}

    /**
     * Record every failed ConsoleAction finalized at or after $since that
     * isn't already captured. Returns the number of new events.
     */
    /**
     * @param  bool  $refresh  Re-record sources that already have an event too
     *                         (updateOrCreate refreshes link/title/detail after
     *                         a recorder change). Default skips captured sources.
     * @param  bool  $notify  Fire an error-stream notification for each newly
     *                        captured event. The per-minute live sweep wants
     *                        this; the historical backfill passes false so it
     *                        doesn't blast alerts for old failures.
     */
    public function sync(CarbonInterface $since, bool $refresh = false, bool $notify = true): int
    {
        return $this->syncConsoleActions($since, $refresh, $notify);
    }

    private function syncConsoleActions(CarbonInterface $since, bool $refresh = false, bool $notify = true): int
    {
        $captured = ConsoleAction::query()->getModel()->getMorphClass();
        $count = 0;

        ConsoleAction::query()
            ->where('status', ConsoleAction::STATUS_FAILED)
            ->where('updated_at', '>=', $since)
            ->when(! $refresh, fn ($q) => $q
                ->whereNotExists(fn ($sub) => $sub->select(DB::raw(1))
                    ->from('error_events')
                    ->whereColumn('error_events.source_id', 'console_actions.id')
                    ->where('error_events.source_type', $captured))
                // Uptime checks re-run every few minutes and write a fresh
                // console_actions row each time, so the per-source guard above
                // never collapses them — a site stuck at e.g. HTTP 403 would mint
                // a new ErrorEvent on every probe (hundreds a day). Fold them: skip
                // a failed uptime check while the site already has an un-dismissed
                // uptime ErrorEvent. The first failure of a streak still records
                // (and notifies); repeats are absorbed until it recovers or the
                // user dismisses. The job re-opens the stream by clearing these on
                // recovery (see RunSiteUptimeMonitorCheckJob::resolveOpenUptimeErrors).
                // Correlated on the outer console_actions.kind so non-uptime rows
                // are unaffected.
                ->whereNotExists(fn ($sub) => $sub->select(DB::raw(1))
                    ->from('error_events')
                    ->where('console_actions.kind', 'uptime_check')
                    ->where('error_events.category', 'uptime_check')
                    ->whereNull('error_events.dismissed_at')
                    ->whereColumn('error_events.site_id', 'console_actions.subject_id')))
            ->with('subject')
            ->orderBy('id')
            ->chunkById(200, function ($rows) use (&$count, $notify): void {
                foreach ($rows as $row) {
                    $event = $this->recorder->recordConsoleAction($row);
                    if ($event) {
                        $count++;
                        $this->maybeNotify($event, $notify);
                    }
                }
            });

        return $count;
    }

    /**
     * Notify only for genuinely new events (the recorder upserts on
     * source identity, so a re-sweep of the same failure is not "recently
     * created"). Notification failures must never break the sweep, so they're
     * swallowed — the capture itself is the source of truth.
     */
    private function maybeNotify(ErrorEvent $event, bool $notify): void
    {
        // The server-errors notification dispatcher left with the VM platform.
        // Edge site errors notify through the Edge module's own alerts.
    }
}
