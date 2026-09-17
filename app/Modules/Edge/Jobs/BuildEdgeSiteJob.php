<?php

declare(strict_types=1);

namespace App\Modules\Edge\Jobs;

use App\Models\EdgeDeployment;
use App\Models\Site;
use App\Modules\Edge\Services\EdgeArtifactPublisher;
use App\Modules\Edge\Services\EdgeBuildRunner;
use App\Modules\Edge\Services\EdgeDeliveryContextResolver;
use App\Modules\Edge\Services\EdgeProductionEnv;
use App\Modules\Edge\Support\EdgeBuildMinutes;
use App\Modules\Edge\Support\EdgeLiveBuildLog;
use App\Modules\Edge\Support\EdgeRepoRoot;
use App\Modules\Notifications\Services\NotificationPublisher;
use App\Support\ProductLine\ProductLineKillSwitches;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Throwable;

class BuildEdgeSiteJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Failures allowed; waiting for a free build slot releases without counting. */
    public int $maxExceptions = 2;

    public function __construct(
        public string $deploymentId,
        public ?string $commitOverride = null,
    ) {
        $this->onQueue((string) config('edge.build.queue', 'dply-provision'));
    }

    /** A build waiting on its org's concurrency slots keeps retrying this long. */
    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours(3);
    }

    /**
     * Tier gates before the build proper (ruling r-zdescb7y05vp1bxx): orgs whose
     * tier stops at its build-minute allowance fail fast once it's used, and
     * each org gets `concurrent_builds` slots — a build without one waits.
     */
    public function handle(EdgeBuildRunner $runner): void
    {
        $deployment = EdgeDeployment::query()->find($this->deploymentId);
        $site = $deployment ? Site::find($deployment->site_id) : null;
        $organization = $site?->organization;
        if ($organization === null) {
            $this->runBuild($runner, null);

            return;
        }

        $tier = $organization->tierAllowances();
        if (EdgeBuildMinutes::exhausted(EdgeBuildMinutes::usedThisMonth($organization), $tier)) {
            $message = __('This month’s :minutes build minutes are used up. Upgrade to Pro for more, or wait until the 1st.', ['minutes' => number_format((int) $tier['build_minutes'])]);
            if ($site->status === Site::STATUS_EDGE_ACTIVE) {
                // Keep the live site as it is; only this deploy fails.
                $deployment->update(['status' => EdgeDeployment::STATUS_FAILED, 'failed_at' => now(), 'failure_reason' => $message]);
            } else {
                $this->markFailed($site, $deployment, $message);
            }

            return;
        }

        $timeoutSeconds = (int) $tier['build_timeout_minutes'] * 60;
        $slot = null;
        for ($i = 0; $i < max(1, (int) $tier['concurrent_builds']); $i++) {
            // Expires on its own if a worker dies mid-build.
            $lock = Cache::lock('edge-build-slot:'.$organization->id.':'.$i, $timeoutSeconds + 900);
            if ($lock->get()) {
                $slot = $lock;
                break;
            }
        }
        if ($slot === null) {
            $this->release(20);

            return;
        }

        try {
            $this->runBuild($runner, $timeoutSeconds);
        } finally {
            $slot->release();
        }
    }

    private function runBuild(EdgeBuildRunner $runner, ?int $timeoutSeconds): void
    {
        $deployment = EdgeDeployment::query()->find($this->deploymentId);
        if ($deployment === null) {
            return;
        }

        $site = Site::find($deployment->site_id);
        if ($site === null) {
            return;
        }

        if (ProductLineKillSwitches::blocksEdgeDelivery()) {
            $deployment->update([
                'status' => EdgeDeployment::STATUS_FAILED,
                'last_error' => 'Edge delivery is paused by platform administrators.',
            ]);
            $site->update(['status' => Site::STATUS_EDGE_FAILED]);

            return;
        }

        // Operator may have cancelled while this job was still queued —
        // never overwrite FAILED/cancelled back to building.
        $deployment->refresh();
        if ($deployment->wasCancelledByOperator()) {
            return;
        }

        $edge = $site->edgeMeta();
        $source = is_array($edge['source'] ?? null) ? $edge['source'] : [];
        $build = is_array($edge['build'] ?? null) ? $edge['build'] : [];

        $repo = (string) ($source['repo'] ?? '');
        $branch = (string) ($source['branch'] ?? 'main');
        $buildCommand = (string) ($build['command'] ?? 'npm ci && npm run build');
        $outputDir = (string) ($build['output_dir'] ?? 'dist');
        $runtimeMode = (string) ($edge['runtime_mode'] ?? EdgeBuildRunner::MODE_STATIC);

        if (! $deployment->trySetStatusUnlessCancelled(EdgeDeployment::STATUS_BUILDING)) {
            return;
        }

        $site->refresh();
        if (! $this->siteStillExists($site)) {
            return;
        }
        $site->update(['status' => Site::STATUS_EDGE_PROVISIONING]);

        $buildResult = null;
        $workRoot = null;

        try {
            $repoUrl = str_contains($repo, '://') ? $repo : 'https://github.com/'.$repo.'.git';

            // P-env: production-scope vars become Docker -e flags
            // (EdgeBuildRunner::dockerEnvFlags). Values are pulled
            // through the encrypted accessor and filtered against the
            // model's RESERVED_NAMES so customer code can't shadow
            // platform bindings like HOST_MAP / ASSETS / DEPLOYMENT_ID.
            $buildEnv = app(EdgeProductionEnv::class)->forSite($site);

            // Build minutes bill from here (queue wait and publish excluded).
            $buildStartedAt = now();
            EdgeDeployment::query()->whereKey($deployment->id)->update(['build_started_at' => $buildStartedAt]);
            try {
                $buildResult = $runner->build(
                    $deployment,
                    $repoUrl,
                    $branch,
                    $buildCommand,
                    $outputDir,
                    $buildEnv,
                    $this->commitOverride,
                    $runtimeMode,
                    EdgeRepoRoot::normalize(is_string($source['repo_root'] ?? null) ? $source['repo_root'] : null) ?: null,
                    $timeoutSeconds,
                );
            } finally {
                EdgeDeployment::query()->whereKey($deployment->id)->update([
                    'build_seconds' => max(1, (int) $buildStartedAt->diffInSeconds(now())),
                ]);
            }
            $artifactDir = $buildResult['artifact_dir'];
            $workRoot = dirname($artifactDir);

            // Cancel mid-Docker: leave artifacts for cleanup, do not publish.
            $deployment->refresh();
            if ($deployment->wasCancelledByOperator() || ! $this->siteStillExists($site)) {
                if (is_dir($artifactDir) && str_starts_with($artifactDir, EdgeBuildRunner::buildRoot())) {
                    File::deleteDirectory($workRoot);
                }

                return;
            }

            $buildLogPath = $this->persistBuildLog($site, $deployment, $buildResult['build_log']);
            $updates = ['build_log_path' => $buildLogPath];
            if (is_string($buildResult['git_commit'] ?? null) && $buildResult['git_commit'] !== '') {
                $updates['git_commit'] = $buildResult['git_commit'];
            }
            // Persist commit subject/author into deployment.meta so the
            // previews row + deploy history can show "what is this".
            $commitMeta = array_filter([
                'subject' => $buildResult['git_commit_subject'] ?? null,
                'author' => $buildResult['git_commit_author'] ?? null,
                'committed_at' => $buildResult['git_commit_at'] ?? null,
            ], fn ($value) => is_string($value) && $value !== '');
            if ($commitMeta !== []) {
                $existingMeta = is_array($deployment->meta) ? $deployment->meta : [];
                // Preserve cancelled flag if a race set it during persist.
                $updates['meta'] = array_merge($existingMeta, ['commit' => $commitMeta]);
            }
            $deployment->refresh();
            if ($deployment->wasCancelledByOperator()) {
                if (is_dir($artifactDir) && str_starts_with($artifactDir, EdgeBuildRunner::buildRoot())) {
                    File::deleteDirectory($workRoot);
                }

                return;
            }
            $deployment->update($updates);

            if (! $this->siteStillExists($site)) {
                // Same containment rule as PublishEdgeDeploymentJob: only ever
                // recurse inside the configured build root. A hardcoded temp-dir
                // check stops matching the moment work_root moves.
                if (is_dir($artifactDir) && str_starts_with($artifactDir, EdgeBuildRunner::buildRoot())) {
                    File::deleteDirectory($workRoot);
                }

                return;
            }

            // SSR: persist the bundled worker module(s) into a sidecar
            // file next to the artifact dir so PublishEdgeDeploymentJob
            // (which re-resolves over a queue boundary) can find it
            // without us shoving it through job args / the DB.
            $ssrSidecarPath = null;
            if (is_array($buildResult['ssr_modules'] ?? null) && $buildResult['ssr_modules'] !== []) {
                $ssrSidecarPath = $workRoot.'/ssr-bundle.json';
                File::put($ssrSidecarPath, json_encode([
                    'entry_module' => $buildResult['ssr_entry_module'] ?? 'worker.js',
                    'modules' => $buildResult['ssr_modules'],
                ], JSON_THROW_ON_ERROR));
            }

            // Middleware: same sidecar pattern, separate file so a
            // middleware-only deploy doesn't get conflated with SSR
            // and so the two can coexist on a single deployment.
            $middlewareSidecarPath = null;
            if (is_array($buildResult['middleware_modules'] ?? null) && $buildResult['middleware_modules'] !== []) {
                $middlewareSidecarPath = $workRoot.'/middleware-bundle.json';
                File::put($middlewareSidecarPath, json_encode([
                    'entry_module' => $buildResult['middleware_entry_module'] ?? 'middleware.js',
                    'source_path' => $buildResult['middleware_source_path'] ?? null,
                    'modules' => $buildResult['middleware_modules'],
                ], JSON_THROW_ON_ERROR));
            }

            $cacheAsync = is_array($buildResult['cache_async'] ?? null)
                ? $buildResult['cache_async']
                : null;

            $deployment->refresh();
            if ($deployment->wasCancelledByOperator() || ! $this->siteStillExists($site)) {
                if (is_dir($artifactDir) && str_starts_with($artifactDir, EdgeBuildRunner::buildRoot())) {
                    File::deleteDirectory($workRoot);
                }

                return;
            }

            PublishEdgeDeploymentJob::dispatch(
                $deployment->id,
                $artifactDir,
                $ssrSidecarPath,
                $middlewareSidecarPath,
                $cacheAsync,
            );
        } catch (Throwable $e) {
            // build() throws before returning — $buildResult stays null for the
            // common failure path (clone/lint/docker/install). Still persist
            // whatever the runner wrote to the local log so the Build log tab
            // isn't empty after a failed deploy.
            $deployment = EdgeDeployment::query()->find($this->deploymentId);
            if ($deployment === null || $deployment->wasCancelledByOperator()) {
                return;
            }

            $localLog = $this->resolveLocalBuildLogPath($deployment, $buildResult);
            if ($localLog !== null) {
                try {
                    $buildLogPath = $this->persistBuildLog($site, $deployment, $localLog);
                    $deployment->update(['build_log_path' => $buildLogPath]);
                } catch (Throwable) {
                    // Best-effort — failure reason still captures the exception message.
                }
            }

            $this->markFailed($site, $deployment, $e->getMessage());

            throw $e;
        }
    }

    /**
     * @param  array{build_log?: string}|null  $buildResult
     */
    private function resolveLocalBuildLogPath(EdgeDeployment $deployment, ?array $buildResult): ?string
    {
        $fromResult = is_array($buildResult) ? ($buildResult['build_log'] ?? null) : null;
        if (is_string($fromResult) && is_file($fromResult)) {
            return $fromResult;
        }

        $deployment->refresh();
        $fromMeta = $deployment->meta['local_build_log_path'] ?? null;
        if (is_string($fromMeta) && is_file($fromMeta)) {
            return $fromMeta;
        }

        $candidate = rtrim(EdgeBuildRunner::buildRoot(), '/').'/dply-edge-build-'.$deployment->id.'/build.log';
        if (is_file($candidate)) {
            return $candidate;
        }

        return null;
    }

    private function persistBuildLog(Site $site, EdgeDeployment $deployment, string $localLogPath): string
    {
        $storageKey = trim($deployment->storage_prefix, '/').'/build.log';

        try {
            $context = app(EdgeDeliveryContextResolver::class)->forSite($site);
            $diskName = $context->diskName;
        } catch (Throwable) {
            $diskName = (string) config('edge.disk.name', 'edge_r2');
        }

        app(EdgeArtifactPublisher::class)->uploadFile($localLogPath, $storageKey, $diskName);
        EdgeLiveBuildLog::clear((string) $deployment->id);

        return $storageKey;
    }

    private function markFailed(Site $site, EdgeDeployment $deployment, string $message): void
    {
        $meta = $site->edgeMeta();
        $meta['last_error'] = $message;
        $meta['last_error_at'] = now()->toIso8601String();
        $site->update([
            'status' => Site::STATUS_EDGE_FAILED,
            'meta' => array_merge(is_array($site->meta) ? $site->meta : [], ['edge' => $meta]),
        ]);
        $deployment->update([
            'status' => EdgeDeployment::STATUS_FAILED,
            'failed_at' => now(),
            'failure_reason' => $message,
        ]);

        // P9b: edge.deploy.failed — the publish phase has its own copy of this
        // in PublishEdgeDeploymentJob, but a build that dies before publish
        // (clone, dply.yaml lint, docker, install/build, artifact upload)
        // never reached it, so the most common failure of all was silent.
        try {
            app(NotificationPublisher::class)->publish(
                eventKey: 'edge.deploy.failed',
                subject: $site->fresh(),
                title: "Edge deploy failed: {$site->name}",
                body: $message,
                url: route('sites.show', ['server' => $site->server_id, 'site' => $site->id, 'section' => 'edge-deploys']),
                metadata: [
                    'deployment_id' => (string) $deployment->id,
                    'commit' => $deployment->git_commit,
                    'branch' => $deployment->git_branch,
                    'failure_reason' => $message,
                    'phase' => 'build',
                ],
            );
        } catch (Throwable) {
            // Notification publish is best-effort — it must never mask the
            // build error we were called to record.
        }
    }

    /**
     * The site can be deleted while a build is in flight, so each checkpoint
     * must re-query rather than reuse an earlier result.
     *
     * @phpstan-impure
     */
    private function siteStillExists(Site $site): bool
    {
        return Site::query()->whereKey($site->getKey())->exists();
    }
}
