<?php

declare(strict_types=1);

namespace App\Modules\Edge\Support;

use RuntimeException;

/**
 * Normalizes and applies per-site monorepo roots stored on Edge sites
 * at edge.source.repo_root (relative to the git checkout root).
 */
final class EdgeRepoRoot
{
    /** @var list<string> */
    public const CONFIG_FILENAMES = [
        'dply.toml',
        'dply.yaml',
        'dply.yml',
        'dply.json',
    ];

    public static function normalize(?string $value): string
    {
        if (! is_string($value)) {
            return '';
        }

        $value = trim(str_replace('\\', '/', trim((string) $value)), '/');
        if ($value === '' || str_contains($value, '..')) {
            return '';
        }

        return $value;
    }

    public static function isValid(?string $value): bool
    {
        $normalized = self::normalize($value);

        return $value === null
            || trim((string) $value) === ''
            || $normalized !== '';
    }

    public static function applyToCheckout(string $checkout, ?string $repoRoot, callable $log): string
    {
        $repoRoot = self::normalize($repoRoot);
        if ($repoRoot === '') {
            return $checkout;
        }

        $candidate = rtrim($checkout, '/').'/'.$repoRoot;
        if (! is_dir($candidate)) {
            throw new RuntimeException('Repository root "'.$repoRoot.'" was not found in the checkout.');
        }

        $log('Using site repo_root: '.$repoRoot);

        return $candidate;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    public static function changedFilesFromPushPayload(array $payload): array
    {
        $files = [];

        $commits = is_array($payload['commits'] ?? null) ? $payload['commits'] : [];
        foreach ($commits as $commit) {
            if (! is_array($commit)) {
                continue;
            }
            foreach (['added', 'modified', 'removed'] as $key) {
                $paths = is_array($commit[$key] ?? null) ? $commit[$key] : [];
                foreach ($paths as $path) {
                    if (is_string($path) && $path !== '') {
                        $files[] = str_replace('\\', '/', $path);
                    }
                }
            }
        }

        $head = is_array($payload['head_commit'] ?? null) ? $payload['head_commit'] : null;
        if ($head !== null) {
            foreach (['added', 'modified', 'removed'] as $key) {
                $paths = is_array($head[$key] ?? null) ? $head[$key] : [];
                foreach ($paths as $path) {
                    if (is_string($path) && $path !== '') {
                        $files[] = str_replace('\\', '/', $path);
                    }
                }
            }
        }

        return array_values(array_unique($files));
    }

    /**
     * When repo_root is empty, any push to the source branch redeploys.
     * When set, only changes under repo_root/** or repo-level dply config
     * files trigger a production redeploy.
     *
     * @param  list<string>  $changedFiles
     */
    public static function pushTouchesSite(string $repoRoot, array $changedFiles): bool
    {
        if ($changedFiles === []) {
            return true;
        }

        $repoRoot = self::normalize($repoRoot);
        if ($repoRoot === '') {
            return true;
        }

        $prefix = $repoRoot.'/';
        foreach ($changedFiles as $file) {
            if (self::isRepoConfigPath($file, $repoRoot)) {
                return true;
            }

            if ($file === $repoRoot || str_starts_with($file, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private static function isRepoConfigPath(string $file, string $repoRoot): bool
    {
        foreach (self::CONFIG_FILENAMES as $name) {
            if ($file === $name) {
                return true;
            }

            if ($repoRoot !== '' && $file === $repoRoot.'/'.$name) {
                return true;
            }
        }

        return false;
    }
}
