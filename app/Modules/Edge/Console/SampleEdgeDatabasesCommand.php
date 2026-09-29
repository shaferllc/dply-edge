<?php

declare(strict_types=1);

namespace App\Modules\Edge\Console;

use App\Models\DplyDatabase;
use App\Models\Site;
use App\Modules\Edge\Services\EdgeAppDatabase;
use App\Modules\Edge\Support\EdgeDatabaseResize;
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
 * Also its memory, for the memory alert and the suggested resize
 * (EdgeDatabaseResize), which someone approves in the database sheet.
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
            // Memory, for the resize suggestion (EdgeDatabaseResize). Only a fresh, new snapshot.
            if (EdgeDatabaseResize::record($site, $in) !== null) {
                $high = EdgeDatabaseResize::memoryHigh($site);
                if ($high !== null) {
                    $this->notifyOnce($publisher, $site, 'edge.database.memory_high',
                        __('The database for :app is using :pct% of its memory', ['app' => $site->name, 'pct' => (int) round($high['used_mb'] / $high['limit_mb'] * 100)]),
                        __('Near the limit, queries slow down and the database can restart. A bigger size gives it room.'),
                        $high);
                }
            }
            $this->suggestResize($site->fresh());

            $max = (int) ($in['max_connections'] ?? 0);
            if ($max > 0 && (int) ($in['connections'] ?? 0) >= self::WARN_AT * $max) {
                $this->notifyOnce($publisher, $site, 'edge.database.connections_high',
                    __('The database for :app is near its connection limit', ['app' => $site->name]),
                    __(':n of :max connections were open. More instances or workers each open their own; a larger size allows more.', ['n' => (int) $in['connections'], 'max' => $max]),
                    ['connections' => (int) $in['connections'], 'max' => $max]);
            }
        }

        $this->sampleOtherDatabases($publisher);

        return self::SUCCESS;
    }

    /**
     * Databases that are no app's primary (DplyDatabases): their history on
     * their own row, and the same disk, connection and memory alerts, sent to
     * an app they are attached to. Resize suggestions stay with the primary.
     */
    private function sampleOtherDatabases(NotificationPublisher $publisher): void
    {
        DplyDatabase::query()->whereDoesntHave('sites', fn ($q) => $q->where('dply_database_site.primary', true))->each(function (DplyDatabase $database) use ($publisher): void {
            try {
                $in = ValkeyGatewayClient::fromConfig($database->region)->insights($database->remote_id, true);
            } catch (Throwable $e) {
                $this->warn($database->name.': '.$e->getMessage());

                return;
            }
            $disk = (int) ($in['disk_bytes'] ?? 0);
            $used = (int) ($in['disk_used_bytes'] ?? 0);
            $state = (array) ($database->state ?? []);
            if (isset($in['size_bytes']) || $disk > 0) {
                $state['history'] = self::withPoint((array) ($state['history'] ?? []), ['size' => (int) ($in['size_bytes'] ?? 0), 'disk_used' => $used, 'disk' => $disk, 'connections' => (int) ($in['connections'] ?? 0)]);
                $database->forceFill(['state' => $state])->save();
            }
            $site = $database->sites()->first();
            if ($site === null) {
                return; // detached everywhere: recorded, nobody to tell
            }
            $key = 'db-'.$database->id;
            if ($disk > 0 && $used >= self::WARN_AT * $disk) {
                $this->notifyOnce($publisher, $site, 'edge.database.disk_filling',
                    __('The :name database disk is :pct% full', ['name' => $database->name, 'pct' => (int) round($used / $disk * 100)]),
                    __('A full disk stops writes. Pick a larger disk on its card; a disk only grows.'),
                    ['database' => $database->name, 'used' => $used, 'disk' => $disk], $key);
            }
            $max = (int) ($in['max_connections'] ?? 0);
            if ($max > 0 && (int) ($in['connections'] ?? 0) >= self::WARN_AT * $max) {
                $this->notifyOnce($publisher, $site, 'edge.database.connections_high',
                    __('The :name database is near its connection limit', ['name' => $database->name]),
                    __(':n of :max connections were open. A larger size allows more.', ['n' => (int) $in['connections'], 'max' => $max]),
                    ['database' => $database->name, 'connections' => (int) $in['connections'], 'max' => $max], $key);
            }
            $limit = (int) ($in['memory_bytes'] ?? 0);
            if ($limit > 0 && (int) ($in['memory_anon_bytes'] ?? 0) >= 0.9 * $limit && strtotime((string) ($in['taken_at'] ?? '')) >= now()->subMinutes(90)->getTimestamp()) {
                $this->notifyOnce($publisher, $site, 'edge.database.memory_high',
                    __('The :name database is using :pct% of its memory', ['name' => $database->name, 'pct' => (int) round((int) $in['memory_anon_bytes'] / $limit * 100)]),
                    __('Near the limit, queries slow down and the database can restart. A bigger size gives it room.'),
                    ['database' => $database->name], $key);
            }
        });
    }

    /**
     * A day's point added to a history, the day's highest reading, newest last.
     *
     * @param  list<array<string, mixed>>  $history
     * @param  array{size: int, disk_used: int, disk: int, connections: int}  $point
     * @return list<array<string, mixed>>
     */
    private static function withPoint(array $history, array $point): array
    {
        $today = now()->utc()->toDateString();
        $last = end($history);
        if (is_array($last) && ($last['date'] ?? '') === $today) {
            array_pop($history);
            foreach (['size', 'disk_used', 'connections'] as $k) {
                $point[$k] = max($point[$k], (int) ($last[$k] ?? 0));
            }
        }
        $history[] = ['date' => $today] + $point;

        return array_slice(array_values($history), -self::HISTORY_DAYS);
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

    /**
     * Tell the app's people about a suggested resize, once per suggested
     * size: again only after the suggestion went away and came back (or its
     * dismissal ran out). Approving happens in the database sheet.
     */
    private function suggestResize(Site $site): void
    {
        $suggestion = EdgeDatabaseResize::suggestion($site);
        $database = (array) ($site->edgeMeta()['database'] ?? []);
        $told = $database['resize_notified'] ?? null;
        if ($suggestion === null || isset($database['resize_scheduled'])) {
            if ($told !== null && $suggestion === null) {
                unset($database['resize_notified']);
                $site->mergeEdgeMeta(['database' => $database]);
                $site->save();
            }

            return;
        }
        if ($told === $suggestion['size']) {
            return;
        }
        $to = EdgeAppDatabase::POSTGRES_SIZES[$suggestion['size']] ?? null;
        EdgeDatabaseResize::notify($site, 'edge.database.resize_suggested',
            $suggestion['direction'] === 'up'
                ? __('The database for :app could use a bigger size', ['app' => $site->name])
                : __('The database for :app could run on a smaller size', ['app' => $site->name]),
            $suggestion['reason'].' '.__('Suggested: :size. Review it and resize now or tonight; resizing restarts the database.', ['size' => $to !== null ? $to['cpu'].' · '.$to['memory'] : $suggestion['size']]),
            $suggestion);
        $database['resize_notified'] = $suggestion['size'];
        $site->mergeEdgeMeta(['database' => $database]);
        $site->save();
        $this->line($site->name.': suggested '.$suggestion['size']);
    }

    /** @param  array<string, mixed>  $metadata */
    private function notifyOnce(NotificationPublisher $publisher, Site $site, string $event, string $title, string $body, array $metadata, ?string $key = null): void
    {
        $cacheKey = 'edge:database:'.($key ?? $site->id).':alerted:'.$event;
        if (! Cache::add($cacheKey, true, self::ALERT_EVERY)) {
            return;
        }
        try {
            $publisher->publish(
                eventKey: $event,
                subject: $site,
                title: $title,
                body: $body,
                url: route('sites.show', ['server' => $site->server_id, 'site' => $site->id, 'section' => 'general']),
                metadata: $metadata,
            );
            $this->line($title);
        } catch (Throwable $e) {
            Cache::forget($cacheKey); // try again next run
            $this->warn($site->name.': '.$e->getMessage());
        }
    }
}
