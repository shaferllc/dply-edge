<?php

declare(strict_types=1);

namespace App\Modules\Edge\Actions;

use App\Enums\SiteType;
use App\Models\EdgeDeployment;
use App\Models\EdgeSiteAccessRule;
use App\Models\Server;
use App\Models\Site;
use App\Modules\Edge\Jobs\BuildEdgeSiteJob;
use App\Modules\Edge\Services\EdgeGithubCheckRunService;
use App\Modules\Edge\Services\EdgeGithubPullRequestCommenter;
use App\Modules\Edge\Support\EdgeRepoRoot;
use App\Modules\Edge\Support\EdgeTestingDomains;
use App\Modules\Edge\Support\FakeEdgeProvision;
use App\Support\Preview\UnifiedPreviewHostname;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Spawn a preview deployment for a source-mode Edge site.
 *
 * Two flavors:
 * - PR-driven ({@see handle()}): idempotent on (parent, branch). The GitHub
 *   webhook calls this on PR open/sync — re-running CI on the same PR returns
 *   the existing preview row instead of duplicating. Mirrors a Check Run +
 *   summary comment back to the PR.
 * - Ad-hoc ({@see handleAdhoc()}): idempotent on (parent, commit). User
 *   clicks "Create preview" with a picked SHA. No PR, no GitHub side effects.
 */
class CreateEdgePreviewSite
{
    public const KIND_PR = 'pr';

    public const KIND_ADHOC = 'adhoc';

    public function handle(
        Site $parent,
        string $branch,
        ?int $prNumber = null,
        ?string $headSha = null,
    ): Site {
        $parentSource = $parent->edgeMeta()['source'] ?? null;
        if (! is_array($parentSource) || ! is_string($parentSource['repo'] ?? null)) {
            throw new \RuntimeException(
                'Parent site has no source spec — only git-connected Edge sites can spawn previews.',
            );
        }

        $branch = trim($branch);
        if ($branch === '') {
            throw new \InvalidArgumentException('Branch is required to create a preview deployment.');
        }

        $existing = self::findExisting($parent, $branch);
        if ($existing !== null) {
            return $this->refreshExistingPreview($existing, $prNumber, $headSha);
        }

        $slug = Str::slug(app(UnifiedPreviewHostname::class)->branchPreviewLabel($parent, $branch, $prNumber));
        $name = $this->previewName($parent->name, $branch, $prNumber);
        $testingDomain = self::apexForParent($parent);
        $hostname = app(UnifiedPreviewHostname::class)->branchPreviewHostname($parent, $branch, $prNumber, $testingDomain);

        $parentBuild = is_array($parent->edgeMeta()['build'] ?? null) ? $parent->edgeMeta()['build'] : [];
        $parentRouting = is_array($parent->edgeMeta()['routing'] ?? null) ? $parent->edgeMeta()['routing'] : [];
        // Hybrid previews must inherit the parent's origin/auth/routes or the
        // build's healthcheck step fails with "No origin URL configured."
        // Inherit verbatim so previews proxy the same backend as the parent.
        $parentOrigin = is_array($parent->edgeMeta()['origin'] ?? null) ? $parent->edgeMeta()['origin'] : null;

        $server = Server::query()->create([
            'user_id' => $parent->user_id,
            'organization_id' => $parent->organization_id,
            'name' => 'edge-'.$slug,
            'status' => Server::STATUS_READY,
            'meta' => [
                'host_kind' => Server::HOST_KIND_DPLY_EDGE,
            ],
        ]);

        $sourceSpec = array_filter([
            'repo' => (string) $parentSource['repo'],
            'branch' => $branch,
            'repo_root' => EdgeRepoRoot::normalize(is_string($parentSource['repo_root'] ?? null) ? $parentSource['repo_root'] : null) ?: null,
            'deploy_on_push' => true,
        ], static fn ($value) => $value !== null);

        $edgeMeta = [
            'runtime_mode' => $parent->edgeMeta()['runtime_mode'] ?? 'static',
            'source' => $sourceSpec,
            'build' => $parentBuild,
            'routing' => array_merge($parentRouting, [
                'hostname' => $hostname,
            ]),
            'live_url' => 'https://'.$hostname,
            'preview_parent_site_id' => $parent->id,
            'preview_kind' => self::KIND_PR,
            'preview_branch' => $branch,
            'preview_pr_number' => $prNumber,
            'preview_head_sha' => is_string($headSha) && $headSha !== '' ? $headSha : null,
        ];
        if ($parentOrigin !== null) {
            $edgeMeta['origin'] = $parentOrigin;
        }

        $site = Site::query()->create([
            'server_id' => $server->id,
            'user_id' => $parent->user_id,
            'organization_id' => $parent->organization_id,
            'name' => $name,
            'slug' => $slug,
            'type' => SiteType::Static,
            'runtime' => null,
            'document_root' => null,
            'repository_path' => null,
            'edge_backend' => $parent->edge_backend,
            'status' => Site::STATUS_EDGE_PROVISIONING,
            'webhook_secret' => Str::random(48),
            'meta' => [
                'runtime_profile' => 'edge_web',
                'edge' => $edgeMeta,
            ],
        ]);

        $prefix = trim((string) config('edge.r2.key_prefix', 'edge/'), '/')
            .'/'.$parent->organization_id.'/'.$site->id.'/'.Str::ulid();

        $deployment = EdgeDeployment::query()->create([
            'site_id' => $site->id,
            'organization_id' => $parent->organization_id,
            'status' => EdgeDeployment::STATUS_BUILDING,
            'git_branch' => $branch,
            'storage_prefix' => $prefix,
        ]);

        // Apply dply.yaml-declared preview protection (if any) so the
        // freshly-created preview comes up gated from the start. The
        // dashboard's per-preview override flow still takes precedence
        // once the user touches it.
        $this->applyDplyPreviewProtection($parent, $site);

        BuildEdgeSiteJob::dispatch($deployment->id);

        if (is_string($headSha) && $headSha !== '') {
            $this->mirrorPreviewOntoGithub($site->fresh());
        }

        return $site->fresh();
    }

    /**
     * Reads `previews.protection` from the parent's most recent live
     * deployment's repo_config and creates an EdgeSiteAccessRule on
     * the new preview when present. Best-effort — never fails the
     * preview creation.
     */
    private function applyDplyPreviewProtection(Site $parent, Site $preview): void
    {
        try {
            $parentLive = EdgeDeployment::query()
                ->where('site_id', $parent->id)
                ->where('status', EdgeDeployment::STATUS_LIVE)
                ->latest('id')
                ->first();
            $previews = is_array($parentLive?->repo_config['previews'] ?? null) ? $parentLive->repo_config['previews'] : [];
            $protection = is_array($previews['protection'] ?? null) ? $previews['protection'] : [];
            $mode = is_string($protection['mode'] ?? null) ? $protection['mode'] : '';
            if ($mode === '' || $mode === 'none') {
                return;
            }

            $modeMap = [
                'password' => EdgeSiteAccessRule::MODE_PASSWORD,
                'dply-account' => EdgeSiteAccessRule::MODE_DPLY_ACCOUNT,
                'email' => EdgeSiteAccessRule::MODE_DPLY_ACCOUNT, // email gating piggybacks on dply_account + allowed_emails
            ];
            $resolvedMode = $modeMap[$mode] ?? null;
            if ($resolvedMode === null) {
                return;
            }

            $rule = EdgeSiteAccessRule::query()->firstOrNew(['site_id' => $preview->id]);
            $rule->mode = $resolvedMode;
            $rule->cookie_secret = $rule->cookie_secret ?: Str::random(48);
            if ($mode === 'email' && is_array($protection['allowed_emails'] ?? null)) {
                $rule->allowed_emails = array_values(array_filter(array_map('strval', $protection['allowed_emails'])));
            }
            $rule->save();
        } catch (\Throwable $e) {
            Log::warning('dply.yaml preview protection apply failed', [
                'parent_id' => $parent->id,
                'preview_id' => $preview->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Ad-hoc preview from a picked commit (no PR). Dedups by commit SHA so
     * clicking Create twice with the same SHA returns the existing preview
     * instead of building a duplicate. A different SHA from the same branch
     * creates a new preview (each commit gets its own URL).
     */
    public function handleAdhoc(Site $parent, string $branch, string $headSha, ?string $refKind = null): Site
    {
        $parentSource = $parent->edgeMeta()['source'] ?? null;
        if (! is_array($parentSource) || ! is_string($parentSource['repo'] ?? null)) {
            throw new \RuntimeException(
                'Parent site has no source spec — only git-connected Edge sites can spawn previews.',
            );
        }

        $branch = trim($branch);
        if ($branch === '') {
            throw new \InvalidArgumentException('Branch is required to create an ad-hoc preview.');
        }

        $headSha = strtolower(trim($headSha));
        if (preg_match('/^[a-f0-9]{7,40}$/', $headSha) !== 1) {
            throw new \InvalidArgumentException('Commit SHA must be 7–40 hex characters.');
        }

        $existing = self::findExistingByCommit($parent, $headSha);
        if ($existing !== null) {
            // Same SHA after a failed build (e.g. worker Docker) — rebuild
            // instead of returning a dead preview URL.
            if ($this->adhocPreviewNeedsRebuild($existing)) {
                app(RedeployEdgeSite::class)->handle($existing, $headSha);

                return $existing->fresh();
            }

            return $existing;
        }

        $hostnames = app(UnifiedPreviewHostname::class);
        $testingDomain = self::apexForParent($parent);
        $slug = Str::slug($hostnames->adhocPreviewLabel($parent, $headSha));
        $name = sprintf('%s preview (%s)', $parent->name, substr($headSha, 0, 7));
        $hostname = $hostnames->adhocPreviewHostname($parent, $headSha, $testingDomain);

        $parentBuild = is_array($parent->edgeMeta()['build'] ?? null) ? $parent->edgeMeta()['build'] : [];
        $parentRouting = is_array($parent->edgeMeta()['routing'] ?? null) ? $parent->edgeMeta()['routing'] : [];
        $parentOrigin = is_array($parent->edgeMeta()['origin'] ?? null) ? $parent->edgeMeta()['origin'] : null;

        $server = Server::query()->create([
            'user_id' => $parent->user_id,
            'organization_id' => $parent->organization_id,
            'name' => 'edge-'.$slug,
            'status' => Server::STATUS_READY,
            'meta' => [
                'host_kind' => Server::HOST_KIND_DPLY_EDGE,
            ],
        ]);

        $sourceSpec = array_filter([
            'repo' => (string) $parentSource['repo'],
            'branch' => $branch,
            'repo_root' => EdgeRepoRoot::normalize(is_string($parentSource['repo_root'] ?? null) ? $parentSource['repo_root'] : null) ?: null,
            // Ad-hoc previews freeze on the picked commit — auto-deploy on
            // future branch pushes would defeat the "this URL is that SHA"
            // contract the operator is asking for.
            'deploy_on_push' => false,
        ], static fn ($value) => $value !== null);

        // Normalize ref kind so the view can branch on it safely. Anything
        // outside the known set falls back to null (renders like a branch).
        $refKind = in_array($refKind, ['branch', 'tag', 'commit'], true) ? $refKind : null;

        $edgeMeta = [
            'runtime_mode' => $parent->edgeMeta()['runtime_mode'] ?? 'static',
            'source' => $sourceSpec,
            'build' => $parentBuild,
            'routing' => array_merge($parentRouting, [
                'hostname' => $hostname,
            ]),
            'live_url' => 'https://'.$hostname,
            'preview_parent_site_id' => $parent->id,
            'preview_kind' => self::KIND_ADHOC,
            'preview_branch' => $branch,
            'preview_ref_kind' => $refKind,
            'preview_pr_number' => null,
            'preview_head_sha' => $headSha,
        ];
        if ($parentOrigin !== null) {
            // Hybrid parents: previews share the same origin/auth/routes so
            // proxied routes hit the same backend and the build's origin
            // healthcheck has a URL to probe.
            $edgeMeta['origin'] = $parentOrigin;
        }

        $site = Site::query()->create([
            'server_id' => $server->id,
            'user_id' => $parent->user_id,
            'organization_id' => $parent->organization_id,
            'name' => $name,
            'slug' => $slug,
            'type' => SiteType::Static,
            'runtime' => null,
            'document_root' => null,
            'repository_path' => null,
            'edge_backend' => $parent->edge_backend,
            'status' => Site::STATUS_EDGE_PROVISIONING,
            'webhook_secret' => Str::random(48),
            'meta' => [
                'runtime_profile' => 'edge_web',
                'edge' => $edgeMeta,
            ],
        ]);

        $prefix = trim((string) config('edge.r2.key_prefix', 'edge/'), '/')
            .'/'.$parent->organization_id.'/'.$site->id.'/'.Str::ulid();

        $deployment = EdgeDeployment::query()->create([
            'site_id' => $site->id,
            'organization_id' => $parent->organization_id,
            'status' => EdgeDeployment::STATUS_BUILDING,
            'git_branch' => $branch,
            'git_commit' => $headSha,
            'storage_prefix' => $prefix,
        ]);

        BuildEdgeSiteJob::dispatch($deployment->id, $headSha);

        return $site->fresh();
    }

    /**
     * PR synchronize on an existing preview — redeploy the new head SHA and
     * refresh the GitHub Check Run + PR summary comment.
     */
    private function refreshExistingPreview(
        Site $preview,
        ?int $prNumber,
        ?string $headSha,
    ): Site {
        $headSha = is_string($headSha) && $headSha !== '' ? $headSha : null;
        $edge = $preview->edgeMeta();
        $metaUpdates = [];

        if ($prNumber !== null && $prNumber > 0) {
            $metaUpdates['preview_pr_number'] = $prNumber;
        }
        if ($headSha !== null) {
            $metaUpdates['preview_head_sha'] = $headSha;
            // Each commit gets its own Check Run — drop the stale id.
            $metaUpdates['github_check_run_id'] = null;
        }

        if ($metaUpdates !== []) {
            $preview->mergeEdgeMeta($metaUpdates);
            $preview->save();
            $preview->refresh();
        }

        if ($headSha === null) {
            return $preview;
        }

        $prefix = trim((string) config('edge.r2.key_prefix', 'edge/'), '/')
            .'/'.$preview->organization_id.'/'.$preview->id.'/'.Str::ulid();

        $deployment = EdgeDeployment::query()->create([
            'site_id' => $preview->id,
            'organization_id' => $preview->organization_id,
            'status' => EdgeDeployment::STATUS_BUILDING,
            'git_branch' => (string) ($edge['preview_branch'] ?? $preview->edgeMeta()['preview_branch'] ?? 'main'),
            'git_commit' => $headSha,
            'storage_prefix' => $prefix,
        ]);

        $preview->update(['status' => Site::STATUS_EDGE_PROVISIONING]);

        BuildEdgeSiteJob::dispatch($deployment->id);

        $this->mirrorPreviewOntoGithub($preview->fresh());

        return $preview->fresh();
    }

    private function mirrorPreviewOntoGithub(Site $preview): void
    {
        $headSha = trim((string) ($preview->edgeMeta()['preview_head_sha'] ?? ''));
        if ($headSha === '') {
            return;
        }

        try {
            app(EdgeGithubCheckRunService::class)->create($preview);
        } catch (\Throwable $e) {
            Log::warning('Edge preview check-run create failed', [
                'site_id' => (string) $preview->id,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            app(EdgeGithubPullRequestCommenter::class)->upsert($preview, 'building');
        } catch (\Throwable $e) {
            Log::warning('Edge preview PR comment create failed', [
                'site_id' => (string) $preview->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public static function findExisting(Site $parent, string $branch): ?Site
    {
        return self::livePreviewQuery($parent)
            ->whereJsonContains('meta->edge->preview_branch', $branch)
            ->first();
    }

    /**
     * Ad-hoc dedup key — same parent + same commit returns the existing
     * preview (including failed ones so a retry rebuilds that row). PR
     * previews are excluded so a webhook-driven preview on the same branch
     * can't accidentally satisfy an ad-hoc create request.
     */
    public static function findExistingByCommit(Site $parent, string $headSha): ?Site
    {
        $headSha = strtolower(trim($headSha));
        if ($headSha === '') {
            return null;
        }

        return self::livePreviewQuery($parent)
            ->whereJsonContains('meta->edge->preview_kind', self::KIND_ADHOC)
            ->whereJsonContains('meta->edge->preview_head_sha', $headSha)
            ->first();
    }

    private function adhocPreviewNeedsRebuild(Site $preview): bool
    {
        if ($preview->status === Site::STATUS_EDGE_FAILED) {
            return true;
        }

        $latest = $preview->edgeDeployments()->latest('created_at')->first();

        return $latest !== null && $latest->status === EdgeDeployment::STATUS_FAILED;
    }

    /**
     * @return Collection<int, Site>
     */
    public static function listForParent(Site $parent): Collection
    {
        return self::livePreviewQuery($parent)
            ->with(['edgeDeployments' => fn ($query) => $query->orderByDesc('created_at')->limit(1)])
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * @return Builder<Site>
     */
    private static function livePreviewQuery(Site $parent): Builder
    {
        return Site::query()
            ->where('organization_id', $parent->organization_id)
            ->whereJsonContains('meta->edge->preview_parent_site_id', $parent->id)
            ->whereNull('meta->edge->torn_down_at');
    }

    private function previewName(string $parentName, string $branch, ?int $prNumber): string
    {
        if ($prNumber !== null && $prNumber > 0) {
            return sprintf('PR #%d — %s', $prNumber, $parentName);
        }

        return sprintf('%s preview (%s)', $parentName, $branch);
    }

    /**
     * Apex for the preview hostname.
     *
     * Under Fake Edge, never inherit a public on-dply.* apex from the parent —
     * builds only write the local host map / disk, so those URLs hit Cloudflare
     * with an empty HOST_MAP and look "broken". Use DPLY_EDGE_TESTING_DOMAINS
     * (e.g. dply.test / edge.test) so Valet/dnsmasq can route traffic here.
     */
    private static function apexForParent(Site $parent): string
    {
        if (FakeEdgeProvision::enabled()) {
            $local = EdgeTestingDomains::localDevApex();
            if ($local !== '') {
                return $local;
            }
        }

        return app(UnifiedPreviewHostname::class)->apexForSite($parent);
    }
}
