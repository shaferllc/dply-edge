<?php

declare(strict_types=1);

namespace App\Modules\Secrets\Services\Stores;

use App\Modules\Secrets\Services\Contracts\VaultStore;
use Aws\S3\S3Client;
use RuntimeException;

/**
 * Primary store: a versioned + object-locked bucket in a SEPARATE cloud account.
 * S3 wiring mirrors {@see DatabaseBackupS3ClientFactory}.
 * The box's IAM should be PutObject + ListBucket only (no Get/Delete) so a
 * compromised box can neither exfiltrate prior ciphertext nor erase history;
 * Get is used only from the isolated drill/restore host.
 */
final class ObjectStorageVaultStore implements VaultStore
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(private readonly array $config) {}

    public function name(): string
    {
        return 'object';
    }

    public function enabled(): bool
    {
        return (bool) ($this->config['enabled'] ?? false)
            && trim((string) ($this->config['bucket'] ?? '')) !== ''
            && trim((string) ($this->config['access_key'] ?? '')) !== ''
            && trim((string) ($this->config['secret'] ?? '')) !== '';
    }

    public function put(string $key, string $ciphertext, array $meta): void
    {
        $client = $this->client();
        $bucket = (string) $this->config['bucket'];

        $client->putObject([
            'Bucket' => $bucket,
            'Key' => $this->objectKey($key),
            'Body' => $ciphertext,
            'ContentType' => 'application/octet-stream',
        ]);

        $client->putObject([
            'Bucket' => $bucket,
            'Key' => $this->objectKey($key.'.meta.json'),
            'Body' => json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            'ContentType' => 'application/json',
        ]);
    }

    public function get(string $key): string
    {
        $result = $this->client()->getObject([
            'Bucket' => (string) $this->config['bucket'],
            'Key' => $this->objectKey($key),
        ]);

        return (string) $result['Body'];
    }

    public function list(string $prefix): array
    {
        $client = $this->client();
        $bucket = (string) $this->config['bucket'];
        $fullPrefix = $this->objectKey($prefix);

        $out = [];
        $token = null;
        do {
            $args = ['Bucket' => $bucket, 'Prefix' => $fullPrefix];
            if ($token !== null) {
                $args['ContinuationToken'] = $token;
            }
            $page = $client->listObjectsV2($args);

            foreach ($page['Contents'] ?? [] as $object) {
                $objKey = (string) $object['Key'];
                if (! str_ends_with($objKey, '.meta.json')) {
                    continue;
                }
                $sidecar = $client->getObject(['Bucket' => $bucket, 'Key' => $objKey]);
                $meta = json_decode((string) $sidecar['Body'], true);
                if (! is_array($meta)) {
                    continue;
                }
                $logicalKey = $this->stripRoot(substr($objKey, 0, -strlen('.meta.json')));
                $out[] = ['key' => $logicalKey, 'meta' => $meta];
            }

            $token = ($page['IsTruncated'] ?? false) ? ($page['NextContinuationToken'] ?? null) : null;
        } while ($token !== null);

        usort($out, fn ($a, $b) => strcmp((string) ($b['meta']['created_at'] ?? ''), (string) ($a['meta']['created_at'] ?? '')));

        return $out;
    }

    private function client(): S3Client
    {
        if (! $this->enabled()) {
            throw new RuntimeException('object-store is not configured.');
        }

        $region = trim((string) ($this->config['region'] ?? '')) ?: 'us-east-1';
        $args = [
            'version' => 'latest',
            'region' => $region,
            'credentials' => [
                'key' => (string) $this->config['access_key'],
                'secret' => (string) $this->config['secret'],
            ],
        ];
        $endpoint = trim((string) ($this->config['endpoint'] ?? ''));
        if ($endpoint !== '') {
            $args['endpoint'] = $endpoint;
        }
        if ((bool) ($this->config['use_path_style'] ?? false)) {
            $args['use_path_style_endpoint'] = true;
        }

        return new S3Client($args);
    }

    private function rootPrefix(): string
    {
        return trim((string) ($this->config['path'] ?? ''), '/');
    }

    private function objectKey(string $key): string
    {
        $root = $this->rootPrefix();

        return $root === '' ? $key : $root.'/'.$key;
    }

    private function stripRoot(string $objectKey): string
    {
        $root = $this->rootPrefix();

        return $root !== '' && str_starts_with($objectKey, $root.'/')
            ? substr($objectKey, strlen($root) + 1)
            : $objectKey;
    }
}
