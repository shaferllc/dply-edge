<?php

declare(strict_types=1);

namespace App\Modules\Secrets\Services\Stores;

use App\Modules\Secrets\Services\Contracts\VaultStore;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Tertiary store: 1Password documents via the `op` CLI, for emergency
 * human-accessible recovery alongside where the offline recovery key lives.
 * Best-effort — failures here never fail an escrow that already hit the object
 * store. The blob key is the document title; the sidecar is a sibling document.
 */
final class OnePasswordVaultStore implements VaultStore
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(private readonly array $config) {}

    public function name(): string
    {
        return 'onepassword';
    }

    public function enabled(): bool
    {
        return (bool) ($this->config['enabled'] ?? false)
            && trim((string) ($this->config['vault'] ?? '')) !== '';
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function put(string $key, string $ciphertext, array $meta): void
    {
        $this->createDocument($key, $ciphertext);
        $this->createDocument($key.'.meta.json', (string) json_encode($meta, JSON_UNESCAPED_SLASHES));
    }

    public function get(string $key): string
    {
        $result = $this->op(['document', 'get', $key, '--vault', $this->vault()]);
        if (! $result->successful()) {
            throw new RuntimeException('op document get failed: '.trim($result->errorOutput()));
        }

        return $result->output();
    }

    /** @return array<string, mixed> */
    /**
     * @return list<array<string, array<mixed>|string>>
     */
    public function list(string $prefix): array
    {
        $result = $this->op(['document', 'list', '--vault', $this->vault(), '--format', 'json']);
        if (! $result->successful()) {
            return [];
        }

        $items = json_decode($result->output(), true);
        if (! is_array($items)) {
            return [];
        }

        $out = [];
        foreach ($items as $item) {
            $title = (string) ($item['title'] ?? '');
            if (! str_starts_with($title, $prefix) || ! str_ends_with($title, '.meta.json')) {
                continue;
            }
            $sidecar = $this->op(['document', 'get', $title, '--vault', $this->vault()]);
            if (! $sidecar->successful()) {
                continue;
            }
            $meta = json_decode($sidecar->output(), true);
            if (! is_array($meta)) {
                continue;
            }
            $out[] = ['key' => substr($title, 0, -strlen('.meta.json')), 'meta' => $meta];
        }

        usort($out, fn ($a, $b) => strcmp((string) ($b['meta']['created_at'] ?? ''), (string) ($a['meta']['created_at'] ?? '')));

        return $out;
    }

    private function vault(): string
    {
        return (string) $this->config['vault'];
    }

    private function createDocument(string $title, string $contents): void
    {
        $result = Process::input($contents)->timeout(60)->run([
            $this->opBin(), 'document', 'create', '-', '--title', $title, '--vault', $this->vault(),
        ]);
        if (! $result->successful()) {
            throw new RuntimeException('op document create failed: '.trim($result->errorOutput()));
        }
    }

    /**
     * @param  array<int, string>  $args
     */
    private function op(array $args): ProcessResult
    {
        return Process::timeout(60)->run(array_merge([$this->opBin()], $args));
    }

    private function opBin(): string
    {
        return (string) ($this->config['op_bin'] ?? 'op');
    }
}
