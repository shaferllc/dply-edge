<?php

declare(strict_types=1);

namespace App\Modules\Edge\Console;

use App\Models\Site;
use App\Modules\Edge\Services\EdgeAppDatabase;
use App\Modules\Edge\Support\EdgeDplyDatabase;
use App\Modules\Notifications\Services\NotificationPublisher;
use App\Modules\Providers\Valkey\ValkeyGatewayClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Hourly: each dply database's size, disk and connections for the panel's
 * 30-day history, and an alert when the disk is filling or connections are
 * near the limit. (Failing backups alert from EdgeValkeyUsageCollector.)
 *
 * Reads the snapshot the database agent took before its last stop
 * (insights?cached=1), so it never wakes a sleeping database. At most one
 * alert of each kind per database every ALERT_EVERY seconds.
 *
 *   php artisan dply:edge:sample-databases
 */
class SampleEdgeDatabasesCommand extends Command
{
    public const HISTORY_DAYS = 30;

    public const ALERT_EVERY = 86400;

    /** Share of the disk, or of max connections, that warns. */
    public const WARN_AT = 0.8;

    protected $signature = 'dply:edge:sample-databases';

    protected $description = 'Record dply database history and alert on disk and connections.';

    public function handle(NotificationPublisher $publisher): int
    {
        $sites = Site::query()->where('meta->edge->database->provider', 'dply')->get();
        foreach ($sites as $site) {
            $database = $site->edgeMeta()['database'] ?? null;
            if (! is_array($database) || ! EdgeAppDatabase::isDply($database) || (string) ($database['remote_id'] ?? '') === '' || $site->isEdgePreview()) {
                continue;
            }
            try {
                $in = ValkeyGatewayClient::fromConfig(EdgeDplyDatabase::regionOf($database))->insights((string) $database['remote_id'], true);
            } catch (Throwable $e) {
                $this->warn($site->name.': '.$e->getMessage());
                $in = [];
            }

            $disk = (int) ($in['disk_bytes'] ?? 0);
            $used = (int) ($in['disk_used_bytes'] ?? 0);
            if (isset($in['size_bytes']) || $disk > 0) {
                $this->record($site, [
                    'size' => (int) ($in['size_bytes'] ?? 0),
                    'disk_used' => $used,
                    'disk' => $disk,
                    'connections' => (int) ($in['connections'] ?? 0),
                ]);
            }

            if ($disk > 0 && $used >= self::WARN_AT * $disk) {
                $this->notifyOnce($publisher, $site, 'edge.database.disk_filling',
                    __('The database disk for :app is :pct% full', ['app' => $site->name, 'pct' => (int) round($used / $disk * 100)]),
                    __('A full disk stops writes. Pick a larger disk under Resources; a disk only grows.'),
                    ['used' => $used, 'disk' => $disk]);
            }
            $max = (int) ($in['max_connections'] ?? 0);
            if ($max > 0 && (int) ($in['connections'] ?? 0) >= self::WARN_AT * $max) {
                $this->notifyOnce($publisher, $site, 'edge.database.connections_high',
                    __('The database for :app is near its connection limit', ['app' => $site->name]),
                    __(':n of :max connections were open. More instances or workers each open their own; a larger size allows more.', ['n' => (int) $in['connections'], 'max' => $max]),
                    ['connections' => (int) $in['connections'], 'max' => $max]);
            }
        }

        return self::SUCCESS;
    }

    /**
     * One point per day, the day's highest reading, newest last.
     *
     * @param  array{size: int, disk_used: int, disk: int, connections: int}  $point
     */
    private function record(Site $site, array $point): void
    {
        $site->refresh();
        $database = (array) ($site->edgeMeta()['database'] ?? []);
        $history = (array) ($database['history'] ?? []);
        $today = now()->utc()->toDateString();
        $last = end($history);
        if (is_array($last) && ($last['date'] ?? '') === $today) {
            array_pop($history);
            foreach (['size', 'disk_used', 'connections'] as $k) {
                $point[$k] = max($point[$k], (int) ($last[$k] ?? 0));
            }
        }
        $history[] = ['date' => $today] + $point;
        $database['history'] = array_slice(array_values($history), -self::HISTORY_DAYS);
        $site->mergeEdgeMeta(['database' => $database]);
        $site->save();
    }

    /** @param  array<string, mixed>  $metadata */
    private function notifyOnce(NotificationPublisher $publisher, Site $site, string $event, string $title, string $body, array $metadata): void
    {
        if (! Cache::add('edge:database:'.$site->id.':alerted:'.$event, true, self::ALERT_EVERY)) {
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
            Cache::forget('edge:database:'.$site->id.':alerted:'.$event); // try again next run
            $this->warn($site->name.': '.$e->getMessage());
        }
    }
}
