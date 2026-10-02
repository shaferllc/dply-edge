<?php

declare(strict_types=1);

namespace App\Modules\SourceControl\Services;

use App\Models\Site;
use App\Models\SocialAccount;
use App\Models\User;
use App\Modules\SourceControl\Contracts\GitIdentity;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Credentials for cloning a private repository with a linked Git account.
 *
 * The token travels as an `http.<host>/.extraheader` in git's environment
 * (GIT_CONFIG_COUNT/KEY/VALUE) — never in argv, never in the clone URL, so
 * it can't reach a logged command line, `ps`, or the checkout's
 * `.git/config` (whose remote stays the plain URL). Needs git >= 2.31.
 */
class GitCloneAuth
{
    public function __construct(
        private readonly GitIdentityResolver $resolver,
    ) {}

    /** 'github' | 'gitlab' | 'bitbucket' for an https URL on those hosts, else null. */
    public static function providerForUrl(string $url): ?string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return match ($host) {
            'github.com', 'www.github.com' => 'github',
            'gitlab.com', 'www.gitlab.com' => 'gitlab',
            'bitbucket.org', 'www.bitbucket.org' => 'bitbucket',
            default => null,
        };
    }

    /**
     * Env for cloning a site's repo: the account pinned when the site was
     * created, else the owner's best identity for that host. [] = anonymous.
     *
     * @return array<string, string>
     */
    public function envForSite(Site $site, string $url): array
    {
        if (DplyGit::isRemote($url)) {
            // Callers expect [] on trouble (the clone then fails with the usual
            // auth message), and some run inside a page request.
            try {
                return app(DplyGit::class)->cloneEnv($url);
            } catch (Throwable $e) {
                Log::warning('dply Git read token failed', ['site_id' => $site->id, 'error' => $e->getMessage()]);

                return [];
            }
        }

        $provider = self::providerForUrl($url);
        $user = $site->user;
        if ($provider === null || $user === null) {
            return [];
        }

        // Preview sites don't copy the parent's pinned account; use the parent's.
        $parentId = $site->edgeMeta()['preview_parent_site_id'] ?? null;
        if (($site->repositoryMeta()['git_source_control_account_id'] ?? '') === '' && is_string($parentId) && $parentId !== '') {
            $site = Site::query()->find($parentId) ?? $site;
        }

        return $this->envFor($this->resolver->forSite($site, $user, $provider), $url);
    }

    /**
     * Env for a user's own clone (create-page detection): the account they
     * picked when it matches the repo's host, else their best one for it.
     *
     * @return array<string, string>
     */
    public function envForUser(User $user, string $accountId, string $url): array
    {
        $provider = self::providerForUrl($url);
        if ($provider === null) {
            return [];
        }

        $identity = $accountId !== '' ? $this->resolver->forId($user, $accountId) : null;
        if ($identity === null || $identity->provider() !== $provider || $this->resolver->isKnownBad($identity)) {
            $identity = $this->resolver->forUserProvider($user, $provider);
        }

        return $this->envFor($identity, $url);
    }

    /**
     * @return array<string, string>
     */
    public function envFor(?GitIdentity $identity, string $url): array
    {
        $provider = self::providerForUrl($url);
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if ($identity === null || $provider === null || $identity->provider() !== $provider || $scheme !== 'https') {
            return [];
        }

        $token = $this->fresh($identity)->accessToken();
        $username = SourceControlRepositoryBrowser::cloneUsername($provider);
        if ($token === '' || $username === '') {
            return [];
        }

        return [
            'GIT_TERMINAL_PROMPT' => '0',
            'GIT_CONFIG_COUNT' => '1',
            'GIT_CONFIG_KEY_0' => 'http.https://'.strtolower((string) parse_url($url, PHP_URL_HOST)).'/.extraheader',
            'GIT_CONFIG_VALUE_0' => 'Authorization: Basic '.base64_encode($username.':'.$token),
        ];
    }

    /**
     * Mask anything from $env that could identify the token.
     *
     * @param  array<string, string>  $env
     */
    public static function redact(string $text, array $env): string
    {
        $header = (string) ($env['GIT_CONFIG_VALUE_0'] ?? '');
        if ($header === '' || $text === '') {
            return $text;
        }

        $encoded = substr($header, strlen('Authorization: Basic '));
        $decoded = (string) base64_decode($encoded, true);
        $secrets = array_filter([$encoded, $decoded, explode(':', $decoded, 2)[1] ?? ''], fn (string $s): bool => strlen($s) >= 8);

        return str_replace($secrets, '***', $text);
    }

    /**
     * OAuth tokens that expire (GitLab and Bitbucket after ~2h, GitHub apps
     * with expiring user tokens) are refreshed here. A failed refresh keeps
     * the current token so the clone's own auth error reaches the operator.
     */
    private function fresh(GitIdentity $identity): GitIdentity
    {
        if (! $identity instanceof SocialAccount || blank($identity->refresh_token) || ! $this->expiresSoon($identity)) {
            return $identity;
        }

        try {
            return Cache::lock('git-token-refresh:'.$identity->id, 30)->block(15, function () use ($identity): SocialAccount {
                $identity->refresh();
                if (! $this->expiresSoon($identity) || blank($identity->refresh_token)) {
                    return $identity; // another worker refreshed it while we waited
                }

                $body = $this->requestRefresh($identity->provider(), (string) $identity->refresh_token);
                if (! is_string($body['access_token'] ?? null) || $body['access_token'] === '') {
                    return $identity;
                }

                $identity->forceFill([
                    'access_token' => $body['access_token'],
                    'refresh_token' => is_string($body['refresh_token'] ?? null) && $body['refresh_token'] !== '' ? $body['refresh_token'] : $identity->refresh_token,
                    'expires_at' => is_numeric($body['expires_in'] ?? null) ? now()->addSeconds((int) $body['expires_in']) : null,
                ])->save();

                return $identity;
            });
        } catch (Throwable $e) {
            Log::warning('GitCloneAuth: token refresh failed', ['account' => $identity->id, 'provider' => $identity->provider(), 'message' => $e->getMessage()]);

            return $identity;
        }
    }

    /** Unknown expiry counts as expiring: GitLab/Bitbucket sign-in never recorded one. */
    private function expiresSoon(SocialAccount $account): bool
    {
        return $account->expires_at === null || $account->expires_at->isBefore(now()->addMinutes(5));
    }

    /**
     * @return array<string, mixed>
     */
    private function requestRefresh(string $provider, string $refreshToken): array
    {
        $config = (array) config('services.'.$provider, []);
        $form = [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ];

        $response = match ($provider) {
            'github' => Http::asForm()->acceptJson()->post('https://github.com/login/oauth/access_token', $form + [
                'client_id' => $config['client_id'] ?? '',
                'client_secret' => $config['client_secret'] ?? '',
            ]),
            'gitlab' => Http::asForm()->acceptJson()->post('https://gitlab.com/oauth/token', $form + [
                'client_id' => $config['client_id'] ?? '',
                'client_secret' => $config['client_secret'] ?? '',
                'redirect_uri' => $config['redirect'] ?? '',
            ]),
            'bitbucket' => Http::asForm()->acceptJson()
                ->withBasicAuth((string) ($config['client_id'] ?? ''), (string) ($config['client_secret'] ?? ''))
                ->post('https://bitbucket.org/site/oauth2/access_token', $form),
            default => null,
        };

        return $response !== null && $response->successful() ? (array) $response->json() : [];
    }
}
