<?php

declare(strict_types=1);

namespace App\Modules\SourceControl\Services\Concerns;

use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Str;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\MarkdownConverter;
use Throwable;

/**
 * Provider-agnostic helpers for {@see SourceControlRepositoryReader}:
 * remote-URL parsing, entry sorting, blob classification and markdown rendering.
 */
trait ParsesGitRemotes
{
    /**
     * @return array<string, mixed>
     */
    private function remoteForSite(Site $site): ?array
    {
        return $this->parseRemoteUrl($site->sourceControlRepositoryUrl());
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
            $path = (string) preg_replace('/\.git$/', '', substr($url, $colonPos + 1));

            return $this->remoteFromHostAndPath($host, $path);
        }
        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['host'])) {
            return null;
        }
        $host = strtolower((string) $parts['host']);
        $path = trim((string) ($parts['path'] ?? ''), '/');
        $path = (string) preg_replace('/\.git$/', '', $path);

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

            return ['provider' => 'github', 'owner' => $segments[0], 'repo' => $segments[1], 'label' => $segments[0].'/'.$segments[1]];
        }
        if (str_contains($host, 'bitbucket.org')) {
            $segments = explode('/', $path);
            if (count($segments) < 2) {
                return null;
            }

            return ['provider' => 'bitbucket', 'workspace' => $segments[0], 'repo' => $segments[1], 'label' => $segments[0].'/'.$segments[1]];
        }
        if (str_contains($host, 'gitlab')) {
            return ['provider' => 'gitlab', 'project_path' => $path, 'gitlab_api_base' => 'https://'.$host, 'label' => $path];
        }

        return null;
    }

    private function encodePath(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     * @return list<mixed>
     */
    private function sortEntries(array $entries): array
    {
        usort($entries, function (array $a, array $b): int {
            if ($a['type'] !== $b['type']) {
                return $a['type'] === 'dir' ? -1 : 1;
            }

            return strcasecmp($a['name'], $b['name']);
        });

        // array_values() is idempotent; this was nested 32 deep, which cost 32
        // redundant array copies per listing at runtime and made PHPStan
        // re-infer the entry shape 32 times over — the whole app/Modules run
        // went from >22min to seconds once this collapsed to a single call.
        return $entries;
    }

    private function looksBinary(string $raw): bool
    {
        if ($raw === '') {
            return false;
        }
        $sample = substr($raw, 0, 1024);

        return str_contains($sample, "\0");
    }

    private function formatApiError(int $status, string $body): string
    {
        $snippet = Str::limit(trim($body), 200);

        return __('Git provider returned :status.', ['status' => (string) $status]).($snippet !== '' ? ' '.$snippet : '');
    }

    /**
     * @return array<string, mixed>
     */
    private function buildFileResult(string $raw, int $size, string $htmlUrl, string $provider): array
    {
        $binary = $this->looksBinary($raw);
        if ($size === 0) {
            $size = strlen($raw);
        }

        return [
            'ok' => true,
            'content' => $binary ? '' : $raw,
            'size' => $size,
            'too_large' => false,
            'binary' => $binary,
            'html_url' => $htmlUrl,
            'error' => null,
            'provider' => $provider,
        ];
    }

    private function renderMarkdown(string $raw): string
    {
        try {
            $environment = new Environment([
                'html_input' => 'escape',
                'allow_unsafe_links' => false,
            ]);
            $environment->addExtension(new CommonMarkCoreExtension);
            $environment->addExtension(new GithubFlavoredMarkdownExtension);
            $converter = new MarkdownConverter($environment);

            return (string) $converter->convert($raw);
        } catch (Throwable) {
            return '<pre>'.e($raw).'</pre>';
        }
    }

    /**
     * @param  array<string, mixed>  $remote
     * @return array<string, mixed>
     */
    private function probeReadmeViaFile(array $remote, Site $site, User $user, string $branch, string $provider): array
    {
        foreach (['README.md', 'readme.md', 'Readme.md', 'README', 'README.rst', 'README.txt'] as $candidate) {
            $file = match ($provider) {
                'gitlab' => $this->gitlabFile($remote, $site, $user, $branch, $candidate),
                'bitbucket' => $this->bitbucketFile($remote, $site, $user, $branch, $candidate),
                default => null,
            };
            if (! is_array($file) || ! ($file['ok'] ?? false) || ($file['too_large'] ?? false) || ($file['binary'] ?? false)) {
                continue;
            }
            $raw = (string) ($file['content'] ?? '');
            if ($raw === '') {
                continue;
            }

            return [
                'ok' => true,
                'name' => $candidate,
                'content_html' => $this->renderMarkdown($raw),
                'content_raw' => $raw,
                'error' => null,
                'provider' => $provider,
            ];
        }

        return ['ok' => true, 'name' => null, 'content_html' => '', 'content_raw' => '', 'error' => null, 'provider' => $provider];
    }
}
