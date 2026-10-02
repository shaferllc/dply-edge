<?php

declare(strict_types=1);

namespace App\Modules\Edge\Services;

use App\Models\DplyDatabase;
use App\Modules\Edge\Jobs\RestoreEdgeDplyPostgresJob;
use App\Modules\Edge\Jobs\TransferEdgeDplyDatabaseJob;
use App\Modules\Edge\Support\EdgeDplyDatabase;
use App\Modules\Providers\Valkey\ValkeyGatewayClient;
use Illuminate\Support\Carbon;
use RuntimeException;
use Throwable;

/**
 * What you can do to any dply database, primary or not: a read-only query,
 * exports and imports, and a point-in-time restore. Shared by the Resources
 * panel and the API. State (transfer, restore) goes through
 * DplyDatabases::remember, so it lands wherever the database's state lives.
 *
 * Failures are RuntimeExceptions whose message is fit to show.
 */
final class DplyDatabaseActions
{
    /** A transfer lasts at most an hour; past this a "running" record is a dead worker. */
    private const TRANSFER_STALE_MINUTES = 70;

    public static function client(DplyDatabase $database): ValkeyGatewayClient
    {
        return ValkeyGatewayClient::fromConfig(EdgeDplyDatabase::regionOf(DplyDatabases::record($database)));
    }

    /**
     * One read-only statement (a find for MongoDB), at most 200 rows.
     *
     * @return array<string, mixed>
     */
    public static function query(DplyDatabase $database, string $sql, string $collection = '', string $filter = ''): array
    {
        $body = $database->engine === 'mongodb'
            ? ['collection' => trim($collection), 'filter' => trim($filter) ?: '{}']
            : ['sql' => $sql];

        return self::client($database)->action($database->remote_id, 'query', $body);
    }

    /**
     * Exported files, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public static function exports(DplyDatabase $database): array
    {
        return array_reverse(self::client($database)->databaseExports($database->remote_id));
    }

    public static function export(DplyDatabase $database): void
    {
        self::transfer($database, 'export', '');
    }

    /** Load a file it exported, or one uploaded to its imports. The key is built here from its own id. */
    public static function import(DplyDatabase $database, string $from, string $file): void
    {
        if (! in_array($from, ['exports', 'imports'], true) || ! self::validFile($file)) {
            throw new RuntimeException(__('Pick a file to load.'));
        }
        self::transfer($database, 'import', 'tenants/'.$database->remote_id.'/'.$from.'/'.$file);
    }

    /** A curl command that uploads a dump to its imports, valid an hour. */
    public static function uploadCommand(DplyDatabase $database, string $file): string
    {
        $file = trim($file);
        if (! self::validFile($file)) {
            throw new RuntimeException(__('Name the file: letters, digits, dot, dash and underscore.'));
        }
        // A fresh key per upload: backups and imports sit under an R2 bucket
        // lock that refuses overwriting an object, so a second upload under
        // the same name would fail.
        $link = self::client($database)->databaseUploadLink($database->remote_id, now()->utc()->format('Ymd\THis').'-'.$file);

        return 'curl -fT '.escapeshellarg($file).' '.escapeshellarg($link['url']);
    }

    /**
     * Restore to a moment inside the plan's backup window. The current data is kept
     * aside by the database until the next restore. Returns the UTC target.
     */
    public static function restore(DplyDatabase $database, string $at): string
    {
        try {
            $time = Carbon::parse($at, 'UTC');
        } catch (Throwable) {
            throw new RuntimeException(__('Pick a date and time.'));
        }
        $days = EdgeDplyDatabase::backupDays($database->organization);
        if ($time->isFuture() || $time->lt(now()->subDays($days))) {
            throw new RuntimeException(__('Pick a time in the last :days days.', ['days' => $days]));
        }
        if ((DplyDatabases::record($database)['restore']['status'] ?? '') === 'running') {
            throw new RuntimeException(__('A restore is already running.'));
        }
        $target = $time->utc()->format('Y-m-d\TH:i:s\Z');
        DplyDatabases::remember($database, ['restore' => ['status' => 'running', 'target' => $target]]);
        // Minutes of work (fetch a backup, replay or load it): a queued job.
        RestoreEdgeDplyPostgresJob::dispatch('', $target, $database->id);

        return $target;
    }

    private static function transfer(DplyDatabase $database, string $kind, string $key): void
    {
        $running = DplyDatabases::record($database)['transfer'] ?? null;
        if (is_array($running) && ($running['status'] ?? '') === 'running'
            && Carbon::parse($running['started_at'] ?? 'now')->gt(now()->subMinutes(self::TRANSFER_STALE_MINUTES))) {
            throw new RuntimeException(__('An export or import is already running.'));
        }
        DplyDatabases::remember($database, ['transfer' => ['status' => 'running', 'kind' => $kind, 'file' => basename($key), 'started_at' => now()->toIso8601String()]]);
        TransferEdgeDplyDatabaseJob::dispatch('', $kind, $key, $database->id);
    }

    private static function validFile(string $file): bool
    {
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,119}$/', $file) === 1;
    }
}
