<?php

declare(strict_types=1);

namespace App\Services\DeployContract;

use App\Models\DeployContractRun;
use App\Models\EdgeDeployment;
use App\Models\Site;
use App\Models\User;
use App\Modules\Edge\Services\EdgeGithubDeployContractCheckService;
use App\Services\DeployContract\Checks\CloudOriginHealthCheck;
use App\Services\DeployContract\Checks\EdgeDeployReplayPassCheck;
use App\Services\DeployContract\Checks\EdgeEnvKeysSubsetCheck;
use App\Services\DeployContract\Checks\EdgeHybridOriginHealthCheck;
use App\Services\DeployContract\Checks\EdgePreviewLiveDeploymentCheck;
use App\Services\DeployContract\Checks\EdgePreviewReviewReadyCheck;
use App\Services\DeployContract\Contracts\DeployContractCheck;

final class DeployContractEvaluator
{
    /** @var list<class-string<DeployContractCheck>> */
    private const CHECKS = [
        EdgePreviewLiveDeploymentCheck::class,
        EdgePreviewReviewReadyCheck::class,
        EdgeDeployReplayPassCheck::class,
        EdgeEnvKeysSubsetCheck::class,
        EdgeHybridOriginHealthCheck::class,
        CloudOriginHealthCheck::class,
    ];

    public function __construct(
        private readonly DeployContractLinkedResources $linkedResources,
        private readonly EdgeGithubDeployContractCheckService $githubChecks,
    ) {}

    /**
     * @return array{
     *   passed: bool,
     *   checks: list<array{key: string, label: string, engine: string, status: string, message: string}>,
     *   summary: array{passed_count: int, failed_count: int, skipped_count: int, policy_source: string},
     * }
     */
    public function evaluate(DeployContractContext $context): array
    {
        $checks = [];
        $passedCount = 0;
        $failedCount = 0;
        $skippedCount = 0;

        foreach (self::CHECKS as $class) {
            /** @var DeployContractCheck $check */
            $check = app($class);
            $result = $check->evaluate($context);

            $checks[] = [
                'key' => $check->key(),
                'label' => $check->label(),
                'engine' => $check->engine(),
                'status' => $result->status,
                'message' => $result->message,
            ];

            if ($result->status === DeployContractCheckResult::STATUS_SKIP) {
                $skippedCount++;
            } elseif ($result->passed()) {
                $passedCount++;
            } else {
                $failedCount++;
            }
        }

        return [
            'passed' => $failedCount === 0,
            'checks' => $checks,
            'summary' => [
                'passed_count' => $passedCount,
                'failed_count' => $failedCount,
                'skipped_count' => $skippedCount,
                'policy_source' => $context->policy->requires === []
                    ? 'platform_defaults'
                    : 'repo_contract',
            ],
        ];
    }

    public function runAndPersist(
        Site $parent,
        Site $preview,
        ?User $triggeredBy = null,
    ): DeployContractRun {
        $previewDeployment = EdgeDeployment::query()
            ->where('site_id', $preview->id)
            ->where('status', EdgeDeployment::STATUS_LIVE)
            ->latest('published_at')
            ->first();

        $context = $this->buildContext($parent, $preview, $previewDeployment, $triggeredBy);

        $this->githubChecks->start($preview);

        $evaluation = $this->evaluate($context);

        $run = DeployContractRun::query()->create([
            'organization_id' => $parent->organization_id,
            'parent_site_id' => $parent->id,
            'preview_site_id' => $preview->id,
            'preview_deployment_id' => $previewDeployment?->id,
            'git_commit' => $previewDeployment?->git_commit,
            'triggered_by_user_id' => $triggeredBy?->id,
            'status' => $evaluation['passed']
                ? DeployContractRun::STATUS_PASSED
                : DeployContractRun::STATUS_FAILED,
            'checks' => $evaluation['checks'],
            'summary' => $evaluation['summary'],
            'finished_at' => now(),
        ]);

        $this->githubChecks->complete(
            $preview,
            $evaluation['passed'] ? 'success' : 'failure',
            $this->contractDetailsUrl($parent, $preview),
            $evaluation['checks'],
        );

        return $run;
    }

    public function buildContext(
        Site $parent,
        Site $preview,
        ?EdgeDeployment $previewDeployment,
        ?User $triggeredBy,
    ): DeployContractContext {
        $repoConfig = is_array($previewDeployment?->repo_config) ? $previewDeployment->repo_config : [];
        $contractBlock = is_array($repoConfig['contract'] ?? null) ? $repoConfig['contract'] : null;
        $policy = DeployContractPolicy::fromRepoConfig($contractBlock);
        $linked = $this->linkedResources->forParent($parent);
        $backendSites = $this->linkedResources->linkedDplyBackendSites($parent, $previewDeployment)->all();

        return new DeployContractContext(
            $parent,
            $preview,
            $previewDeployment,
            $triggeredBy,
            $policy,
            $linked['cloud'],
            $linked['byo'],
            $backendSites,
        );
    }

    private function contractDetailsUrl(Site $parent, Site $preview): ?string
    {
        try {
            return route('sites.preview-comments', [
                'server' => $preview->server_id,
                'site' => $preview,
            ]);
        } catch (\Throwable) {
            return $parent->edgeLiveUrl();
        }
    }
}
