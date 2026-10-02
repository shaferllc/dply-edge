<?php

declare(strict_types=1);

namespace App\Modules\Edge\Jobs;

use App\Models\Site;
use App\Modules\Edge\Services\Containers\EdgeContainerAgent;
use App\Modules\Edge\Services\Containers\EdgeContainerCommands;
use App\Support\DplyRuntime;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Runs one Console command through the dply agent and writes its output to
 * the run (EdgeContainerCommands) as it arrives, a batch at least every
 * half second, so the page polling it sees the command live.
 */
final class RunContainerCommandJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    public function __construct(
        public string $runId,
        public string $siteId,
        public string $command,
        public ?string $target,
        public int $commandTimeout,
        public bool $wake,
    ) {
        // The agent's own timeout ends the command; this only stops a stuck job.
        $this->timeout = $commandTimeout + 120;
        $this->onQueue(DplyRuntime::CONSOLE_QUEUE);
    }

    public function handle(): void
    {
        $site = Site::query()->find($this->siteId);
        if ($site === null) {
            EdgeContainerCommands::update($this->runId, fn (array $run): array => ['status' => 'failed', 'error' => __('The app is gone.')] + $run);

            return;
        }
        EdgeContainerCommands::update($this->runId, fn (array $run): array => ['status' => 'running'] + $run);
        /** @var list<array<string, mixed>> $pending */
        $pending = [];
        $flushedAt = microtime(true);
        $flush = function () use (&$pending, &$flushedAt): void {
            if ($pending !== []) {
                $lines = $pending;
                EdgeContainerCommands::update($this->runId, function (array $run) use ($lines): array {
                    $run['lines'] = [...$run['lines'], ...$lines];

                    return $run;
                });
                $pending = [];
            }
            $flushedAt = microtime(true);
        };
        try {
            $result = EdgeContainerAgent::exec($site, $this->command, function (array $line) use (&$pending, &$flushedAt, $flush): void {
                if (! array_key_exists('exit', $line)) {
                    $pending[] = $line;
                }
                if (microtime(true) - $flushedAt >= 0.5) {
                    $flush();
                }
            }, $this->target, $this->commandTimeout, $this->wake);
            $flush();
            EdgeContainerCommands::update($this->runId, fn (array $run): array => (($result['asleep'] ?? false) === true
                ? ['status' => 'asleep']
                : ['status' => 'done', 'result' => $result]) + $run);
        } catch (Throwable $e) {
            $flush();
            EdgeContainerCommands::update($this->runId, fn (array $run): array => ['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500)] + $run);
        } finally {
            EdgeContainerCommands::release($this->runId);
        }
    }
}
