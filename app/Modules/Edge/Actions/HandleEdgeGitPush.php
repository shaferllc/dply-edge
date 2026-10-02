<?php

declare(strict_types=1);

namespace App\Modules\Edge\Actions;

use App\Models\EdgeDeployment;
use App\Models\Site;
use App\Modules\Edge\Jobs\TeardownEdgeSiteJob;
use App\Modules\Edge\Support\EdgePreviewPolicy;
use App\Modules\Edge\Support\EdgeRepoRoot;

/**
 * What a push to an Edge site's repo does — shared by the GitHub webhook and
 * dply Git push events so the two sources can't drift.
 *
 *   push to the source branch   → RedeployEdgeSite (when Deploy on push is on)
 *   push to another branch      → that branch's preview, when $branchPreviews
 *   branch deleted (after = 0…) → tear that branch's preview down
 *
 * GitHub passes $branchPreviews = false: there the pull request, not the
 * branch, is what gets a preview. dply Git has no pull requests, so each
 * branch is the unit, gated by `previews.enabled` / `exclude_branches`.
 */
class HandleEdgeGitPush
{
    private const ZERO_SHA = '0000000000000000000000000000000000000000';

    /**
     * @param  list<string>  $changedFiles  empty = unknown (deploys)
     * @param  (callable(EdgeDeployment): void)|null  $onDeploy
     * @return array<string, mixed> the outcome, as the webhook's JSON body
     */
    public function handle(
        Site $site,
        string $ref,
        ?string $after,
        array $changedFiles = [],
        bool $branchPreviews = false,
        ?callable $onDeploy = null,
    ): array {
        $branch = preg_replace('#^refs/heads/#', '', $ref) ?? '';
        $sourceBranch = (string) ($site->edgeMeta()['source']['branch'] ?? 'main');
        $deleted = $after === self::ZERO_SHA;

        if ($branch === '' || $branch !== $sourceBranch) {
            if (! $branchPreviews || ! str_starts_with($ref, 'refs/heads/')) {
                return [
                    'ok' => true,
                    'queued' => false,
                    'reason' => 'push_branch_does_not_match_source',
                    'pushed_branch' => $branch,
                    'source_branch' => $sourceBranch,
                ];
            }

            return $this->branchPreview($site, $branch, $deleted ? null : $after);
        }

        if ($deleted) {
            return ['ok' => true, 'queued' => false, 'reason' => 'source_branch_deleted', 'branch' => $branch];
        }

        // Build → "Deploy on push" is the switch; legacy sites without the key deploy.
        if (($site->edgeMeta()['source']['deploy_on_push'] ?? true) === false) {
            return ['ok' => true, 'queued' => false, 'reason' => 'deploy_on_push_disabled', 'branch' => $branch];
        }

        if (! EdgeRepoRoot::pushTouchesSite($site->edgeRepoRoot(), $changedFiles)) {
            return [
                'ok' => true,
                'queued' => false,
                'reason' => 'push_outside_repo_root',
                'repo_root' => $site->edgeRepoRoot(),
                'changed_files' => $changedFiles,
            ];
        }

        $deployment = (new RedeployEdgeSite)->handle($site, $after);
        if ($onDeploy !== null) {
            $onDeploy($deployment);
        }

        return [
            'ok' => true,
            'queued' => 'redeploy',
            'site' => $site->id,
            'deployment_id' => $deployment->id,
            'branch' => $branch,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function branchPreview(Site $site, string $branch, ?string $headSha): array
    {
        if ($headSha === null) {
            $preview = CreateEdgePreviewSite::findExisting($site, $branch);
            if ($preview === null) {
                return ['ok' => true, 'queued' => false, 'reason' => 'no_preview', 'branch' => $branch];
            }
            TeardownEdgeSiteJob::dispatch($preview->id);

            return ['ok' => true, 'queued' => 'teardown', 'preview_id' => $preview->id, 'branch' => $branch];
        }

        // A branch is what a pull request is on GitHub, so it takes the PR rule.
        if (! EdgePreviewPolicy::shouldCreatePreview($site, EdgePreviewPolicy::EVENT_PULL_REQUEST, $branch)) {
            return ['ok' => true, 'queued' => false, 'reason' => 'preview_disabled_by_dply_yaml', 'branch' => $branch];
        }

        $preview = (new CreateEdgePreviewSite)->handle($site, $branch, null, $headSha);

        return ['ok' => true, 'queued' => 'preview', 'preview_id' => $preview->id, 'branch' => $branch];
    }
}
