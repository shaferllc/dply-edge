<?php

declare(strict_types=1);

namespace App\Modules\Edge\Console;

use App\Models\Site;
use App\Modules\Edge\Services\Containers\EdgeContainerDeployer;
use App\Modules\Edge\Support\EdgeContainerInstances;
use App\Modules\Edge\Support\EdgeContainerSettings;
use App\Modules\Providers\Cloudflare\EdgeCloudflareClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * How long a sleeping container app takes to answer, measured the way a
 * visitor meets it: wait until every web instance is asleep (asking the
 * Worker never starts one), time the first request, then time warm ones.
 * Wake overhead is cold minus the warm median, so the network between this
 * machine and the edge mostly cancels out. Run it before any page or post
 * quotes a wake time (Laravel Cloud publishes theirs).
 *
 * --recent reports what real visitors hit instead: the Worker's cold-start
 * rows (EdgeContainerDeployer::WAKE_DATASET) and the image's "dply-boot:"
 * log line (EdgeContainerDockerfile), which say where the time went.
 *
 *   php artisan dply:edge:wake-time {site} [--path=/] [--warm=5]
 *   php artisan dply:edge:wake-time {site} --recent
 */
class MeasureWakeTimeCommand extends Command
{
    protected $signature = 'dply:edge:wake-time
        {site : Site id of a container app}
        {--path=/ : Path to request}
        {--warm=5 : Warm requests after the cold one}
        {--wait= : Seconds to wait for the app to fall asleep (default: its sleep-after plus 3 minutes)}
        {--poll=30 : Seconds between asleep checks}
        {--recent : Report the cold starts real visitors hit in the last 7 days instead of measuring}';

    protected $description = 'Measure how long a sleeping container app takes to answer its first request.';

    public function handle(): int
    {
        $site = Site::query()->where('meta->edge->runtime_mode', 'container')->find($this->argument('site'));
        $base = rtrim((string) $site?->edgeLiveUrl(), '/');
        if ($site === null || $base === '') {
            $this->error('No deployed container app with that id.');

            return self::FAILURE;
        }
        if ($this->option('recent')) {
            return $this->recent($site);
        }
        $settings = EdgeContainerSettings::for($site);
        if ($settings['min_instances'] > 0) {
            $this->error("It keeps {$settings['min_instances']} instance(s) warm, so it never sleeps. Set Min instances to 0 first.");

            return self::FAILURE;
        }

        $poll = max(1, (int) $this->option('poll'));
        $wait = $this->option('wait') !== null ? (int) $this->option('wait') : self::seconds($settings['sleep_after']) + 180;
        if (! $this->waitForSleep($site, $base, $wait, $poll)) {
            $this->error('It did not fall asleep within '.intdiv($wait, 60).' minutes. Is something (a monitor, a cron, a browser tab) keeping it awake?');

            return self::FAILURE;
        }

        $url = $base.'/'.ltrim((string) $this->option('path'), '/');
        $cold = self::time($url);
        if ($cold['status'] < 200 || $cold['status'] >= 400) {
            $this->error("The first request answered {$cold['status']} after ".self::ms($cold['ms']).': not a clean wake, so there is nothing to quote.');

            return self::FAILURE;
        }
        $warm = array_map(static fn (): float => self::time($url)['ms'], range(1, max(1, (int) $this->option('warm'))));
        $median = self::median($warm);

        $this->line("Cold (first request after sleep): ".self::ms($cold['ms'])." ({$cold['status']})");
        $this->line('Warm, '.count($warm).' requests: median '.self::ms($median).', fastest '.self::ms(min($warm)).', slowest '.self::ms(max($warm)));
        $this->info('Wake overhead: ~'.self::ms(max(0.0, $cold['ms'] - $median)).' (cold minus warm median)');
        $this->line("Measured from this machine to {$url}; network time is in both numbers. Size: {$settings['instance_type']}.");

        return self::SUCCESS;
    }

    private function recent(Site $site): int
    {
        $client = EdgeCloudflareClient::fromConfig();
        $since = now()->utc()->subDays(7)->format('Y-m-d H:i:s');
        $id = preg_replace('/[^A-Za-z0-9]/', '', (string) $site->id);
        try {
            $rows = $client->queryAnalyticsEngineSql('SELECT double1 AS ready, double2 AS probe, double3 AS request, timestamp FROM '
                .EdgeContainerDeployer::WAKE_DATASET." WHERE blob1 = '{$id}' AND timestamp >= toDateTime('{$since}') ORDER BY timestamp DESC LIMIT 1000");
        } catch (Throwable) {
            $rows = []; // the dataset exists only after the first cold start anywhere
        }
        $column = static fn (string $key): array => array_values(array_filter(array_map(static fn ($r): float => (float) ($r[$key] ?? -1), $rows), static fn (float $v): bool => $v >= 0));
        $ready = $column('ready');
        $request = $column('request');
        if ($request === []) {
            $this->line('No cold starts recorded in the last 7 days. They are recorded once the app is redeployed with this version.');
        } else {
            $waited = array_map(static fn ($r): float => max(0.0, (float) ($r['ready'] ?? 0)) + (float) ($r['request'] ?? 0), $rows);
            $this->line('Cold starts in the last 7 days: '.count($rows));
            $this->info('A visitor waited: median '.self::ms(self::median($waited)).', p95 '.self::ms(self::percentile($waited, 95)));
            $this->line('  until the app answered its port (start + boot + probe): median '.self::ms(self::median($ready ?: [0.0])).', p95 '.self::ms(self::percentile($ready ?: [0.0], 95)));
            $this->line('  of which the readiness probe: median '.self::ms(self::median($column('probe') ?: [0.0])));
            $this->line('  then their first request: median '.self::ms(self::median($request)).', p95 '.self::ms(self::percentile($request, 95)));
        }

        $boots = [];
        try {
            foreach (EdgeContainerDeployer::appLogLines($site, 1440, 'dply-boot:') as $line) {
                if (preg_match('/start=([\d.]+) sqlite=([\d.]+) migrate=([\d.]+) (?:caches|optimize)=([\d.]+)/', $line['message'], $m) === 1) {
                    $boots[] = array_map('floatval', array_slice($m, 1));
                }
            }
        } catch (Throwable) {
            // Logs are best effort; the timings above stand on their own.
        }
        if ($boots !== []) {
            $phase = static fn (int $to, int $from): string => self::ms(self::median(array_map(static fn (array $b): float => ($b[$to] - ($from < 0 ? 0 : $b[$from])) * 1000, $boots)));
            // Only differences: /proc/uptime does not restart with each container
            // start, so "start" on its own is not the time to reach the script.
            $this->line('Boot, median of '.count($boots).' in the last day: SQLite restore '.$phase(1, 0)
                .', migrations '.$phase(2, 1).', Laravel caches '.$phase(3, 2));
        }

        return self::SUCCESS;
    }

    private function waitForSleep(Site $site, string $base, int $wait, int $poll): bool
    {
        $checks = max(1, (int) ceil($wait / $poll));
        for ($i = 0; $i <= $checks; $i++) {
            if (self::running($site, $base) === 0) {
                return true;
            }
            if ($i === 0) {
                $this->line('Waiting for it to fall asleep (up to '.intdiv($wait, 60).' min). Keep it closed in your browser meanwhile.');
            }
            if ($i < $checks) {
                Sleep::for($poll)->seconds();
            }
        }

        return false;
    }

    /** Web instances running now, or null when the Worker did not answer. */
    private static function running(Site $site, string $base): ?int
    {
        try {
            $rows = Http::timeout(15)->withHeaders(['x-dply-queue-token' => EdgeContainerDeployer::queueToken($site)])
                ->get($base.'/_dply/instances')->throw()->json();
        } catch (Throwable) {
            return null;
        }

        return count(array_filter(is_array($rows) ? $rows : [], static fn ($row): bool => is_array($row) && in_array($row['status'] ?? '', EdgeContainerInstances::RUNNING, true)));
    }

    /** @return array{ms: float, status: int} */
    private static function time(string $url): array
    {
        $start = hrtime(true);
        try {
            $status = Http::timeout(90)->withHeaders(['Cache-Control' => 'no-cache'])->get($url)->status();
        } catch (Throwable) {
            $status = 0;
        }

        return ['ms' => (hrtime(true) - $start) / 1e6, 'status' => $status];
    }

    /** "5m" / "1h" (EdgeContainerSettings::SLEEP_AFTER) in seconds. */
    public static function seconds(string $duration): int
    {
        return preg_match('/^(\d+)([mh])$/', $duration, $m) === 1 ? (int) $m[1] * ($m[2] === 'h' ? 3600 : 60) : 300;
    }

    /** @param  list<float>  $values */
    public static function median(array $values): float
    {
        sort($values);
        $mid = intdiv(count($values), 2);

        return count($values) % 2 === 1 ? $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;
    }

    /** Nearest-rank percentile. @param  list<float>  $values */
    public static function percentile(array $values, int $p): float
    {
        sort($values);

        return $values[max(0, (int) ceil($p / 100 * count($values)) - 1)];
    }

    private static function ms(float $ms): string
    {
        return number_format($ms).' ms';
    }
}
