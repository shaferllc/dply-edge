<?php

declare(strict_types=1);

namespace App\Modules\Edge\Support;

use App\Models\DplyDatabase;
use App\Models\Site;
use App\Modules\Edge\Services\DplyDatabases;
use App\Modules\Edge\Services\EdgeAppDatabase;
use App\Modules\Notifications\Services\NotificationPublisher;
use Illuminate\Support\Carbon;
use RuntimeException;
use Throwable;

/**
 * Suggest a bigger or smaller size for a dply database, and resize only
 * when someone approves it (now, or tonight in the organization's time
 * zone). A resize restarts the database, so it is never automatic.
 *
 * Evidence is the hourly memory sample (SampleEdgeDatabasesCommand, from the
 * database agent's cgroup numbers) and the engine's cache hit rate:
 *
 *   bigger   the last UP_HOURS samples all hold over UP_MEMORY of memory,
 *            or all read a cache hit rate under UP_HIT_RATE;
 *   smaller  DOWN_DAYS of samples never above DOWN_MEMORY, with the cache
 *            hit rate at or above DOWN_HIT_RATE throughout.
 *
 * State: `memory` (samples), `resize_dismissed` ({size, at, by}) and
 * `resize_scheduled` ({size, at, by}). Each function takes an app (its
 * primary, at meta.edge.database) or a database row (one that is no app's
 * primary: its state through DplyDatabases::record / remember).
 */
final class EdgeDatabaseResize
{
    public const SAMPLES = 168;

    public const UP_HOURS = 6;

    public const UP_MEMORY = 0.85;

    public const UP_HIT_RATE = 90.0;

    public const DOWN_DAYS = 7;

    public const DOWN_MEMORY = 0.35;

    public const DOWN_HIT_RATE = 99.0;

    /** Over this now: the memory_high alert, apart from the slower suggestion. */
    public const HIGH_MEMORY = 0.9;

    public const DISMISS_DAYS = 30;

    /** Local hour a "tonight" resize runs. */
    public const TONIGHT_HOUR = 3;

    /**
     * Memory samples, oldest first: [unix time, MB the engine holds, MB limit, cache hit % or null].
     *
     * @return list<array{0: int, 1: int, 2: int, 3: float|null}>
     */
    public static function samples(Site|DplyDatabase $of): array
    {
        return array_values(array_filter((array) (self::state($of)['memory'] ?? []), 'is_array'));
    }

    /**
     * Add one sample from a database agent snapshot, if it is new and fresh:
     * an asleep database's snapshot repeats hour after hour.
     *
     * @param  array<string, mixed>  $insights
     * @return array{0: int, 1: int, 2: int, 3: float|null}|null the sample added
     */
    public static function record(Site|DplyDatabase $of, array $insights): ?array
    {
        $limit = (int) ($insights['memory_bytes'] ?? 0);
        $taken = strtotime((string) ($insights['taken_at'] ?? '')) ?: 0;
        if ($limit <= 0 || $taken < now()->subMinutes(90)->getTimestamp()) {
            return null;
        }
        $samples = self::samples($of);
        if ($samples !== [] && end($samples)[0] >= $taken) {
            return null;
        }
        $hit = $insights['cache_hit_ratio'] ?? null;
        $sample = [$taken, (int) round((int) ($insights['memory_anon_bytes'] ?? 0) / 1048576), (int) round($limit / 1048576), is_numeric($hit) ? (float) $hit : null];
        $samples[] = $sample;
        self::remember($of, ['memory' => array_slice($samples, -self::SAMPLES)]);

        return $sample;
    }

    /**
     * A size to move to, and why; null when the current one fits, nothing
     * fits better, it was dismissed, or the trial pins the size.
     *
     * @return array{size: string, direction: string, reason: string, from: string}|null
     */
    public static function suggestion(Site|DplyDatabase $of): ?array
    {
        $database = self::state($of);
        if (! EdgeAppDatabase::isDply($database) || EdgeTrialLimits::applies($of->organization)) {
            return null;
        }
        $current = (string) ($database['size'] ?? '');
        $sizes = EdgeDplyDatabase::offeredSizes();
        $at = array_search($current, $sizes, true);
        if ($at === false) {
            return null;
        }
        $samples = self::samples($of);
        $recent = array_slice($samples, -self::UP_HOURS);
        $share = static fn (array $s): float => $s[2] > 0 ? $s[1] / $s[2] : 0.0;

        $suggestion = null;
        if (count($recent) === self::UP_HOURS && isset($sizes[$at + 1])) {
            if (collect($recent)->every(fn ($s) => $share($s) >= self::UP_MEMORY)) {
                $suggestion = ['size' => $sizes[$at + 1], 'direction' => 'up', 'reason' => __('It has used over :pct% of its memory for the last :hours hours it ran.', ['pct' => (int) (self::UP_MEMORY * 100), 'hours' => self::UP_HOURS])];
            } elseif (collect($recent)->every(fn ($s) => $s[3] !== null && $s[3] < self::UP_HIT_RATE)) {
                $suggestion = ['size' => $sizes[$at + 1], 'direction' => 'up', 'reason' => __('Under :pct% of reads have come from memory for the last :hours hours it ran, so it waits on disk. More memory keeps more of the data in memory.', ['pct' => (int) self::UP_HIT_RATE, 'hours' => self::UP_HOURS])];
            }
        }
        $week = array_values(array_filter($samples, fn ($s) => $s[0] >= now()->subDays(self::DOWN_DAYS)->getTimestamp()));
        if ($suggestion === null && $at > 0 && $week !== [] && $week[0][0] <= now()->subDays(self::DOWN_DAYS - 1)->getTimestamp()
            && collect($week)->every(fn ($s) => $share($s) < self::DOWN_MEMORY && ($s[3] === null || $s[3] >= self::DOWN_HIT_RATE))) {
            $suggestion = ['size' => $sizes[$at - 1], 'direction' => 'down', 'reason' => __('It has used under :pct% of its memory for :days days, with nearly every read from memory. A smaller size costs less.', ['pct' => (int) (self::DOWN_MEMORY * 100), 'days' => self::DOWN_DAYS])];
        }
        if ($suggestion === null) {
            return null;
        }
        $dismissed = (array) ($database['resize_dismissed'] ?? []);
        if (($dismissed['size'] ?? null) === $suggestion['size'] && (int) ($dismissed['until'] ?? 0) > now()->getTimestamp()) {
            return null;
        }

        return $suggestion + ['from' => $current];
    }

    /** Right now the engine holds over HIGH_MEMORY of its memory (latest fresh sample). */
    public static function memoryHigh(Site|DplyDatabase $of): ?array
    {
        $last = self::samples($of) === [] ? null : last(self::samples($of));

        return is_array($last) && $last[2] > 0 && $last[1] / $last[2] >= self::HIGH_MEMORY && $last[0] >= now()->subMinutes(90)->getTimestamp()
            ? ['used_mb' => $last[1], 'limit_mb' => $last[2]]
            : null;
    }

    public static function dismiss(Site|DplyDatabase $of, string $size): void
    {
        self::remember($of, ['resize_dismissed' => ['size' => $size, 'until' => now()->addDays(self::DISMISS_DAYS)->getTimestamp()]]);
    }

    /** When "tonight" is: the next TONIGHT_HOUR:00 in the organization's time zone. */
    public static function tonight(Site|DplyDatabase $of): Carbon
    {
        $tz = (string) ($of->organization?->timezone ?: 'UTC');
        try {
            $at = now($tz)->setTime(self::TONIGHT_HOUR, 0);
        } catch (Throwable) {
            $at = now('UTC')->setTime(self::TONIGHT_HOUR, 0);
        }

        return $at->isPast() ? $at->addDay() : $at;
    }

    public static function schedule(Site|DplyDatabase $of, string $size, ?string $userId): Carbon
    {
        $at = self::tonight($of);
        self::remember($of, ['resize_scheduled' => ['size' => $size, 'at' => $at->getTimestamp(), 'by' => $userId]]);

        return $at;
    }

    public static function cancelScheduled(Site|DplyDatabase $of): void
    {
        self::remember($of, ['resize_scheduled' => null]);
    }

    /**
     * Resize now, through the same path as the database sheet, and say how
     * it went (edge.database.resized / resize_failed). Returns the error.
     */
    public static function apply(Site|DplyDatabase $of, string $size): ?string
    {
        if ($of instanceof DplyDatabase) {
            return self::applyToRow($of, $size);
        }
        $site = $of;
        $database = (array) ($site->edgeMeta()['database'] ?? []);
        $engine = (string) ($database['engine'] ?? 'postgres');
        $from = (string) ($database['size'] ?? '');
        $error = EdgeAppDatabase::sync($site, $engine, $engine, (string) ($database['plan'] ?? ''), $size, (int) ($database['suspend'] ?? 0), (int) ($database['disk_gb'] ?? 0));
        $site->save(); // sync() records the new size with mergeEdgeMeta, which does not save
        self::cancelScheduled($site);

        $to = EdgeAppDatabase::POSTGRES_SIZES[$size] ?? null;
        $label = $to !== null ? $to['cpu'].' · '.$to['memory'] : $size;
        self::notify($site, $error === null ? 'edge.database.resized' : 'edge.database.resize_failed',
            $error === null
                ? __('The database for :app is now :size', ['app' => $site->name, 'size' => $label])
                : __('The database for :app could not be resized', ['app' => $site->name]),
            $error === null
                ? __('It restarts at the new size the next time the app connects. Open connections dropped once.')
                : $error,
            ['from' => $from, 'to' => $size, 'error' => $error]);

        return $error;
    }

    /** A database that is no app's primary: resized through DplyDatabases, told to an app it is on. */
    private static function applyToRow(DplyDatabase $database, string $size): ?string
    {
        $from = $database->size;
        $site = $database->sites()->first();
        try {
            if ($site === null) {
                throw new RuntimeException(__('Attach it to an app to resize it.'));
            }
            DplyDatabases::update($database, $site, $size, $database->suspend, $database->disk_gb);
            $error = null;
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
        self::cancelScheduled($database);
        if ($site !== null) {
            $to = EdgeAppDatabase::POSTGRES_SIZES[$size] ?? null;
            self::notify($site, $error === null ? 'edge.database.resized' : 'edge.database.resize_failed',
                $error === null
                    ? __('The :name database is now :size', ['name' => $database->name, 'size' => $to !== null ? $to['cpu'].' · '.$to['memory'] : $size])
                    : __('The :name database could not be resized', ['name' => $database->name]),
                $error === null ? __('It restarts at the new size the next time an app connects. Open connections dropped once.') : $error,
                ['database' => $database->name, 'from' => $from, 'to' => $size, 'error' => $error], 'db-'.$database->id);
        }

        return $error;
    }

    /** @param  array<string, mixed>  $metadata */
    public static function notify(Site $site, string $event, string $title, string $body, array $metadata = [], string $sheet = 'database'): void
    {
        try {
            app(NotificationPublisher::class)->publish(
                eventKey: $event,
                subject: $site,
                title: $title,
                body: $body,
                url: self::sheetUrl($site, $sheet),
                metadata: $metadata,
            );
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** The app's Overview with a sheet open (database: the suggestion card; app: the size suggestion; db-{id}: another database's sheet). */
    public static function sheetUrl(Site $site, string $sheet = 'database'): string
    {
        return route('sites.show', ['server' => $site->server_id, 'site' => $site->id, 'section' => 'general']).'?sheet='.$sheet;
    }

    /** @return array<string, mixed> */
    private static function state(Site|DplyDatabase $of): array
    {
        return $of instanceof Site ? (array) ($of->edgeMeta()['database'] ?? []) : DplyDatabases::record($of);
    }

    /** @param  array<string, mixed>  $values  null removes a key */
    private static function remember(Site|DplyDatabase $of, array $values): void
    {
        if ($of instanceof DplyDatabase) {
            DplyDatabases::remember($of, $values);

            return;
        }
        $of->mergeEdgeMeta(['database' => array_filter(array_merge((array) ($of->edgeMeta()['database'] ?? []), $values), static fn ($v) => $v !== null)]);
        $of->save();
    }
}
