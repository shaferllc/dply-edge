<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Edge\Support\EdgeBuilderHeartbeat;
use App\Support\DplyRuntime;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Is this host set up for its DPLY_RUNTIME role? Exit 0 = yes. On a builder
 * it is the pod's liveness probe (deploy/builders/k8s/builder.yaml) and,
 * when healthy, stamps the builder heartbeat the control plane watches
 * (dply:edge:check-builders).
 *
 *   builder / worker / all: Docker answers (DOCKER_HOST), git >= 2.31,
 *                           the build work root is writable
 *   every mode:             DplyRuntime::configurationIssues()
 */
class DplyRuntimeCheckCommand extends Command
{
    public const MIN_GIT = '2.31.0';

    protected $signature = 'dply:runtime:check';

    protected $description = 'Check this host is set up for its DPLY_RUNTIME role (builder: Docker, git, work root; heartbeat)';

    public function handle(): int
    {
        $mode = DplyRuntime::mode();
        $problems = DplyRuntime::configurationIssues();

        if (DplyRuntime::runsBuilds()) {
            $problems = array_merge($problems, self::buildHostProblems());
        }

        $this->line("DPLY_RUNTIME={$mode}; queues: ".(DplyRuntime::workerQueueList($mode) ?: '(none)'));
        foreach ($problems as $problem) {
            $this->error($problem);
        }
        if ($problems !== []) {
            return self::FAILURE;
        }

        if ($mode === DplyRuntime::MODE_BUILDER) {
            try {
                EdgeBuilderHeartbeat::beat((string) gethostname());
            } catch (Throwable $e) {
                // No Valkey = no queue either: this builder is not useful.
                $this->error('Could not write the builder heartbeat (queue store unreachable): '.$e->getMessage());

                return self::FAILURE;
            }
        }
        $this->info('ok');

        return self::SUCCESS;
    }

    /** @return list<string> */
    public static function buildHostProblems(): array
    {
        $problems = [];
        if (! Process::timeout(15)->run(['docker', 'info', '--format', '{{.ServerVersion}}'])->successful()) {
            $problems[] = 'Docker does not answer (check DOCKER_HOST / the dind sidecar / socket access).';
        }

        $git = Process::timeout(15)->run(['git', '--version']);
        $version = preg_match('/(\d+\.\d+(?:\.\d+)?)/', $git->output(), $m) === 1 ? $m[1] : '0';
        if (! $git->successful() || version_compare($version, self::MIN_GIT, '<')) {
            $problems[] = 'git '.self::MIN_GIT.' or newer is required (found '.($git->successful() ? $version : 'none').').';
        }

        $root = rtrim((string) config('edge.build.work_root'), '/');
        if ($root === '' || (! is_dir($root) && ! @mkdir($root, 0775, true)) || ! is_writable($root)) {
            $problems[] = "The build work root {$root} is not writable.";
        }

        return $problems;
    }
}
