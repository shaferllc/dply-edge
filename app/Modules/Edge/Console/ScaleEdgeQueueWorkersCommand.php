<?php

declare(strict_types=1);

namespace App\Modules\Edge\Console;

use App\Models\Site;
use App\Modules\Edge\Support\EdgeQueueWorkers;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * Autoscale queue workers: ask each autoscaling app how many jobs wait on
 * its workers' queues, and run enough workers that each process has at most
 * `scale_per` of them (between the always-on count and the maximum).
 *
 * Scheduled every minute with --for=50: it checks every 10 seconds, reading
 * the backlog from the queue itself (EdgeQueueWorkers::backlog), so a burst
 * gets workers within seconds without waking the app.
 *
 * Up is immediate. Down waits until the target has stayed lower for
 * SCALE_DOWN_AFTER, so a queue that drains between bursts keeps its workers.
 *
 *   php artisan dply:edge:scale-queue-workers
 */
class ScaleEdgeQueueWorkersCommand extends Command
{
    public const SCALE_DOWN_AFTER = 300;

    /** Tell the Worker the count again this often even when it has not changed (self-heal). */
    public const RESYNC_EVERY = 60;

    protected $signature = 'dply:edge:scale-queue-workers
        {--for=0 : Keep checking for this many seconds (the schedule runs it for most of each minute)}
        {--every=10 : Seconds between checks while --for runs}';

    protected $description = 'Start or stop autoscaled queue workers to match each app\'s backlog.';

    public function handle(): int
    {
        $until = now()->getTimestamp() + max(0, (int) $this->option('for'));
        $every = max(1, (int) $this->option('every'));
        do {
            $this->pass();
            $more = now()->getTimestamp() + $every <= $until;
            if ($more) {
                Sleep::for($every)->seconds();
            }
        } while ($more);

        return self::SUCCESS;
    }

    private function pass(): void
    {
        $sites = Site::query()
            ->where('meta->edge->runtime_mode', 'container')
            ->where('meta->edge->container->workers->enabled', true)
            ->get()
            ->filter(fn (Site $site): bool => ! $site->isEdgePreview()
                && is_string($site->edgeLiveUrl()) && $site->edgeLiveUrl() !== ''
                && EdgeQueueWorkers::runningInstances($site) > 0
                && ! EdgeQueueWorkers::for($site)['paused']);

        foreach ($sites as $site) {
            foreach (EdgeQueueWorkers::groups($site) as $group) {
                if ($group['autoscale']) {
                    $this->scale($site, $group);
                }
            }
        }
    }

    /** Points kept for the workspace chart: one a minute, six hours. */
    public const HISTORY_POINTS = 360;

    /** Per group; the main group ('') keeps the original keys. */
    public static function stateKey(Site $site, string $group = ''): string
    {
        return 'edge:workers:'.$site->id.':scaler'.($group !== '' ? ':'.$group : '');
    }

    public static function historyKey(Site $site, string $group = ''): string
    {
        return 'edge:workers:'.$site->id.':history'.($group !== '' ? ':'.$group : '');
    }

    /**
     * Workers running and jobs waiting, oldest first.
     *
     * @return list<array{at: int, count: int, backlog: int}>
     */
    public static function history(Site $site, string $group = ''): array
    {
        $points = Cache::get(self::historyKey($site, $group), []);

        return is_array($points) ? array_values($points) : [];
    }

    /** @param  array{key: string, instances: int, max_instances: int, processes: int, scale_per: int, max_wait: int}  $settings */
    private function scale(Site $site, array $settings): void
    {
        $group = $settings['key'];
        $key = self::stateKey($site, $group);
        $state = Cache::get($key, []);
        $current = (int) ($state['count'] ?? $settings['instances']);
        $busyAt = (int) ($state['busy_at'] ?? 0);

        try {
            $queue = EdgeQueueWorkers::queueState($site, $group);
            $backlog = $queue['waiting'];
        } catch (Throwable $e) {
            Cache::put($key, array_merge($state, ['error' => $e->getMessage(), 'at' => now()->getTimestamp()]), now()->addDay());

            return;
        }

        $target = EdgeQueueWorkers::targetInstances($settings, $backlog, $queue['oldest_age'], $current);
        // Scaled to zero, a delayed job falling due would wait for a store
        // nobody wakes: keep one worker while any are scheduled.
        if ($target === 0 && ($queue['delayed'] ?? 0) > 0) {
            $target = min(1, $settings['max_instances']);
        }
        if ($target >= $current) {
            $busyAt = now()->getTimestamp();
        } elseif (now()->getTimestamp() - $busyAt < self::SCALE_DOWN_AFTER) {
            $target = $current; // not quiet for long enough yet
        }
        // Clamp a count left from older settings.
        $target = max($settings['instances'], min($settings['max_instances'], $target));

        $now = now()->getTimestamp();
        $error = $state['error'] ?? null;
        $syncedAt = (int) ($state['synced_at'] ?? 0);
        // Only tell the Worker when the count changes, and once a minute anyway.
        if ($target !== $current || $now - $syncedAt >= self::RESYNC_EVERY || $error !== null) {
            $error = null;
            try {
                $failed = collect(EdgeQueueWorkers::scale($site, $target, $group))->reject(fn (array $w): bool => $w['ok']);
                if ($failed->isNotEmpty()) {
                    $error = $failed->map(fn (array $w): string => $w['name'].': '.$w['error'])->implode(' · ');
                }
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
            $syncedAt = $now;
        }

        Cache::put($key, ['count' => $target, 'busy_at' => $busyAt, 'backlog' => $backlog, 'oldest_age' => $queue['oldest_age'], 'at' => $now, 'synced_at' => $syncedAt, 'error' => $error], now()->addDay());
        // One chart point a minute, keeping the minute's peak.
        $history = self::history($site, $group);
        $last = end($history);
        if (is_array($last) && $now - $last['at'] < 55) {
            $history[array_key_last($history)] = ['at' => $last['at'], 'count' => max($last['count'], $target), 'backlog' => max($last['backlog'], $backlog)];
        } else {
            $history[] = ['at' => $now, 'count' => $target, 'backlog' => $backlog];
        }
        Cache::put(self::historyKey($site, $group), array_slice($history, -self::HISTORY_POINTS), now()->addDay());
        if ($target !== $current) {
            $this->line(sprintf('%s%s: %d → %d workers (%d waiting)', $site->name, $group !== '' ? ' ['.$group.']' : '', $current, $target, $backlog));
        }
    }
}
