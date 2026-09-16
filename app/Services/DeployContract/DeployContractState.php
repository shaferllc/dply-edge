<?php

declare(strict_types=1);

namespace App\Services\DeployContract;

use App\Models\DeployContractRun;
use App\Models\EdgeDeployment;
use App\Models\Site;

/**
 * Aggregates deploy contract status for a preview — latest run, promote gate.
 */
final class DeployContractState
{
    public function enabled(): bool
    {
        return (bool) config('deploy_contract.require_for_promote', true);
    }

    public function latestRunForPreview(Site $parent, Site $preview): ?DeployContractRun
    {
        return DeployContractRun::query()
            ->where('parent_site_id', $parent->id)
            ->where('preview_site_id', $preview->id)
            ->latest('created_at')
            ->first();
    }

    public function runMatchesCurrentDeployment(DeployContractRun $run, Site $preview): bool
    {
        $deployment = EdgeDeployment::query()
            ->where('site_id', $preview->id)
            ->where('status', EdgeDeployment::STATUS_LIVE)
            ->latest('published_at')
            ->first();

        if ($deployment === null) {
            return $run->preview_deployment_id === null;
        }

        return (string) $run->preview_deployment_id === (string) $deployment->id;
    }

    /**
     * @return array{enabled: bool, require_run: bool, allow_waivers: bool, ready_to_promote: bool, has_run: bool, run_current: bool, status: string|null, passed_count: int, ...}
     *                                                                                                                                                                             enabled: bool,
     *                                                                                                                                                                             require_run: bool,
     *                                                                                                                                                                             allow_waivers: bool,
     *                                                                                                                                                                             ready_to_promote: bool,
     *                                                                                                                                                                             has_run: bool,
     *                                                                                                                                                                             run_current: bool,
     *                                                                                                                                                                             status: ?string,
     *                                                                                                                                                                             passed_count: int,
     *                                                                                                                                                                             failed_count: int,
     *                                                                                                                                                                             checks: list<array<string, mixed>>,
     *                                                                                                                                                                             run_id: ?string,
     *                                                                                                                                                                             finished_at: ?string,
     *                                                                                                                                                                             }
     */
    public function forPreview(Site $parent, Site $preview): array
    {
        $enabled = $this->enabled();
        $run = $this->latestRunForPreview($parent, $preview);
        $runCurrent = $run !== null && $this->runMatchesCurrentDeployment($run, $preview);
        $allowWaivers = (bool) config('deploy_contract.allow_waivers', true);
        $requireRun = (bool) config('deploy_contract.require_run_before_promote', true);

        $ready = ! $enabled;
        if ($enabled) {
            if ($run === null) {
                $ready = ! $requireRun;
            } elseif (! $runCurrent) {
                $ready = false;
            } else {
                $ready = $run->isPromoteAllowed();
            }
        }

        $summary = is_array($run?->summary) ? $run->summary : [];
        $policySource = is_string($summary['policy_source'] ?? null) ? $summary['policy_source'] : null;

        return [
            'enabled' => $enabled,
            'require_run' => $requireRun,
            'allow_waivers' => $allowWaivers,
            'ready_to_promote' => $ready,
            'has_run' => $run !== null,
            'run_current' => $runCurrent,
            'status' => $run?->status,
            'passed_count' => (int) ($summary['passed_count'] ?? 0),
            'failed_count' => (int) ($summary['failed_count'] ?? 0),
            'checks' => is_array($run?->checks) ? $run->checks : [],
            'run_id' => $run?->id !== null ? (string) $run->id : null,
            'finished_at' => $run?->finished_at?->toDateTimeString(),
            'policy_source' => $policySource,
        ];
    }

    /**
     * @param  array<string, mixed>  $contract
     */
    public function promoteBlockedMessage(array $contract): ?string
    {
        if (empty($contract['enabled'])) {
            return null;
        }

        if (! empty($contract['ready_to_promote'])) {
            return null;
        }

        if (empty($contract['has_run'])) {
            return (string) __('Run the deploy contract on this preview before promoting to production.');
        }

        if (empty($contract['run_current'])) {
            return (string) __('A new preview deployment was published — re-run the deploy contract before promote.');
        }

        if (($contract['status'] ?? null) === DeployContractRun::STATUS_FAILED) {
            return (string) __('Deploy contract failed — fix failing checks, re-run the contract, or record a waiver.');
        }

        return (string) __('Deploy contract must pass before promote.');
    }

    /**
     * @param  array<string, mixed>  $contract
     * @return list<array{label: string, value: string, mono?: bool}>
     */
    public function confirmModalRows(array $contract): array
    {
        if (empty($contract['enabled'])) {
            return [];
        }

        $status = (string) ($contract['status'] ?? __('Not run'));
        $ready = ! empty($contract['ready_to_promote']);

        return [
            [
                'label' => (string) __('Deploy contract'),
                'value' => $ready
                    ? (string) __('Passed')
                    : (string) __('Not ready — :status', ['status' => $status]),
            ],
            [
                'label' => (string) __('Contract checks'),
                'value' => sprintf(
                    '%d passed · %d failed',
                    (int) ($contract['passed_count'] ?? 0),
                    (int) ($contract['failed_count'] ?? 0),
                ),
            ],
        ];
    }
}
