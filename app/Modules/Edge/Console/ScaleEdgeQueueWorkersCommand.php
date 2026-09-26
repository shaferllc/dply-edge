<?php

declare(strict_types=1);

namespace App\Modules\Edge\Console;

use App\Models\Site;
use App\Modules\Edge\Support\EdgeQueueWorkers;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Autoscale queue workers: ask each autoscaling app how many jobs wait on
 * its workers' queues, and run enough workers that each process has at most
 * `scale_per` of them (between the always-on count and the maximum).
 *
 * Up is immediate. Down waits until the target has stayed lower for
 * SCALE_DOWN_AFTER, so a queue that drains between bursts keeps its workers.
 *
 *   php artisan dply:edge:scale-queue-workers
 */
class ScaleEdgeQueueWorkersCommand extends Command
{
    public const SCALE_DOWN_AFTER = 300;

    protected $signature = 'dply:edge:scale-queue-workers';

    protected $description = 'Start or stop autoscaled queue workers to match each app\'s backlog.';

    public function handle(): int
    {
        $sites = Site::query()
            ->where('meta->edge->runtime_mode', 'container')
            ->where('meta->edge->container->workers->autoscale', true)
            ->get()
            ->filter(fn (Site $site): bool => ! $site->isEdgePreview()
                && is_string($site->edgeLiveUrl()) && $site->edgeLiveUrl() !== ''
                && EdgeQueueWorkers::runningInstances($site) > 0);

        foreach ($sites as $site) {
            $this->scale($site);
        }

        return self::SUCCESS;
    }

    public static function stateKey(Site $site): string
    {
        return 'edge:workers:'.$site->id.':scaler';
    }

    private function scale(Site $site): void
    {
        $settings = EdgeQueueWorkers::for($site);
        $key = self::stateKey($site);
        $state = Cache::get($key, []);
        $current = (int) ($state['count'] ?? $settings['instances']);
        $busyAt = (int) ($state['busy_at'] ?? 0);

        try {
            $backlog = EdgeQueueWorkers::backlog($site);
        } catch (Throwable $e) {
            Cache::put($key, array_merge($state, ['error' => $e->getMessage(), 'at' => now()->getTimestamp()]), now()->addDay());

            return;
        }

        $target = EdgeQueueWorkers::targetInstances($settings, $backlog);
        if ($target >= $current) {
            $busyAt = now()->getTimestamp();
        } elseif (now()->getTimestamp() - $busyAt < self::SCALE_DOWN_AFTER) {
            $target = $current; // not quiet for long enough yet
        }
        // Clamp a count left from older settings.
        $target = max($settings['instances'], min($settings['max_instances'], $target));

        $error = null;
        try {
            $failed = collect(EdgeQueueWorkers::scale($site, $target))->reject(fn (array $w): bool => $w['ok']);
            if ($failed->isNotEmpty()) {
                $error = $failed->map(fn (array $w): string => $w['name'].': '.$w['error'])->implode(' · ');
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }

        Cache::put($key, ['count' => $target, 'busy_at' => $busyAt, 'backlog' => $backlog, 'at' => now()->getTimestamp(), 'error' => $error], now()->addDay());
        if ($target !== $current) {
            $this->line(sprintf('%s: %d → %d workers (%d waiting)', $site->name, $current, $target, $backlog));
        }
    }
}
