<?php

declare(strict_types=1);

namespace App\Modules\Secrets\Services;

/**
 * A pointer to one escrowed blob. Built from the plaintext `.meta.json` sidecar
 * (so listing never decrypts) or at escrow time. `stores` records which
 * backends hold this key.
 */
final class VaultBlobRef
{
    /**
     * @param  list<string>  $stores
     */
    public function __construct(
        public readonly string $key,
        public readonly string $scope,
        public readonly string $source,
        public readonly string $createdAt,
        public readonly ?string $plaintextSha256 = null,
        public readonly ?int $byteLen = null,
        public array $stores = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toMeta(string $writer): array
    {
        return [
            'schema' => 1,
            'scope' => $this->scope,
            'source' => $this->source,
            'created_at' => $this->createdAt,
            'writer' => $writer,
            'plaintext_sha256' => $this->plaintextSha256,
            'byte_len' => $this->byteLen,
        ];
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  list<string>  $stores
     */
    public static function fromMeta(string $key, array $meta, array $stores = []): self
    {
        return new self(
            key: $key,
            scope: (string) ($meta['scope'] ?? ''),
            source: (string) ($meta['source'] ?? ''),
            createdAt: (string) ($meta['created_at'] ?? ''),
            plaintextSha256: isset($meta['plaintext_sha256']) ? (string) $meta['plaintext_sha256'] : null,
            byteLen: isset($meta['byte_len']) ? (int) $meta['byte_len'] : null,
            stores: $stores,
        );
    }
}
