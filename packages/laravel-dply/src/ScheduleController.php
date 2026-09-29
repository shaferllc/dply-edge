<?php

declare(strict_types=1);

namespace Dply\Laravel;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Cron Trigger from the site Worker: runs the handler as an artisan command
 * (`schedule:run` for the scheduler, or e.g. `reports:send --daily`). A
 * handler whose first word is not an artisan command runs as a shell command
 * in the app root (e.g. `php scripts/cleanup.php`, `node bin/sync.js`).
 */
class ScheduleController
{
    public function __invoke(Request $request): JsonResponse
    {
        $token = (string) config('queue.connections.dply.token');
        if ($token === '' || ! hash_equals($token, (string) $request->header('x-dply-queue-token'))) {
            return new JsonResponse(['error' => 'Forbidden'], 403);
        }

        $command = trim((string) $request->input('handler', '')) ?: 'schedule:run';
        $command = (string) preg_replace('/^php\s+artisan\s+/', '', $command);
        @set_time_limit(0);

        try {
            if (self::isArtisan($command)) {
                $exit = Artisan::call($command);
                $output = Artisan::output();
            } else {
                $process = Process::fromShellCommandline($command, base_path(), null, null, null);
                $exit = $process->run();
                $output = $process->getOutput().$process->getErrorOutput();
            }
        } catch (Throwable $e) {
            report($e);

            return new JsonResponse(['command' => $command, 'error' => $e->getMessage()], 500);
        }

        return new JsonResponse([
            'command' => $command,
            'exit' => $exit,
            'output' => mb_substr($output, -2000),
            'plan' => $command === 'schedule:run' ? $this->plan() : null,
        ], $exit === 0 ? 200 : 500);
    }

    /** Whether the handler's first word is a registered artisan command. */
    public static function isArtisan(string $command): bool
    {
        $name = strtok($command, " \t") ?: '';

        return $name !== '' && array_key_exists($name, Artisan::all());
    }

    /**
     * When the scheduled tasks are due, so the Worker wakes the app only
     * then: each task's cron expression and timezone. Null means "every
     * minute" (a sub-minute task, or one we cannot describe).
     *
     * @return list<array{cron: string, tz: string}>|null
     */
    private function plan(): ?array
    {
        try {
            $plan = [];
            foreach (app(Schedule::class)->events() as $event) {
                if (method_exists($event, 'isRepeatable') && $event->isRepeatable()) {
                    return null;
                }
                $tz = $event->timezone instanceof \DateTimeZone ? $event->timezone->getName() : (string) ($event->timezone ?: config('app.timezone', 'UTC'));
                $plan[$event->getExpression().'|'.$tz] = ['cron' => $event->getExpression(), 'tz' => $tz];
            }

            return array_values($plan);
        } catch (Throwable) {
            return null;
        }
    }
}
