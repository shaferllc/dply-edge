<?php

declare(strict_types=1);

namespace App\Livewire\Sites;

use App\Modules\Edge\Actions\PromoteEdgePreview;
use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Livewire\Concerns\ManagesDeployContract;
use App\Models\EdgeDeployment;
use App\Models\EdgePreviewComment;
use App\Models\EdgePreviewReviewApproval;
use App\Models\Server;
use App\Models\Site;
use App\Services\DeployContract\DeployContractState;
use App\Modules\Edge\Services\EdgePreviewReviewState;
use App\Modules\Edge\Support\EdgeDeploymentConfirmSummary;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Preview review hub — PR-linked threads, approvals, and promote workflow.
 */
#[Layout('layouts.app')]
class EdgePreviewComments extends Component
{
    use ConfirmsActionWithModal;
    use DispatchesToastNotifications;
    use ManagesDeployContract;

    public Server $server;

    public Site $site;

    public string $newCommentBody = '';

    public string $newCommentUrlPath = '/';

    public ?string $replyToCommentId = null;

    public string $replyBody = '';

    public string $approvalNote = '';

    protected function contractParentSite(): Site
    {
        $parent = $this->parentSite();
        if ($parent === null) {
            abort(404);
        }

        return $parent;
    }

    public function runDeployContractFromReviewHub(): void
    {
        $this->runDeployContract((string) $this->site->id);
    }

    public function confirmWaiveDeployContractFromReviewHub(): void
    {
        $this->confirmWaiveDeployContract((string) $this->site->id);
    }

    public function mount(Server $server, Site $site): void
    {
        $this->authorize('view', $site);

        if (! $site->usesEdgeRuntime() || ! $site->isEdgePreview()) {
            abort(404);
        }

        $this->server = $server;
        $this->site = $site;
    }

    public function addComment(): void
    {
        $this->authorize('update', $this->site);

        $this->validate([
            'newCommentBody' => ['required', 'string', 'max:8000'],
            'newCommentUrlPath' => ['required', 'string', 'max:2048', 'regex:#^/[^\s]*$#'],
        ]);

        EdgePreviewComment::query()->create([
            'organization_id' => $this->site->organization_id,
            'site_id' => $this->site->id,
            'created_by_user_id' => auth()->id(),
            'selector' => null,
            'viewport_width' => null,
            'url_path' => trim($this->newCommentUrlPath),
            'body' => trim($this->newCommentBody),
        ]);

        $this->newCommentBody = '';
        $this->toastSuccess(__('Comment added.'));
    }

    public function startReply(string $commentId): void
    {
        $this->replyToCommentId = $commentId;
        $this->replyBody = '';
    }

    public function cancelReply(): void
    {
        $this->replyToCommentId = null;
        $this->replyBody = '';
    }

    public function submitReply(): void
    {
        $this->authorize('update', $this->site);

        if ($this->replyToCommentId === null) {
            return;
        }

        $this->validate([
            'replyBody' => ['required', 'string', 'max:8000'],
        ]);

        $parent = EdgePreviewComment::query()
            ->where('site_id', $this->site->id)
            ->whereNull('parent_id')
            ->whereKey($this->replyToCommentId)
            ->firstOrFail();

        EdgePreviewComment::query()->create([
            'organization_id' => $this->site->organization_id,
            'site_id' => $this->site->id,
            'parent_id' => $parent->id,
            'created_by_user_id' => auth()->id(),
            'url_path' => $parent->url_path,
            'selector' => $parent->selector,
            'viewport_width' => $parent->viewport_width,
            'body' => trim($this->replyBody),
        ]);

        $this->replyToCommentId = null;
        $this->replyBody = '';
        $this->toastSuccess(__('Reply added.'));
    }

    public function toggleResolved(string $commentId): void
    {
        $this->authorize('update', $this->site);

        $comment = EdgePreviewComment::query()
            ->where('site_id', $this->site->id)
            ->whereKey($commentId)
            ->firstOrFail();

        $comment->update([
            'resolved_at' => $comment->resolved_at === null ? now() : null,
            'resolved_by_user_id' => $comment->resolved_at === null ? auth()->id() : null,
        ]);
    }

    public function confirmDeleteComment(string $commentId): void
    {
        $this->authorize('update', $this->site);

        $this->openConfirmActionModal(
            'deleteComment',
            [$commentId],
            __('Delete this comment?'),
            __('The comment and any replies will be removed from this preview review.'),
            __('Delete comment'),
            true,
        );
    }

    public function deleteComment(string $commentId): void
    {
        $this->authorize('update', $this->site);

        EdgePreviewComment::query()
            ->where('site_id', $this->site->id)
            ->whereKey($commentId)
            ->delete();

        $this->toastSuccess(__('Comment deleted.'));
    }

    public function approveReview(): void
    {
        $this->authorize('update', $this->site);

        $this->validate([
            'approvalNote' => ['nullable', 'string', 'max:500'],
        ]);

        $userId = auth()->id();
        if ($userId === null) {
            return;
        }

        EdgePreviewReviewApproval::query()->updateOrCreate(
            [
                'site_id' => $this->site->id,
                'user_id' => $userId,
            ],
            [
                'organization_id' => $this->site->organization_id,
                'note' => trim($this->approvalNote) !== '' ? trim($this->approvalNote) : null,
            ],
        );

        $this->approvalNote = '';
        $this->toastSuccess(__('Review approved.'));
    }

    public function revokeApproval(): void
    {
        $this->authorize('update', $this->site);

        $userId = auth()->id();
        if ($userId === null) {
            return;
        }

        EdgePreviewReviewApproval::query()
            ->where('site_id', $this->site->id)
            ->where('user_id', $userId)
            ->delete();

        $this->toastSuccess(__('Approval removed.'));
    }

    public function confirmPromoteToProduction(EdgePreviewReviewState $reviewState): void
    {
        $this->authorize('update', $this->site);

        $parent = $this->parentSite();
        if ($parent === null) {
            $this->toastError(__('Parent production site not found.'));

            return;
        }

        $this->authorize('update', $parent);

        $review = $reviewState->forPreview($this->site);
        if ($blocked = $reviewState->promoteBlockedMessage($review)) {
            $this->toastError($blocked);

            return;
        }

        $contractState = app(DeployContractState::class);
        $contract = $contractState->forPreview($parent, $this->site);
        if ($blocked = $contractState->promoteBlockedMessage($contract)) {
            $this->toastError($blocked);

            return;
        }

        $previewDeployment = EdgeDeployment::query()
            ->where('site_id', $this->site->id)
            ->where('status', EdgeDeployment::STATUS_LIVE)
            ->latest('published_at')
            ->first();

        if ($previewDeployment === null) {
            $this->toastError(__('Preview has no live deployment to promote.'));

            return;
        }

        $rows = array_merge(
            EdgeDeploymentConfirmSummary::promoteRows($parent, $this->site, $previewDeployment),
            $reviewState->confirmModalRows($review),
            $contractState->confirmModalRows($contract),
        );

        $this->openConfirmActionModal(
            'promoteToProduction',
            [],
            __('Promote this preview to production?'),
            __('Copy this preview\'s artifacts into a fresh production prefix and flip the host map. The preview keeps running.'),
            __('Promote to production'),
            false,
            $rows,
        );
    }

    public function promoteToProduction(): void
    {
        $parent = $this->parentSite();
        if ($parent === null) {
            $this->toastError(__('Parent production site not found.'));

            return;
        }

        $this->authorize('update', $parent);

        try {
            app(PromoteEdgePreview::class)->handle($parent, (string) $this->site->id);
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());

            return;
        }

        $org = $parent->organization;
        if ($org !== null) {
            audit_log($org, auth()->user(), 'site.edge.preview.promoted', $parent, null, [
                'preview_site_id' => $this->site->id,
                'from_review_hub' => true,
            ]);
        }

        $this->toastSuccess(__('Preview promoted — production is now serving the preview build.'));
    }

    public function render(EdgePreviewReviewState $reviewState, DeployContractState $contractState): View
    {
        $threads = EdgePreviewComment::query()
            ->where('site_id', $this->site->id)
            ->whereNull('parent_id')
            ->with([
                'createdBy:id,name,email',
                'resolvedBy:id,name,email',
                'replies.createdBy:id,name,email',
                'replies.resolvedBy:id,name,email',
            ])
            ->orderByDesc('created_at')
            ->get();

        $review = $reviewState->forPreview($this->site);
        $parent = $this->parentSite();
        $contract = $parent !== null
            ? $contractState->forPreview($parent, $this->site)
            : ['enabled' => false, 'ready_to_promote' => true];
        $userApproval = EdgePreviewReviewApproval::query()
            ->where('site_id', $this->site->id)
            ->where('user_id', auth()->id())
            ->first();

        return view('livewire.sites.edge-preview-comments', [
            'threads' => $threads,
            'review' => $review,
            'contract' => $contract,
            'deployContractEnabled' => true,
            'parentSite' => $parent,
            'userHasApproved' => $userApproval !== null,
        ]);
    }

    private function parentSite(): ?Site
    {
        $parentId = $this->site->edgeMeta()['preview_parent_site_id'] ?? null;
        if (! is_string($parentId) || $parentId === '') {
            return null;
        }

        return Site::query()->find($parentId);
    }
}
