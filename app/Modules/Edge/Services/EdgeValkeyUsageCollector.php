<?php

declare(strict_types=1);

namespace App\Modules\Edge\Services;

use App\Models\DplyDatabase;
use App\Models\EdgePostgresUsage;
use App\Models\EdgeRedisUsage;
use App\Models\Site;
use App\Modules\Billing\Support\UsagePrice;
use App\Modules\Edge\Support\EdgeContainerConnections;
use App\Modules\Edge\Support\EdgeDplyDatabase;
use App\Modules\Edge\Support\EdgeValkey;
use App\Modules\Notifications\Services\NotificationPublisher;
use App\Modules\Providers\Valkey\ValkeyGatewayClient;
use App\Modules\Providers\Valkey\ValkeyRegions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Per-second billing for dply Valkey (T-021). The gateway reports a running
 * total of awake seconds per database, and of commands served over its REST
 * API (rest_commands, billed per 100K). Each run adds what changed since the
 * last run to today's edge_redis_usage row. The last total seen is kept on
 * the site (meta.edge.valkey_counter) so a missed run is caught up, never lost.
 *
 * dply Postgres rides the same pass (same gateway, same totals): awake
 * seconds x the size's compute units, plus the volume's size for every hour
 * since the last run, into edge_postgres_usage, where EdgeAppDatabaseCost
 * prices it like any Postgres. The last total and time are on
 * meta.edge.database (usage_counter, storage_at).
 */
class EdgeValkeyUsageCollector
{
    /**
     * Runs one at a time: two overlapping runs would both read the same last
     * counter and bill a stretch twice. A run that finds the lock taken skips;
     * nothing is lost, since the gateway's totals only go up and the next run
     * adds the difference.
     *
     * @param  ?string  $siteId  Only this app (the workspace refreshes one on open).
     * @return array{sites: int, seconds: int}
     */
    public function collect(bool $dryRun = false, ?string $siteId = null): array
    {
        if (! ValkeyGatewayClient::configured()) {
            return ['sites' => 0, 'seconds' => 0];
        }
        $lock = Cache::lock('edge-valkey-usage-collect', 120);
        if (! $lock->get()) {
            return ['sites' => 0, 'seconds' => 0];
        }
        try {
            return $this->collectLocked($dryRun, $siteId);
        } finally {
            $lock->release();
        }
    }

    /** @return array{sites: int, seconds: int} */
    private function collectLocked(bool $dryRun, ?string $siteId): array
    {
        // Every region's gateway: tenant ids are unique across regions.
        $totals = [];
        $restTotals = [];
        foreach (array_keys(ValkeyRegions::all()) as $region) {
            if (ValkeyGatewayClient::configured($region)) {
                $usage = ValkeyGatewayClient::fromConfig($region)->usageTotals();
                $totals += $usage['awake_seconds'];
                $restTotals += $usage['rest_commands'];
            }
        }
        $date = now()->utc()->toDateString();
        $sites = 0;
        $seconds = 0;

        $this->collectOtherDplyDatabases($totals, $date, $dryRun, $siteId);

        Site::query()->whereNotNull('edge_backend')->whereNotNull('organization_id')
            ->when($siteId !== null, fn ($query) => $query->whereKey($siteId))
            ->each(function (Site $site) use ($totals, $restTotals, $date, $dryRun, &$sites, &$seconds): void {
                $this->collectDplyPostgres($site, $totals, $date, $dryRun);
                if (! $dryRun) {
                    $this->trackBackup($site);
                }
                $counters = (array) ($site->edgeMeta()['valkey_counter'] ?? []);
                // REST commands (valkey-gateway rest.go), tracked the same way.
                $restCounters = (array) ($site->edgeMeta()['valkey_rest_counter'] ?? []);
                $added = 0;
                $restAdded = 0;
                foreach (EdgeContainerConnections::for($site) as $connection) {
                    if ($connection['kind'] !== 'redis' || ! EdgeValkey::isTarget($connection['target'])) {
                        continue;
                    }
                    $restTotal = $restTotals[EdgeValkey::tenantId($connection['target'])] ?? null;
                    if ($restTotal !== null) {
                        $restLast = (int) ($restCounters[$connection['target']] ?? 0);
                        $restAdded += $restTotal >= $restLast ? $restTotal - $restLast : $restTotal;
                        $restCounters[$connection['target']] = $restTotal;
                    }
                    $total = $totals[EdgeValkey::tenantId($connection['target'])] ?? null;
                    if ($total === null) {
                        continue;
                    }
                    $last = (int) ($counters[$connection['target']] ?? 0);
                    // A total below the last one means the tenant was recreated.
                    $added += $total >= $last ? $total - $last : $total;
                    $counters[$connection['target']] = $total;
                }
                if (($added === 0 && $restAdded === 0) || $dryRun) {
                    $sites += $added > 0 ? 1 : 0;
                    $seconds += $added;

                    return;
                }

                DB::transaction(function () use ($site, $date, $added, $counters, $restAdded, $restCounters): void {
                    $row = EdgeRedisUsage::query()->firstOrCreate(
                        ['site_id' => $site->id, 'date' => $date],
                        ['organization_id' => $site->organization_id],
                    );
                    if ($added > 0) {
                        $row->increment('awake_seconds', $added);
                    }
                    if ($restAdded > 0) {
                        $row->increment('rest_commands', $restAdded);
                    }
                    $site->mergeEdgeMeta(['valkey_counter' => $counters, 'valkey_rest_counter' => $restCounters]);
                    $site->save();
                });
                $sites++;
                $seconds += $added;
            });

        return ['sites' => $sites, 'seconds' => $seconds];
    }

    /** @param  array<string, int>  $totals */
    /**
     * Copies the database's backup status onto meta.edge.database.backup for
     * the Resources tab. Notifies once when backups start failing (again after
     * they recover), and whenever a window of changes is newly lost.
     */
    private function trackBackup(Site $site): void
    {
        $database = $site->edgeMeta()['database'] ?? null;
        if (! is_array($database) || ! EdgeAppDatabase::isDply($database) || (string) ($database['remote_id'] ?? '') === '') {
            return;
        }
        $backup = $this->backupStatus((string) $database['remote_id'], EdgeDplyDatabase::regionOf($database), (array) ($database['backup'] ?? []), $site, $site->name);
        if ($backup !== null) {
            $site->mergeEdgeMeta(['database' => array_merge($database, ['backup' => $backup])]);
            $site->save();
        }
    }

    /** A database that is no app's primary keeps its backup status on its own row (DplyDatabases). */
    private function trackOtherBackup(DplyDatabase $database): void
    {
        $state = (array) ($database->state ?? []);
        $site = $database->sites()->first(); // who hears about it; none when detached everywhere
        $backup = $this->backupStatus($database->remote_id, $database->region, (array) ($state['backup'] ?? []), $site, $database->name);
        if ($backup !== null) {
            $database->forceFill(['state' => array_merge($state, ['backup' => $backup])])->save();
        }
    }

    /**
     * The gateway's backup status, alerting once when backups start failing
     * or changes are lost. Null when unreachable or unchanged.
     *
     * @param  array<string, mixed>  $previous
     * @return array<string, mixed>|null
     */
    private function backupStatus(string $remoteId, string $region, array $previous, ?Site $site, string $label): ?array
    {
        try {
            $status = ValkeyGatewayClient::fromConfig($region)->backupStatus($remoteId);
        } catch (Throwable) {
            return null; // the gateway or agent is unreachable; keep the last known status
        }
        $problem = EdgeDplyDatabase::backupProblem($status);
        $alerted = $problem !== null && ($previous['alerted'] ?? false);
        if ($problem !== null && ! $alerted && $site !== null) {
            $alerted = $this->notify($site, __('Database backup failed for :site', ['site' => $label]), $problem);
        }
        if ($site !== null && ($status['lost_at'] ?? '') !== '' && ($status['lost_at'] ?? '') !== ($previous['lost_at'] ?? '')) {
            $this->notify($site, __('Some database changes for :site cannot be restored', ['site' => $label]), ucfirst((string) $status['lost']).'. '.__('A new full backup was started.'));
        }
        $backup = array_merge($status, ['alerted' => $alerted]);

        return $backup != $previous ? $backup : null;
    }

    private function notify(Site $site, string $title, string $body): bool
    {
        try {
            app(NotificationPublisher::class)->publish(
                eventKey: 'site.errors.operation_failed',
                subject: $site,
                title: $title,
                body: $body,
                url: route('sites.show', ['server' => $site->server_id, 'site' => $site->id, 'section' => 'general']),
            );

            return true;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    /**
     * dply databases that are no app's primary (another app's extra, or
     * detached from every app) bill from their own row: the primary's usage
     * goes through its app's mirror (collectDplyPostgres).
     *
     * @param  array<string, int>  $totals
     */
    private function collectOtherDplyDatabases(array $totals, string $date, bool $dryRun, ?string $siteId): void
    {
        DplyDatabase::query()
            ->whereDoesntHave('sites', fn ($q) => $q->where('dply_database_site.primary', true))
            ->when($siteId !== null, fn ($q) => $q->whereHas('sites', fn ($s) => $s->whereKey($siteId)))
            ->each(function (DplyDatabase $database) use ($totals, $date, $dryRun): void {
                if (! $dryRun) {
                    $this->trackOtherBackup($database);
                    $database->refresh();
                }
                $state = (array) ($database->state ?? []);
                $now = now()->timestamp;
                $total = $totals[$database->remote_id] ?? null;
                $last = (int) ($state['usage_counter'] ?? 0);
                $awake = $total === null ? 0 : ($total >= $last ? $total - $last : $total);
                $cu = UsagePrice::databaseBilledCu(array_key_exists($database->size, EdgeAppDatabase::POSTGRES_SIZES) ? $database->size : EdgeDplyDatabase::OFFERED_SIZES[0]);
                $bytes = EdgeDplyDatabase::disk($database->disk_gb) * 1024 ** 3;
                $storageByteHours = (int) round($bytes * max(0, $now - (int) ($state['storage_at'] ?? $now)) / 3600);
                $computeUnitSeconds = (int) round($awake * $cu);
                if ($dryRun || ($computeUnitSeconds === 0 && $storageByteHours === 0 && isset($state['storage_at']))) {
                    return;
                }
                DB::transaction(function () use ($database, $state, $date, $total, $last, $now, $computeUnitSeconds, $storageByteHours): void {
                    $row = EdgePostgresUsage::query()->firstOrCreate(
                        ['project_id' => $database->remote_id, 'date' => $date],
                        ['organization_id' => $database->organization_id, 'site_id' => $database->sites()->value('sites.id')],
                    );
                    $row->increment('compute_unit_seconds', $computeUnitSeconds);
                    $row->increment('storage_byte_hours', $storageByteHours);
                    $database->forceFill(['state' => array_merge($state, ['usage_counter' => $total ?? $last, 'storage_at' => $now])])->save();
                });
            });
    }

    private function collectDplyPostgres(Site $site, array $totals, string $date, bool $dryRun): void
    {
        $database = $site->edgeMeta()['database'] ?? null;
        if (! is_array($database) || ! EdgeAppDatabase::isDply($database) || (string) ($database['remote_id'] ?? '') === '') {
            return;
        }
        $id = (string) $database['remote_id'];
        $now = now()->timestamp;
        $total = $totals[$id] ?? null;
        $last = (int) ($database['usage_counter'] ?? 0);
        $awake = $total === null ? 0 : ($total >= $last ? $total - $last : $total);
        // The stored size, offered today or not: it is what the pod runs at.
        // Billed compute units: a size with its own price scales them.
        $size = (string) ($database['size'] ?? '');
        $cu = UsagePrice::databaseBilledCu(array_key_exists($size, EdgeAppDatabase::POSTGRES_SIZES) ? $size : EdgeDplyDatabase::OFFERED_SIZES[0]);
        $since = (int) ($database['storage_at'] ?? $now);
        $bytes = EdgeDplyDatabase::disk((int) ($database['disk_gb'] ?? 0)) * 1024 ** 3;
        $storageByteHours = (int) round($bytes * max(0, $now - $since) / 3600);
        $computeUnitSeconds = (int) round($awake * $cu);
        if ($dryRun || ($computeUnitSeconds === 0 && $storageByteHours === 0 && isset($database['storage_at']))) {
            return;
        }

        DB::transaction(function () use ($site, $date, $id, $database, $total, $last, $now, $computeUnitSeconds, $storageByteHours): void {
            $row = EdgePostgresUsage::query()->firstOrCreate(
                ['site_id' => $site->id, 'project_id' => $id, 'date' => $date],
                ['organization_id' => $site->organization_id],
            );
            $row->increment('compute_unit_seconds', $computeUnitSeconds);
            $row->increment('storage_byte_hours', $storageByteHours);
            $site->mergeEdgeMeta(['database' => array_merge($database, ['usage_counter' => $total ?? $last, 'storage_at' => $now])]);
            $site->save();
        });
    }
}
