<?php

declare(strict_types=1);

namespace App\Modules\Edge\Services\RuntimeDetection;

use App\Modules\SourceControl\Services\GitIdentityResolver;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Fast path: build a tiny on-disk skeleton of the repo by pulling just the
 * files runtime detection cares about via the GitHub Contents API. Skips
 * the multi-second `git clone` for Node/static Edge repos by trading
 * one ~1 MB clone for ~25 small ~10 KB HTTP requests in parallel.
 *
 * Returns the temp directory path on success (caller runs the existing
 * {@see RepositoryRuntimePlanComposer} against it and deletes it after),
 * or null when the repo isn't on GitHub / package.json is missing / the
 * API errors. Callers fall back to the clone-based path.
 */
final class GitHubFastDetectFetcher
{
    /** Detection-relevant paths to probe in the repo root. */
    private const PROBE_PATHS = [
        // Node manifest + lockfiles (existence drives PM detection)
        'package.json',
        'pnpm-lock.yaml',
        'yarn.lock',
        'package-lock.json',
        'bun.lock',
        'bun.lockb',
        // Node version hints
        '.nvmrc',
        '.node-version',
        '.tool-versions',
        // dply manifest
        'dply.yaml',
        'dply.yml',
        // Cloudflare Worker / Keel entry
        'wrangler.toml',
        'wrangler.jsonc',
        'wrangler.json',
        'worker.ts',
        'bootstrap/app.ts',
        // Static-site signals
        'index.html',
        '_config.yml',
        'hugo.toml',
        'config.toml',
        // Common framework config files
        'astro.config.js',
        'astro.config.mjs',
        'astro.config.ts',
        'next.config.js',
        'next.config.mjs',
        'next.config.ts',
        'nuxt.config.js',
        'nuxt.config.ts',
        'vite.config.js',
        'vite.config.ts',
        'svelte.config.js',
        'gatsby-config.js',
        'gatsby-config.ts',
        // Python (lite — full Python detection still falls back to clone)
        'requirements.txt',
        'pyproject.toml',
        // Ruby (lite — same)
        'Gemfile',
    ];

    public function fetch(string $owner, string $repo, string $branch = 'main'): ?string
    {
        $branch = trim($branch) !== '' ? trim($branch) : 'main';
        $owner = trim($owner);
        $repo = trim($repo);
        if ($owner === '' || $repo === '') {
            return null;
        }

        $client = $this->buildClient();

        // Parallel fetch — Http::pool batches concurrent requests. 25
        // small JSON responses come back in ~200-400ms vs a multi-second
        // clone for a fat monorepo.
        $responses = Http::pool(function (Pool $pool) use ($client, $owner, $repo, $branch): array {
            $reqs = [];
            foreach (self::PROBE_PATHS as $path) {
                $reqs[$path] = $pool->as($path)
                    ->withHeaders($client['headers'])
                    ->when(! empty($client['token']), fn ($r) => $r->withToken($client['token']))
                    ->baseUrl('https://api.github.com')
                    ->acceptJson()
                    ->timeout(10)
                    ->get("/repos/{$owner}/{$repo}/contents/{$path}", ['ref' => $branch]);
            }

            return $reqs;
        });

        // package.json is the minimum signal — without it we can't
        // detect anything useful, so bail to the clone fallback.
        $packageJson = $responses['package.json'] ?? null;
        if ($packageJson === null || ! $packageJson->successful()) {
            return null;
        }

        // Materialize a temp dir + write each successful response's
        // decoded content. RepositoryRuntimePlanComposer will then read
        // it just like a normal checkout.
        $tmp = rtrim(sys_get_temp_dir(), '/').'/dply-fastdetect-'.bin2hex(random_bytes(6));
        if (! @mkdir($tmp, 0o700, true) && ! is_dir($tmp)) {
            return null;
        }

        try {
            foreach (self::PROBE_PATHS as $path) {
                $response = $responses[$path] ?? null;
                if ($response === null || ! $response->successful()) {
                    continue; // 404 / missing — fine, just skip
                }
                $json = $response->json();
                if (! is_array($json) || ! isset($json['content'])) {
                    continue;
                }
                $decoded = base64_decode((string) preg_replace('/\s+/', '', (string) $json['content']), true);
                if ($decoded === false) {
                    continue;
                }
                $absolute = $tmp.'/'.$path;
                $parent = dirname($absolute);
                if (! is_dir($parent) && ! @mkdir($parent, 0o700, true) && ! is_dir($parent)) {
                    continue;
                }
                @file_put_contents($absolute, $decoded);
            }
        } catch (Throwable) {
            $this->deleteRecursive($tmp);

            return null;
        }

        return $tmp;
    }

    /**
     * @return array{token: ?string, headers: array<string, string>}
     */
    private function buildClient(): array
    {
        $headers = ['X-GitHub-Api-Version' => '2022-11-28'];
        $token = null;

        $user = auth()->user();
        if ($user !== null) {
            try {
                $identity = app(GitIdentityResolver::class)->forUserProvider($user, 'github');
                $accessToken = $identity?->accessToken();
                if (is_string($accessToken) && $accessToken !== '') {
                    $token = $accessToken;
                }
            } catch (Throwable) {
                // Unauthed is fine for public repos.
            }
        }

        return ['token' => $token, 'headers' => $headers];
    }

    public function deleteRecursive(string $path): void
    {
        if (! file_exists($path) && ! is_link($path)) {
            return;
        }
        if (is_link($path) || ! is_dir($path)) {
            @unlink($path);

            return;
        }
        $entries = @scandir($path);
        if ($entries === false) {
            return;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->deleteRecursive($path.'/'.$entry);
        }
        @rmdir($path);
    }
}
