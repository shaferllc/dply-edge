<?php

declare(strict_types=1);

namespace App\Modules\Edge\Services\Realtime;

use App\Models\EdgeRealtimeApp;
use App\Models\EdgeRealtimeUsage;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Realtime usage for billing (docs/edge-realtime.md). The relay's
 * connection_seconds and messages_in only go up, so each run adds the
 * difference from the last reading (kept on the app's meta:
 * last_connection_seconds, last_messages_in) to today's edge_realtime_usage row,
 * keeps the day's highest peak, then resets the relay's peak.
 *
 * Asleep (disabled) apps are read too: their counters stop growing, and the
 * stretch between the last run and the sleep still lands on the right day.
 *
 * Only publishes (messages_in) bill; deliveries (messages_out) are free, so a
 * broadcast to 1,000 listeners is one message (ruling r-ez5s8c56zn0ry3sw).
 */
final class EdgeRealtimeUsageCollector
{
    public function __construct(private EdgeRealtimeApps $apps) {}

    /**
     * @return array{apps: int, connection_seconds: int, messages: int}
     */
    public function collect(bool $dryRun = false): array
    {
        $totals = ['apps' => 0, 'connection_seconds' => 0, 'messages' => 0];
        EdgeRealtimeApp::query()->each(function (EdgeRealtimeApp $app) use ($dryRun, &$totals): void {
            // Two runs reading the same baseline would bill one stretch twice.
            // A run that finds the lock taken skips; the next run catches up.
            $lock = Cache::lock('edge-realtime-usage:'.$app->id, 60);
            if (! $lock->get()) {
                return;
            }
            try {
                $added = $this->collectApp($app, $dryRun);
            } finally {
                $lock->release();
            }
            if ($added !== null) {
                $totals['apps']++;
                $totals['connection_seconds'] += $added['connection_seconds'];
                $totals['messages'] += $added['messages'];
            }
        });

        return $totals;
    }

    /**
     * One app now, under the same lock. EdgeRealtimeApps::destroy calls it
     * so the stretch since the last hourly run is billed before the app goes.
     */
    public function collectOne(EdgeRealtimeApp $app): void
    {
        $lock = Cache::lock('edge-realtime-usage:'.$app->id, 60);
        if (! $lock->get()) {
            return;
        }
        try {
            $this->collectApp($app, false);
        } finally {
            $lock->release();
        }
    }

    /** @return array{connection_seconds: int, messages: int}|null Null when the relay could not be read. */
    private function collectApp(EdgeRealtimeApp $app, bool $dryRun): ?array
    {
        try {
            $stats = $this->apps->stats($app);
        } catch (Throwable $e) {
            Log::warning('Realtime usage: relay unreachable, skipped', ['app' => $app->id, 'error' => $e->getMessage()]);

            return null;
        }

        $meta = (array) ($app->meta ?? []);
        $messagesTotal = $stats['messages_in'];
        // Apps collected before deliveries went free only have last_messages
        // (in + out), which can't be split: start their baseline now.
        $lastMessages = $meta['last_messages_in'] ?? (isset($meta['last_messages']) ? $messagesTotal : 0);
        $seconds = self::delta($stats['connection_seconds'], (int) ($meta['last_connection_seconds'] ?? 0));
        $messages = self::delta($messagesTotal, (int) $lastMessages);
        $peak = $stats['peak_connections'];
        if ($dryRun) {
            return ['connection_seconds' => $seconds, 'messages' => $messages];
        }

        if ($seconds > 0 || $messages > 0 || $peak > 0) {
            DB::transaction(function () use ($app, $meta, $stats, $messagesTotal, $seconds, $messages, $peak): void {
                $row = EdgeRealtimeUsage::query()->firstOrCreate(
                    ['realtime_app_id' => $app->id, 'date' => now()->utc()->toDateString()],
                    ['organization_id' => $app->organization_id, 'site_id' => $app->site_id, 'connection_seconds' => 0, 'messages' => 0, 'peak_connections' => 0],
                );
                $row->connection_seconds += $seconds;
                $row->messages += $messages;
                $row->peak_connections = max($row->peak_connections, $peak);
                $row->save();
                $app->meta = array_merge(Arr::except($meta, 'last_messages'), ['last_connection_seconds' => $stats['connection_seconds'], 'last_messages_in' => $messagesTotal]);
                $app->save();
            });
        }

        if ($peak > 0) {
            try {
                $this->apps->resetPeak($app);
            } catch (Throwable $e) {
                // The next run reads the same peak again; max() keeps it harmless.
                Log::warning('Realtime usage: peak reset failed', ['app' => $app->id, 'error' => $e->getMessage()]);
            }
        }

        return ['connection_seconds' => $seconds, 'messages' => $messages];
    }

    /** A total below the last reading means the hub's counters restarted: the new total is all new. */
    private static function delta(int $total, int $last): int
    {
        return $total >= $last ? $total - $last : $total;
    }
}
