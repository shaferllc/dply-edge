<?php

declare(strict_types=1);

namespace App\Modules\Edge\Support;

use App\Models\DplyDatabase;
use App\Models\EdgeDatabase;
use App\Models\EdgeQueue;
use App\Models\Organization;
use App\Models\Site;
use App\Modules\Billing\Services\EdgeSiteBillingAnalytics;
use App\Modules\Edge\Services\EdgeAppDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Every app and the resources it uses, as rows: containers and static apps,
 * dply databases, Valkey and every other attached kind, D1 databases and
 * queues. Built only from what dply stores (meta, the hourly backup status,
 * the cached instance snapshot), so reading it never wakes or calls an app.
 * Shared by the admin Resources page (all organizations) and Projects →
 * Resources (one). Ruling r-5w7h5d0b902aeq0n.
 *
 * ponytail: builds every row on each call, with a billing read per app.
 * Paginate or cache it once there are hundreds of apps.
 */
final class EdgeResourceIndex
{
    /** Kinds rows are grouped by, in display order. */
    public const KINDS = [
        'app' => 'Apps',
        'database' => 'dply databases',
        'redis' => 'Valkey',
        'd1' => 'D1 databases',
        'queue' => 'Queues',
        'other' => 'Other resources',
    ];

    /**
     * One row per app and per resource.
     *
     * @return list<array{group: string, kind: string, id: string, name: string, org: string, orgId: ?string, apps: list<string>, state: string, detail: string, problem: ?string, costCents: ?int, href: ?string, siteId: ?string, region: ?string, engine: ?string, databaseId: ?string}>
     */
    public static function rows(?string $organizationId = null): array
    {
        $orgs = Organization::query()->when($organizationId !== null, fn ($q) => $q->whereKey($organizationId))->pluck('name', 'id');
        $rows = [];
        $primaryIds = [];

        $sites = Site::query()->whereNotNull('edge_backend')->when($organizationId !== null, fn ($q) => $q->where('organization_id', $organizationId))->orderBy('name')->get();
        foreach ($sites as $site) {
            $meta = $site->edgeMeta();
            $org = (string) ($orgs[$site->organization_id] ?? '—');
            $container = ($meta['runtime_mode'] ?? '') === 'container';
            $rows[] = self::appRow($site, $org, $container);

            $database = $meta['database'] ?? null;
            if (is_array($database) && EdgeAppDatabase::isDply($database) && (string) ($database['remote_id'] ?? '') !== '') {
                $primaryIds[(string) $database['remote_id']] = true;
                $rows[] = self::databaseRow((string) $database['remote_id'], (string) ($database['engine'] ?? 'postgres'), $site->name, $org, (string) $site->organization_id, [$site->name], $database, (string) ($database['size'] ?? ''), (int) ($database['disk_gb'] ?? 0), (int) ($database['suspend'] ?? 0), EdgeDplyDatabase::regionOf($database), (string) $site->id, null);
            }
            foreach (EdgeContainerConnections::for($site) as $connection) {
                $rows[] = [
                    'group' => $connection['kind'] === 'redis' ? 'redis' : 'other',
                    'kind' => EdgeContainerConnections::KINDS[$connection['kind']]['label'] ?? $connection['kind'],
                    'id' => $connection['target'] !== '' ? $connection['target'] : $connection['host'],
                    'name' => $connection['name'],
                    'org' => $org,
                    'orgId' => (string) $site->organization_id,
                    'apps' => [$site->name],
                    'state' => $connection['asleep'] ? 'asleep' : 'on',
                    'detail' => $connection['plan'] !== '' ? $connection['plan'] : '',
                    'problem' => null,
                    'costCents' => null,
                    'href' => null,
                    'siteId' => (string) $site->id,
                    'region' => null,
                    'engine' => null,
                    'databaseId' => null,
                ];
            }
        }

        // Databases with their own row: an app's extra ones and detached ones.
        foreach (DplyDatabase::query()->when($organizationId !== null, fn ($q) => $q->where('organization_id', $organizationId))->with('sites:id,name')->get() as $database) {
            if (isset($primaryIds[$database->remote_id])) {
                continue;
            }
            $state = (array) ($database->state ?? []);
            $rows[] = self::databaseRow($database->remote_id, (string) $database->engine, (string) $database->name, (string) ($orgs[$database->organization_id] ?? '—'), (string) $database->organization_id, $database->sites->pluck('name')->all(), $state, (string) ($database->size ?? ''), (int) $database->disk_gb, (int) $database->suspend, (string) $database->region, $database->sites->first()?->id, (string) $database->id);
        }

        foreach (EdgeDatabase::query()->when($organizationId !== null, fn ($q) => $q->where('organization_id', $organizationId))->get() as $d1) {
            $rows[] = self::plainRow('d1', 'D1', (string) $d1->cloudflare_id, (string) $d1->name, (string) ($orgs[$d1->organization_id] ?? '—'), (string) $d1->organization_id, (string) ($d1->location_hint ?? ''));
        }
        foreach (EdgeQueue::query()->when($organizationId !== null, fn ($q) => $q->where('organization_id', $organizationId))->get() as $queue) {
            $rows[] = self::plainRow('queue', 'Queue', (string) $queue->cloudflare_id, (string) $queue->name, (string) ($orgs[$queue->organization_id] ?? '—'), (string) $queue->organization_id, '');
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    private static function appRow(Site $site, string $org, bool $container): array
    {
        $meta = $site->edgeMeta();
        $problem = match (true) {
            $site->status === Site::STATUS_EDGE_FAILED => __('Last deploy failed'),
            (bool) ($meta['traffic_gate'] ?? false) => __('Paused: no plan or spending limit reached'),
            ($meta['check_copy_handoff'] ?? null) !== null => __('Visitors are on a check copy'),
            default => null,
        };
        // Only what a recent visit to the app's Resources tab cached: never asks the app.
        $instances = $container ? Cache::get('edge-container-instances:'.$site->id) : null;
        $running = is_array($instances) && is_array($instances['instances'] ?? null) ? (int) ($instances['running'] ?? 0) : null;
        $billing = app(EdgeSiteBillingAnalytics::class)->forSite($site);

        return [
            'group' => 'app',
            'kind' => $container ? 'Container' : 'Static',
            'id' => (string) $site->id,
            'name' => (string) $site->name,
            'org' => $org,
            'orgId' => (string) $site->organization_id,
            'apps' => [],
            'state' => (string) $site->status,
            'detail' => trim($site->edgeHostname().($running !== null ? ' · '.trans_choice(':count instance running|:count instances running', $running) : '')),
            'problem' => $problem,
            'costCents' => is_array($billing) ? (int) ($billing['total_cents'] ?? 0) : null,
            'href' => $site->server_id !== null ? route('sites.show', ['site' => $site->id]) : null,
            'siteId' => (string) $site->id,
            'region' => null,
            'engine' => $container ? 'container' : null,
            'databaseId' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $record  where its backup status lives (site meta or the row's state)
     * @param  list<string>  $apps
     * @return array<string, mixed>
     */
    private static function databaseRow(string $remoteId, string $engine, string $name, string $org, string $orgId, array $apps, array $record, string $size, int $diskGb, int $suspend, string $region, ?string $siteId, ?string $databaseId): array
    {
        $backup = (array) ($record['backup'] ?? []);
        $verify = (array) ($backup['verify'] ?? []);
        $problem = EdgeDplyDatabase::backupProblem($backup);
        if ($problem === null && $verify !== [] && ! ($verify['ok'] ?? false)) {
            $problem = __('Restore check failed: :error', ['error' => Str::limit((string) ($verify['error'] ?? ''), 120)]);
        }
        $lastBackup = (string) ($backup['last_ok_at'] ?? '');
        $detail = collect([
            $size !== '' ? $size : null,
            $diskGb > 0 ? $diskGb.' GB disk' : null,
            $suspend === -1 ? __('stays on') : __('sleeps'),
            $lastBackup !== '' ? __('backed up :ago', ['ago' => Carbon::parse($lastBackup)->diffForHumans()]) : __('no backup yet'),
            ($verify['ok'] ?? false) ? __('restore checked :ago', ['ago' => Carbon::parse((string) $verify['at'])->diffForHumans()]) : null,
        ])->filter()->implode(' · ');

        return [
            'group' => 'database',
            'kind' => ['postgres' => 'Postgres', 'mysql' => 'MySQL', 'mongodb' => 'MongoDB'][$engine] ?? $engine,
            'id' => $remoteId,
            'name' => $name,
            'org' => $org,
            'orgId' => $orgId,
            'apps' => $apps,
            'state' => '',
            'detail' => $detail,
            'problem' => $problem,
            'costCents' => null,
            'href' => null,
            'siteId' => $siteId,
            'region' => $region,
            'engine' => $engine,
            'databaseId' => $databaseId,
        ];
    }

    /** @return array<string, mixed> */
    private static function plainRow(string $group, string $kind, string $id, string $name, string $org, string $orgId, string $detail): array
    {
        return ['group' => $group, 'kind' => $kind, 'id' => $id, 'name' => $name, 'org' => $org, 'orgId' => $orgId, 'apps' => [], 'state' => '', 'detail' => $detail, 'problem' => null, 'costCents' => null, 'href' => null, 'siteId' => null, 'region' => null, 'engine' => null, 'databaseId' => null];
    }
}
