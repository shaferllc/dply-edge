<?php

namespace App\Services\Sites;

use App\Modules\SourceControl\Contracts\GitIdentity;
use App\Models\Site;
use App\Modules\SourceControl\Services\GitIdentityResolver;
use App\Support\GitRemoteRepositoryRef;
use App\Modules\SourceControl\Support\GitHubWebhookFailure;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class RepositoryWebhookProvisioner
{
    public function __construct(
        private ?GitIdentityResolver $resolver = null,
    ) {
        $this->resolver ??= app(GitIdentityResolver::class);
    }

    public function canRegisterProviderHook(Site $site): bool
    {
        return true;
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public function enable(Site $site, GitIdentity $account): array
    {
        if (! $this->canRegisterProviderHook($site)) {
            return ['ok' => false, 'message' => __('Only the sync group leader can register a provider webhook. Deploys for other members run when the leader receives a push.')];
        }

        $repo = $site->repositoryMeta();
        $kind = (string) ($repo['git_provider_kind'] ?? 'custom');
        if ($kind === 'custom') {
            return ['ok' => false, 'message' => __('Choose GitHub, GitLab, or Bitbucket as the repository type to enable Quick deploy.')];
        }

        $url = trim((string) ($site->git_repository_url ?? ''));
        $ref = GitRemoteRepositoryRef::parse($url, $kind);
        if ($ref === null || $ref->provider === 'custom') {
            return ['ok' => false, 'message' => __('Could not parse the repository URL for this provider.')];
        }

        if ($site->webhook_secret === null || $site->webhook_secret === '') {
            $site->webhook_secret = Str::random(48);
            $site->save();
        }

        $hookUrl = $site->deployHookUrl();
        $secret = (string) $site->webhook_secret;

        // Reuse the caller-provided instance: disable() clears hook meta on this
        // model, then we create the new hook and write meta back — no reload.
        $this->disable($site, $account);

        $result = match ($kind) {
            'github' => $this->createGitHubHook($site, $account, $ref, $hookUrl, $secret),
            'gitlab' => $this->createGitLabHook($site, $account, $ref, $hookUrl, $secret),
            'bitbucket' => $this->createBitbucketHook($site, $account, $ref, $hookUrl, $secret),
            default => ['ok' => false, 'message' => __('Unsupported provider.')],
        };

        if (! $result['ok']) {
            return $result;
        }

        $site->mergeRepositoryMeta([
            'quick_deploy_enabled' => true,
            'quick_deploy_mode' => 'webhook',
            'provider_hook' => [
                'id' => $result['hook_id'],
                'provider' => $kind,
                'account_id' => $account->id(),
            ],
            'poll_last_skip_reason' => null,
        ]);
        $site->save();

        return ['ok' => true, 'message' => __('Quick deploy enabled with webhook delivery. The provider will POST to your Dply deploy URL on push.')];
    }

    /**
     * @return array{ok: bool, message: string, hook_id?: string|int}
     */
    private function createGitHubHook(Site $site, GitIdentity $account, GitRemoteRepositoryRef $ref, string $hookUrl, string $secret): array
    {
        if ($ref->owner === null || $ref->repo === null) {
            return ['ok' => false, 'message' => __('Invalid GitHub repository path.')];
        }

        $token = $account->accessToken();
        if ($token === '' || $account->provider() !== 'github') {
            return ['ok' => false, 'message' => __('Connect a GitHub account or add a personal access token under Profile → Source control.')];
        }

        $response = Http::withToken($token)
            ->acceptJson()
            ->post($account->apiBaseUrl().'/repos/'.$ref->owner.'/'.$ref->repo.'/hooks', [
                'name' => 'web',
                'active' => true,
                'events' => ['push'],
                'config' => [
                    'url' => $hookUrl,
                    'content_type' => 'json',
                    'insecure_ssl' => '0',
                    'secret' => $secret,
                ],
            ]);

        if (! $response->successful()) {
            Log::warning('GitHub webhook create failed', ['site_id' => $site->id, 'status' => $response->status(), 'scopes' => $response->header('X-OAuth-Scopes'), 'body' => $response->body()]);

            return ['ok' => false, 'message' => GitHubWebhookFailure::message($response, $account->kind() === 'pat')];
        }

        $id = $response->json('id');

        return ['ok' => true, 'message' => 'ok', 'hook_id' => $id];
    }

    /**
     * @return array{ok: bool, message: string, hook_id?: string|int}
     */
    private function createGitLabHook(Site $site, GitIdentity $account, GitRemoteRepositoryRef $ref, string $hookUrl, string $secret): array
    {
        if ($ref->gitlabProjectPath === null) {
            return ['ok' => false, 'message' => __('Invalid GitLab repository path.')];
        }

        $token = $account->accessToken();
        if ($token === '' || $account->provider() !== 'gitlab') {
            return ['ok' => false, 'message' => __('Connect a GitLab account or add a personal access token under Profile → Source control.')];
        }

        $encoded = rawurlencode($ref->gitlabProjectPath);

        $response = Http::withToken($token)
            ->acceptJson()
            ->post($account->apiBaseUrl().'/api/v4/projects/'.$encoded.'/hooks', [
                'url' => $hookUrl,
                'push_events' => true,
                'token' => $secret,
                'enable_ssl_verification' => true,
            ]);

        if (! $response->successful()) {
            Log::warning('GitLab webhook create failed', ['site_id' => $site->id, 'status' => $response->status(), 'body' => $response->body()]);

            $hint = $account->kind() === 'pat'
                ? __('GitLab rejected the webhook (:status). The personal access token needs the api scope.', ['status' => $response->status()])
                : __('GitLab rejected the webhook (:status). Re-link GitLab with API access.', ['status' => $response->status()]);

            return ['ok' => false, 'message' => $hint];
        }

        return ['ok' => true, 'message' => 'ok', 'hook_id' => $response->json('id')];
    }

    /**
     * @return array{ok: bool, message: string, hook_id?: string|int}
     */
    private function createBitbucketHook(Site $site, GitIdentity $account, GitRemoteRepositoryRef $ref, string $hookUrl, string $secret): array
    {
        if ($ref->owner === null || $ref->repo === null) {
            return ['ok' => false, 'message' => __('Invalid Bitbucket repository path.')];
        }

        $token = $account->accessToken();
        if ($token === '' || $account->provider() !== 'bitbucket') {
            return ['ok' => false, 'message' => __('Connect a Bitbucket account or add a personal access token under Profile → Source control.')];
        }

        $response = Http::withToken($token)
            ->acceptJson()
            ->post($account->apiBaseUrl().'/2.0/repositories/'.$ref->owner.'/'.$ref->repo.'/hooks', [
                'description' => 'Dply deploy',
                'url' => $hookUrl,
                'active' => true,
                'events' => [
                    'repo:push',
                ],
            ]);

        if (! $response->successful()) {
            Log::warning('Bitbucket webhook create failed', ['site_id' => $site->id, 'status' => $response->status(), 'body' => $response->body()]);

            $hint = $account->kind() === 'pat'
                ? __('Bitbucket rejected the webhook (:status). The personal access token needs repository:admin and webhook permissions.', ['status' => $response->status()])
                : __('Bitbucket rejected the webhook (:status).', ['status' => $response->status()]);

            return ['ok' => false, 'message' => $hint];
        }

        $uuid = $response->json('uuid');
        $hookId = is_string($uuid) ? $uuid : (string) ($response->json('uuid') ?? '');

        return ['ok' => true, 'message' => 'ok', 'hook_id' => $hookId !== '' ? $hookId : 'bitbucket'];
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

    /**
     * Enable Quick deploy in poll mode: dply checks the Git tip on a schedule
     * (no provider push webhook). Removes any registered provider hook.
     *
     * @return array{ok: bool, message: string}
     */
    public function enablePoll(Site $site): array
    {
        $kind = (string) ($site->repositoryMeta()['git_provider_kind'] ?? 'custom');
        if ($kind === 'custom') {
            $kind = $this->detectProviderKind((string) ($site->git_repository_url ?? ''));
        }
        if (! in_array($kind, ['github', 'gitlab', 'bitbucket'], true)) {
            return ['ok' => false, 'message' => __('Poll mode needs a GitHub, GitLab, or Bitbucket repository.')];
        }

        if (trim((string) ($site->git_repository_url ?? '')) === '') {
            return ['ok' => false, 'message' => __('Connect a repository before enabling Quick deploy.')];
        }

        // Drop any inbound provider hook; signed dply hook URL still works.
        $this->disable($site->fresh() ?? $site);
        $site = $site->fresh() ?? $site;

        if ($site->webhook_secret === null || $site->webhook_secret === '') {
            $site->update(['webhook_secret' => Str::random(48)]);
            $site = $site->fresh() ?? $site;
        }

        $site->mergeRepositoryMeta([
            'quick_deploy_enabled' => true,
            'quick_deploy_mode' => 'poll',
            'git_provider_kind' => $kind,
            'provider_hook' => null,
            'poll_last_skip_reason' => null,
        ]);
        $site->save();

        return ['ok' => true, 'message' => __('Quick deploy enabled with poll delivery. dply will check for new commits on a short schedule.')];
    }

    private function detectProviderKind(string $url): string
    {
        $url = strtolower($url);

        return match (true) {
            str_contains($url, 'github.com') => 'github',
            str_contains($url, 'gitlab') => 'gitlab',
            str_contains($url, 'bitbucket.org') => 'bitbucket',
            default => 'custom',
        };
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

    /**
     * After rotating the Dply webhook secret, update the provider hook configuration when Quick deploy is enabled.
     */
    public function syncProviderHookSecret(Site $site): void
    {
        $repo = $site->repositoryMeta();
        if (! ($repo['quick_deploy_enabled'] ?? false)) {
            return;
        }
        if (($repo['quick_deploy_mode'] ?? 'webhook') !== 'webhook') {
            return;
        }
        $hook = is_array($repo['provider_hook'] ?? null) ? $repo['provider_hook'] : null;
        if ($hook === null) {
            return;
        }
        $accountId = (string) ($hook['account_id'] ?? '');
        $owner = $site->user;
        $account = ($accountId !== '' && $owner !== null) ? $this->resolver->forId($owner, $accountId) : null;
        if ($account === null) {
            return;
        }

        $provider = (string) ($hook['provider'] ?? '');
        $hookId = $hook['id'] ?? null;
        $url = trim((string) ($site->git_repository_url ?? ''));
        $ref = GitRemoteRepositoryRef::parse($url, $provider !== '' ? $provider : 'github');
        $secret = (string) $site->webhook_secret;
        if ($secret === '') {
            return;
        }

        $token = $account->accessToken();
        if ($token === '') {
            return;
        }

        if ($provider === 'github' && $ref->owner && $ref->repo && $hookId !== null) {
            Http::withToken($token)
                ->patch($account->apiBaseUrl().'/repos/'.$ref->owner.'/'.$ref->repo.'/hooks/'.$hookId, [
                    'config' => [
                        'url' => $site->deployHookUrl(),
                        'content_type' => 'json',
                        'insecure_ssl' => '0',
                        'secret' => $secret,
                    ],
                ]);
        }

        if ($provider === 'gitlab' && $ref?->gitlabProjectPath !== null && $hookId !== null) {
            $encoded = rawurlencode($ref->gitlabProjectPath);
            Http::withToken($token)
                ->put($account->apiBaseUrl().'/api/v4/projects/'.$encoded.'/hooks/'.$hookId, [
                    'url' => $site->deployHookUrl(),
                    'push_events' => true,
                    'token' => $secret,
                    'enable_ssl_verification' => true,
                ]);
        }
    }
}
