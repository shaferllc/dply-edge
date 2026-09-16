<?php

declare(strict_types=1);

namespace App\Modules\Secrets\Services\Contracts;

/**
 * A durable backend that holds age-encrypted blobs + their plaintext sidecars.
 * All stores share the same key layout so a blob written by one (or by the bash
 * guards) is listable/restorable from any other.
 */
interface VaultStore
{
    public function name(): string;

    /** True when configured/usable; the vault skips disabled stores. */
    public function enabled(): bool;

    /**
     * Persist ciphertext at `$key` plus its sidecar at `$key.meta.json`.
     *
     * @param  array<string, mixed>  $meta
     */
    public function put(string $key, string $ciphertext, array $meta): void;

    /** Fetch ciphertext for `$key`. */
    public function get(string $key): string;

    /**
     * List blobs under `$prefix`, newest first, read from sidecars.
     *
     * @return array<int, array{key: string, meta: array<string, mixed>}>
     */
    public function list(string $prefix): array;
}
