<?php

declare(strict_types=1);

namespace App\Modules\SourceControl\Services\Concerns;

use App\Models\Site;
use App\Models\User;
use App\Modules\SourceControl\Contracts\GitIdentity;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Bitbucket REST calls for {@see SourceControlRepositoryReader}.
 * Split out of that class (was >1000 lines); dispatch and caching stay there.
 */
trait ReadsBitbucketRepositories
{
    /**
     * @param  array<string, mixed>  $remote
     * @return array<string, mixed>
     */
    private function bitbucketBranches(array $remote, Site $site, User $user): array
    {
        $identity = $this->resolver->forSite($site, $user, 'bitbucket');
        if ($identity === null) {
            return ['ok' => false, 'branches' => [], 'error' => __('Link a Bitbucket account or add a personal access token to browse this repo.'), 'provider' => 'bitbucket'];
        }

        $url = $identity->apiBaseUrl().'/2.0/repositories/'.$remote['workspace'].'/'.$remote['repo'].'/refs/branches';
        $response = Http::withToken($identity->accessToken())->acceptJson()->get($url, ['pagelen' => 100]);
        if (! $response->successful()) {
            return ['ok' => false, 'branches' => [], 'error' => $this->formatApiError($response->status(), $response->body()), 'provider' => 'bitbucket'];
        }
        $payload = $response->json();
        $rows = is_array($payload['values'] ?? null) ? $payload['values'] : [];
        $defaultBranch = $this->bitbucketDefaultBranch($remote, $identity);

        $branches = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $name = (string) ($row['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $target = is_array($row['target'] ?? null) ? $row['target'] : [];
            $branches[] = [
                'name' => $name,
                'sha' => (string) ($target['hash'] ?? ''),
                'committed_at' => isset($target['date']) ? (string) $target['date'] : null,
                'committer' => null,
                'is_default' => $defaultBranch !== null && $name === $defaultBranch,
            ];
        }

        return ['ok' => true, 'branches' => $branches, 'error' => null, 'provider' => 'bitbucket'];
    }

    /**
     * @param  array<string, mixed>  $remote
     * @return array<string, mixed>
     */
    private function bitbucketTags(array $remote, Site $site, User $user): array
    {
        $identity = $this->resolver->forSite($site, $user, 'bitbucket');
        if ($identity === null) {
            return ['ok' => false, 'tags' => [], 'error' => __('Link a Bitbucket account or add a personal access token to browse this repo.'), 'provider' => 'bitbucket'];
        }

        $url = $identity->apiBaseUrl().'/2.0/repositories/'.$remote['workspace'].'/'.$remote['repo'].'/refs/tags';
        $response = Http::withToken($identity->accessToken())->acceptJson()->get($url, ['pagelen' => 100]);
        if (! $response->successful()) {
            return ['ok' => false, 'tags' => [], 'error' => $this->formatApiError($response->status(), $response->body()), 'provider' => 'bitbucket'];
        }

        $rows = is_array($response->json('values')) ? $response->json('values') : [];
        $tags = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $name = (string) ($row['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $target = is_array($row['target'] ?? null) ? $row['target'] : [];
            $tags[] = [
                'name' => $name,
                'sha' => (string) ($target['hash'] ?? ''),
                'committed_at' => isset($target['date']) ? (string) $target['date'] : null,
            ];
        }

        return ['ok' => true, 'tags' => $tags, 'error' => null, 'provider' => 'bitbucket'];
    }

    /**
     * @param  array<string, mixed>  $remote
     * @return array<string, mixed>
     */
    private function bitbucketTree(array $remote, Site $site, User $user, string $branch, string $path): array
    {
        $identity = $this->resolver->forSite($site, $user, 'bitbucket');
        if ($identity === null) {
            return ['ok' => false, 'entries' => [], 'error' => __('Link a Bitbucket account or add a personal access token.'), 'provider' => 'bitbucket'];
        }

        $segment = $path === '' ? '' : $this->encodePath($path).'/';
        $url = $identity->apiBaseUrl().'/2.0/repositories/'.$remote['workspace'].'/'.$remote['repo'].'/src/'.rawurlencode($branch).'/'.$segment;
        $response = Http::withToken($identity->accessToken())->acceptJson()->get($url);
        if (! $response->successful()) {
            return ['ok' => false, 'entries' => [], 'error' => $this->formatApiError($response->status(), $response->body()), 'provider' => 'bitbucket'];
        }
        $payload = $response->json();
        $rows = is_array($payload['values'] ?? null) ? $payload['values'] : [];

        $entries = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $type = ((string) ($row['type'] ?? 'commit_file')) === 'commit_directory' ? 'dir' : 'file';
            $rowPath = (string) ($row['path'] ?? '');
            $name = (string) Str::afterLast($rowPath, '/');
            if ($name === '') {
                $name = $rowPath;
            }
            $entries[] = [
                'name' => $name,
                'path' => $rowPath,
                'type' => $type,
                'size' => (int) ($row['size'] ?? 0),
                'sha' => isset($row['commit']['hash']) ? (string) $row['commit']['hash'] : null,
            ];
        }

        return ['ok' => true, 'entries' => $this->sortEntries($entries), 'error' => null, 'provider' => 'bitbucket'];
    }

    /**
     * @param  array<string, mixed>  $remote
     * @return array<string, mixed>
     */
    private function bitbucketFile(array $remote, Site $site, User $user, string $branch, string $path): array
    {
        $identity = $this->resolver->forSite($site, $user, 'bitbucket');
        if ($identity === null) {
            return ['ok' => false, 'content' => '', 'size' => 0, 'too_large' => false, 'binary' => false, 'html_url' => null, 'error' => __('Link a Bitbucket account or add a personal access token.'), 'provider' => 'bitbucket'];
        }

        $url = $identity->apiBaseUrl().'/2.0/repositories/'.$remote['workspace'].'/'.$remote['repo'].'/src/'.rawurlencode($branch).'/'.$this->encodePath($path);
        $htmlUrl = 'https://bitbucket.org/'.$remote['workspace'].'/'.$remote['repo'].'/src/'.rawurlencode($branch).'/'.$this->encodePath($path);
        $response = Http::withToken($identity->accessToken())->get($url);
        if (! $response->successful()) {
            return ['ok' => false, 'content' => '', 'size' => 0, 'too_large' => false, 'binary' => false, 'html_url' => $htmlUrl, 'error' => $this->formatApiError($response->status(), $response->body()), 'provider' => 'bitbucket'];
        }
        $raw = $response->body();
        $size = strlen($raw);
        if ($size > self::MAX_FILE_BYTES) {
            return ['ok' => true, 'content' => '', 'size' => $size, 'too_large' => true, 'binary' => false, 'html_url' => $htmlUrl, 'error' => null, 'provider' => 'bitbucket'];
        }

        return $this->buildFileResult($raw, $size, $htmlUrl, 'bitbucket');
    }

    /**
     * @param  array<string, mixed>  $remote
     */
    private function bitbucketDefaultBranch(array $remote, GitIdentity $identity): ?string
    {
        try {
            $url = $identity->apiBaseUrl().'/2.0/repositories/'.$remote['workspace'].'/'.$remote['repo'];
            $response = Http::withToken($identity->accessToken())->acceptJson()->get($url);
            if ($response->successful()) {
                $body = $response->json();
                $name = $body['mainbranch']['name'] ?? null;

                return is_string($name) ? $name : null;
            }
        } catch (Throwable) {
            // ignore
        }

        return null;
    }
}
