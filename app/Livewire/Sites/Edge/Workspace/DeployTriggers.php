<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Edge\Workspace;

use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Livewire\Concerns\Edge\ManagesEdgeBuildSettings;
use App\Livewire\Concerns\Edge\MountsEdgeWorkspaceSection;
use App\Livewire\Forms\EdgeBuildSettingsForm;
use App\Models\Server;
use App\Models\Site;
use App\Modules\Edge\Actions\MoveSiteOffDplyGit;
use App\Modules\Edge\Jobs\MoveSiteToDplyGitJob;
use App\Modules\Edge\Support\EdgeContainerConnections;
use App\Modules\SourceControl\Services\DplyGit;
use App\Modules\SourceControl\Services\SourceControlRepositoryBrowser;
use App\Support\Sites\EdgeSiteViewData;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class DeployTriggers extends Component
{
    use ConfirmsActionWithModal;
    use DispatchesToastNotifications;
    use ManagesEdgeBuildSettings;
    use MountsEdgeWorkspaceSection;

    public EdgeBuildSettingsForm $buildForm;

    /** The hook open in its dialog. */
    public ?string $openHookId = null;

    public function mount(Server $server, Site $site): void
    {
        $this->mountEdgeWorkspaceSection($server, $site);
        $this->mountEdgeBuildSettings($site);
    }

    public function openHook(string $hookId): void
    {
        $this->openHookId = $hookId;
        $this->dispatch('open-modal', 'deploy-hook');
    }

    public function openNewHook(): void
    {
        $this->authorize('update', $this->site);
        $this->edge_new_deploy_hook_name = '';
        $this->edge_just_minted_deploy_hook_url = null;
        $this->dispatch('open-modal', 'deploy-hook-new');
    }

    /** Revoke the hook open in its dialog. */
    public function revokeOpenHook(): void
    {
        if ($this->openHookId === null) {
            return;
        }
        $this->revokeEdgeDeployHook($this->openHookId);
        $this->openHookId = null;
        $this->dispatch('close-modal', 'deploy-hook');
    }

    /** A dply Git push token, shown once in its dialog. */
    public ?string $dplyGitToken = null;

    public function moveToDplyGit(): void
    {
        $this->authorize('update', $this->site);
        if ($this->site->isEdgePreview() || DplyGit::siteUses($this->site)
            || ! EdgeContainerConnections::flagOn('git', $this->site->organization)) {
            return;
        }

        $this->site->mergeEdgeMeta(['dply_git_move' => ['status' => 'moving', 'at' => now()->toIso8601String()]]);
        $this->site->save();
        MoveSiteToDplyGitJob::dispatch($this->site->id);
        $this->toastSuccess(__('Copying your code to dply Git. This page updates when it’s done.'));
    }

    public function createDplyGitToken(): void
    {
        $this->authorize('update', $this->site);
        try {
            $this->dplyGitToken = app(DplyGit::class)->tokenForSite($this->site)['token'];
        } catch (\Throwable $e) {
            report($e);
            $this->toastError(__('Couldn’t create a push token: :reason', ['reason' => $e->getMessage()]));

            return;
        }
        $this->dispatch('open-modal', 'dply-git-token');
    }

    /** Open the confirm for moving back, warning when dply Git has pushes the old repo lacks. */
    public function confirmMoveOffDplyGit(): void
    {
        $this->authorize('update', $this->site);
        $from = (string) ($this->site->edgeMeta()['dply_git']['moved_from'] ?? '');
        if ($from === '') {
            return;
        }

        $pushed = MoveSiteOffDplyGit::pushedSinceMove($this->site);
        $this->openConfirmActionModal(
            'moveOffDplyGit',
            [],
            __('Move back to :repo', ['repo' => $from]),
            __('Deploy from :repo again? dply Git stops deploying this app; its copy of the code is kept, so you can move again later.', ['repo' => $from]),
            __('Move back'),
            false,
            warning: match ($pushed) {
                true => __('Someone pushed to dply Git after the move. Those commits aren’t on :repo — push them there first, or they won’t deploy.', ['repo' => $from]),
                null => __('Commits pushed only to dply Git aren’t copied back. Push them to :repo first.', ['repo' => $from]),
                false => null,
            },
        );
    }

    public function moveOffDplyGit(): void
    {
        $this->authorize('update', $this->site);
        try {
            $result = app(MoveSiteOffDplyGit::class)->handle($this->site, auth()->user());
        } catch (\Throwable $e) {
            report($e);
            $this->toastError($e->getMessage());

            return;
        }
        $this->site->refresh();

        $result['webhook'] === null
            ? $this->toastSuccess(__('Back on :repo.', ['repo' => $result['repo']]))
            : $this->toastWarning(__('Back on :repo, but GitHub isn’t connected: :reason', ['repo' => $result['repo'], 'reason' => $result['webhook']]));
    }

    public function revokeDplyGitTokens(): void
    {
        $this->authorize('update', $this->site);
        try {
            $count = app(DplyGit::class)->revokeAllForSite($this->site);
        } catch (\Throwable $e) {
            report($e);
            $this->toastError(__('Couldn’t revoke the tokens: :reason', ['reason' => $e->getMessage()]));

            return;
        }
        $this->toastSuccess(trans_choice('Revoked :count token.|Revoked :count tokens.', $count));
    }

    public function dismissDplyGitToken(): void
    {
        $this->dplyGitToken = null;
    }

    public function render(): View
    {
        $viewData = array_merge(
            EdgeSiteViewData::context($this->site, 'deploy-triggers'),
            [
                'server' => $this->server,
                'site' => $this->site,
            ],
        );

        if (auth()->user() !== null) {
            $viewData['linkedSourceControlAccounts'] = app(SourceControlRepositoryBrowser::class)
                ->accountsForUser(auth()->user());
        }

        return view('livewire.sites.edge.workspace.deploy-triggers', $viewData);
    }
}
