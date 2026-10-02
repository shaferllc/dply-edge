<?php

declare(strict_types=1);

namespace App\Modules\SourceControl\Services;

use App\Models\Site;
use App\Modules\Providers\Cloudflare\CloudflareArtifactsClient;

/**
 * dply Git: app repos hosted on Cloudflare Artifacts (T-034).
 *
 * A dply Git app's `edge.source.repo` is its Artifacts remote,
 * `https://<account>.artifacts.cloudflare.net/git/<namespace>/<repo>.git`,
 * where repo = the production site's id. Everything here is keyed off that
 * URL, so a preview (which copies the parent's remote) works unchanged.
 */
class DplyGit
{
    private readonly CloudflareArtifactsClient $client;

    public function __construct(?CloudflareArtifactsClient $client = null)
    {
        $this->client = $client ?? CloudflareArtifactsClient::fromConfig();
    }

    public function client(): CloudflareArtifactsClient
    {
        return $this->client;
    }

    /**
     * @return array{namespace: string, repo: string}|null
     */
    public static function parse(string $url): ?array
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if (strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https' || ! str_ends_with($host, '.artifacts.cloudflare.net')) {
            return null;
        }
        if (preg_match('#^/git/([^/]+)/([^/]+?)(?:\.git)?/?$#', (string) parse_url($url, PHP_URL_PATH), $m) !== 1) {
            return null;
        }

        return ['namespace' => rawurldecode($m[1]), 'repo' => rawurldecode($m[2])];
    }

    public static function isRemote(string $url): bool
    {
        return self::parse($url) !== null;
    }

    public static function siteUses(Site $site): bool
    {
        return self::isRemote((string) ($site->edgeMeta()['source']['repo'] ?? ''));
    }

    /**
     * Git env for a build clone: a short-lived read token as Basic auth
     * (Artifacts ignores the username), in the same shape GitCloneAuth uses
     * for GitHub so {@see GitCloneAuth::redact()} masks it in build logs.
     *
     * @return array<string, string>
     */
    public function cloneEnv(string $url): array
    {
        $repo = self::parse($url);
        if ($repo === null) {
            return [];
        }

        $token = $this->client->createToken($repo['namespace'], $repo['repo'], 'read', (int) config('edge.git.read_token_ttl', 3600));

        return self::envForToken($url, $token['plaintext']);
    }

    /**
     * @return array<string, string>
     */
    public static function envForToken(string $url, string $token): array
    {
        return [
            'GIT_TERMINAL_PROMPT' => '0',
            'GIT_CONFIG_COUNT' => '1',
            'GIT_CONFIG_KEY_0' => 'http.https://'.strtolower((string) parse_url($url, PHP_URL_HOST)).'/.extraheader',
            'GIT_CONFIG_VALUE_0' => 'Authorization: Basic '.base64_encode('x:'.$token),
        ];
    }

    /**
     * Revoke every token on the site's repo — people's, agents' and any
     * build's read token (a running build may then fail; redeploy it).
     * Returns how many were revoked.
     */
    public function revokeAllForSite(Site $site): int
    {
        $repo = self::parse((string) ($site->edgeMeta()['source']['repo'] ?? ''));
        if ($repo === null) {
            return 0;
        }
        $ids = $this->client->activeTokenIds($repo['namespace'], $repo['repo']);
        foreach ($ids as $id) {
            $this->client->revokeToken($repo['namespace'], $id);
        }

        return count($ids);
    }

    /**
     * A token for a person or agent to clone/push with.
     *
     * @param  'read'|'write'  $scope
     * @return array{remote: string, token: string, token_id: string, scope: string, expires_at: ?string}
     */
    public function tokenForSite(Site $site, string $scope = 'write', ?int $ttlSeconds = null): array
    {
        $remote = (string) ($site->edgeMeta()['source']['repo'] ?? '');
        $repo = self::parse($remote);
        if ($repo === null) {
            throw new \RuntimeException(__('This app is not on dply Git.'));
        }

        $token = $this->client->createToken($repo['namespace'], $repo['repo'], $scope, $ttlSeconds ?? (int) config('edge.git.push_token_ttl', 2_592_000));

        return [
            'remote' => $remote,
            'token' => $token['plaintext'],
            'token_id' => $token['id'],
            'scope' => $token['scope'],
            'expires_at' => $token['expires_at'],
        ];
    }
}
