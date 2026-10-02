<?php

declare(strict_types=1);

namespace App\Modules\Edge\Services\Containers;

use App\Models\AuditLog;
use App\Models\Site;
use App\Models\User;
use App\Modules\Edge\Jobs\RunContainerCommandJob;
use App\Modules\Edge\Support\EdgeContainerSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * One-off commands in a container app through the dply agent (T-038):
 * started from the workspace Console or the admin Resources page, run by a
 * queued job (a command outlives PHP's 30s request limit), and read back by
 * polling. Output lives in the cache for an hour; who ran what lives in the
 * audit log: the app's activity for its own team, and for dply support also
 * the platform log, so the customer sees it either way.
 */
final class EdgeContainerCommands
{
    /** Kept per run; older lines are dropped from the front. */
    public const MAX_LINES = 2000;

    private const TTL = 3600;

    /**
     * The containers a command can run in: the jobs container when the app
     * has one, then each web instance.
     *
     * @return array<string, string> target => label
     */
    public static function targets(Site $site): array
    {
        $settings = EdgeContainerSettings::for($site);
        $targets = $settings['dedicated_jobs'] ? ['jobs' => __('Jobs container')] : [];
        for ($i = 0; $i < max(1, (int) $settings['max_instances']); $i++) {
            $targets['instance-'.$i] = __('Web instance :n', ['n' => $i + 1]);
        }

        return $targets;
    }

    /** Commands to try, for the app's framework. @return list<string> */
    public static function suggestions(Site $site): array
    {
        return match (true) {
            $site->isLaravelFrameworkDetected() => ['php artisan about', 'php artisan migrate:status', 'php artisan queue:failed', 'php artisan optimize:clear', 'php artisan tinker --execute="dump(App\\Models\\User::count())"'],
            $site->isRailsFrameworkDetected() => ['bin/rails about', 'bin/rails db:migrate:status', 'bin/rails runner "puts User.count"'],
            default => ['node --version', 'npm ls --depth=0', 'ls -la'],
        };
    }

    /**
     * Queue a command and return its run id. $support marks dply staff (an
     * operator on the admin page): the audit entry says so.
     *
     * @param  array<string, mixed>  $context  extra audit values (an operator's reason)
     */
    public static function start(Site $site, string $command, ?string $target, int $timeout, bool $wake, ?User $user, bool $support = false, array $context = []): string
    {
        $id = (string) Str::ulid();
        Cache::put(self::key($id), ['site' => (string) $site->id, 'command' => $command, 'target' => $target, 'status' => 'queued', 'lines' => [], 'result' => null, 'error' => null], self::TTL);
        if ($site->organization !== null) {
            AuditLog::log($site->organization, $user, $support ? 'support.container.command' : 'site.edge.command', $site, null, ['command' => $command, 'target' => $target ?? 'default'] + $context);
        }
        RunContainerCommandJob::dispatch($id, (string) $site->id, $command, $target, $timeout, $wake);

        return $id;
    }

    /** @return array{site: string, command: string, target: ?string, status: string, lines: list<array<string, mixed>>, result: ?array<string, mixed>, error: ?string}|null */
    public static function read(string $id): ?array
    {
        $run = Cache::get(self::key($id));

        return is_array($run) ? $run : null;
    }

    /** @param  callable(array<string, mixed>): array<string, mixed>  $change */
    public static function update(string $id, callable $change): void
    {
        $run = self::read($id);
        if ($run !== null) {
            $run = $change($run);
            if (count($run['lines']) > self::MAX_LINES) {
                $run['lines'] = array_slice($run['lines'], -self::MAX_LINES);
            }
            Cache::put(self::key($id), $run, self::TTL);
        }
    }

    private static function key(string $id): string
    {
        return 'container-run:'.$id;
    }
}
