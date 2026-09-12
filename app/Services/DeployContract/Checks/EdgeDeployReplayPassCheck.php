<?php

declare(strict_types=1);

namespace App\Services\DeployContract\Checks;

use App\Models\EdgeDeployReplay;
use App\Services\DeployContract\Contracts\DeployContractCheck;
use App\Services\DeployContract\DeployContractCheckResult;
use App\Services\DeployContract\DeployContractContext;

final class EdgeDeployReplayPassCheck implements DeployContractCheck
{
    public function key(): string
    {
        return 'edge.preview.replay';
    }

    public function label(): string
    {
        return (string) __('Shadow replay');
    }

    public function engine(): string
    {
        return 'edge';
    }

    public function evaluate(DeployContractContext $context): DeployContractCheckResult
    {
        if (! $context->policy->shouldRunCheck($this->key())) {
            return new DeployContractCheckResult(
                DeployContractCheckResult::STATUS_SKIP,
                (string) __('Not required by repo deploy contract.'),
            );
        }

        if (! $context->policy->effectiveRequireReplay()) {
            return new DeployContractCheckResult(
                DeployContractCheckResult::STATUS_SKIP,
                (string) __('Replay is not required by deploy contract policy.'),
            );
        }

        $replay = EdgeDeployReplay::query()
            ->where('parent_site_id', $context->parent->id)
            ->where('preview_site_id', $context->preview->id)
            ->where('status', EdgeDeployReplay::STATUS_COMPLETED)
            ->when(
                $context->previewDeployment !== null,
                fn ($q) => $q->where('preview_deployment_id', $context->previewDeployment->id),
            )
            ->latest('finished_at')
            ->first();

        if ($replay === null) {
            return new DeployContractCheckResult(
                DeployContractCheckResult::STATUS_FAIL,
                (string) __('Run shadow replay against this preview before promote.'),
            );
        }

        $passRate = (float) data_get($replay->summary, 'pass_rate', 0);
        $minRate = $context->policy->effectiveMinReplayPassRate();

        if ($passRate < $minRate) {
            return new DeployContractCheckResult(
                DeployContractCheckResult::STATUS_FAIL,
                (string) __('Last replay pass rate :rate% is below the required :min%.', [
                    'rate' => round($passRate, 1),
                    'min' => $minRate,
                ]),
            );
        }

        return new DeployContractCheckResult(
            DeployContractCheckResult::STATUS_PASS,
            (string) __('Last replay :rate% status match meets the :min% threshold.', [
                'rate' => round($passRate, 1),
                'min' => $minRate,
            ]),
        );
    }
}
