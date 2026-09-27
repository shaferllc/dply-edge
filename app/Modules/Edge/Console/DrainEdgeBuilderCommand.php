<?php

declare(strict_types=1);

namespace App\Modules\Edge\Console;

use App\Models\EdgeDeployment;
use App\Support\DplyRuntime;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Queue;
use Laravel\Horizon\Contracts\SupervisorRepository;
use Laravel\Horizon\MasterSupervisor;
use Throwable;

/**
 * A builder pod's preStop, before `horizon:terminate --wait`: stop taking new
 * builds, then wait until nothing this host started is still in flight — its
 * builds, and the publish / cache-snapshot jobs they queue on this host's own
 * lane (DplyRuntime::hostQueue). Terminating straight away would stop the
 * lane's worker while a finishing build was still queueing its publish.
 */
class DrainEdgeBuilderCommand extends Command
{
    protected $signature = 'dply:builder:drain {--max-wait=7200 : Seconds to wait before giving up} {--poll=5}';

    protected $description = 'Stop taking builds on this builder and wait for its builds and publishes to finish';

    public function handle(): int
    {
        $this->pauseBuildSupervisors();

        $lane = DplyRuntime::hostQueue();
        if ($lane === null) {
            $this->line('No host lane (single build host): horizon:terminate --wait covers the rest.');

            return self::SUCCESS;
        }

        $deadline = time() + max(0, (int) $this->option('max-wait'));
        $idleChecks = 0;
        do {
            $busy = $this->inFlight($lane);
            // Twice idle in a row: a build popped just before the pause has
            // not written its lane to the deployment yet on the first look.
            $idleChecks = $busy === 0 ? $idleChecks + 1 : 0;
            if ($idleChecks >= 2) {
                $this->info("Drained: nothing in flight on {$lane}.");

                return self::SUCCESS;
            }
            if (time() >= $deadline) {
                break;
            }
            $this->line("{$busy} build/publish job(s) in flight on {$lane}; waiting.");
            sleep(max(1, (int) $this->option('poll')));
        } while (true);

        $this->error("Still busy on {$lane} after the wait; terminating anyway (the reaper handles what is left).");

        return self::FAILURE;
    }

    public function inFlight(string $lane): int
    {
        $deployments = EdgeDeployment::query()
            ->whereIn('status', [EdgeDeployment::STATUS_BUILDING, EdgeDeployment::STATUS_PUBLISHING])
            ->where('meta->build_queue', $lane)
            ->count();
        try {
            $queued = (int) Queue::connection('redis')->size($lane);
        } catch (Throwable) {
            $queued = 0;
        }

        return $deployments + $queued;
    }

    private function pauseBuildSupervisors(): void
    {
        try {
            foreach (app(SupervisorRepository::class)->names() as $name) {
                if (str_starts_with($name, MasterSupervisor::basename()) && str_ends_with($name, ':supervisor-build')) {
                    $this->call('horizon:pause-supervisor', ['name' => $name]);
                }
            }
        } catch (Throwable $e) {
            $this->warn('Could not pause the build supervisor: '.$e->getMessage());
        }
    }
}
