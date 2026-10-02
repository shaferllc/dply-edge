<?php

declare(strict_types=1);

namespace App\Support\Sites;

use App\Models\EdgeDeployment;
use App\Models\EdgeUsageSnapshot;
use App\Models\Site;
use App\Modules\Edge\Support\EdgeContainerSettings;
use Illuminate\Support\Carbon;

/**
 * View data for the Overview's service map: the app drawn the way a request
 * travels (visitors → edge → runtime → data). Built from stored config and
 * samples only, so the Overview never waits on a provider call.
 */
final class EdgeServiceMap
{
    /**
     * @return array{
     *     requests: list<int>, requestsToday: int, requests30d: int,
     *     placement: ?array{location: string, region: string, rtt: ?int},
     *     runtime: string, framework: ?string,
     *     container: ?array{type: string, memGib: float, maxInstances: int, sleepAfter: string, memMb: ?int, memSeries: list<float>},
     *     workers: ?array{processes: int, queues: string, autoscale: bool, maxInstances: int, paused: bool, scheduler: bool},
     *     database: ?array{engine: string, name: string, plan: string, status: string, diskGb: ?int, suspend: ?int, backupAt: ?Carbon, logAt: ?Carbon},
     *     stores: list<array{name: string, plan: string, asleep: bool, sleepAfter: ?int}>,
     *     serving: ?EdgeDeployment, sameCommitRedeploys: int,
     * }
     */
    public static function for(Site $site): array
    {
        $meta = $site->edgeMeta();
        $runtime = (string) ($meta['runtime_mode'] ?? 'static');
        $container = is_array($meta['container'] ?? null) ? $meta['container'] : null;
        $workers = is_array($container['workers'] ?? null) ? $container['workers'] : null;
        $db = is_array($meta['database'] ?? null) ? $meta['database'] : null;
        $placement = is_array($meta['placement'] ?? null) ? $meta['placement'] : null;
        $framework = trim((string) ($meta['build']['framework'] ?? ''));

        $requests = self::dailyRequests($site);

        $memSeries = [];
        if ($runtime === 'container') {
            foreach ((array) ($meta['memory']['samples'] ?? []) as $sample) {
                if (is_array($sample) && isset($sample[1])) {
                    $memSeries[] = (float) $sample[1];
                }
            }
        }
        $type = (string) ($container['instance_type'] ?? 'basic');

        $sleep = (array) ($meta['valkey_sleep'] ?? []);
        $stores = [];
        foreach ((array) ($meta['connections'] ?? []) as $conn) {
            if (! is_array($conn) || ($conn['kind'] ?? '') !== 'redis') {
                continue;
            }
            $stores[] = [
                'name' => (string) ($conn['name'] ?? 'REDIS'),
                'plan' => str_replace('_', ' ', (string) ($conn['plan'] ?? '')),
                'asleep' => (bool) ($conn['asleep'] ?? false),
                'sleepAfter' => isset($sleep[$conn['target'] ?? '']) ? (int) $sleep[$conn['target']] : null,
            ];
        }

        [$serving, $redeploys] = self::serving($site, $meta['active_deployment_id'] ?? null);

        return [
            'requests' => $requests,
            'requestsToday' => (int) end($requests),
            'requests30d' => array_sum($requests),
            'placement' => $placement && filled($placement['location'] ?? null) ? [
                'location' => (string) $placement['location'],
                'region' => (string) ($placement['region'] ?? ''),
                'rtt' => isset($placement['rtt_ms']) ? (int) $placement['rtt_ms'] : null,
            ] : null,
            'runtime' => $runtime,
            'framework' => $framework !== '' && strtolower($framework) !== 'unknown' ? ucfirst($framework) : null,
            'container' => $runtime === 'container' && $container !== null ? [
                'type' => $type,
                'memGib' => (float) (EdgeContainerSettings::INSTANCE_TYPES[$type][1] ?? 1),
                'maxInstances' => (int) ($container['max_instances'] ?? 1),
                'sleepAfter' => (string) ($container['sleep_after'] ?? ''),
                'memMb' => $memSeries !== [] ? (int) round(end($memSeries)) : null,
                'memSeries' => array_slice($memSeries, -48),
            ] : null,
            'workers' => $runtime === 'container' && ($workers['enabled'] ?? false) ? [
                'processes' => (int) ($workers['processes'] ?? 1),
                'queues' => (string) ($workers['queues'] ?? 'default'),
                'autoscale' => (bool) ($workers['autoscale'] ?? false),
                'maxInstances' => (int) ($workers['max_instances'] ?? 1),
                'paused' => (bool) ($workers['paused'] ?? false),
                'scheduler' => (bool) ($container['scheduler'] ?? false),
            ] : null,
            'database' => $db !== null && filled($db['engine'] ?? null) ? [
                'engine' => ['postgres' => 'Postgres', 'mysql' => 'MySQL', 'mongodb' => 'MongoDB', 'sql' => 'SQLite'][$db['engine']] ?? ucfirst((string) $db['engine']),
                'name' => (string) ($db['name'] ?? ''),
                'plan' => (string) ($db['plan'] ?? ''),
                'status' => (string) ($db['status'] ?? ''),
                'diskGb' => isset($db['disk_gb']) ? (int) $db['disk_gb'] : null,
                'suspend' => isset($db['suspend']) ? (int) $db['suspend'] : null,
                'backupAt' => filled($db['backup']['last_ok_at'] ?? null) ? Carbon::parse($db['backup']['last_ok_at']) : null,
                'logAt' => filled($db['backup']['log_ok_at'] ?? null) ? Carbon::parse($db['backup']['log_ok_at']) : null,
                'checkedAt' => filled($db['backup']['checked_at'] ?? null) ? Carbon::parse($db['backup']['checked_at']) : null,
                // The weekly restore check (VerifyDatabaseBackupJob): ok + at, or the error.
                'verify' => is_array($db['backup']['verify'] ?? null) ? $db['backup']['verify'] : null,
            ] : null,
            'stores' => $stores,
            'serving' => $serving,
            'sameCommitRedeploys' => $redeploys,
        ];
    }

    /**
     * SVG path through $values in a $w × $h box; null when there is nothing to draw.
     * $ceiling fixes the top of the scale (e.g. a memory limit) instead of the peak.
     *
     * @param  list<int|float>  $values
     */
    public static function path(array $values, int $w, int $h, bool $closed = false, ?float $ceiling = null): ?string
    {
        $peak = $values === [] ? 0 : max($values);
        if ($peak <= 0 || count($values) < 2) {
            return null;
        }
        $max = $ceiling !== null ? max($ceiling, $peak) / 1.1 : $peak;
        $step = $w / (count($values) - 1);
        $d = '';
        foreach (array_values($values) as $i => $v) {
            $d .= ($i === 0 ? 'M' : ' L').round($i * $step, 1).' '.round($h - 2 - ($v / ($max * 1.1)) * ($h - 4), 1);
        }

        return $closed ? $d." L{$w} {$h} L0 {$h} Z" : $d;
    }

    /** "10m" → "10 min", "1h" → "1 h". */
    public static function duration(string $value): string
    {
        return preg_replace(['/^(\d+)m$/', '/^(\d+)h$/'], ['$1 min', '$1 h'], $value) ?? $value;
    }

    /** @return list<int> requests per day, oldest first, last 30 days (snapshots are daily) */
    private static function dailyRequests(Site $site): array
    {
        $start = now()->subDays(29)->startOfDay();
        $byDay = EdgeUsageSnapshot::query()
            ->where('site_id', $site->id)
            ->where('period_start', '>=', $start->toDateString())
            ->groupBy('period_start')
            ->selectRaw('period_start, COALESCE(SUM(requests), 0) AS requests')
            ->get()
            ->mapWithKeys(fn ($r) => [Carbon::parse($r->period_start)->toDateString() => (int) $r->requests]);

        $series = [];
        for ($i = 0; $i < 30; $i++) {
            $series[] = (int) ($byDay[$start->copy()->addDays($i)->toDateString()] ?? 0);
        }

        return $series;
    }

    /**
     * The deployment serving traffic, plus how many other deployments shipped
     * that same commit (redeploys without a new push).
     *
     * @return array{0: ?EdgeDeployment, 1: int}
     */
    private static function serving(Site $site, mixed $activeId): array
    {
        if (! is_string($activeId) || $activeId === '') {
            return [null, 0];
        }
        $serving = EdgeDeployment::query()->where('site_id', $site->id)->find($activeId);
        if ($serving === null || blank($serving->git_commit)) {
            return [$serving, 0];
        }

        return [$serving, EdgeDeployment::query()
            ->where('site_id', $site->id)
            ->where('git_commit', $serving->git_commit)
            ->whereKeyNot($serving->id)
            ->count()];
    }
}
