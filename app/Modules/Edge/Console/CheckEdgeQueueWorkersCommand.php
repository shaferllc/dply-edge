<?php

declare(strict_types=1);

namespace App\Modules\Edge\Console;

use App\Models\Site;
use App\Modules\Edge\Services\Containers\EdgeContainerDeployer;
use App\Modules\Edge\Support\EdgeQueueWorkers;
use App\Modules\Notifications\Services\NotificationPublisher;
use App\Modules\Providers\Cloudflare\EdgeCloudflareClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Alert when an app's queue workers fail jobs or keep exiting.
 *
 * Reads the workers' own output from the app's logs (job lines ending in
 * FAIL, and the supervisor's "exited … within 10s" retries), so checking
 * never wakes a sleeping app or database. At most one alert of each kind per
 * site every ALERT_EVERY seconds.
 *
 *   php artisan dply:edge:check-queue-workers
 */
class CheckEdgeQueueWorkersCommand extends Command
{
    public const WINDOW_MINUTES = 5;

    public const ALERT_EVERY = 1800;

    /** Boot-time exits in the window before it counts as crashing. */
    public const CRASH_EXITS = 3;

    protected $signature = 'dply:edge:check-queue-workers';

    protected $description = 'Alert on failing jobs and crash-looping queue workers.';

    public function handle(NotificationPublisher $publisher): int
    {
        $sites = Site::query()
            ->where('meta->edge->runtime_mode', 'container')
            ->where('meta->edge->container->workers->enabled', true)
            ->get()
            ->filter(fn (Site $site): bool => ! $site->isEdgePreview()
                && EdgeQueueWorkers::runningInstances($site) > 0
                && ! EdgeQueueWorkers::for($site)['paused']);

        if ($sites->isEmpty()) {
            return self::SUCCESS;
        }

        // Three Cloudflare API calls per run however many apps there are:
        // Cloudflare allows 1,200 per 5 minutes per account, and one query
        // set per app would run out at a few hundred apps.
        try {
            $client = EdgeCloudflareClient::fromConfig();
            $applications = $client->listContainerApplications();
            $failLines = $client->workerLogs([], self::WINDOW_MINUTES, 2000, ' FAIL');
            $crashLines = $client->workerLogs([], self::WINDOW_MINUTES, 2000, 'within 10s, retrying');
        } catch (Throwable $e) {
            $this->warn($e->getMessage());

            return self::SUCCESS;
        }

        foreach ($sites as $site) {
            // A site's lines: its Worker script and the container applications named after it.
            $script = EdgeContainerDeployer::scriptName($site);
            $services = [$script => true];
            foreach ($applications as $application) {
                if ($application['id'] !== '' && str_starts_with($application['name'], $script)) {
                    $services[$application['id']] = true;
                }
            }
            $mine = static fn (array $l): bool => isset($services[$l['service']]);
            $failed = array_values(array_filter($failLines, static fn (array $l): bool => $mine($l) && str_ends_with(rtrim($l['message']), 'FAIL')));
            $crashes = array_values(array_filter($crashLines, static fn (array $l): bool => $mine($l) && str_contains($l['message'], 'within 10s, retrying')));

            if ($failed !== []) {
                $this->notifyOnce($publisher, $site, 'edge.workers.failed_jobs',
                    trans_choice(':count job failed on :app|:count jobs failed on :app', count($failed), ['app' => $site->name]),
                    __('In the last :minutes minutes. Latest: :line', ['minutes' => self::WINDOW_MINUTES, 'line' => trim($failed[0]['message'])]),
                    ['failed' => count($failed)]);
            }
            if (count($crashes) >= self::CRASH_EXITS) {
                $this->notifyOnce($publisher, $site, 'edge.workers.crashing',
                    __('Queue workers on :app keep exiting', ['app' => $site->name]),
                    __(':count exits right after starting in the last :minutes minutes. :line', ['count' => count($crashes), 'minutes' => self::WINDOW_MINUTES, 'line' => trim($crashes[0]['message'])]),
                    ['exits' => count($crashes)]);
            }
        }

        return self::SUCCESS;
    }

    /** @param  array<string, mixed>  $metadata */
    private function notifyOnce(NotificationPublisher $publisher, Site $site, string $event, string $title, string $body, array $metadata): void
    {
        if (! Cache::add('edge:workers:'.$site->id.':alerted:'.$event, true, self::ALERT_EVERY)) {
            return;
        }
        try {
            $publisher->publish(
                eventKey: $event,
                subject: $site,
                title: $title,
                body: $body,
                url: route('sites.show', ['server' => $site->server_id, 'site' => $site->id, 'section' => 'resources']),
                metadata: $metadata,
            );
            $this->line($title);
        } catch (Throwable $e) {
            Cache::forget('edge:workers:'.$site->id.':alerted:'.$event); // try again next run
            $this->warn($site->name.': '.$e->getMessage());
        }
    }
}
