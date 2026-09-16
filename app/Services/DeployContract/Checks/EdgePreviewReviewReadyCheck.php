<?php

declare(strict_types=1);

namespace App\Services\DeployContract\Checks;

use App\Modules\Edge\Services\EdgePreviewReviewState;
use App\Services\DeployContract\Contracts\DeployContractCheck;
use App\Services\DeployContract\DeployContractCheckResult;
use App\Services\DeployContract\DeployContractContext;

final class EdgePreviewReviewReadyCheck implements DeployContractCheck
{
    public function __construct(
        private readonly EdgePreviewReviewState $reviewState = new EdgePreviewReviewState,
    ) {}

    public function key(): string
    {
        return 'edge.preview.review';
    }

    public function label(): string
    {
        return (string) __('Preview review');
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

        $review = $this->reviewState->forPreview($context->preview);

        if (! empty($review['ready_to_promote'])) {
            return new DeployContractCheckResult(
                DeployContractCheckResult::STATUS_PASS,
                (string) __('Review threads and approvals satisfy promote policy.'),
            );
        }

        $message = $this->reviewState->promoteBlockedMessage($review)
            ?? (string) __('Preview review is not ready to promote.');

        return new DeployContractCheckResult(
            DeployContractCheckResult::STATUS_FAIL,
            $message,
        );
    }
}
