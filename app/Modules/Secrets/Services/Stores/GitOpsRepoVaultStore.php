<?php

declare(strict_types=1);

namespace App\Modules\Secrets\Services\Stores;

use App\Modules\Secrets\Services\Contracts\VaultStore;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Secondary store: a private ops git repo holding ciphertext-only blobs +
 * plaintext sidecars. Versioned + diffable, off the prod infra. Best-effort —
 * the vault logs and continues if git is unavailable as long as the object
 * store succeeded.
 */
final class GitOpsRepoVaultStore implements VaultStore
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(private readonly array $config) {}

    public function name(): string
    {
        return 'git';
    }

    public function enabled(): bool
    {
        return (bool) ($this->config['enabled'] ?? false)
            && trim((string) ($this->config['repo'] ?? '')) !== '';
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function put(string $key, string $ciphertext, array $meta): void
    {
        $dir = $this->ensureClone();
        $this->writeFile($dir, $key, $ciphertext);
        $this->writeFile($dir, $key.'.meta.json', json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->git($dir, ['add', '-A']);
        $this->git($dir, ['commit', '-m', 'escrow: '.$key], allowFailure: true); // no-op commit is fine
        $this->git($dir, ['push', 'origin', $this->branch()]);
    }

    public function get(string $key): string
    {
        $dir = $this->ensureClone();
        $path = $dir.'/'.$key;
        if (! is_file($path)) {
            throw new RuntimeException("git store missing key: {$key}");
        }

        return (string) file_get_contents($path);
    }

    /** @return array<string, mixed> */
    /**
     * @return list<array<string, array<mixed>|string>>
     */
    public function list(string $prefix): array
    {
        $dir = $this->ensureClone();
        $base = $dir.'/'.$prefix;
        if (! is_dir($base)) {
            return [];
        }

        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.meta.json')) {
                continue;
            }
            $meta = json_decode((string) file_get_contents($file->getPathname()), true);
            if (! is_array($meta)) {
                continue;
            }
            $logical = ltrim(str_replace($dir, '', substr($file->getPathname(), 0, -strlen('.meta.json'))), '/');
            $out[] = ['key' => $logical, 'meta' => $meta];
        }

        usort($out, fn ($a, $b) => strcmp((string) ($b['meta']['created_at'] ?? ''), (string) ($a['meta']['created_at'] ?? '')));

        return $out;
    }

    private function branch(): string
    {
        return (string) ($this->config['branch'] ?? 'main');
    }

    private function ensureClone(): string
    {
        $dir = (string) $this->config['work_dir'];

        if (is_dir($dir.'/.git')) {
            $this->git($dir, ['pull', '--ff-only', 'origin', $this->branch()], allowFailure: true);

            return $dir;
        }

        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        $result = Process::timeout(120)->run(['git', 'clone', '--branch', $this->branch(), (string) $this->config['repo'], $dir]);
        if (! $result->successful()) {
            throw new RuntimeException('git clone failed: '.trim($result->errorOutput()));
        }

        return $dir;
    }

    private function writeFile(string $dir, string $key, string $contents): void
    {
        $path = $dir.'/'.$key;
        $parent = dirname($path);
        if (! is_dir($parent)) {
            mkdir($parent, 0700, true);
        }
        file_put_contents($path, $contents);
    }

    /**
     * @param  array<int, string>  $args
     */
    private function git(string $dir, array $args, bool $allowFailure = false): void
    {
        $result = Process::path($dir)->timeout(120)->run(array_merge(['git'], $args));
        if (! $allowFailure && ! $result->successful()) {
            throw new RuntimeException('git '.implode(' ', $args).' failed: '.trim($result->errorOutput()));
        }
    }
}
