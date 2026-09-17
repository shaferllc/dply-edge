<?php

declare(strict_types=1);

namespace App\Modules\Edge\Jobs;

use App\Models\EdgeDeployment;
use App\Models\Site;
use App\Modules\Notifications\Services\NotificationPublisher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * One request to a container site's live URL right after it goes live, so a
 * crashing app (missing DATABASE_URL, bad migration) is reported now instead
 * of by the first visitor. Records the result on the deployment; a 5xx or no
 * response publishes edge.deploy.failed (the deploy stays live).
 */
class CheckEdgeContainerHealthJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public string $deploymentId) {}

    public function handle(): void
    {
        $deployment = EdgeDeployment::query()->find($this->deploymentId);
        $site = $deployment ? Site::find($deployment->site_id) : null;
        $url = $site?->edgeLiveUrl();
        if ($deployment === null || $site === null || $url === null) {
            return;
        }

        $started = microtime(true);
        try {
            // Allow a cold start: the image may still be rolling out.
            $status = Http::timeout(90)->withoutRedirecting()->get($url)->status();
            $error = null;
        } catch (Throwable $e) {
            $status = null;
            $error = $e->getMessage();
        }
        $ok = $status !== null && $status < 500;

        $meta = is_array($deployment->meta) ? $deployment->meta : [];
        $meta['container']['health'] = [
            'ok' => $ok,
            'status' => $status,
            'ms' => (int) round((microtime(true) - $started) * 1000),
            'error' => $error,
            'checked_at' => now()->toIso8601String(),
        ];
        $deployment->update(['meta' => $meta]);

        if ($ok) {
            return;
        }

        try {
            app(NotificationPublisher::class)->publish(
                eventKey: 'edge.deploy.failed',
                subject: $site,
                title: "Container app unhealthy after deploy: {$site->name}",
                body: $status !== null ? "{$url} answered HTTP {$status}." : "{$url} did not answer: {$error}",
                url: route('sites.show', ['server' => $site->server_id, 'site' => $site->id, 'section' => 'edge-container']),
                metadata: ['deployment_id' => (string) $deployment->id, 'status' => $status, 'phase' => 'health'],
            );
        } catch (Throwable) {
            // Notification delivery is best-effort.
        }
    }
}
