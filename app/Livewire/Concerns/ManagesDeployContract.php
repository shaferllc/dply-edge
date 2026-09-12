<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Models\Site;
use App\Services\DeployContract\DeployContractEvaluator;
use App\Services\DeployContract\DeployContractState;
use Livewire\Component;
use App\Actions\DeployContract\WaiveDeployContractRun;
use App\Models\DeployContractRun;

/**
 * Run deploy contract checks and optional waiver flow on Edge previews.
 *
 * @phpstan-require-extends Component
 *
 * @property Site $site
 *
 * @method void openConfirmActionModal(string $method, mixed $arguments = [], string $title = 'Confirm action', string $message = 'Are you sure?', string $confirmLabel = 'Confirm', bool $destructive = false, ?list<array{label: string, value: string, mono?: bool, multiline?: bool, link?: bool}> $details = null)
 */
trait ManagesDeployContract
{
    use DispatchesToastNotifications;

    public string $deployContractWaiverReason = '';

    protected function contractParentSite(): Site
    {
        return $this->site;
    }

    public function runDeployContract(string $previewSiteId): void
    {
        $parent = $this->contractParentSite();

        if (! $parent->usesEdgeRuntime() || $parent->isEdgePreview()) {
            return;
        }

        $this->authorize('update', $parent);

        $preview = Site::query()->find($previewSiteId);
        if ($preview === null
            || $preview->organization_id !== $parent->organization_id
            || ($preview->edgeMeta()['preview_parent_site_id'] ?? null) !== $parent->id) {
            $this->toastError(__('Preview not found or not a child of this site.'));

            return;
        }

        $run = app(DeployContractEvaluator::class)->runAndPersist(
            $parent,
            $preview,
            auth()->user(),
        );

        $org = $parent->organization;
        if ($org !== null) {
            audit_log($org, auth()->user(), 'site.deploy_contract.run', $parent, null, [
                'preview_site_id' => $preview->id,
                'run_id' => $run->id,
                'status' => $run->status,
            ]);
        }

        if ($run->status === DeployContractRun::STATUS_PASSED) {
            $this->toastSuccess(__('Deploy contract passed — promote is unblocked when review policy is satisfied.'));
        } else {
            $this->toastError(__('Deploy contract failed — see check results below.'));
        }
    }

    public function confirmWaiveDeployContract(string $previewSiteId): void
    {
        if (! (bool) config('deploy_contract.allow_waivers', true)) {
            $this->toastError(__('Deploy contract waivers are disabled.'));

            return;
        }

        $parent = $this->contractParentSite();

        if (! $parent->usesEdgeRuntime() || $parent->isEdgePreview()) {
            return;
        }

        $this->authorize('update', $parent);

        $this->openConfirmActionModal(
            'waiveDeployContract',
            [$previewSiteId],
            __('Waive failed deploy contract?'),
            __('This records an audited exception so promote can proceed without passing every check. Explain what was verified manually.'),
            __('Record waiver'),
            false,
        );
    }

    public function waiveDeployContract(string $previewSiteId): void
    {
        $parent = $this->contractParentSite();

        if (! $parent->usesEdgeRuntime() || $parent->isEdgePreview()) {
            return;
        }

        $this->authorize('update', $parent);

        $preview = Site::query()->find($previewSiteId);
        if ($preview === null
            || $preview->organization_id !== $parent->organization_id
            || ($preview->edgeMeta()['preview_parent_site_id'] ?? null) !== $parent->id) {
            $this->toastError(__('Preview not found or not a child of this site.'));

            return;
        }

        try {
            $run = app(WaiveDeployContractRun::class)->handle(
                $parent,
                $preview,
                auth()->user(),
                $this->deployContractWaiverReason,
            );
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());

            return;
        }

        $this->deployContractWaiverReason = '';

        $org = $parent->organization;
        if ($org !== null) {
            audit_log($org, auth()->user(), 'site.deploy_contract.waived', $parent, null, [
                'preview_site_id' => $preview->id,
                'run_id' => $run->id,
            ]);
        }

        $this->toastSuccess(__('Deploy contract waived — promote is allowed with audit trail.'));
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function deployContractReviewForPreview(Site $preview): ?array
    {
        return app(DeployContractState::class)->forPreview($this->contractParentSite(), $preview);
    }

    protected function deployContractBlocksPromote(Site $preview): ?string
    {
        $contract = $this->deployContractReviewForPreview($preview);
        if ($contract === null) {
            return null;
        }

        return app(DeployContractState::class)->promoteBlockedMessage($contract);
    }
}
