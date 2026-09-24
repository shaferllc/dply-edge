<?php

declare(strict_types=1);

namespace App\Modules\SourceControl\Services;

use App\Models\User;
use App\Modules\SourceControl\Contracts\GitIdentity;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class SourceControlRepositoryBrowser
{
    /**
     * Page size asked of every provider. 100 is GitHub's and GitLab's maximum;
     * Bitbucket accepts it too.
     */
    private const PER_PAGE = 100;

    /**
     * Hard stop on paging, so an account with thousands of repos can't turn one
     * picker render into an unbounded fan of API calls. 10 x 100 = 1,000 repos;
     * past that the "paste a URL" path is the sane way in.
     */
    private const MAX_PAGES = 10;

    private ?string $repositoryError = null;

    public function __construct(
        private ?GitIdentityResolver $resolver = null,
    ) {
        $this->resolver ??= app(GitIdentityResolver::class);
    }

    /**
     * @return list<array{id: string, provider: string, label: string, kind: string}>
     */
    public function accountsForUser(User $user): array
    {
        $cacheKey = 'source-control.accounts.'.(string) $user->getKey();
        $cached = request()->attributes->get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $accounts = array_map(
            fn (GitIdentity $identity): array => [
                'id' => $identity->id(),
                'provider' => $identity->provider(),
                'label' => $identity->displayLabel(),
                'kind' => $identity->kind(),
            ],
            $this->resolver->allForUser($user),
        );

        request()->attributes->set($cacheKey, $accounts);

        return $accounts;
    }

    /**
     * @return list<array{label: string, url: string, branch: string}>
     */
    public function repositoriesForAccount(GitIdentity $account): array
    {
        $this->repositoryError = null;

        if ($account->accessToken() === '') {
            $this->repositoryError = __('This token could not be read. Replace it under Source control.');

            return [];
        }

        return match ($account->provider()) {
            'github' => $this->githubRepositories($account),
            'gitlab' => $this->gitlabRepositories($account),
            'bitbucket' => $this->bitbucketRepositories($account),
            default => [],
        };
    }

    /**
     * Why the last {@see repositoriesForAccount()} call returned nothing.
     * Null when the provider answered, including an empty list.
     */
    public function repositoryError(): ?string
    {
        return $this->repositoryError;
    }

    public function authenticatedCloneUrl(GitIdentity $account, string $repositoryUrl): string
    {
        $token = $account->accessToken();
        if ($token === '') {
            return $repositoryUrl;
        }

        $parts = parse_url($repositoryUrl);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return $repositoryUrl;
        }

        $user = match ($account->provider()) {
            'github' => 'x-access-token',
            'gitlab' => 'oauth2',
            'bitbucket' => 'x-token-auth',
            default => '',
        };

        if ($user === '') {
            return $repositoryUrl;
        }

        $auth = rawurlencode($user).':'.rawurlencode($token).'@'.$parts['host'];
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $path = $parts['path'] ?? '';
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';

        return $parts['scheme'].'://'.$auth.$port.$path.$query;
    }

    /**
     * @return list<array<string, string>>
     */
    private function githubRepositories(GitIdentity $account): array
    {
        $rows = [];

        // `affiliation` is spelled out rather than left to the default so the
        // intent is visible: repos the user owns, is a collaborator on, or can
        // reach through an org. Repos in an org that has not approved the OAuth
        // app are invisible to the API regardless — the picker's access hint
        // points there, since no amount of paging will surface them.
        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $response = Http::withToken($account->accessToken())
                ->acceptJson()
                ->get($account->apiBaseUrl().'/user/repos', [
                    'sort' => 'updated',
                    'affiliation' => 'owner,collaborator,organization_member',
                    'per_page' => self::PER_PAGE,
                    'page' => $page,
                ]);

            if (! $response->successful()) {
                $this->rememberFailure($account, $response, $rows);

                break;
            }

            $body = $response->json();
            if (! is_array($body) || $body === []) {
                break;
            }

            $rows = array_merge($rows, $body);

            if (count($body) < self::PER_PAGE) {
                break;
            }
        }

        return collect($rows)
            ->filter(fn (mixed $repo): bool => is_array($repo) && is_string($repo['clone_url'] ?? null))
            ->map(fn (array $repo): array => [
                'label' => (string) ($repo['full_name'] ?? $repo['name'] ?? $repo['clone_url']),
                'url' => (string) $repo['clone_url'],
                'branch' => (string) ($repo['default_branch'] ?? 'main'),
            ])
            ->unique('url')
            ->sortBy('label')
            ->values()
            ->all();
    }

    /**
     * @return list<array{label: string, url: string, branch: string}>
     */
    private function gitlabRepositories(GitIdentity $account): array
    {
        $rows = [];

        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $response = Http::withToken($account->accessToken())
                ->acceptJson()
                ->get($account->apiBaseUrl().'/api/v4/projects', [
                    'membership' => true,
                    'simple' => true,
                    'per_page' => self::PER_PAGE,
                    'page' => $page,
                ]);

            if (! $response->successful()) {
                $this->rememberFailure($account, $response, $rows);

                break;
            }

            $body = $response->json();
            if (! is_array($body) || $body === []) {
                break;
            }

            $rows = array_merge($rows, $body);

            if (count($body) < self::PER_PAGE) {
                break;
            }
        }

        return collect($rows)
            ->filter(fn (mixed $repo): bool => is_array($repo) && is_string($repo['http_url_to_repo'] ?? null))
            ->map(fn (array $repo): array => [
                'label' => (string) ($repo['path_with_namespace'] ?? $repo['name'] ?? $repo['http_url_to_repo']),
                'url' => (string) $repo['http_url_to_repo'],
                'branch' => (string) ($repo['default_branch'] ?? 'main'),
            ])
            ->unique('url')
            ->sortBy('label')
            ->values()
            ->all();
    }

    /**
     * @return list<array{label: string, url: string, branch: string}>
     */
    private function bitbucketRepositories(GitIdentity $account): array
    {
        $rows = [];
        // Bitbucket hands back an absolute `next` URL rather than page numbers,
        // so the first call carries the query and the rest just follow the link.
        $url = $account->apiBaseUrl().'/2.0/repositories';
        $query = ['role' => 'member', 'pagelen' => self::PER_PAGE];

        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $response = Http::withToken($account->accessToken())
                ->acceptJson()
                ->get($url, $query);

            if (! $response->successful()) {
                $this->rememberFailure($account, $response, $rows);

                break;
            }

            $values = $response->json('values', []);
            if (! is_array($values) || $values === []) {
                break;
            }

            $rows = array_merge($rows, $values);

            $next = $response->json('next');
            if (! is_string($next) || $next === '') {
                break;
            }

            $url = $next;
            $query = [];
        }

        return collect($rows)
            ->filter(fn (mixed $repo): bool => is_array($repo))
            ->map(function (array $repo): ?array {
                $cloneUrl = collect($repo['links']['clone'] ?? [])
                    ->firstWhere('name', 'https')['href'] ?? null;

                if (! is_string($cloneUrl) || $cloneUrl === '') {
                    return null;
                }

                return [
                    'label' => (string) ($repo['full_name'] ?? $repo['name'] ?? $cloneUrl),
                    'url' => $cloneUrl,
                    'branch' => (string) ($repo['mainbranch']['name'] ?? 'main'),
                ];
            })
            ->filter()
            ->unique('url')
            ->sortBy('label')
            ->values()
            ->all();
    }

    /**
     * @param  list<mixed>  $rows
     */
    private function rememberFailure(GitIdentity $account, Response $response, array $rows): void
    {
        if ($rows !== []) {
            return;
        }

        $message = $response->json('message');
        $detail = is_string($message) ? trim($message) : '';
        if ($detail === '') {
            $detail = trim($response->reason()) ?: 'request failed';
        }

        $this->repositoryError = __(':provider rejected this token (HTTP :status — :detail). Replace it under Source control.', [
            'provider' => ucfirst($account->provider()),
            'status' => (string) $response->status(),
            'detail' => $detail,
        ]);
    }
}
