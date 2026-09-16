<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Edge;

use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Models\Site;
use App\Models\User;
use App\Modules\Edge\Actions\DeployEdgeCommit;
use App\Modules\SourceControl\Services\GitIdentityResolver;
use App\Modules\SourceControl\Services\SiteGitCommitsFetcher;
use App\Modules\SourceControl\Services\SourceControlRepositoryReader;
use Illuminate\Support\Str;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * @phpstan-require-extends Component
 *
 * @property Site $site
 */
trait ManagesEdgeDeployCommit
{
    use DispatchesToastNotifications;

    public string $edge_deploy_commit_sha = '';

    public ?string $edge_deploy_commit_branch = null;

    public ?string $edge_deploy_commit_ref_kind = null;

    public bool $edge_deploy_ref_picker_open = false;

    public string $edge_deploy_ref_tab = 'commits';

    public string $edge_deploy_ref_search = '';

    public string $edge_deploy_ref_branch = '';

    /** @var list<array<string, mixed>> */
    public array $edge_deploy_ref_results = [];

    public ?string $edge_deploy_ref_error = null;

    public ?string $edge_deploy_ref_needs_provider = null;

    public function deployEdgeCommit(): void
    {
        if (! $this->site->usesEdgeRuntime()) {
            return;
        }
        $this->authorize('update', $this->site);

        $sha = trim($this->edge_deploy_commit_sha);
        if ($sha === '') {
            $this->toastError(__('Enter a commit SHA to deploy.'));

            return;
        }

        try {
            (new DeployEdgeCommit)->handle($this->site, $sha, $this->edge_deploy_commit_branch);
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());

            return;
        }

        $this->edge_deploy_commit_sha = '';
        $this->edge_deploy_commit_branch = null;

        $this->toastSuccess(__('Deploy started for that commit.'));
    }

    public function openEdgeDeployRefPicker(): void
    {
        if (! $this->site->usesEdgeRuntime()) {
            return;
        }
        $this->authorize('update', $this->site);

        $source = is_array($this->site->edgeMeta()['source'] ?? null) ? $this->site->edgeMeta()['source'] : [];
        $this->edge_deploy_ref_branch = (string) ($source['branch'] ?? 'main');
        $this->edge_deploy_ref_picker_open = true;
        $this->refreshEdgeDeployRefs();
    }

    public function closeEdgeDeployRefPicker(): void
    {
        $this->edge_deploy_ref_picker_open = false;
    }

    public function setEdgeDeployRefTab(string $tab): void
    {
        if (! in_array($tab, ['commits', 'branches', 'tags'], true)) {
            return;
        }

        $this->edge_deploy_ref_tab = $tab;
        $this->refreshEdgeDeployRefs();
    }

    public function updatedEdgeDeployRefSearch(): void
    {
        if ($this->edge_deploy_ref_picker_open) {
            $this->refreshEdgeDeployRefs();
        }
    }

    public function updatedEdgeDeployRefBranch(): void
    {
        if ($this->edge_deploy_ref_picker_open && $this->edge_deploy_ref_tab === 'commits') {
            $this->refreshEdgeDeployRefs();
        }
    }

    public function selectEdgeDeployRef(string $sha): void
    {
        $sha = strtolower(trim($sha));
        if (preg_match('/^[a-f0-9]{7,40}$/', $sha) !== 1) {
            return;
        }

        $this->edge_deploy_commit_sha = $sha;

        $branch = null;
        $kind = null;
        if ($this->edge_deploy_ref_tab === 'commits') {
            $branch = trim($this->edge_deploy_ref_branch) !== ''
                ? trim($this->edge_deploy_ref_branch)
                : null;
            $kind = $branch !== null ? 'commit' : null;
        } elseif ($this->edge_deploy_ref_tab === 'branches') {
            foreach ($this->edge_deploy_ref_results as $ref) {
                if (strtolower(trim((string) ($ref['sha'] ?? ''))) === $sha) {
                    $label = trim((string) ($ref['label'] ?? ''));
                    $branch = $label !== '' ? $label : null;
                    $kind = $branch !== null ? 'branch' : null;
                    break;
                }
            }
        } elseif ($this->edge_deploy_ref_tab === 'tags') {
            foreach ($this->edge_deploy_ref_results as $ref) {
                if (strtolower(trim((string) ($ref['sha'] ?? ''))) === $sha) {
                    $label = trim((string) ($ref['label'] ?? ''));
                    $branch = $label !== '' ? $label : null;
                    $kind = $branch !== null ? 'tag' : null;
                    break;
                }
            }
        }

        $this->edge_deploy_commit_branch = $branch;
        $this->edge_deploy_commit_ref_kind = $kind;
        $this->closeEdgeDeployRefPicker();
    }

    public function updatedEdgeDeployCommitSha(): void
    {
        $this->edge_deploy_commit_branch = null;
        $this->edge_deploy_commit_ref_kind = null;
    }

    public function edgeDeployRefMissingProvider(): ?string
    {
        $user = auth()->user();
        if ($user === null) {
            return null;
        }

        $provider = app(SourceControlRepositoryReader::class)->providerForSite($this->site);
        if ($provider === null || ! in_array($provider, ['github', 'gitlab', 'bitbucket'], true)) {
            return null;
        }

        return app(GitIdentityResolver::class)->forUserProvider($user, $provider) === null
            ? $provider
            : null;
    }

    #[On('source-control-linked')]
    public function onEdgeSourceControlLinked(): void
    {
        if ($this->edge_deploy_ref_picker_open) {
            $this->refreshEdgeDeployRefs();
        }
    }

    private function refreshEdgeDeployRefs(): void
    {
        $user = auth()->user();
        if ($user === null) {
            $this->edge_deploy_ref_results = [];
            $this->edge_deploy_ref_error = __('Sign in to browse repository refs.');
            $this->edge_deploy_ref_needs_provider = null;

            return;
        }

        $search = mb_strtolower(trim($this->edge_deploy_ref_search));

        if ($this->edge_deploy_ref_tab === 'branches') {
            $result = app(SourceControlRepositoryReader::class)->branches($this->site, $user);
            if (! ($result['ok'] ?? false)) {
                $this->edge_deploy_ref_results = [];
                $this->edge_deploy_ref_error = (string) ($result['error'] ?? __('Could not load branches.'));
                $this->edge_deploy_ref_needs_provider = $this->detectEdgeDeployRefProviderGap($user, $result);

                return;
            }

            $this->edge_deploy_ref_error = null;
            $this->edge_deploy_ref_needs_provider = null;
            $this->edge_deploy_ref_results = $this->filterEdgeDeployRefs(
                collect($result['branches'] ?? [])
                    ->map(fn (array $branch): array => [
                        'kind' => 'branch',
                        'label' => (string) ($branch['name'] ?? ''),
                        'sha' => (string) ($branch['sha'] ?? ''),
                        'meta' => ($branch['is_default'] ?? false) ? __('Default branch') : null,
                    ])
                    ->all(),
                $search,
                ['label'],
            );

            return;
        }

        if ($this->edge_deploy_ref_tab === 'tags') {
            $result = app(SourceControlRepositoryReader::class)->tags($this->site, $user);
            if (! ($result['ok'] ?? false)) {
                $this->edge_deploy_ref_results = [];
                $this->edge_deploy_ref_error = (string) ($result['error'] ?? __('Could not load tags.'));
                $this->edge_deploy_ref_needs_provider = $this->detectEdgeDeployRefProviderGap($user, $result);

                return;
            }

            $this->edge_deploy_ref_error = null;
            $this->edge_deploy_ref_needs_provider = null;
            $this->edge_deploy_ref_results = $this->filterEdgeDeployRefs(
                collect($result['tags'] ?? [])
                    ->map(fn (array $tag): array => [
                        'kind' => 'tag',
                        'label' => (string) ($tag['name'] ?? ''),
                        'sha' => (string) ($tag['sha'] ?? ''),
                        'meta' => null,
                    ])
                    ->all(),
                $search,
                ['label'],
            );

            return;
        }

        $branch = trim($this->edge_deploy_ref_branch) !== '' ? trim($this->edge_deploy_ref_branch) : null;
        $result = app(SiteGitCommitsFetcher::class)->fetch($this->site, $user, 40, $branch);
        if (! $result['ok']) {
            $this->edge_deploy_ref_results = [];
            $this->edge_deploy_ref_error = (string) $result['error'];
            $this->edge_deploy_ref_needs_provider = $this->detectEdgeDeployRefProviderGap($user, $result);

            return;
        }

        $this->edge_deploy_ref_error = null;
        $this->edge_deploy_ref_needs_provider = null;
        $this->edge_deploy_ref_results = $this->filterEdgeDeployRefs(
            collect($result['commits'])
                ->map(fn (array $commit): array => [
                    'kind' => 'commit',
                    'label' => (string) $commit['short_sha'],
                    'sha' => (string) $commit['sha'],
                    'meta' => Str::limit((string) $commit['message'], 72),
                ])
                ->all(),
            $search,
            ['label', 'sha', 'meta'],
        );
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function detectEdgeDeployRefProviderGap(User $user, array $result): ?string
    {
        $provider = (string) ($result['provider'] ?? '');
        if ($provider === '' || ! in_array($provider, ['github', 'gitlab', 'bitbucket'], true)) {
            return null;
        }

        return app(GitIdentityResolver::class)->forUserProvider($user, $provider) === null
            ? $provider
            : null;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $fields
     * @return list<array<string, mixed>>
     */
    private function filterEdgeDeployRefs(array $rows, string $search, array $fields): array
    {
        if ($search === '') {
            return array_values(array_filter($rows, fn (array $row): bool => ($row['sha'] ?? '') !== ''));
        }

        return array_values(array_filter($rows, function (array $row) use ($search, $fields): bool {
            if (($row['sha'] ?? '') === '') {
                return false;
            }

            foreach ($fields as $field) {
                $value = mb_strtolower((string) ($row[$field] ?? ''));
                if ($value !== '' && str_contains($value, $search)) {
                    return true;
                }
            }

            return false;
        }));
    }
}
