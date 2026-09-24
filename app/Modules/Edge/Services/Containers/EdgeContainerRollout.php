<?php

declare(strict_types=1);

namespace App\Modules\Edge\Services\Containers;

use App\Models\Site;
use App\Modules\Providers\Cloudflare\EdgeCloudflareClient;
use Throwable;

/**
 * Wait for Cloudflare to actually roll the container out.
 *
 * `wrangler deploy` returns once the Worker script is uploaded — the container
 * image may still be ingesting, scheduling, or crash-looping. Marking a
 * deployment live at that point is a false success: we saw a deployment read
 * `live` while every request answered "The container is not running".
 *
 * So after wrangler returns we poll the container application until it settles
 * and report what Cloudflare says, rather than assuming.
 */
final class EdgeContainerRollout
{
    /** Cloudflare names the application after the Worker script. */
    public static function applicationName(Site $site): string
    {
        return EdgeContainerDeployer::scriptName($site).'-app';
    }

    /**
     * Poll until the rollout settles, or the budget runs out.
     *
     * Settled means the newest rollout is `completed` at 100% and nothing is
     * `starting` or `scheduling`. Instance counts alone are not enough: a
     * gradual rollout at 0% (instances 0/N) has nothing starting yet.
     * `active` is deliberately not required — an idle container sits at
     * active=0. A non-zero `failed` is the unambiguous bad signal.
     *
     * @param  callable(string): void  $log
     * @return array{ok: bool, settled: bool, health: array<string, mixed>, version: int|null, reason: ?string}
     */
    public function await(Site $site, callable $log, int $timeoutSeconds = 180, int $pollSeconds = 5): array
    {
        $name = self::applicationName($site);
        $deadline = microtime(true) + max(10, $timeoutSeconds);
        $client = EdgeCloudflareClient::fromConfig();
        $last = [];
        $version = null;
        $id = null;

        while (microtime(true) < $deadline) {
            try {
                $id ??= $this->applicationId($client, $name);
                if ($id === null) {
                    sleep($pollSeconds);   // Cloudflare may not have registered it yet.

                    continue;
                }

                $app = $client->containerApplication($id);
                $version = isset($app['version']) ? (int) $app['version'] : $version;
                $last = is_array($app['health']['instances'] ?? null) ? $app['health']['instances'] : [];
            } catch (Throwable $e) {
                $log('Rollout check failed (will retry): '.$e->getMessage()."\n");
                sleep($pollSeconds);

                continue;
            }

            $failed = (int) ($last['failed'] ?? 0);
            $starting = (int) ($last['starting'] ?? 0) + (int) ($last['scheduling'] ?? 0);
            $progress = self::rolloutProgress($client->containerRollouts($id));

            if ($failed > 0) {
                $log(sprintf("Rollout reports %d failed instance(s) — %s\n", $failed, (string) json_encode($last)));

                return ['ok' => false, 'settled' => true, 'health' => $last, 'version' => $version, 'reason' => 'container instances failed to start'];
            }

            // Health can sit at starting=0 while Cloudflare's rollout is still
            // at 0% (instances 0/N). That is not settled.
            if ($starting === 0 && ! $progress['in_progress']) {
                $log(sprintf("Rollout settled (version %s, %d%%) — %s\n", $version ?? '?', $progress['percentage'] ?? 100, (string) json_encode($last)));

                return ['ok' => true, 'settled' => true, 'health' => $last, 'version' => $version, 'reason' => null];
            }

            $log(sprintf(
                "Rollout in progress — %s%%, %d instance(s) starting…\n",
                $progress['percentage'] === null ? '?' : (string) $progress['percentage'],
                $starting,
            ));
            sleep($pollSeconds);
        }

        // Out of budget: say so rather than claim success. The deploy is not
        // necessarily failed — Cloudflare may still finish — but nobody should
        // read this as "confirmed running".
        $log("Rollout did not settle within the wait budget — check the Container tab.\n");

        return ['ok' => false, 'settled' => false, 'health' => $last, 'version' => $version, 'reason' => 'timed out waiting for rollout'];
    }

    /**
     * Whether the newest rollout is still moving instances onto the new image.
     *
     * Cloudflare lists rollouts newest first. `completed` at 100% is the only
     * settled state. A row still at 0% (instances 0/N) is in progress even
     * when the health counts are all zero. No row yet means it has not started.
     *
     * @param  list<array<string, mixed>>  $rollouts
     * @return array{in_progress: bool, percentage: int|null, status: ?string}
     */
    public static function rolloutProgress(array $rollouts): array
    {
        $latest = $rollouts[0] ?? null;
        if (! is_array($latest)) {
            return ['in_progress' => true, 'percentage' => null, 'status' => null];
        }

        $status = (string) ($latest['status'] ?? '');
        $percentage = data_get($latest, 'progress.version_distribution.target_version_percentage');
        $percentage = is_numeric($percentage) ? (int) $percentage : null;
        $stepsDone = true;
        foreach (is_array($latest['steps'] ?? null) ? $latest['steps'] : [] as $step) {
            if (is_array($step) && ($step['status'] ?? '') !== 'completed') {
                $stepsDone = false;
            }
        }

        $done = $status === 'completed' && $stepsDone && ($percentage === null || $percentage >= 100);

        return ['in_progress' => ! $done, 'percentage' => $percentage, 'status' => $status !== '' ? $status : null];
    }

    private function applicationId(EdgeCloudflareClient $client, string $name): ?string
    {
        foreach ($client->listContainerApplications() as $application) {
            if ($application['name'] === $name) {
                return $application['id'] !== '' ? $application['id'] : null;
            }
        }

        return null;
    }
}
