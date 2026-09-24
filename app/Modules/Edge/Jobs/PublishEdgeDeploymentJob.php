<?php

declare(strict_types=1);

namespace App\Modules\Edge\Jobs;

use App\Models\EdgeDeployment;
use App\Models\Site;
use App\Modules\Edge\Services\EdgeBuildRunner;
use App\Modules\Edge\Services\EdgeDeployDurationRegression;
use App\Modules\Edge\Services\EdgeDeploymentPruner;
use App\Modules\Edge\Services\EdgeGithubCheckRunService;
use App\Modules\Edge\Services\EdgeGithubPullRequestCommenter;
use App\Modules\Edge\Services\EdgeMiddlewareBundleUploader;
use App\Modules\Edge\Services\EdgeRouter;
use App\Modules\Edge\Services\EdgeSsrBundleUploader;
use App\Modules\Edge\Services\EdgeTestingHostnameProvisioner;
use App\Modules\Edge\Services\EnsureEdgeRepoDomains;
use App\Modules\Edge\Services\OriginHealthcheckRunner;
use App\Modules\Edge\Support\FakeEdgeProvision;
use App\Modules\Notifications\Services\NotificationPublisher;
use App\Support\ProductLine\ProductLineKillSwitches;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\File;
use Throwable;

class PublishEdgeDeploymentJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /**
     * @param  array{site_id?: string, checkout?: string, cache_key?: string, checkout_root?: string}|null  $cacheAsync
     */
    public function __construct(
        public string $deploymentId,
        public string $localArtifactDir,
        /**
         * Path to the JSON sidecar BuildEdgeSiteJob writes when the
         * build is SSR-mode — contains the bundled worker.js module
         * source. Null for static + hybrid deploys.
         */
        public ?string $ssrBundlePath = null,
        /**
         * Sidecar for an esbuild-bundled middleware module (P10a).
         * Null when the repo has no middleware.{ts,js} or when the
         * runtime is SSR (the SSR Worker handles middleware itself).
         */
        public ?string $middlewareBundlePath = null,
        /**
         * When set, leave the checkout on disk and queue an R2 cache
         * snapshot after cleanup so tar+upload is off the deploy path.
         *
         * @var array{site_id?: string, checkout?: string, cache_key?: string, checkout_root?: string}|null
         */
        public ?array $cacheAsync = null,
    ) {
        $this->onQueue((string) config('edge.build.queue', 'dply-provision'));
    }

    public function handle(): void
    {
        $deployment = EdgeDeployment::query()->find($this->deploymentId);
        if ($deployment === null) {
            return;
        }

        $site = Site::find($deployment->site_id);
        if ($site === null) {
            $this->cleanupLocalArtifact();

            return;
        }

        $deployment->refresh();
        if ($deployment->wasCancelledByOperator()) {
            $this->cleanupLocalArtifact();

            return;
        }

        if (ProductLineKillSwitches::blocksEdgeDelivery()) {
            $this->markFailed($site, $deployment, 'Edge delivery is paused by platform administrators.');
            $this->cleanupLocalArtifact();

            return;
        }

        $backend = EdgeRouter::backendFor($site);
        if ($backend === null) {
            $this->markFailed($site, $deployment, 'No edge backend available.');

            return;
        }

        if (! $deployment->trySetStatusUnlessCancelled(EdgeDeployment::STATUS_PUBLISHING)) {
            $this->cleanupLocalArtifact();

            return;
        }

        // Atomic gate for hybrid sites: confirm the origin answers before
        // we flip KV to point at this deployment. Without this, an unhealthy
        // origin would start receiving Worker-proxied traffic the moment KV
        // propagates. Static sites bypass — there's nothing to healthcheck.
        if (($site->edgeMeta()['runtime_mode'] ?? 'static') === 'hybrid') {
            $health = app(OriginHealthcheckRunner::class)->run($site->fresh());
            if (! $health['ok']) {
                $this->markFailed($site, $deployment, $health['message']);
                $this->cleanupLocalArtifact();

                return;
            }
        }

        try {
            $deployment->refresh();
            if ($deployment->wasCancelledByOperator()) {
                $this->cleanupLocalArtifact();

                return;
            }

            // SSR: ship the bundled worker.js into the dispatch
            // namespace BEFORE we publish KV — that way the host map
            // is never pointing at a script that hasn't landed yet.
            // Script name gets persisted to deployment.meta.ssr so
            // the publisher payload includes it on the very first
            // host map write.
            // Middleware: upload before the host map publish (same
            // reasoning as SSR — never let KV point at a script that
            // isn't live yet). No-op when no sidecar was produced.
            app(EdgeMiddlewareBundleUploader::class)
                ->uploadFromSidecar($deployment, $site, $this->middlewareBundlePath);
            $deployment->refresh();

            if ($deployment->wasCancelledByOperator()) {
                $this->cleanupLocalArtifact();

                return;
            }

            if (($site->edgeMeta()['runtime_mode'] ?? 'static') === 'ssr') {
                app(EdgeSsrBundleUploader::class)
                    ->uploadFromSidecar($deployment, $site, $this->ssrBundlePath);
                $deployment->refresh();
            }

            if ($deployment->wasCancelledByOperator()) {
                $this->cleanupLocalArtifact();

                return;
            }

            $result = $backend->publishDeployment($deployment, $site, $this->localArtifactDir);

            $deployment->refresh();
            if ($deployment->wasCancelledByOperator()) {
                // Best-effort: unpublish what we just wrote; site teardown
                // will also unpublish when the cancel path deletes the site.
                try {
                    $backend->unpublish($site);
                } catch (Throwable) {
                }
                $this->cleanupLocalArtifact();

                return;
            }

            EdgeDeployment::query()
                ->where('site_id', $site->id)
                ->where('status', EdgeDeployment::STATUS_LIVE)
                ->update(['status' => EdgeDeployment::STATUS_SUPERSEDED]);

            $deployment->update([
                'status' => EdgeDeployment::STATUS_LIVE,
                'published_at' => now(),
                'cf_kv_version' => $result['cf_kv_version'],
            ]);

            $meta = $site->edgeMeta();
            $meta['live_url'] = $result['live_url'];
            $meta['active_deployment_id'] = $deployment->id;
            $hostname = parse_url((string) ($result['live_url'] ?? ''), PHP_URL_HOST);
            if (is_string($hostname) && $hostname !== '') {
                $routing = is_array($meta['routing'] ?? null) ? $meta['routing'] : [];
                $routing['hostname'] = strtolower($hostname);
                $meta['routing'] = $routing;
            }
            unset($meta['last_error'], $meta['last_error_at']);

            $site->update([
                'status' => Site::STATUS_EDGE_ACTIVE,
                'edge_backend_id' => (string) ($site->edge_backend_id ?: $deployment->id),
                'meta' => array_merge(is_array($site->meta) ? $site->meta : [], ['edge' => $meta]),
            ]);

            if (($site->edgeMeta()['runtime_mode'] ?? '') === 'container' && ! FakeEdgeProvision::enabled()) {
                CheckEdgeContainerHealthJob::dispatch((string) $deployment->id)->delay(now()->addSeconds(20));
            }

            try {
                app(EdgeTestingHostnameProvisioner::class)->provision($site->fresh());
            } catch (Throwable) {
                // DNS is best-effort — KV publish already succeeded.
            }

            try {
                app(EdgeDeploymentPruner::class)->prune($site->fresh());
            } catch (Throwable) {
                // Pruning is best-effort — old artifacts will be retried next publish.
            }

            // Auto-attach any custom domains declared in dply.yaml's
            // `domains:` block that aren't attached yet. Removing a
            // domain from the file does NOT detach — detaches are
            // explicit only (dashboard / API).
            try {
                app(EnsureEdgeRepoDomains::class)->ensure($site->fresh(), $deployment->fresh());
            } catch (Throwable) {
                // Best-effort; declared domains can be retried on next deploy.
            }

            // Mark the matching GitHub Check Run + update the PR
            // comment with the live URL. Wrapped — GitHub flake must
            // not fail a successful publish.
            $liveUrl = is_string($result['live_url'] ?? null) ? (string) $result['live_url'] : null;
            try {
                app(EdgeGithubCheckRunService::class)->complete($site->fresh(), 'success', $liveUrl);
            } catch (Throwable) {
                // Check Run update is best-effort.
            }
            try {
                app(EdgeGithubPullRequestCommenter::class)->upsert($site->fresh(), 'success', $liveUrl);
            } catch (Throwable) {
                // PR comment update is best-effort.
            }

            // P9b: edge.deploy.succeeded — fan out to subscribed
            // notification channels (Slack / Discord / email / webhook).
            // Best-effort; downstream channel failures are isolated by
            // NotificationRoutingResolver, so we just need to avoid
            // letting publisher exceptions kill an otherwise-good deploy.
            try {
                $commit = $deployment->git_commit
                    ? substr((string) $deployment->git_commit, 0, 7)
                    : null;
                app(NotificationPublisher::class)->publish(
                    eventKey: 'edge.deploy.succeeded',
                    subject: $site->fresh(),
                    title: $commit !== null
                        ? "Edge deploy live: {$site->name} ({$commit})"
                        : "Edge deploy live: {$site->name}",
                    body: $liveUrl,
                    url: $liveUrl,
                    metadata: [
                        'deployment_id' => (string) $deployment->id,
                        'commit' => $deployment->git_commit,
                        'branch' => $deployment->git_branch,
                        'live_url' => $liveUrl,
                        // Earlier->later: Carbon 3's signed diff made the old
                        // reversed order negative, which max(0, …) clamped to 0,
                        // so this metric had always reported zero.
                        'duration_ms' => $deployment->published_at && $deployment->created_at
                            ? max(0, $deployment->created_at->diffInMilliseconds($deployment->published_at))
                            : null,
                    ],
                );
            } catch (Throwable) {
                // Notification publish is best-effort.
            }

            // P9b: edge.deploy.duration_regressed — the deploy succeeded, it
            // was just markedly slower than this site's recent norm. Advisory,
            // so it rides alongside the success notification rather than
            // replacing it.
            try {
                $regression = app(EdgeDeployDurationRegression::class)->evaluate($deployment->fresh());
                if ($regression !== null) {
                    app(NotificationPublisher::class)->publish(
                        eventKey: 'edge.deploy.duration_regressed',
                        subject: $site->fresh(),
                        title: "Edge deploy slower than usual: {$site->name}",
                        body: sprintf(
                            'This deploy took %ss, %sx the median of the last %d deploys (%ss).',
                            round($regression['duration_ms'] / 1000, 1),
                            $regression['ratio'],
                            $regression['samples'],
                            round($regression['p50_ms'] / 1000, 1),
                        ),
                        url: $liveUrl,
                        metadata: [
                            'deployment_id' => (string) $deployment->id,
                            'commit' => $deployment->git_commit,
                            'branch' => $deployment->git_branch,
                            ...$regression,
                        ],
                    );
                }
            } catch (Throwable) {
                // Regression detection is advisory — never fail a live deploy.
            }
        } catch (Throwable $e) {
            $this->markFailed($site, $deployment, $e->getMessage());

            throw $e;
        } finally {
            $this->cleanupLocalArtifact();
        }
    }

    private function cleanupLocalArtifact(): void
    {
        // Containment check: only ever recursively delete inside the build root.
        // This must track EdgeBuildRunner::buildRoot() — when the root moved off
        // sys_get_temp_dir() a hardcoded temp-dir check here silently stopped
        // matching, and every successful deploy leaked its workdir (src/ is
        // pruned mid-build, but out/ and build.log survived).
        $buildRoot = EdgeBuildRunner::buildRoot();
        $workRoot = dirname($this->localArtifactDir);

        if (is_dir($this->localArtifactDir) && str_starts_with($this->localArtifactDir, $buildRoot)) {
            $cacheAsync = $this->cacheAsync;
            $checkout = is_array($cacheAsync) ? ($cacheAsync['checkout'] ?? null) : null;
            $cacheKey = is_array($cacheAsync) ? ($cacheAsync['cache_key'] ?? null) : null;
            $siteId = is_array($cacheAsync) ? ($cacheAsync['site_id'] ?? null) : null;
            $checkoutRoot = is_array($cacheAsync) ? ($cacheAsync['checkout_root'] ?? $checkout) : null;

            if (
                is_string($checkout) && $checkout !== ''
                && is_string($cacheKey) && $cacheKey !== ''
                && is_string($siteId) && $siteId !== ''
                && is_dir($checkout)
            ) {
                // Keep src/ for SnapshotEdgeBuildCacheJob; drop publish artifacts.
                File::deleteDirectory($this->localArtifactDir);
                if (is_file($workRoot.'/build.log')) {
                    @unlink($workRoot.'/build.log');
                }
                SnapshotEdgeBuildCacheJob::dispatch(
                    $siteId,
                    $checkout,
                    $cacheKey,
                    is_string($checkoutRoot) ? $checkoutRoot : $checkout,
                );
            } else {
                File::deleteDirectory($workRoot);
            }
        }
        if ($this->ssrBundlePath !== null && is_file($this->ssrBundlePath)) {
            @unlink($this->ssrBundlePath);
        }
        if ($this->middlewareBundlePath !== null && is_file($this->middlewareBundlePath)) {
            @unlink($this->middlewareBundlePath);
        }
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

        try {
            app(EdgeGithubCheckRunService::class)->complete($site->fresh(), 'failure');
        } catch (Throwable) {
            // Check Run update is best-effort.
        }
        try {
            app(EdgeGithubPullRequestCommenter::class)->upsert($site->fresh(), 'failure');
        } catch (Throwable) {
            // PR comment update is best-effort.
        }

        // P9b: edge.deploy.failed — fan out to subscribed channels.
        // Body carries the failure reason so the operator sees
        // *why* the deploy died in their Slack / inbox without
        // having to open the dashboard.
        try {
            app(NotificationPublisher::class)->publish(
                eventKey: 'edge.deploy.failed',
                subject: $site->fresh(),
                title: "Edge deploy failed: {$site->name}",
                body: $message,
                url: route('sites.show', ['server' => $site->server_id, 'site' => $site->id, 'section' => 'deploys']),
                metadata: [
                    'deployment_id' => (string) $deployment->id,
                    'commit' => $deployment->git_commit,
                    'branch' => $deployment->git_branch,
                    'failure_reason' => $message,
                ],
            );
        } catch (Throwable) {
            // Notification publish is best-effort.
        }
    }
}
