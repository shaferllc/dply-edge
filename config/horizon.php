<?php

use App\Support\DplyRuntime;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Horizon
|--------------------------------------------------------------------------
|
| Queues are defined here — no HORIZON_QUEUES env needed.
|
|   supervisor-build  → Edge builds/publishes — CPU + RAM bound (docker run)
|   supervisor-deploy → BYO deploys / remote tasks — I/O bound (blocked on SSH)
|   supervisor-fast   → notifications, insights, probes, short work
|
| Build and deploy work used to share one `supervisor-heavy` pool. They want
| opposite concurrency: builds saturate cores (keep processes ≈ vCPU), while
| deploys sit idle waiting on SSH (over-subscribe freely). Pooling them also let
| one long Astro build skew the `time` autoscaling maths and starve the other
| queue. Keep them split.
|
| NOTE: the generic HORIZON_MAX_PROCESSES / HORIZON_MIN_PROCESSES /
| HORIZON_BALANCE / HORIZON_TRIES / HORIZON_WORKER_MEMORY / HORIZON_JOB_TIMEOUT
| env vars are NOT read here — they belong to the customer worker-pool feature
| ({@see App\Support\WorkerPools\WorkerPoolHorizonConfig}), which templates them
| onto customer boxes. The control plane's own knobs are the HORIZON_BUILD_* /
| HORIZON_DEPLOY_* / HORIZON_FAST_* names below.
|
| Add a queue name below when you introduce a new Redis list.
|
*/

// Which queues this host drains depends on DPLY_RUNTIME (App\Support\DplyRuntime):
// the builder takes only the build lanes, a container never does, an
// all-in-one box takes everything. A supervisor left with no queues is dropped.
$consumes = DplyRuntime::queuesFor(DplyRuntime::normalizeMode(env('DPLY_RUNTIME', 'all')));

$buildQueues = array_values(array_intersect([
    DplyRuntime::BUILD_QUEUE, // Edge build/publish — docker, git, npm, wrangler
], $consumes));

$deployQueues = array_values(array_intersect([
    'dply',           // redis default queue: general control-plane work
], $consumes));

// default (notifications), dply-builder (short builder-host jobs),
// dply-background, dply-control, dply-manage, probes:worker-1 (uptime probes,
// mirror site_uptime.probe_workers) — whichever this host drains.
$fastQueues = array_values(array_diff($consumes, $buildQueues, $deployQueues));

$withQueues = static fn (array $supervisors): array => array_filter(
    $supervisors,
    static fn (array $supervisor): bool => $supervisor['queue'] !== [],
);

return [

    'name' => env('HORIZON_NAME'),

    'domain' => env('HORIZON_DOMAIN'),

    'path' => env('HORIZON_PATH', 'horizon'),

    'use' => 'default',

    'prefix' => env(
        'HORIZON_PREFIX',
        Str::slug(env('APP_NAME', 'laravel'), '_').'_horizon:'
    ),

    'middleware' => ['web', 'auth'],

    'allowed_emails' => env('HORIZON_ALLOWED_EMAILS', ''),

    'waits' => [
        'redis:default' => 60,
        'redis:dply' => 60,
        'redis:dply-provision' => 120,
    ],

    'trim' => [
        'recent' => 60,
        'pending' => 60,
        'completed' => 60,
        'recent_failed' => 10080,
        'failed' => 10080,
        'monitored' => 10080,
    ],

    'silenced' => [
        // App\Jobs\ExampleJob::class,
    ],

    'silenced_tags' => [
        // 'notifications',
    ],

    'metrics' => [
        'trim_snapshots' => [
            'job' => 24,
            'queue' => 24,
        ],
    ],

    'fast_termination' => false,

    'memory_limit' => 64,

    /*
     * balanceMaxShift / balanceCooldown: Horizon's defaults (1 process per 3s)
     * mean a pool takes ~18s of sustained backlog to walk from min to max — so
     * bursts shorter than that never reach max at all. Shift faster.
     */
    'defaults' => [
        'supervisor-build' => [
            'connection' => 'redis',
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'balanceMaxShift' => 5,
            'balanceCooldown' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            // Build output/log streaming is held in PHP while docker does the
            // real work — 128M leaves no headroom on a big build log.
            'memory' => 256,
            'tries' => 1,
            'nice' => 0,
        ],
        'supervisor-deploy' => [
            'connection' => 'redis',
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'balanceMaxShift' => 5,
            'balanceCooldown' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => 1,
            'nice' => 0,
        ],
        'supervisor-fast' => [
            'connection' => 'redis',
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'balanceMaxShift' => 5,
            'balanceCooldown' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => 1,
            'nice' => 0,
        ],
    ],

    'environments' => [

        'production' => $withQueues([
            // Builds saturate cores — keep maxProcesses at or below the worker
            // box's vCPU count or concurrent `docker run` builds thrash.
            'supervisor-build' => [
                'queue' => $buildQueues,
                'minProcesses' => (int) env('HORIZON_BUILD_MIN_PROCESSES', 2),
                'maxProcesses' => (int) env('HORIZON_BUILD_MAX_PROCESSES', (int) env('HORIZON_HEAVY_MAX_PROCESSES', 4)),
                'timeout' => (int) env('HORIZON_BUILD_TIMEOUT', 7320),
            ],
            // Deploys block on SSH round-trips, not CPU — over-subscribe.
            'supervisor-deploy' => [
                'queue' => $deployQueues,
                'minProcesses' => (int) env('HORIZON_DEPLOY_MIN_PROCESSES', 2),
                'maxProcesses' => (int) env('HORIZON_DEPLOY_MAX_PROCESSES', 16),
                'timeout' => (int) env('HORIZON_DEPLOY_TIMEOUT', 3600),
            ],
            'supervisor-fast' => [
                'queue' => $fastQueues,
                'minProcesses' => (int) env('HORIZON_FAST_MIN_PROCESSES', 1),
                'maxProcesses' => (int) env('HORIZON_FAST_MAX_PROCESSES', 10),
                'timeout' => 900,
            ],
        ]),

        'local' => $withQueues([
            'supervisor-build' => [
                'queue' => $buildQueues,
                'balance' => 'simple',
                'minProcesses' => (int) env('HORIZON_BUILD_MIN_PROCESSES', 1),
                'maxProcesses' => (int) env('HORIZON_BUILD_MAX_PROCESSES', (int) env('HORIZON_HEAVY_MAX_PROCESSES', 3)),
                'timeout' => (int) env('HORIZON_BUILD_TIMEOUT', 7320),
            ],
            'supervisor-deploy' => [
                'queue' => $deployQueues,
                'balance' => 'simple',
                'minProcesses' => 1,
                'maxProcesses' => (int) env('HORIZON_DEPLOY_MAX_PROCESSES', 4),
                'timeout' => (int) env('HORIZON_DEPLOY_TIMEOUT', 3600),
            ],
            'supervisor-fast' => [
                'queue' => $fastQueues,
                'balance' => 'simple',
                'minProcesses' => 1,
                'maxProcesses' => 8,
                'timeout' => 900,
            ],
        ]),

    ],

    'watch' => [
        'app',
        'bootstrap',
        'config/**/*.php',
        'database/**/*.php',
        'public/**/*.php',
        'resources/**/*.php',
        'routes',
        'composer.lock',
        'composer.json',
        '.env',
    ],

];
