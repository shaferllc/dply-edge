<?php

declare(strict_types=1);

namespace App\Services\Concerns;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesDoSpacesRegistry
{
    /**
     * Create a Spaces access key via the Spaces Keys API. The secret is only
     * ever returned at creation time, so the caller must capture it.
     *
     * Grants scope the key to specific buckets. IMPORTANT: a DigitalOcean
     * Spaces key created with NO grants has NO access (every S3 call returns
     * AccessDenied) — an empty grant list is NOT "full access". So when the
     * caller passes no grants we default to a full-access grant on all buckets
     * (`bucket: ""`, `permission: "fullaccess"`), which is what lets the key
     * create buckets and read/write objects like a console-created key.
     *
     * @param  list<array{bucket: string, permission: string}>  $grants
     * @return array{access_key: string, secret_key: string}
     */
    public function createSpacesKey(string $name, array $grants = []): array
    {
        if ($grants === []) {
            $grants = [['bucket' => '', 'permission' => 'fullaccess']];
        }

        $payload = array_filter([
            'name' => $name !== '' ? $name : null,
            'grants' => $grants,
        ], fn ($v) => $v !== null);

        $response = $this->request('post', '/spaces/keys', $payload);
        $this->assertSuccess($response, 'create Spaces key');

        $key = $response->json('key');
        if (! is_array($key)) {
            $key = $response->json();
        }

        $accessKey = is_array($key) ? (string) ($key['access_key'] ?? '') : '';
        $secret = is_array($key) ? (string) ($key['secret_key'] ?? '') : '';
        if ($accessKey === '' || $secret === '') {
            throw new \RuntimeException('DigitalOcean did not return Spaces key credentials.');
        }

        return ['access_key' => $accessKey, 'secret_key' => $secret];
    }

    /**
     * Revoke a Spaces access key.
     *
     * A 404 means it is already gone, which is the outcome a teardown wanted,
     * so it reports false rather than raising. Every other failure raises —
     * a key that outlives its bucket is a live credential nobody is watching.
     */
    public function deleteSpacesKey(string $accessKey): bool
    {
        $accessKey = trim($accessKey);
        if ($accessKey === '') {
            return false;
        }

        $response = $this->request('delete', '/spaces/keys/'.rawurlencode($accessKey));
        if ($response->status() === 404) {
            return false;
        }

        $this->assertSuccess($response, 'delete Spaces key');

        return true;
    }

    /**
     * Create a container registry in DigitalOcean.
     *
     * @return array<string, mixed>
     */
    public function createContainerRegistry(string $name, string $subscriptionTier = 'starter'): array
    {
        $response = $this->request('post', '/registry', [
            'name' => $name,
            'subscription_tier_slug' => $subscriptionTier,
        ]);
        $this->assertSuccess($response, 'create container registry');

        $registry = $response->json('registry');

        return is_array($registry) ? $registry : [];
    }

    /**
     * Get container registry details.
     *
     * @return array<string, mixed>|null
     */
    public function getContainerRegistry(string $name): ?array
    {
        $response = $this->request('get', '/registry/'.$name);

        if ($response->status() === 404) {
            return null;
        }

        $this->assertSuccess($response, 'get container registry');

        $registry = $response->json('registry');

        return is_array($registry) ? $registry : null;
    }

    /**
     * Get Docker credentials for registry authentication.
     *
     * @return array{auths: array<string, array{auth: string}>}
     */
    public function getContainerRegistryCredentials(): array
    {
        $response = $this->request('get', '/registry/docker-credentials');
        $this->assertSuccess($response, 'get registry credentials');

        $dockerConfig = $response->json();

        return is_array($dockerConfig) ? $dockerConfig : [];
    }
}
