<?php

declare(strict_types=1);

namespace App\Services\DeployContract\Checks;

use App\Modules\Edge\Services\OriginHealthcheckRunner;
use App\Services\DeployContract\Contracts\DeployContractCheck;
use App\Services\DeployContract\DeployContractCheckResult;
use App\Services\DeployContract\DeployContractContext;

final class EdgeHybridOriginHealthCheck implements DeployContractCheck
{
    public function __construct(
        private readonly OriginHealthcheckRunner $healthcheck,
    ) {}

    public function key(): string
    {
        return 'edge.origin.health';
    }

    public function label(): string
    {
        return (string) __('Hybrid origin health');
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

        if (($context->parent->edgeMeta()['runtime_mode'] ?? 'static') !== 'hybrid') {
            return new DeployContractCheckResult(
                DeployContractCheckResult::STATUS_SKIP,
                (string) __('Site is not a hybrid Edge runtime.'),
            );
        }

        if ($context->linkedCloudSite !== null) {
            return new DeployContractCheckResult(
                DeployContractCheckResult::STATUS_SKIP,
                (string) __('Cloud origin check covers the linked app.'),
            );
        }

        $result = $this->healthcheck->run($context->parent);

        if ($result['ok']) {
            return new DeployContractCheckResult(
                DeployContractCheckResult::STATUS_PASS,
                (string) $result['message'],
            );
        }

        return new DeployContractCheckResult(
            DeployContractCheckResult::STATUS_FAIL,
            (string) $result['message'],
        );
    }
}
