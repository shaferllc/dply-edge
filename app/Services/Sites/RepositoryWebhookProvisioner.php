<?php

namespace App\Services\Sites;

use App\Models\Site;
use App\Modules\Edge\Services\EdgeGithubWebhookProvisioner;
use App\Modules\SourceControl\Contracts\GitIdentity;
use App\Modules\SourceControl\Services\GitIdentityResolver;
use App\Support\GitRemoteRepositoryRef;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Tears down a provider push webhook when a site is deleted (Site::deleting in
 * AppServiceProvider).
 *
 * The enable/poll/secret-rotation half of this class registered hooks against
 * `hooks.site.deploy`, a VM-era route removed in the Edge cut, and had no
 * callers. Edge sites register their push hooks through
 * {@see EdgeGithubWebhookProvisioner}; this only
 * cleans up hooks older sites may still carry in `provider_hook` meta.
 */
class RepositoryWebhookProvisioner
{
    public function __construct(
        private ?GitIdentityResolver $resolver = null,
    ) {
        $this->resolver ??= app(GitIdentityResolver::class);
    }

    public function disable(Site $site, ?GitIdentity $account = null): void
    {
        $repo = $site->repositoryMeta();
        $hook = is_array($repo['provider_hook'] ?? null) ? $repo['provider_hook'] : null;
        if ($hook === null) {
            $site->mergeRepositoryMeta([
                'quick_deploy_enabled' => false,
                'quick_deploy_mode' => null,
                'poll_last_tip_sha' => null,
                'poll_last_checked_at' => null,
                'poll_last_skip_reason' => null,
            ]);
            $site->save();

            return;
        }

        $provider = (string) ($hook['provider'] ?? '');
        $hookId = $hook['id'] ?? null;
        $accountId = (string) ($hook['account_id'] ?? '');

        if ($account === null && $accountId !== '' && $site->user_id !== null) {
            $owner = $site->user;
            if ($owner !== null) {
                $account = $this->resolver->forId($owner, $accountId);
            }
        }

        $url = trim((string) ($site->git_repository_url ?? ''));
        $ref = GitRemoteRepositoryRef::parse($url, $provider !== '' ? $provider : 'github');

        try {
            if ($account && $hookId !== null && $ref !== null) {
                match ($provider) {
                    'github' => $this->deleteGitHubHook($account, $ref, $hookId),
                    'gitlab' => $this->deleteGitLabHook($account, $ref, $hookId),
                    'bitbucket' => $this->deleteBitbucketHook($account, $ref, $hookId),
                    default => null,
                };
            }
        } catch (\Throwable $e) {
            Log::warning('Provider webhook delete failed', ['site_id' => $site->id, 'error' => $e->getMessage()]);
        }

        $site->mergeRepositoryMeta([
            'quick_deploy_enabled' => false,
            'quick_deploy_mode' => null,
            'provider_hook' => null,
            'poll_last_tip_sha' => null,
            'poll_last_checked_at' => null,
            'poll_last_skip_reason' => null,
        ]);
        $site->save();
    }

    private function deleteGitHubHook(GitIdentity $account, GitRemoteRepositoryRef $ref, mixed $hookId): void
    {
        if ($ref->owner === null || $ref->repo === null) {
            return;
        }
        $token = $account->accessToken();
        if ($token === '') {
            return;
        }
        Http::withToken($token)
            ->delete($account->apiBaseUrl().'/repos/'.$ref->owner.'/'.$ref->repo.'/hooks/'.$hookId);
    }

    private function deleteGitLabHook(GitIdentity $account, GitRemoteRepositoryRef $ref, mixed $hookId): void
    {
        if ($ref->gitlabProjectPath === null) {
            return;
        }
        $token = $account->accessToken();
        if ($token === '') {
            return;
        }
        $encoded = rawurlencode($ref->gitlabProjectPath);
        Http::withToken($token)
            ->delete($account->apiBaseUrl().'/api/v4/projects/'.$encoded.'/hooks/'.$hookId);
    }

    private function deleteBitbucketHook(GitIdentity $account, GitRemoteRepositoryRef $ref, mixed $hookId): void
    {
        if ($ref->owner === null || $ref->repo === null) {
            return;
        }
        $token = $account->accessToken();
        if ($token === '') {
            return;
        }
        $uuid = is_string($hookId) ? $hookId : (string) $hookId;
        $encoded = rawurlencode($uuid);
        Http::withToken($token)
            ->delete($account->apiBaseUrl().'/2.0/repositories/'.$ref->owner.'/'.$ref->repo.'/hooks/'.$encoded);
    }
}
