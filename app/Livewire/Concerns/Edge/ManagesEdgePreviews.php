<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Edge;

use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Models\Site;
use App\Modules\Edge\Actions\CreateEdgePreviewSite;
use App\Modules\Edge\Jobs\TeardownEdgeSiteJob;
use Livewire\Component;

/**
 * Ad-hoc preview create/teardown. Compose with
 * {@see ManagesEdgeDeploymentLifecycle} for promote
 * confirmations and {@see ManagesEdgeDeployCommit} for the ref picker.
 *
 * @phpstan-require-extends Component
 *
 * @property Site $site
 * @property string $edge_deploy_commit_sha
 * @property ?string $edge_deploy_commit_branch
 * @property ?string $edge_deploy_commit_ref_kind
 *
 * @method void openConfirmActionModal(string $method, mixed $arguments = [], string $title = 'Confirm action', string $message = 'Are you sure?', string $confirmLabel = 'Confirm', bool $destructive = false, ?list<array{label: string, value: string, mono?: bool, multiline?: bool, link?: bool}> $details = null)
 */
trait ManagesEdgePreviews
{
    use DispatchesToastNotifications;

    public ?string $edge_adhoc_preview_pending_site_id = null;

    public function createAdhocEdgePreview(): void
    {
        if (! $this->site->usesEdgeRuntime() || $this->site->isEdgePreview()) {
            return;
        }
        $this->authorize('update', $this->site);

        $sha = strtolower(trim($this->edge_deploy_commit_sha));
        if (preg_match('/^[a-f0-9]{7,40}$/', $sha) !== 1) {
            $this->toastError(__('Pick a commit (or type a 7–40 char SHA) before creating a preview.'));

            return;
        }

        $branch = $this->edge_deploy_commit_branch !== null
            ? trim($this->edge_deploy_commit_branch)
            : '';
        if ($branch === '') {
            $source = is_array($this->site->edgeMeta()['source'] ?? null)
                ? $this->site->edgeMeta()['source']
                : [];
            $branch = trim((string) ($source['branch'] ?? 'main'));
            if ($branch === '') {
                $branch = 'main';
            }
        }

        try {
            $preview = app(CreateEdgePreviewSite::class)->handleAdhoc(
                $this->site,
                $branch,
                $sha,
                $this->edge_deploy_commit_ref_kind,
            );
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());

            return;
        }

        $this->edge_deploy_commit_sha = '';
        $this->edge_deploy_commit_branch = null;
        $this->edge_deploy_commit_ref_kind = null;
        $this->edge_adhoc_preview_pending_site_id = (string) $preview->id;

        $this->toastSuccess(__('Preview build queued — the URL stays disabled until the worker is live.'));
    }

    public function adhocPreviewIsPending(): bool
    {
        if ($this->edge_adhoc_preview_pending_site_id === null) {
            return false;
        }

        $preview = Site::query()->find($this->edge_adhoc_preview_pending_site_id);
        if ($preview === null
            || $preview->organization_id !== $this->site->organization_id
            || ($preview->edgeMeta()['preview_parent_site_id'] ?? null) !== $this->site->id) {
            $this->edge_adhoc_preview_pending_site_id = null;

            return false;
        }

        if ($preview->status === Site::STATUS_EDGE_FAILED) {
            $this->edge_adhoc_preview_pending_site_id = null;
            $latest = $preview->edgeDeployments()->latest()->first();
            $reason = $latest?->failure_reason ?: __('Preview build failed — see deploy log.');
            $this->toastError($reason);

            return false;
        }

        if ($preview->status === Site::STATUS_EDGE_ACTIVE) {
            $deployment = $preview->edgeDeployments()->latest()->first();
            $publishedAt = $deployment?->published_at;
            if ($publishedAt === null || $publishedAt->diffInSeconds(now()) >= 45) {
                $this->edge_adhoc_preview_pending_site_id = null;
                $this->toastSuccess(__('Preview is live — the URL should respond now.'));

                return false;
            }
        }

        return true;
    }

    public function confirmTearDownEdgePreview(string $previewSiteId): void
    {
        $this->openConfirmActionModal(
            'tearDownEdgePreview',
            [$previewSiteId],
            __('Tear down this preview?'),
            __('The R2 artifacts and Edge hostname will be removed and the preview URL will stop responding. This cannot be undone — the preview can be re-created from the same commit afterwards.'),
            __('Tear down preview'),
            true,
        );
    }

    public function tearDownEdgePreview(string $previewSiteId): void
    {
        if (! $this->site->usesEdgeRuntime()) {
            return;
        }
        $this->authorize('update', $this->site);

        $preview = Site::query()->find($previewSiteId);
        if ($preview === null
            || $preview->organization_id !== $this->site->organization_id
            || ($preview->edgeMeta()['preview_parent_site_id'] ?? null) !== $this->site->id) {
            $this->toastError(__('Preview not found or not a child of this site.'));

            return;
        }

        TeardownEdgeSiteJob::dispatch($preview->id);

        $branch = (string) ($preview->edgeMeta()['preview_branch'] ?? '');
        $this->toastSuccess(__('Preview teardown queued for branch :branch.', ['branch' => $branch]));
    }
}
