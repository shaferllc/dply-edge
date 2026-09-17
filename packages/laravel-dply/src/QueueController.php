<?php

declare(strict_types=1);

namespace Dply\Laravel;

use Illuminate\Container\Container;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
use Throwable;

/**
 * Receives a batch from the site Worker and runs each job through Laravel's
 * queue worker. Answers { failed: [ids] } — the Worker retries those and
 * acks the rest.
 */
class QueueController
{
    public function __invoke(Request $request, Container $container): JsonResponse
    {
        /** @var Worker $worker */
        $worker = $container->make('queue.worker');
        $token = (string) config('queue.connections.dply.token');
        if ($token === '' || ! hash_equals($token, (string) $request->header('x-dply-queue-token'))) {
            return new JsonResponse(['error' => 'Forbidden'], 403);
        }

        @set_time_limit(0);
        $options = new WorkerOptions(maxTries: 0, timeout: 0, sleep: 0);
        $failed = [];

        foreach ((array) $request->input('messages', []) as $message) {
            $job = new DplyJob(
                $container,
                json_encode($message['body'] ?? null, JSON_THROW_ON_ERROR),
                (int) ($message['attempts'] ?? 1),
                (string) ($message['id'] ?? ''),
                'dply',
                (string) $request->input('queue', 'JOBS'),
            );

            try {
                $worker->process('dply', $job, $options);
            } catch (Throwable) {
                // Laravel already released or failed the job and reported it.
            }

            if ($job->isReleased() && ! $job->hasFailed()) {
                $failed[] = $job->getJobId();
            }
        }

        return new JsonResponse(['failed' => $failed]);
    }
}
