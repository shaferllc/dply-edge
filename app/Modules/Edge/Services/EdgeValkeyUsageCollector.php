<?php

declare(strict_types=1);

namespace App\Modules\Edge\Services;

use App\Models\EdgePostgresUsage;
use App\Models\EdgeRedisUsage;
use App\Models\Site;
use App\Modules\Edge\Support\EdgeContainerConnections;
use App\Modules\Edge\Support\EdgeDplyDatabase;
use App\Modules\Edge\Support\EdgeValkey;
use App\Modules\Notifications\Services\NotificationPublisher;
use App\Modules\Providers\Valkey\ValkeyGatewayClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Per-second billing for dply Valkey (T-021). The gateway reports a running
 * total of awake seconds per database. Each run adds what changed since the
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
        $totals = ValkeyGatewayClient::fromConfig()->usage();
        $date = now()->utc()->toDateString();
        $sites = 0;
        $seconds = 0;

        Site::query()->whereNotNull('edge_backend')->whereNotNull('organization_id')
            ->when($siteId !== null, fn ($query) => $query->whereKey($siteId))
            ->each(function (Site $site) use ($totals, $date, $dryRun, &$sites, &$seconds): void {
                $this->collectDplyPostgres($site, $totals, $date, $dryRun);
                if (! $dryRun) {
                    $this->trackBackup($site);
                }
                $counters = (array) ($site->edgeMeta()['valkey_counter'] ?? []);
                $added = 0;
                foreach (EdgeContainerConnections::for($site) as $connection) {
                    if ($connection['kind'] !== 'redis' || ! EdgeValkey::isTarget($connection['target'])) {
                        continue;
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
                if ($added === 0 || $dryRun) {
                    $sites += $added > 0 ? 1 : 0;
                    $seconds += $added;

                    return;
                }

                DB::transaction(function () use ($site, $date, $added, $counters): void {
                    $row = EdgeRedisUsage::query()->firstOrCreate(
                        ['site_id' => $site->id, 'date' => $date],
                        ['organization_id' => $site->organization_id],
                    );
                    $row->increment('awake_seconds', $added);
                    $site->mergeEdgeMeta(['valkey_counter' => $counters]);
                    $site->save();
                });
                $sites++;
                $seconds += $added;
            });

        return ['sites' => $sites, 'seconds' => $seconds];
    }

    /** @param  array<string, int>  $totals */
    /**
     * Copies the database's last backup result onto meta.edge.database.backup
     * for the Resources tab, and notifies once when backups start failing
     * (again after the next success).
     */
    private function trackBackup(Site $site): void
    {
        $database = $site->edgeMeta()['database'] ?? null;
        if (! is_array($database) || ! EdgeAppDatabase::isDply($database) || (string) ($database['remote_id'] ?? '') === '') {
            return;
        }
        try {
            $status = ValkeyGatewayClient::fromConfig()->backupStatus((string) $database['remote_id']);
        } catch (Throwable) {
            return; // the gateway or agent is unreachable; keep the last known status
        }
        $previous = (array) ($database['backup'] ?? []);
        $failing = ($status['last_error_at'] ?? '') !== '' && ($status['last_error_at'] ?? '') > ($status['last_ok_at'] ?? '');
        $alerted = $failing && ($previous['alerted'] ?? false);
        if ($failing && ! $alerted) {
            try {
                app(NotificationPublisher::class)->publish(
                    eventKey: 'site.errors.operation_failed',
                    subject: $site,
                    title: __('Database backup failed for :site', ['site' => $site->name]),
                    body: (string) ($status['last_error'] ?? ''),
                    url: route('sites.show', ['server' => $site->server_id, 'site' => $site->id, 'section' => 'resources']),
                );
                $alerted = true;
            } catch (Throwable $e) {
                report($e);
            }
        }
        $backup = array_merge($status, ['alerted' => $alerted]);
        if ($backup != $previous) {
            $site->mergeEdgeMeta(['database' => array_merge($database, ['backup' => $backup])]);
            $site->save();
        }
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
        $cu = EdgeAppDatabase::POSTGRES_SIZES[EdgeDplyDatabase::size((string) ($database['size'] ?? ''))]['cu'];
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
