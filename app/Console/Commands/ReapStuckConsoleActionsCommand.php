<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Scheduling\DplySchedule;
use App\Models\ConsoleAction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Terminal-fail console actions that are stuck in flight far past any plausible
 * job runtime — the fingerprint of a queue worker (Horizon) restarted mid-flight,
 * which strands the row so the page-top banner spins forever (no worker will ever
 * finish it). The UI's own isStale() uses short thresholds for "looks stalled"
 * styling; this sweep is deliberately MUCH more conservative so it only reaps the
 * genuinely-dead, never a legitimately long backup/clone/DB-install still running.
 *
 * Scheduled every 15 minutes (see {@see DplySchedule}).
 */
class ReapStuckConsoleActionsCommand extends Command
{
    protected $signature = 'dply:console-actions:reap
        {--queued-minutes=30 : Fail QUEUED actions never picked up within this many minutes (a live worker starts them in seconds).}
        {--running-buffer-minutes=60 : Fail RUNNING actions whose runtime exceeds their per-kind stale threshold PLUS this buffer.}
        {--dry-run : List what would be reaped without writing.}';

    protected $description = 'Fail console actions stranded in queue/running by a restarted worker, so banners stop spinning.';

    public function handle(): int
    {
        $queuedMinutes = max(5, (int) $this->option('queued-minutes'));
        $bufferSeconds = max(0, (int) $this->option('running-buffer-minutes') * 60);
        $dryRun = (bool) $this->option('dry-run');

        $queuedCutoff = now()->subMinutes($queuedMinutes);

        // Volume of in-flight rows is tiny (tens at most), so evaluate per-row in
        // PHP to honour each kind's own stale threshold rather than a flat SQL cut.
        $candidates = ConsoleAction::query()
            ->whereNull('dismissed_at')
            ->whereIn('status', [ConsoleAction::STATUS_QUEUED, ConsoleAction::STATUS_RUNNING])
            ->get();

        $reapIds = [];
        foreach ($candidates as $action) {
            if ($action->status === ConsoleAction::STATUS_QUEUED) {
                if ($action->created_at !== null && $action->created_at->lt($queuedCutoff)) {
                    $reapIds[] = (string) $action->id;
                }

                continue;
            }

            // RUNNING: allow the kind's own (possibly multi-hour) stale window,
            // then add the buffer on top before declaring it dead.
            $since = $action->started_at ?? $action->created_at;
            $limit = $action->staleThresholdSeconds() + $bufferSeconds;
            if ($since !== null && $since->lt(now()->subSeconds($limit))) {
                $reapIds[] = (string) $action->id;
            }
        }

        if ($reapIds === []) {
            $this->info('No stranded console actions to reap.');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->warn('[dry-run] Would reap '.count($reapIds).' stranded console action(s):');
            foreach ($candidates->whereIn('id', $reapIds) as $a) {
                $this->line(sprintf('  %s  kind=%s  created=%s  started=%s', $a->status, $a->kind, $a->created_at, $a->started_at ?? '-'));
            }

            return self::SUCCESS;
        }

        $affected = DB::table('console_actions')
            ->whereIn('id', $reapIds)
            ->whereIn('status', [ConsoleAction::STATUS_QUEUED, ConsoleAction::STATUS_RUNNING])
            ->update([
                'status' => ConsoleAction::STATUS_FAILED,
                'finished_at' => now(),
                'dismissed_at' => now(),
                'error' => ConsoleAction::queueWorkerStalledMessage(),
                'updated_at' => now(),
            ]);

        $this->info("Reaped {$affected} stranded console action(s).");

        return self::SUCCESS;
    }
}
