<?php

declare(strict_types=1);

namespace App\Modules\SourceControl\Services;

use App\Models\Site;
use App\Models\User;
use App\Modules\SourceControl\Contracts\GitIdentity;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Loads recent commits for a site's configured Git remote using the viewer's
 * best {@see GitIdentity} (OAuth account or PAT) for that provider, via
 * {@see GitIdentityResolver}.
 */
final class SiteGitCommitsFetcher
{
    private const MAX_COMMITS = 50;

    /**
     * Short cache window for a commit page. Re-renders (any Livewire round-trip)
     * and tab switches otherwise re-hit the provider every time — and when the
     * configured branch is missing, every render repeats the
     * list→404→default-branch lookup→retry dance (3 calls). The key shares the
     * reader's per-site version (see {@see SourceControlRepositoryReader}), so a
     * single {@see SourceControlRepositoryReader::invalidate()} on deploy /
     * branch switch / "Refresh" drops cached commits too.
     */
    private const CACHE_TTL_SECONDS = 120;

    public function __construct(
        private ?GitIdentityResolver $resolver = null,
    ) {
        $this->resolver ??= app(GitIdentityResolver::class);
    }

    /**
     * @return array{
     *     ok: bool,
     *     commits: list<array{
     *         sha: string,
     *         short_sha: string,
     *         message: string,
     *         author_name: string,
     *         author_email: string|null,
     *         committed_at: string|null,
     *         html_url: string|null,
     *     }>,
     *     error: string|null,
     *     provider: string|null,
     *     branch: string,
     *     remote_label: string|null,
     * }
     */
    public function fetch(Site $site, User $user, int $limit = 30, ?string $branchOverride = null, int $page = 1): array
    {
        $branch = $branchOverride !== null && $branchOverride !== ''
            ? $branchOverride
            : (string) ($site->git_branch ?: 'main');
        $remote = $this->parseRemoteUrl($site->sourceControlRepositoryUrl());
        if ($remote === null) {
            return [
                'ok' => false,
                'commits' => [],
                'error' => __('Add a Git repository URL in Deploy settings to list commits.'),
                'provider' => null,
                'branch' => $branch,
                'remote_label' => null,
                'page' => 1,
                'has_more' => false,
            ];
        }

        $limit = max(1, min(self::MAX_COMMITS, $limit));
        $page = max(1, $page);

        $version = (int) Cache::get('repo:reader:v:'.$site->id, 0);
        $cacheKey = 'repo:commits:'.$site->id.':v'.$version.':'.md5($branch.'|'.$limit.'|'.$page);
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $result = match ($remote['provider']) {
            'github' => $this->fetchGithub($remote, $site, $user, $branch, $limit, $page),
            'gitlab' => $this->fetchGitlab($remote, $site, $user, $branch, $limit, $page),
            'bitbucket' => $this->fetchBitbucket($remote, $site, $user, $branch, $limit, $page),
            default => [
                'ok' => false,
                'commits' => [],
                'error' => __('Unsupported Git host. Use GitHub, GitLab, or Bitbucket for commit browsing.'),
                'provider' => $remote['provider'],
                'branch' => $branch,
                'remote_label' => $remote['label'] ?? null,
                'page' => 1,
                'has_more' => false,
            ],
        };

        // Only cache successful payloads — never an error, so a transient 404/5xx
        // or a token the operator just fixed isn't pinned for the full TTL.
        if ($result['ok'] ?? false) {
            $this->persistResolvedBranchIfStale($site, $branchOverride, $result);
            Cache::put($cacheKey, $result, self::CACHE_TTL_SECONDS);
        }

        return $result;
    }

    /**
     * Self-heal a stale deploy branch. When the configured branch didn't exist
     * and a provider fetcher fell back to the repo's real default (e.g. stored
     * "main" but the repo is "master"), persist that default as the site's
     * deploy branch — so future loads query the right ref directly instead of
     * repeating the list→404→default-branch lookup→retry round-trip.
     *
     * Guarded tightly: only when viewing the deploy branch itself (not a
     * transient ?repo_ref preview), only while the stored branch is exactly the
     * missing one, and only for a never-deployed site — so we never override a
     * ref the operator deliberately set or one a live deployment depends on.
     *
     * @param  array<string, mixed>  $result
     */
    private function persistResolvedBranchIfStale(Site $site, ?string $branchOverride, array $result): void
    {
        if ($branchOverride !== null && $branchOverride !== '') {
            return;
        }

        $requested = (string) ($result['requested_branch'] ?? '');
        $resolved = (string) ($result['branch'] ?? '');
        if ($requested === '' || $resolved === '' || strcasecmp($requested, $resolved) === 0) {
            return;
        }

        if ((string) $site->git_branch !== $requested || $site->last_deploy_at !== null) {
            return;
        }

        $site->forceFill(['git_branch' => $resolved])->save();
    }

    /**
     * @return array<string, mixed>
     */
    private function parseRemoteUrl(?string $url): ?array
    {
        if ($url === null || trim($url) === '') {
            return null;
        }

        $url = trim($url);
        if (str_starts_with($url, 'git@')) {
            $colonPos = strpos($url, ':');
            if ($colonPos === false) {
                return null;
            }
            $host = strtolower(substr($url, 4, $colonPos - 4));
            $path = substr($url, $colonPos + 1);
            $path = preg_replace('/\.git$/', '', (string) $path) ?? '';

            return $this->remoteFromHostAndPath($host, $path);
        }

        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['host'])) {
            return null;
        }

        $host = strtolower((string) $parts['host']);
        $path = trim((string) ($parts['path'] ?? ''), '/');
        $path = preg_replace('/\.git$/', '', $path) ?? '';

        return $this->remoteFromHostAndPath($host, $path);
    }

    /**
     * @return array<string, mixed>
     */
    private function remoteFromHostAndPath(string $host, string $path): ?array
    {
        if ($path === '') {
            return null;
        }

        if ($host === 'github.com' || str_ends_with($host, '.github.com')) {
            $segments = explode('/', $path);
            if (count($segments) < 2) {
                return null;
            }

            return [
                'provider' => 'github',
                'owner' => $segments[0],
                'repo' => $segments[1],
                'label' => $segments[0].'/'.$segments[1],
            ];
        }

        if (str_contains($host, 'bitbucket.org')) {
            $segments = explode('/', $path);
            if (count($segments) < 2) {
                return null;
            }

            return [
                'provider' => 'bitbucket',
                'workspace' => $segments[0],
                'repo' => $segments[1],
                'label' => $segments[0].'/'.$segments[1],
            ];
        }

        if (str_contains($host, 'gitlab')) {
            $apiBase = 'https://'.$host;

            return [
                'provider' => 'gitlab',
                'project_path' => $path,
                'gitlab_api_base' => $apiBase,
                'label' => $path,
            ];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $remote
     * @return array<string, mixed>
     */
    private function fetchGithub(array $remote, Site $site, User $user, string $branch, int $limit, int $page = 1): array
    {
        $identity = $this->resolver->forSite($site, $user, 'github');
        if ($identity === null) {
            return [
                'ok' => false,
                'commits' => [],
                'error' => __('Link a GitHub account or add a personal access token under Profile → Source control to browse commits.'),
                'provider' => 'github',
                'branch' => $branch,
                'remote_label' => $remote['label'],
            ];
        }

        $call = fn (string $ref) => Http::withHeaders([
            'User-Agent' => 'Dply (git-commits)',
            'Accept' => 'application/vnd.github+json',
        ])
            ->withToken($identity->accessToken())
            ->acceptJson()
            ->get($identity->apiBaseUrl().'/repos/'.$remote['owner'].'/'.$remote['repo'].'/commits', [
                'sha' => $ref,
                'per_page' => $limit,
                'page' => $page,
            ]);

        $effectiveBranch = $branch;
        $notice = null;
        $response = $call($branch);

        // A 404 on list-commits almost always means the configured branch doesn't
        // exist (e.g. the repo's default is "master" but we stored "main"). Don't
        // hard-fail: fall back to the repo's real default branch, show its commits,
        // and tell the operator the branch was missing so they can pick another via
        // the ref picker.
        if (! $response->successful() && $response->status() === 404) {
            $default = $this->githubDefaultBranch($identity, $remote);
            if ($default !== null && strcasecmp($default, $branch) !== 0) {
                $retry = $call($default);
                if ($retry->successful()) {
                    $response = $retry;
                    $effectiveBranch = $default;
                    $notice = $this->branchFallbackNotice($branch, $default);
                }
            }
        }

        if (! $response->successful()) {
            return [
                'ok' => false,
                'commits' => [],
                'error' => $this->formatApiError($response->status(), $response->body()),
                'provider' => 'github',
                'branch' => $effectiveBranch,
                'remote_label' => $remote['label'],
                'account' => $this->accountInfo($identity),
            ];
        }

        $rows = $response->json();
        if (! is_array($rows)) {
            return [
                'ok' => false,
                'commits' => [],
                'error' => __('Unexpected response from GitHub.'),
                'provider' => 'github',
                'branch' => $branch,
                'remote_label' => $remote['label'],
                'account' => $this->accountInfo($identity),
            ];
        }

        $commits = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $sha = (string) ($row['sha'] ?? '');
            if ($sha === '') {
                continue;
            }
            $commit = is_array($row['commit'] ?? null) ? $row['commit'] : [];
            $author = is_array($commit['author'] ?? null) ? $commit['author'] : [];
            $message = (string) ($commit['message'] ?? '');
            $firstLine = Str::of($message)->explode("\n")->first() ?? $message;

            $commits[] = [
                'sha' => $sha,
                'short_sha' => substr($sha, 0, 7),
                'message' => $firstLine !== '' ? $firstLine : __('(no message)'),
                'author_name' => (string) ($author['name'] ?? __('Unknown')),
                'author_email' => isset($author['email']) ? (string) $author['email'] : null,
                'committed_at' => isset($author['date']) ? (string) $author['date'] : null,
                'html_url' => isset($row['html_url']) ? (string) $row['html_url'] : null,
            ];
        }

        return [
            'ok' => true,
            'commits' => $commits,
            'error' => null,
            'notice' => $notice,
            'provider' => 'github',
            'branch' => $effectiveBranch,
            'requested_branch' => $branch,
            'remote_label' => $remote['label'],
            'page' => $page,
            'has_more' => count($commits) >= $limit,
            'account' => $this->accountInfo($identity),
        ];
    }

    /**
     * The repo's default branch (e.g. "master") via GET /repos/{owner}/{repo}.
     * Used to recover from a list-commits 404 when the configured branch is
     * stale/missing. Returns null when the repo can't be read.
     *
     * @param  array<string, mixed>  $remote
     */
    private function githubDefaultBranch(GitIdentity $identity, array $remote): ?string
    {
        try {
            $r = Http::withHeaders([
                'User-Agent' => 'Dply (git-commits)',
                'Accept' => 'application/vnd.github+json',
            ])
                ->withToken($identity->accessToken())
                ->acceptJson()
                ->get($identity->apiBaseUrl().'/repos/'.$remote['owner'].'/'.$remote['repo']);

            if ($r->successful()) {
                $default = $r->json('default_branch');

                return is_string($default) && $default !== '' ? $default : null;
            }
        } catch (\Throwable) {
            // fall through — caller keeps the original 404
        }

        return null;
    }

    /** Shared "we fell back to the repo's default branch" notice (all providers). */
    private function branchFallbackNotice(string $requested, string $actual): string
    {
        return __('Branch ":requested" wasn\'t found in this repository — showing the default branch ":actual" instead. Use "Change…" to pick another branch, tag, or commit.', [
            'requested' => $requested,
            'actual' => $actual,
        ]);
    }

    /**
     * @param  array<string, mixed>  $remote
     * @return array<string, mixed>
     */
    private function fetchGitlab(array $remote, Site $site, User $user, string $branch, int $limit, int $page = 1): array
    {
        $identity = $this->resolver->forSite($site, $user, 'gitlab');
        if ($identity === null) {
            return [
                'ok' => false,
                'commits' => [],
                'error' => __('Link a GitLab account or add a personal access token under Profile → Source control to browse commits.'),
                'provider' => 'gitlab',
                'branch' => $branch,
                'remote_label' => $remote['label'],
            ];
        }

        $encoded = rawurlencode($remote['project_path']);
        $apiBase = $this->gitlabApiBase($identity, $remote);
        $url = $apiBase.'/api/v4/projects/'.$encoded.'/repository/commits';

        $call = fn (string $ref) => Http::withToken($identity->accessToken())
            ->acceptJson()
            ->get($url, [
                'ref_name' => $ref,
                'per_page' => $limit,
                'page' => $page,
            ]);

        $effectiveBranch = $branch;
        $notice = null;
        $response = $call($branch);

        // A 404 on list-commits means the configured ref doesn't exist (e.g. we
        // stored "main" but the project's default is "master"). Fall back to the
        // project's real default branch instead of hard-failing.
        if (! $response->successful() && $response->status() === 404) {
            $default = $this->gitlabDefaultBranch($identity, $apiBase, $encoded);
            if ($default !== null && strcasecmp($default, $branch) !== 0) {
                $retry = $call($default);
                if ($retry->successful()) {
                    $response = $retry;
                    $effectiveBranch = $default;
                    $notice = $this->branchFallbackNotice($branch, $default);
                }
            }
        }

        if (! $response->successful()) {
            return [
                'ok' => false,
                'commits' => [],
                'error' => $this->formatApiError($response->status(), $response->body()),
                'provider' => 'gitlab',
                'branch' => $effectiveBranch,
                'remote_label' => $remote['label'],
                'account' => $this->accountInfo($identity),
            ];
        }

        $rows = $response->json();
        if (! is_array($rows)) {
            return [
                'ok' => false,
                'commits' => [],
                'error' => __('Unexpected response from GitLab.'),
                'provider' => 'gitlab',
                'branch' => $branch,
                'remote_label' => $remote['label'],
                'account' => $this->accountInfo($identity),
            ];
        }

        $commits = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $id = (string) ($row['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $message = (string) ($row['title'] ?? $row['message'] ?? '');
            $firstLine = Str::of($message)->explode("\n")->first() ?? $message;

            $commits[] = [
                'sha' => $id,
                'short_sha' => (string) ($row['short_id'] ?? substr($id, 0, 7)),
                'message' => $firstLine !== '' ? $firstLine : __('(no message)'),
                'author_name' => (string) ($row['author_name'] ?? __('Unknown')),
                'author_email' => isset($row['author_email']) ? (string) $row['author_email'] : null,
                'committed_at' => isset($row['created_at']) ? (string) $row['created_at'] : null,
                'html_url' => isset($row['web_url']) ? (string) $row['web_url'] : null,
            ];
        }

        return [
            'ok' => true,
            'commits' => $commits,
            'error' => null,
            'notice' => $notice,
            'provider' => 'gitlab',
            'branch' => $effectiveBranch,
            'requested_branch' => $branch,
            'remote_label' => $remote['label'],
            'page' => $page,
            'has_more' => count($commits) >= $limit,
            'account' => $this->accountInfo($identity),
        ];
    }

    /**
     * The project's default branch via GET /projects/{id}. Recovers from a
     * list-commits 404 when the configured branch is stale/missing.
     */
    private function gitlabDefaultBranch(GitIdentity $identity, string $apiBase, string $encodedProject): ?string
    {
        try {
            $r = Http::withToken($identity->accessToken())
                ->acceptJson()
                ->get($apiBase.'/api/v4/projects/'.$encodedProject);

            if ($r->successful()) {
                $default = $r->json('default_branch');

                return is_string($default) && $default !== '' ? $default : null;
            }
        } catch (\Throwable) {
            // fall through — caller keeps the original 404
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $remote
     * @return array<string, mixed>
     */
    private function fetchBitbucket(array $remote, Site $site, User $user, string $branch, int $limit, int $page = 1): array
    {
        $identity = $this->resolver->forSite($site, $user, 'bitbucket');
        if ($identity === null) {
            return [
                'ok' => false,
                'commits' => [],
                'error' => __('Link a Bitbucket account or add a personal access token under Profile → Source control to browse commits.'),
                'provider' => 'bitbucket',
                'branch' => $branch,
                'remote_label' => $remote['label'],
            ];
        }

        $repoBase = $identity->apiBaseUrl().'/2.0/repositories/'.$remote['workspace'].'/'.$remote['repo'];

        // Bitbucket carries the ref in the URL path (.../commits/{ref}).
        $call = fn (string $ref) => Http::withToken($identity->accessToken())
            ->acceptJson()
            ->get($repoBase.'/commits/'.rawurlencode($ref), ['pagelen' => $limit, 'page' => $page]);

        $effectiveBranch = $branch;
        $notice = null;
        $response = $call($branch);

        // A 404 means the configured ref doesn't exist (e.g. stored "main" but
        // the repo's main branch is "master"). Fall back to the repo's real
        // default branch instead of hard-failing.
        if (! $response->successful() && $response->status() === 404) {
            $default = $this->bitbucketDefaultBranch($identity, $repoBase);
            if ($default !== null && strcasecmp($default, $branch) !== 0) {
                $retry = $call($default);
                if ($retry->successful()) {
                    $response = $retry;
                    $effectiveBranch = $default;
                    $notice = $this->branchFallbackNotice($branch, $default);
                }
            }
        }

        if (! $response->successful()) {
            return [
                'ok' => false,
                'commits' => [],
                'error' => $this->formatApiError($response->status(), $response->body()),
                'provider' => 'bitbucket',
                'branch' => $effectiveBranch,
                'remote_label' => $remote['label'],
                'account' => $this->accountInfo($identity),
            ];
        }

        $payload = $response->json();
        $rows = is_array($payload['values'] ?? null) ? $payload['values'] : [];
        // Bitbucket returns a `next` URL when more pages exist — authoritative.
        $hasMore = isset($payload['next']) && (string) $payload['next'] !== '';

        $commits = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $hash = (string) ($row['hash'] ?? '');
            if ($hash === '') {
                continue;
            }
            $message = (string) ($row['message'] ?? '');
            $firstLine = Str::of($message)->explode("\n")->first() ?? $message;
            $author = is_array($row['author'] ?? null) ? $row['author'] : [];
            $userInfo = is_array($author['user'] ?? null) ? $author['user'] : [];
            $html = is_array($row['links']['html'] ?? null) ? $row['links']['html'] : [];
            $htmlHref = isset($html['href']) ? (string) $html['href'] : null;

            $commits[] = [
                'sha' => $hash,
                'short_sha' => substr($hash, 0, 7),
                'message' => $firstLine !== '' ? $firstLine : __('(no message)'),
                'author_name' => (string) ($userInfo['display_name'] ?? $userInfo['username'] ?? __('Unknown')),
                'author_email' => null,
                'committed_at' => isset($row['date']) ? (string) $row['date'] : null,
                'html_url' => $htmlHref,
            ];
        }

        return [
            'ok' => true,
            'commits' => $commits,
            'error' => null,
            'notice' => $notice,
            'provider' => 'bitbucket',
            'branch' => $effectiveBranch,
            'requested_branch' => $branch,
            'remote_label' => $remote['label'],
            'page' => $page,
            'has_more' => $hasMore,
            'account' => $this->accountInfo($identity),
        ];
    }

    /**
     * The repo's default branch via GET /2.0/repositories/{ws}/{repo}
     * (`mainbranch.name`). Recovers from a list-commits 404 when the configured
     * branch is stale/missing.
     */
    private function bitbucketDefaultBranch(GitIdentity $identity, string $repoBase): ?string
    {
        try {
            $r = Http::withToken($identity->accessToken())
                ->acceptJson()
                ->get($repoBase);

            if ($r->successful()) {
                $default = $r->json('mainbranch.name');

                return is_string($default) && $default !== '' ? $default : null;
            }
        } catch (\Throwable) {
            // fall through — caller keeps the original 404
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $remote
     */
    private function gitlabApiBase(GitIdentity $identity, array $remote): string
    {
        $base = $identity->apiBaseUrl();
        if ($base !== '' && $base !== 'https://gitlab.com') {
            return rtrim($base, '/');
        }

        $fromRemote = (string) ($remote['gitlab_api_base'] ?? '');

        return $fromRemote !== '' ? rtrim($fromRemote, '/') : rtrim($base, '/');
    }

    /**
     * Which identity a read was performed with — surfaced in the UI so an
     * operator can see exactly which linked account/token answered (or 404'd),
     * instead of guessing when several are linked.
     *
     * @return array{label: string, id: string, kind: string}
     */
    private function accountInfo(GitIdentity $identity): array
    {
        return [
            'label' => $identity->displayLabel(),
            'id' => $identity->id(),
            'kind' => $identity->kind(),
        ];
    }

    private function formatApiError(int $status, string $body): string
    {
        $snippet = Str::limit(trim($body), 200);

        return __('Git provider returned :status.', ['status' => (string) $status]).($snippet !== '' ? ' '.$snippet : '');
    }
}
