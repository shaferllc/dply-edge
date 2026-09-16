<?php

declare(strict_types=1);

namespace App\Modules\SourceControl\Services;

use App\Models\Site;
use App\Models\User;
use App\Modules\SourceControl\Contracts\GitIdentity;
use App\Modules\SourceControl\Services\Concerns\ParsesGitRemotes;
use App\Modules\SourceControl\Services\Concerns\ReadsBitbucketRepositories;
use App\Modules\SourceControl\Services\Concerns\ReadsGitHubRepositories;
use App\Modules\SourceControl\Services\Concerns\ReadsGitLabRepositories;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Read-only browser for a connected git repo across GitHub / GitLab /
 * Bitbucket — lists branches, walks directory trees, reads file blobs,
 * fetches the README and renders it.
 *
 * Same dispatch shape as {@see SiteGitCommitsFetcher}: parse the remote URL,
 * resolve the viewer's best {@see GitIdentity} for that provider (OAuth or
 * PAT, via {@see GitIdentityResolver}), hit the right REST endpoint using
 * the identity's API base. Each method's return value is cached for
 * {@see self::CACHE_TTL_SECONDS} keyed by site + branch + path so the
 * Repository page doesn't re-fetch every render. Mutating actions (branch
 * switch, repo switch) must call {@see self::invalidate()}.
 */
final class SourceControlRepositoryReader
{
    use ParsesGitRemotes;
    use ReadsBitbucketRepositories;
    use ReadsGitHubRepositories;
    use ReadsGitLabRepositories;

    private const CACHE_TTL_SECONDS = 300;

    /** Files larger than this are surfaced as "too large" so the view can fall back to a provider link. */
    private const MAX_FILE_BYTES = 262144;

    public function __construct(
        private ?GitIdentityResolver $resolver = null,
    ) {
        $this->resolver ??= app(GitIdentityResolver::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function branches(Site $site, User $user): array
    {
        return $this->remember($site, 'branches', '', fn () => $this->branchesUncached($site, $user));
    }

    /**
     * @return array<string, mixed>
     */
    public function tags(Site $site, User $user): array
    {
        return $this->remember($site, 'tags', '', fn () => $this->tagsUncached($site, $user));
    }

    /**
     * @return array<string, mixed>
     */
    public function tree(Site $site, User $user, string $branch, string $path = ''): array
    {
        $branch = $branch !== '' ? $branch : (string) ($site->git_branch ?: 'main');
        $path = trim($path, '/');

        return $this->remember($site, 'tree:'.$branch, $path, fn () => $this->treeUncached($site, $user, $branch, $path));
    }

    /**
     * @return array<string, mixed>
     */
    public function file(Site $site, User $user, string $branch, string $path): array
    {
        $branch = $branch !== '' ? $branch : (string) ($site->git_branch ?: 'main');
        $path = trim($path, '/');

        return $this->remember($site, 'file:'.$branch, $path, fn () => $this->fileUncached($site, $user, $branch, $path));
    }

    /**
     * @return array<string, mixed>
     */
    public function readme(Site $site, User $user, ?string $branch = null): array
    {
        $branch = $branch !== null && $branch !== '' ? $branch : (string) ($site->git_branch ?: 'main');

        return $this->remember($site, 'readme:'.$branch, '', fn () => $this->readmeUncached($site, $user, $branch));
    }

    public function invalidate(Site $site): void
    {
        Cache::increment($this->versionKey($site));
    }

    /**
     * Provider id (github / gitlab / bitbucket) detected from the site's
     * configured Git remote — null if no remote is set or the host is not
     * one we know how to browse. Lets UIs gate "browse refs" affordances
     * before making any HTTP calls.
     */
    public function providerForSite(Site $site): ?string
    {
        $remote = $this->remoteForSite($site);

        return is_array($remote) ? ($remote['provider'] ?? null) : null;
    }

    /* ────────────────────── branches ────────────────────── */

    /**
     * @return array<string, mixed>
     */
    private function branchesUncached(Site $site, User $user): array
    {
        $remote = $this->remoteForSite($site);
        if ($remote === null) {
            return ['ok' => false, 'branches' => [], 'error' => __('Add a Git repository URL first.'), 'provider' => null];
        }

        return match ($remote['provider']) {
            'github' => $this->githubBranches($remote, $site, $user),
            'gitlab' => $this->gitlabBranches($remote, $site, $user),
            'bitbucket' => $this->bitbucketBranches($remote, $site, $user),
            default => ['ok' => false, 'branches' => [], 'error' => __('Unsupported Git host.'), 'provider' => $remote['provider']],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function tagsUncached(Site $site, User $user): array
    {
        $remote = $this->remoteForSite($site);
        if ($remote === null) {
            return ['ok' => false, 'tags' => [], 'error' => __('Add a Git repository URL first.'), 'provider' => null];
        }

        return match ($remote['provider']) {
            'github' => $this->githubTags($remote, $site, $user),
            'gitlab' => $this->gitlabTags($remote, $site, $user),
            'bitbucket' => $this->bitbucketTags($remote, $site, $user),
            default => ['ok' => false, 'tags' => [], 'error' => __('Unsupported Git host.'), 'provider' => $remote['provider']],
        };
    }

    /* ────────────────────── tree ────────────────────── */

    /**
     * @return array<string, mixed>
     */
    private function treeUncached(Site $site, User $user, string $branch, string $path): array
    {
        $remote = $this->remoteForSite($site);
        if ($remote === null) {
            return ['ok' => false, 'entries' => [], 'error' => __('Add a Git repository URL first.'), 'provider' => null, 'path' => $path, 'branch' => $branch];
        }

        $result = match ($remote['provider']) {
            'github' => $this->githubTree($remote, $site, $user, $branch, $path),
            'gitlab' => $this->gitlabTree($remote, $site, $user, $branch, $path),
            'bitbucket' => $this->bitbucketTree($remote, $site, $user, $branch, $path),
            default => ['ok' => false, 'entries' => [], 'error' => __('Unsupported Git host.'), 'provider' => $remote['provider']],
        };

        return $result + ['path' => $path, 'branch' => $branch];
    }

    /* ────────────────────── file ────────────────────── */

    /**
     * @return array<string, mixed>
     */
    private function fileUncached(Site $site, User $user, string $branch, string $path): array
    {
        $remote = $this->remoteForSite($site);
        if ($remote === null) {
            return ['ok' => false, 'content' => '', 'size' => 0, 'too_large' => false, 'binary' => false, 'html_url' => null, 'error' => __('Add a Git repository URL first.'), 'provider' => null, 'path' => $path, 'branch' => $branch];
        }
        if ($path === '') {
            return ['ok' => false, 'content' => '', 'size' => 0, 'too_large' => false, 'binary' => false, 'html_url' => null, 'error' => __('Empty file path.'), 'provider' => $remote['provider'], 'path' => $path, 'branch' => $branch];
        }

        $result = match ($remote['provider']) {
            'github' => $this->githubFile($remote, $site, $user, $branch, $path),
            'gitlab' => $this->gitlabFile($remote, $site, $user, $branch, $path),
            'bitbucket' => $this->bitbucketFile($remote, $site, $user, $branch, $path),
            default => ['ok' => false, 'content' => '', 'size' => 0, 'too_large' => false, 'binary' => false, 'html_url' => null, 'error' => __('Unsupported Git host.'), 'provider' => $remote['provider']],
        };

        return $result + ['path' => $path, 'branch' => $branch];
    }

    /* ────────────────────── readme ────────────────────── */

    /**
     * @return array<string, mixed>
     */
    private function readmeUncached(Site $site, User $user, string $branch): array
    {
        $remote = $this->remoteForSite($site);
        if ($remote === null) {
            return ['ok' => false, 'name' => null, 'content_html' => '', 'content_raw' => '', 'error' => __('Add a Git repository URL first.'), 'provider' => null, 'branch' => $branch];
        }

        $result = match ($remote['provider']) {
            'github' => $this->githubReadme($remote, $site, $user, $branch),
            'gitlab' => $this->probeReadmeViaFile($remote, $site, $user, $branch, 'gitlab'),
            'bitbucket' => $this->probeReadmeViaFile($remote, $site, $user, $branch, 'bitbucket'),
            default => ['ok' => false, 'name' => null, 'content_html' => '', 'content_raw' => '', 'error' => __('Unsupported Git host.'), 'provider' => $remote['provider']],
        };

        return $result + ['branch' => $branch];
    }

    /* ────────────────────── helpers ────────────────────── */

    /* ────────────────────── cache ────────────────────── */

    private function remember(Site $site, string $method, string $path, callable $resolver)
    {
        $version = (int) Cache::get($this->versionKey($site), 0);
        $key = 'repo:reader:'.$site->id.':v'.$version.':'.md5($method.'|'.$path);

        return Cache::remember($key, self::CACHE_TTL_SECONDS, $resolver);
    }

    private function versionKey(Site $site): string
    {
        return 'repo:reader:v:'.$site->id;
    }
}
