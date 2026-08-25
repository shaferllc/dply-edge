<?php

declare(strict_types=1);

namespace App\Support\Admin;

use App\Models\ApiToken;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\Project;
use App\Models\Server;
use App\Models\Site;
use App\Models\StatusPage;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Number;
use Illuminate\Support\Str;

final class AdminPlatformMetrics
{
    /**
     * @return array<string, int>
     */
    public static function counts(): array
    {
        $since = now()->subDay();
        $since7d = now()->subDays(7);

        $pendingJobs = 0;
        $failedJobsCount = 0;
        if (Schema::hasTable('jobs')) {
            $pendingJobs = (int) DB::table('jobs')->count();
        }
        if (Schema::hasTable('failed_jobs')) {
            $failedJobsCount = (int) DB::table('failed_jobs')->count();
        }

        return [
            'users' => User::query()->count(),
            'organizations' => Organization::query()->count(),
            'servers' => Server::query()->count(),
            'sites' => Site::query()->count(),
            'audit_logs_24h' => AuditLog::query()->where('created_at', '>=', $since)->count(),
            'users_7d' => User::query()->where('created_at', '>=', $since7d)->count(),
            'organizations_7d' => Organization::query()->where('created_at', '>=', $since7d)->count(),
            'api_tokens' => ApiToken::query()->count(),
            'invitations_open' => OrganizationInvitation::query()->where('expires_at', '>', now())->count(),
            'status_pages' => StatusPage::query()->count(),
            'projects' => Project::query()->count(),
            'pending_jobs' => $pendingJobs,
            'failed_jobs' => $failedJobsCount,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function system(): array
    {
        $base = base_path('bootstrap/cache');
        $routeCaches = glob($base.'/routes-*.php') ?: [];

        $dbOk = true;
        try {
            DB::connection()->getPdo();
        } catch (\Throwable) {
            $dbOk = false;
        }

        $redisOk = null;
        $redisMessage = null;
        try {
            $redisOk = (bool) Redis::connection()->ping();
        } catch (\Throwable $e) {
            $redisOk = false;
            $redisMessage = $e->getMessage();
        }

        return [
            'php' => PHP_VERSION,
            'laravel' => app()->version(),
            'env' => config('app.env'),
            'debug' => (bool) config('app.debug'),
            'maintenance' => app()->isDownForMaintenance(),
            'url' => config('app.url'),
            'timezone' => config('app.timezone'),
            'config_cached' => is_file($base.'/config.php'),
            'routes_cached' => $routeCaches !== [],
            'events_cached' => is_file($base.'/events.php'),
            'db_ok' => $dbOk,
            'redis_ok' => $redisOk,
            'redis_error' => $redisMessage,
            'queue_connection' => config('queue.default'),
            'cache_store' => config('cache.default'),
            'broadcast' => config('broadcasting.default'),
            'mail_mailer' => config('mail.default'),
            'disk' => self::diskSummary(storage_path()),
        ];
    }

    /**
     * @return array<string, int>
     */
    public static function serverByStatus(): array
    {
        return Server::query()
            ->selectRaw('status, count(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status')
            ->all();
    }

    /**
     * @return array<string, int>
     */
    public static function siteByStatus(): array
    {
        return Site::query()
            ->selectRaw('status, count(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status')
            ->all();
    }

    /**
     * @return Collection<int, \stdClass>
     */
    public static function recentFailedJobs(int $limit = 12): Collection
    {
        if (! Schema::hasTable('failed_jobs')) {
            return collect();
        }

        return DB::table('failed_jobs')
            ->orderByDesc('failed_at')
            ->limit($limit)
            ->get(['id', 'uuid', 'connection', 'queue', 'failed_at']);
    }

    /**
     * @return list<array{label: string, cadence: string}>
     */
    public static function scheduleEntries(): array
    {
        return [
            ['label' => __('Dispatch health checks for reachable servers'), 'cadence' => __('Every 5 minutes')],
            ['label' => __('Dispatch URL health checks for active sites (when enabled)'), 'cadence' => __('Every 10 minutes')],
            ['label' => __('Deploy digest email flush'), 'cadence' => __('Hourly (when digest hours > 0)')],
            ['label' => __('Process scheduled server deletions'), 'cadence' => __('Every minute')],
        ];
    }

    public static function readLogTail(string $path, int $lines = 36): ?string
    {
        if (! is_readable($path)) {
            return null;
        }

        try {
            $size = filesize($path);
            $content = file_get_contents($path, false, null, max(0, $size !== false ? $size - 98_000 : 0));
            if ($content === false) {
                return null;
            }
            $parts = preg_split("/\r\n|\n|\r/", $content) ?: [];
            $tail = array_slice($parts, -$lines);

            return Str::limit(implode("\n", $tail), 12000);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array{free: string, total: string, used_percent: float, path: string}|null
     */
    protected static function diskSummary(string $path): ?array
    {
        if (! is_dir($path)) {
            return null;
        }

        $free = @disk_free_space($path);
        $total = @disk_total_space($path);
        if ($free === false || $total === false || $total <= 0) {
            return null;
        }

        $used = $total - $free;
        $pct = round(100 * ($used / $total), 1);

        return [
            'free' => Number::fileSize($free, 1),
            'total' => Number::fileSize($total, 1),
            'used_percent' => $pct,
            'path' => $path,
        ];
    }
}
