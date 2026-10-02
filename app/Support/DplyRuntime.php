<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Log;

/**
 * Where this process sits in the control plane (DPLY_RUNTIME).
 *
 *   all       — one box does everything (local dev, single VM). Default.
 *   web       — legacy VM split: HTTP only.
 *   worker    — legacy VM split: Horizon (+ scheduler on the primary).
 *   container — dply hosting itself on Cloudflare Containers: the web
 *               instances and the queue workers (DPLY_ROLE=worker, scheduler
 *               in worker-0) are the same image. No Docker, no daemons, no
 *               local disk that outlives the instance. Never drains the
 *               build queues.
 *   builder   — the build VM: Docker, git, wrangler. Drains ONLY the build
 *               queues. No scheduler, no HTTP.
 *
 * docs/self-hosting.md has the full picture.
 */
final class DplyRuntime
{
    public const MODE_ALL = 'all';

    public const MODE_WEB = 'web';

    public const MODE_WORKER = 'worker';

    public const MODE_CONTAINER = 'container';

    public const MODE_BUILDER = 'builder';

    public const MODES = [self::MODE_ALL, self::MODE_WEB, self::MODE_WORKER, self::MODE_CONTAINER, self::MODE_BUILDER];

    public const WORKER_ROLE_PRIMARY = 'primary';

    public const WORKER_ROLE_REPLICA = 'replica';

    /** Edge builds and publishes: docker, git clone, npm, wrangler. Long, CPU-bound. */
    public const BUILD_QUEUE = 'dply-provision';

    /**
     * Short jobs that still need the build host's binaries (repo detection
     * clones, `docker kill` of a cancelled build, scheduled docker/age/node
     * work). Its own lane so a cancel is not stuck behind the builds it is
     * trying to stop.
     */
    public const BUILDER_QUEUE = 'dply-builder';

    /** Queues only the builder (or an all-in-one box) may drain. */
    public const BUILDER_QUEUES = [self::BUILD_QUEUE, self::BUILDER_QUEUE];

    /**
     * Everything else: no shelling out. `dply` is the redis connection's
     * default (REDIS_QUEUE), `default` carries notifications,
     * `dply-background` health/uptime checks.
     */
    public const CONTROL_QUEUES = ['dply', 'default', 'dply-background', 'dply-control', 'dply-manage', self::CONSOLE_QUEUE];

    /**
     * Customers' Console commands (RunContainerCommandJob): their own workers
     * (config/horizon.php supervisor-console), so a long command can never
     * hold up dply's own jobs.
     */
    public const CONSOLE_QUEUE = 'dply-console';

    public static function mode(): string
    {
        return self::normalizeMode((string) config('dply_runtime.mode', self::MODE_ALL));
    }

    /** Pure, so config files (horizon.php) can call it before config() is up. */
    public static function normalizeMode(?string $mode): string
    {
        $mode = strtolower(trim((string) $mode));

        return in_array($mode, self::MODES, true) ? $mode : self::MODE_ALL;
    }

    /**
     * The queues a worker in this mode consumes. `web` consumes nothing.
     *
     * @return list<string>
     */
    public static function queuesFor(string $mode): array
    {
        return match (self::normalizeMode($mode)) {
            self::MODE_WEB => [],
            self::MODE_CONTAINER => self::CONTROL_QUEUES,
            self::MODE_BUILDER => [...self::BUILDER_QUEUES, ...self::hostQueues()],
            default => [...self::BUILDER_QUEUES, ...self::hostQueues(), ...self::CONTROL_QUEUES],
        };
    }

    /**
     * This build host's own lane (DPLY_EDGE_BUILD_HOST_QUEUE, set per pod in
     * deploy/builders/k8s/builder.yaml). With a pool of builders, the jobs
     * that read the build's files from local disk (publish, cache snapshot)
     * must run on the host that built them. Unset (one build host): none.
     */
    public static function hostQueue(): ?string
    {
        $queue = trim((string) getenv('DPLY_EDGE_BUILD_HOST_QUEUE'));

        return $queue !== '' ? $queue : null;
    }

    /** @return list<string> */
    private static function hostQueues(): array
    {
        $queue = self::hostQueue();

        return $queue !== null ? [$queue] : [];
    }

    /** Comma list for `queue:work --queue=` (the container's DPLY_WORKER_QUEUES). */
    public static function workerQueueList(string $mode): string
    {
        return implode(',', self::queuesFor($mode));
    }

    public static function isContainer(): bool
    {
        return self::mode() === self::MODE_CONTAINER;
    }

    /** This host has Docker, git and wrangler and drains the build queues. */
    public static function runsBuilds(): bool
    {
        return in_array(self::mode(), [self::MODE_ALL, self::MODE_WORKER, self::MODE_BUILDER], true);
    }

    /**
     * For host-only commands (Docker bootstrap, local probes): true, plus a
     * log line, when this process cannot do that work here.
     */
    public static function skipsHostWork(string $what): bool
    {
        if (self::runsBuilds()) {
            return false;
        }

        Log::info("[dply-runtime] {$what} skipped: DPLY_RUNTIME=".self::mode().' has no Docker or host daemons; the builder does this.');

        return true;
    }

    public static function workerRole(): string
    {
        $role = strtolower(trim((string) config('dply_runtime.worker_role', self::WORKER_ROLE_PRIMARY)));

        return in_array($role, [self::WORKER_ROLE_PRIMARY, self::WORKER_ROLE_REPLICA], true)
            ? $role
            : self::WORKER_ROLE_PRIMARY;
    }

    public static function isSplitDeployment(): bool
    {
        return self::mode() !== self::MODE_ALL;
    }

    /**
     * Whether `schedule:*` registers the task list. In a container only
     * worker-0 is ever started with schedule:work (DPLY_WORKER_SCHEDULER), and
     * onOneServer() covers overlap during a rollout.
     */
    public static function runsScheduler(): bool
    {
        return match (self::mode()) {
            self::MODE_ALL, self::MODE_CONTAINER => true,
            self::MODE_WORKER => self::workerRole() === self::WORKER_ROLE_PRIMARY,
            default => false,
        };
    }

    public static function expectsHorizon(): bool
    {
        return in_array(self::mode(), [self::MODE_ALL, self::MODE_WORKER, self::MODE_BUILDER], true);
    }

    public static function expectsReverb(): bool
    {
        return in_array(self::mode(), [self::MODE_ALL, self::MODE_WEB], true);
    }

    /**
     * @return list<string>
     */
    public static function configurationIssues(): array
    {
        $issues = [];

        if (! self::isSplitDeployment()) {
            return $issues;
        }

        if ((string) config('queue.default') !== 'redis') {
            $issues[] = 'QUEUE_CONNECTION must be redis when DPLY_RUNTIME is web or worker.';
        }

        if (self::runsScheduler() && (string) config('cache.default') !== 'redis') {
            $issues[] = 'CACHE_STORE=redis is required on the primary worker so Schedule::onOneServer() mutexes across deploys.';
        }

        if (self::isContainer()) {
            if (in_array((string) config('session.driver'), ['file', 'array', 'cookie'], true)) {
                $issues[] = 'SESSION_DRIVER must be redis (or database) in a container: instances do not share a disk.';
            }
            if ((string) config('filesystems.disks.platform.driver') !== 's3') {
                $issues[] = 'PLATFORM_DISK_BUCKET (or the edge R2 bucket) must be set in a container: uploads would land on one instance\'s disk.';
            }
            if (! in_array((string) config('logging.default'), ['stderr', 'stack'], true)) {
                $issues[] = 'LOG_CHANNEL should be stderr in a container: storage/logs is gone on the next restart.';
            }
        }

        return $issues;
    }

    /**
     * @return array<string, mixed>
     */
    public static function aboutPayload(): array
    {
        return [
            'mode' => self::mode(),
            'worker_role' => self::mode() === self::MODE_WORKER ? self::workerRole() : null,
            'queues' => self::queuesFor(self::mode()),
            'runs_scheduler' => self::runsScheduler(),
            'runs_builds' => self::runsBuilds(),
            'expects_horizon' => self::expectsHorizon(),
            'expects_reverb' => self::expectsReverb(),
            'configuration_issues' => self::configurationIssues(),
        ];
    }
}
