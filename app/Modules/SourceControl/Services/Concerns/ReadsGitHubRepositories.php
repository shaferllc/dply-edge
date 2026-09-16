<?php

declare(strict_types=1);

namespace App\Modules\SourceControl\Services\Concerns;

use App\Models\Site;
use App\Models\User;
use App\Modules\SourceControl\Contracts\GitIdentity;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * GitHub REST calls for {@see SourceControlRepositoryReader}.
 * Split out of that class (was >1000 lines); dispatch and caching stay there.
 */
trait ReadsGitHubRepositories
{
    /**
     * @param  array<string, mixed>  $remote
     * @return array<string, mixed>
     */
    private function githubBranches(array $remote, Site $site, User $user): array
    {
        $identity = $this->resolver->forSite($site, $user, 'github');
        if ($identity === null) {
            return ['ok' => false, 'branches' => [], 'error' => __('Link a GitHub account or add a personal access token to browse this repo.'), 'provider' => 'github'];
        }

        $repoMeta = $this->githubRepoMeta($remote, $identity);
        $defaultBranch = $repoMeta['default_branch'] ?? null;

        $response = $this->githubClient($identity)->get(
            $identity->apiBaseUrl().'/repos/'.$remote['owner'].'/'.$remote['repo'].'/branches',
            ['per_page' => 100],
        );
        if (! $response->successful()) {
            return ['ok' => false, 'branches' => [], 'error' => $this->formatApiError($response->status(), $response->body()), 'provider' => 'github'];
        }
        $rows = is_array($response->json()) ? $response->json() : [];

        $branches = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $name = (string) ($row['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $commit = is_array($row['commit'] ?? null) ? $row['commit'] : [];
            $branches[] = [
                'name' => $name,
                'sha' => (string) ($commit['sha'] ?? ''),
                'committed_at' => null,
                'committer' => null,
                'is_default' => $defaultBranch !== null && $name === $defaultBranch,
            ];
        }

        return ['ok' => true, 'branches' => $branches, 'error' => null, 'provider' => 'github'];
    }

    /**
     * @param  array<string, mixed>  $remote
     * @return array<string, mixed>
     */
    private function githubTags(array $remote, Site $site, User $user): array
    {
        $identity = $this->resolver->forSite($site, $user, 'github');
        if ($identity === null) {
            return ['ok' => false, 'tags' => [], 'error' => __('Link a GitHub account or add a personal access token to browse this repo.'), 'provider' => 'github'];
        }

        $response = $this->githubClient($identity)->get(
            $identity->apiBaseUrl().'/repos/'.$remote['owner'].'/'.$remote['repo'].'/tags',
            ['per_page' => 100],
        );
        if (! $response->successful()) {
            return ['ok' => false, 'tags' => [], 'error' => $this->formatApiError($response->status(), $response->body()), 'provider' => 'github'];
        }

        $tags = [];
        foreach (is_array($response->json()) ? $response->json() : [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $name = (string) ($row['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $commit = is_array($row['commit'] ?? null) ? $row['commit'] : [];
            $tags[] = [
                'name' => $name,
                'sha' => (string) ($commit['sha'] ?? ''),
                'committed_at' => null,
            ];
        }

        return ['ok' => true, 'tags' => $tags, 'error' => null, 'provider' => 'github'];
    }

    /**
     * @param  array<string, mixed>  $remote
     * @return array<string, mixed>
     */
    private function githubTree(array $remote, Site $site, User $user, string $branch, string $path): array
    {
        $identity = $this->resolver->forSite($site, $user, 'github');
        if ($identity === null) {
            return ['ok' => false, 'entries' => [], 'error' => __('Link a GitHub account or add a personal access token.'), 'provider' => 'github'];
        }

        $url = $identity->apiBaseUrl().'/repos/'.$remote['owner'].'/'.$remote['repo'].'/contents/'.$this->encodePath($path);
        $response = $this->githubClient($identity)->get($url, ['ref' => $branch]);
        if (! $response->successful()) {
            return ['ok' => false, 'entries' => [], 'error' => $this->formatApiError($response->status(), $response->body()), 'provider' => 'github'];
        }
        $rows = $response->json();
        if (! is_array($rows) || array_keys($rows) !== range(0, count($rows) - 1)) {
            return ['ok' => false, 'entries' => [], 'error' => __('This path is a file, not a directory.'), 'provider' => 'github'];
        }

        $entries = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $type = ((string) ($row['type'] ?? 'file')) === 'dir' ? 'dir' : 'file';
            $entries[] = [
                'name' => (string) ($row['name'] ?? ''),
                'path' => (string) ($row['path'] ?? ''),
                'type' => $type,
                'size' => (int) ($row['size'] ?? 0),
                'sha' => isset($row['sha']) ? (string) $row['sha'] : null,
            ];
        }

        return ['ok' => true, 'entries' => $this->sortEntries($entries), 'error' => null, 'provider' => 'github'];
    }

    /**
     * @param  array<string, mixed>  $remote
     * @return array<string, mixed>
     */
    private function githubFile(array $remote, Site $site, User $user, string $branch, string $path): array
    {
        $identity = $this->resolver->forSite($site, $user, 'github');
        if ($identity === null) {
            return ['ok' => false, 'content' => '', 'size' => 0, 'too_large' => false, 'binary' => false, 'html_url' => null, 'error' => __('Link a GitHub account or add a personal access token.'), 'provider' => 'github'];
        }

        $url = $identity->apiBaseUrl().'/repos/'.$remote['owner'].'/'.$remote['repo'].'/contents/'.$this->encodePath($path);
        $htmlUrl = 'https://github.com/'.$remote['owner'].'/'.$remote['repo'].'/blob/'.rawurlencode($branch).'/'.$this->encodePath($path);
        $response = $this->githubClient($identity)->get($url, ['ref' => $branch]);
        if (! $response->successful()) {
            return ['ok' => false, 'content' => '', 'size' => 0, 'too_large' => false, 'binary' => false, 'html_url' => $htmlUrl, 'error' => $this->formatApiError($response->status(), $response->body()), 'provider' => 'github'];
        }
        $row = $response->json();
        if (! is_array($row) || array_keys($row) === range(0, count($row) - 1)) {
            return ['ok' => false, 'content' => '', 'size' => 0, 'too_large' => false, 'binary' => false, 'html_url' => $htmlUrl, 'error' => __('Path is a directory, not a file.'), 'provider' => 'github'];
        }

        $size = (int) ($row['size'] ?? 0);
        if ($size > self::MAX_FILE_BYTES) {
            return ['ok' => true, 'content' => '', 'size' => $size, 'too_large' => true, 'binary' => false, 'html_url' => $htmlUrl, 'error' => null, 'provider' => 'github'];
        }
        $raw = base64_decode((string) ($row['content'] ?? ''), true);
        if ($raw === false) {
            return ['ok' => false, 'content' => '', 'size' => $size, 'too_large' => false, 'binary' => true, 'html_url' => $htmlUrl, 'error' => __('Could not decode file contents.'), 'provider' => 'github'];
        }

        return $this->buildFileResult($raw, $size, $htmlUrl, 'github');
    }

    /**
     * @param  array<string, mixed>  $remote
     * @return array<string, mixed>
     */
    private function githubReadme(array $remote, Site $site, User $user, string $branch): array
    {
        $identity = $this->resolver->forSite($site, $user, 'github');
        if ($identity === null) {
            return ['ok' => false, 'name' => null, 'content_html' => '', 'content_raw' => '', 'error' => __('Link a GitHub account or add a personal access token.'), 'provider' => 'github'];
        }

        $url = $identity->apiBaseUrl().'/repos/'.$remote['owner'].'/'.$remote['repo'].'/readme';
        $response = $this->githubClient($identity)->get($url, ['ref' => $branch]);
        if ($response->status() === 404) {
            return ['ok' => true, 'name' => null, 'content_html' => '', 'content_raw' => '', 'error' => null, 'provider' => 'github'];
        }
        if (! $response->successful()) {
            return ['ok' => false, 'name' => null, 'content_html' => '', 'content_raw' => '', 'error' => $this->formatApiError($response->status(), $response->body()), 'provider' => 'github'];
        }
        $row = is_array($response->json()) ? $response->json() : [];
        $raw = base64_decode((string) ($row['content'] ?? ''), true);
        if ($raw === false) {
            return ['ok' => false, 'name' => null, 'content_html' => '', 'content_raw' => '', 'error' => __('Could not decode README.'), 'provider' => 'github'];
        }

        return [
            'ok' => true,
            'name' => isset($row['name']) ? (string) $row['name'] : 'README.md',
            'content_html' => $this->renderMarkdown($raw),
            'content_raw' => $raw,
            'error' => null,
            'provider' => 'github',
        ];
    }

    /**
     * @param  array<string, mixed>  $remote
     * @return array<string, mixed>
     */
    private function githubRepoMeta(array $remote, GitIdentity $identity): array
    {
        try {
            $response = $this->githubClient($identity)->get($identity->apiBaseUrl().'/repos/'.$remote['owner'].'/'.$remote['repo']);
            if ($response->successful()) {
                $body = $response->json();

                return is_array($body) ? $body : [];
            }
        } catch (Throwable) {
            // ignore — falling back to no default-branch hint
        }

        return [];
    }

    private function githubClient(GitIdentity $identity)
    {
        return Http::withHeaders([
            'User-Agent' => 'Dply (repo-reader)',
            'Accept' => 'application/vnd.github+json',
        ])->withToken($identity->accessToken())->acceptJson();
    }
}
