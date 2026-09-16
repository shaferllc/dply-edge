<?php

declare(strict_types=1);

namespace App\Services\Concerns;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesDoDomainsSshKeys
{
    /**
     * List account SSH keys.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getSshKeys(): array
    {
        $response = $this->request('get', '/account/keys');
        $this->assertSuccess($response, 'list SSH keys');
        $data = $response->json();
        $keys = $data['ssh_keys'] ?? $data['data'] ?? [];

        return is_array($keys) ? $keys : [];
    }

    /**
     * Add an SSH public key to the account. Returns key array with id.
     *
     * @return array<string, mixed>
     */
    public function addSshKey(string $name, string $publicKey): array
    {
        $response = $this->request('post', '/account/keys', [
            'name' => $name,
            'public_key' => trim($publicKey),
        ]);
        $this->assertSuccess($response, 'create SSH key');
        $data = $response->json();
        $key = $data['ssh_key'] ?? $data;
        if (empty($key) || ! is_array($key)) {
            throw new \RuntimeException('DigitalOcean API did not return SSH key.');
        }

        return $key;
    }

    /**
     * Delete an account SSH key by its DO numeric id or fingerprint.
     */
    public function deleteSshKey(int|string $idOrFingerprint): void
    {
        $value = is_string($idOrFingerprint) ? trim($idOrFingerprint) : (string) $idOrFingerprint;
        if ($value === '') {
            throw new \InvalidArgumentException('SSH key id or fingerprint is required.');
        }

        $response = $this->request('delete', '/account/keys/'.rawurlencode($value));
        $this->assertSuccess($response, 'delete SSH key');
    }

    /**
     * Whether the domain exists in this DigitalOcean account (Networking → Domains).
     */
    public function domainExistsInAccount(string $domain): bool
    {
        return $this->fetchDomain($domain) !== null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function fetchDomain(string $domain): ?array
    {
        $domain = strtolower(trim($domain));
        if ($domain === '') {
            return null;
        }

        $encoded = rawurlencode($domain);
        $response = $this->request('get', '/domains/'.$encoded);
        if ($response->status() === 404) {
            return null;
        }
        $this->assertSuccess($response, 'get domain');
        $data = $response->json();
        $payload = $data['domain'] ?? null;

        return is_array($payload) ? $payload : null;
    }

    /**
     * List every DNS record in a zone, following DO's pagination.
     *
     * DO paginates record lists (20 per page by default). Returning only the
     * first page silently truncates large zones — and callers that purge
     * conflicting records before a CNAME write MUST see every record, or DO
     * rejects the create with "CNAME records cannot share a name with other
     * records". So we request the max page size and walk `links.pages.next`
     * until the zone is exhausted.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getDomainRecords(string $domain, array $query = []): array
    {
        $all = [];
        $page = 1;

        // Hard cap at 50 pages (× 200 = 10k records) so a malformed
        // pagination response can never spin this loop forever.
        do {
            $response = $this->request('get', '/domains/'.$domain.'/records', array_merge($query, [
                'per_page' => 200,
                'page' => $page,
            ]));
            $this->assertSuccess($response, 'list domain records');
            $data = $response->json();
            $records = $data['domain_records'] ?? $data['data'] ?? [];

            if (is_array($records)) {
                foreach ($records as $record) {
                    $all[] = $record;
                }
            }

            $hasNext = is_array($records) && $records !== []
                && is_string(data_get($data, 'links.pages.next'));
            $page++;
        } while ($hasNext && $page <= 50);

        return $all;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findDomainRecord(string $domain, string $type, string $name, ?string $data = null): ?array
    {
        $type = strtoupper($type);
        $records = $this->getDomainRecords($domain, ['type' => $type, 'name' => $name]);

        if ($records === []) {
            $records = $this->getDomainRecords($domain);
        }

        foreach ($records as $record) {
            if (strtoupper((string) ($record['type'] ?? '')) !== $type) {
                continue;
            }

            if ((string) ($record['name'] ?? '') !== $name) {
                continue;
            }

            if ($data !== null && (string) ($record['data'] ?? '') !== $data) {
                continue;
            }

            return $record;
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function createDomainRecord(
        string $domain,
        string $type,
        string $name,
        string $data,
        int $ttl = 60
    ): array {
        $response = $this->request('post', '/domains/'.$domain.'/records', [
            'type' => strtoupper($type),
            'name' => $name,
            'data' => $data,
            'ttl' => $ttl,
        ]);
        $this->assertSuccess($response, 'create domain record');
        $payload = $response->json();
        $record = $payload['domain_record'] ?? $payload;

        if (! is_array($record) || $record === []) {
            throw new \RuntimeException('DigitalOcean API did not return a domain record.');
        }

        return $record;
    }

    public function deleteDomainRecord(string $domain, int $recordId): void
    {
        $response = $this->request('delete', '/domains/'.$domain.'/records/'.$recordId);
        $this->assertSuccess($response, 'delete domain record');
    }
}
