<?php

declare(strict_types=1);

namespace App\Modules\SourceControl\Services\Concerns;

use App\Models\Site;
use App\Models\User;
use App\Modules\SourceControl\Contracts\GitIdentity;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * GitLab REST calls for {@see SourceControlRepositoryReader}.
 * Split out of that class (was >1000 lines); dispatch and caching stay there.
 */
trait ReadsGitLabRepositories
{
    /**
     * @param  array<string, mixed>  $remote
     * @return array<string, mixed>
     */
    private function gitlabBranches(array $remote, Site $site, User $user): array
    {
        $identity = $this->resolver->forSite($site, $user, 'gitlab');
        if ($identity === null) {
            return ['ok' => false, 'branches' => [], 'error' => __('Link a GitLab account or add a personal access token to browse this repo.'), 'provider' => 'gitlab'];
        }

        $projectMeta = $this->gitlabProjectMeta($remote, $identity);
        $defaultBranch = $projectMeta['default_branch'] ?? null;
        $encoded = rawurlencode($remote['project_path']);
        $url = $this->gitlabApiBase($identity, $remote).'/api/v4/projects/'.$encoded.'/repository/branches';

        $response = Http::withToken($identity->accessToken())->acceptJson()->get($url, ['per_page' => 100]);
        if (! $response->successful()) {
            return ['ok' => false, 'branches' => [], 'error' => $this->formatApiError($response->status(), $response->body()), 'provider' => 'gitlab'];
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
                'sha' => (string) ($commit['id'] ?? ''),
                'committed_at' => isset($commit['committed_date']) ? (string) $commit['committed_date'] : null,
                'committer' => isset($commit['committer_name']) ? (string) $commit['committer_name'] : null,
                'is_default' => $defaultBranch !== null && $name === $defaultBranch,
            ];
        }

        return ['ok' => true, 'branches' => $branches, 'error' => null, 'provider' => 'gitlab'];
    }

    /**
     * @param  array<string, mixed>  $remote
     * @return array<string, mixed>
     */
    private function gitlabTags(array $remote, Site $site, User $user): array
    {
        $identity = $this->resolver->forSite($site, $user, 'gitlab');
        if ($identity === null) {
            return ['ok' => false, 'tags' => [], 'error' => __('Link a GitLab account or add a personal access token to browse this repo.'), 'provider' => 'gitlab'];
        }

        $encoded = rawurlencode($remote['project_path']);
        $url = $this->gitlabApiBase($identity, $remote).'/api/v4/projects/'.$encoded.'/repository/tags';
        $response = Http::withToken($identity->accessToken())->acceptJson()->get($url, ['per_page' => 100]);
        if (! $response->successful()) {
            return ['ok' => false, 'tags' => [], 'error' => $this->formatApiError($response->status(), $response->body()), 'provider' => 'gitlab'];
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
                'sha' => (string) ($commit['id'] ?? ''),
                'committed_at' => isset($commit['committed_date']) ? (string) $commit['committed_date'] : null,
            ];
        }

        return ['ok' => true, 'tags' => $tags, 'error' => null, 'provider' => 'gitlab'];
    }

    /**
     * @param  array<string, mixed>  $remote
     * @return array<string, mixed>
     */
    private function gitlabTree(array $remote, Site $site, User $user, string $branch, string $path): array
    {
        $identity = $this->resolver->forSite($site, $user, 'gitlab');
        if ($identity === null) {
            return ['ok' => false, 'entries' => [], 'error' => __('Link a GitLab account or add a personal access token.'), 'provider' => 'gitlab'];
        }

        $encoded = rawurlencode($remote['project_path']);
        $url = $this->gitlabApiBase($identity, $remote).'/api/v4/projects/'.$encoded.'/repository/tree';
        $response = Http::withToken($identity->accessToken())->acceptJson()->get($url, [
            'ref' => $branch,
            'path' => $path,
            'per_page' => 100,
        ]);
        if (! $response->successful()) {
            return ['ok' => false, 'entries' => [], 'error' => $this->formatApiError($response->status(), $response->body()), 'provider' => 'gitlab'];
        }
        $rows = is_array($response->json()) ? $response->json() : [];

        $entries = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $type = ((string) ($row['type'] ?? 'blob')) === 'tree' ? 'dir' : 'file';
            $entries[] = [
                'name' => (string) ($row['name'] ?? ''),
                'path' => (string) ($row['path'] ?? ''),
                'type' => $type,
                'size' => 0,
                'sha' => isset($row['id']) ? (string) $row['id'] : null,
            ];
        }

        return ['ok' => true, 'entries' => $this->sortEntries($entries), 'error' => null, 'provider' => 'gitlab'];
    }

    /**
     * @param  array<string, mixed>  $remote
     * @return array<string, mixed>
     */
    private function gitlabFile(array $remote, Site $site, User $user, string $branch, string $path): array
    {
        $identity = $this->resolver->forSite($site, $user, 'gitlab');
        if ($identity === null) {
            return ['ok' => false, 'content' => '', 'size' => 0, 'too_large' => false, 'binary' => false, 'html_url' => null, 'error' => __('Link a GitLab account or add a personal access token.'), 'provider' => 'gitlab'];
        }

        $encodedProject = rawurlencode($remote['project_path']);
        $encodedPath = rawurlencode($path);
        $apiBase = $this->gitlabApiBase($identity, $remote);
        $url = $apiBase.'/api/v4/projects/'.$encodedProject.'/repository/files/'.$encodedPath;
        $htmlUrl = $apiBase.'/'.$remote['project_path'].'/-/blob/'.rawurlencode($branch).'/'.$this->encodePath($path);
        $response = Http::withToken($identity->accessToken())->acceptJson()->get($url, ['ref' => $branch]);
        if (! $response->successful()) {
            return ['ok' => false, 'content' => '', 'size' => 0, 'too_large' => false, 'binary' => false, 'html_url' => $htmlUrl, 'error' => $this->formatApiError($response->status(), $response->body()), 'provider' => 'gitlab'];
        }
        $row = is_array($response->json()) ? $response->json() : [];

        $size = (int) ($row['size'] ?? 0);
        if ($size > self::MAX_FILE_BYTES) {
            return ['ok' => true, 'content' => '', 'size' => $size, 'too_large' => true, 'binary' => false, 'html_url' => $htmlUrl, 'error' => null, 'provider' => 'gitlab'];
        }
        $encoding = (string) ($row['encoding'] ?? 'base64');
        $contentRaw = (string) ($row['content'] ?? '');
        $raw = $encoding === 'base64' ? base64_decode($contentRaw, true) : $contentRaw;
        if ($raw === false) {
            return ['ok' => false, 'content' => '', 'size' => $size, 'too_large' => false, 'binary' => true, 'html_url' => $htmlUrl, 'error' => __('Could not decode file contents.'), 'provider' => 'gitlab'];
        }

        return $this->buildFileResult($raw, $size, $htmlUrl, 'gitlab');
    }

    /**
     * @param  array<string, mixed>  $remote
     * @return array<string, mixed>
     */
    private function gitlabProjectMeta(array $remote, GitIdentity $identity): array
    {
        try {
            $encoded = rawurlencode($remote['project_path']);
            $url = $this->gitlabApiBase($identity, $remote).'/api/v4/projects/'.$encoded;
            $response = Http::withToken($identity->accessToken())->acceptJson()->get($url);
            if ($response->successful()) {
                $body = $response->json();

                return is_array($body) ? $body : [];
            }
        } catch (Throwable) {
            // ignore
        }

        return [];
    }

    /**
     * GitLab's REST API is rooted on the host (no /api/v3 prefix), but the
     * repo's host may differ from a user's PAT base URL (e.g. a cloud
     * gitlab.com repo with no PAT yet, or a self-hosted PAT pointed at a
     * different host). Prefer the identity's configured base; fall back
     * to the host parsed from the repo URL.
     *
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
}
