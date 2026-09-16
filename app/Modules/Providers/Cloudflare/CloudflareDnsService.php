<?php

namespace App\Modules\Providers\Cloudflare;

use App\Models\ProviderCredential;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Cloudflare DNS API (API token auth). Used for site DNS automation when zone lives in Cloudflare.
 *
 * @see https://developers.cloudflare.com/api/
 */
class CloudflareDnsService
{
    private const BASE = 'https://api.cloudflare.com/client/v4';

    private string $bearerToken;

    /**
     * @param  ProviderCredential|non-empty-string  $credentialOrToken
     */
    public function __construct(ProviderCredential|string $credentialOrToken)
    {
        $token = $credentialOrToken instanceof ProviderCredential
            ? $credentialOrToken->getApiToken()
            : $credentialOrToken;
        $token = is_string($token) ? trim($token) : '';
        if ($token === '') {
            throw new \InvalidArgumentException('Cloudflare API token is required.');
        }
        $this->bearerToken = $token;
    }

    public function verifyToken(): void
    {
        // Verify by listing zones rather than /user/tokens/verify: the latter only
        // validates USER-owned tokens, so an account-owned token (cfat_…) — valid
        // for DNS — fails there as "Invalid API Token". Listing zones exercises the
        // Zone:Zone:Read permission dply actually needs and works for both token
        // kinds. An empty zone list is still a valid token.
        $response = $this->request('get', '/zones', ['per_page' => 1]);
        $this->assertApiSuccess($response, 'verify Cloudflare token');
    }

    public function zoneExists(string $zoneName): bool
    {
        return $this->findZoneId($zoneName) !== null;
    }

    public function findZoneId(string $zoneName): ?string
    {
        $zoneName = strtolower(trim($zoneName));
        if ($zoneName === '') {
            return null;
        }

        $results = $this->zonesNamed($zoneName, activeOnly: true);
        if ($results === []) {
            $results = $this->zonesNamed($zoneName, activeOnly: false);
        }
        if ($results === []) {
            return null;
        }

        $first = $results[0];
        $id = is_array($first) ? ($first['id'] ?? null) : null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function zonesNamed(string $zoneName, bool $activeOnly): array
    {
        $query = ['name' => $zoneName, 'per_page' => 5];
        if ($activeOnly) {
            $query['status'] = 'active';
        }

        $response = $this->request('get', '/zones', $query);
        $this->assertApiSuccess($response, 'list Cloudflare zones');
        $results = $response->json('result');

        return is_array($results) ? array_values(array_filter($results, 'is_array')) : [];
    }

    /**
     * @return list<string>
     */
    public function listZoneNames(): array
    {
        $response = $this->request('get', '/zones', ['per_page' => 50]);
        $this->assertApiSuccess($response, 'list Cloudflare zones');
        $names = [];
        foreach ((array) $response->json('result') as $row) {
            if (is_array($row) && is_string($row['name'] ?? null) && $row['name'] !== '') {
                $names[] = strtolower($row['name']);
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * @return array<string, mixed>
     */
    public function upsertARecord(string $zoneName, string $relativeRecordName, string $ipv4): array
    {
        $zoneId = $this->findZoneId($zoneName);
        if ($zoneId === null) {
            throw new \RuntimeException('Zone ['.$zoneName.'] was not found in this Cloudflare account. Add it to the API token’s Zone Resources, or use a token from the account that owns that zone.');
        }

        $fqdn = $this->fqdn($zoneName, $relativeRecordName);
        $existing = $this->findARecord($zoneId, $fqdn);

        if ($existing !== null) {
            $recordId = (string) ($existing['id'] ?? '');
            if ($recordId === '') {
                throw new \RuntimeException('Cloudflare returned an A record without an id.');
            }

            $response = $this->request('put', '/zones/'.$zoneId.'/dns_records/'.$recordId, [
                'type' => 'A',
                'name' => $fqdn,
                'content' => $ipv4,
                'ttl' => 120,
                'proxied' => false,
            ]);
            $this->assertApiSuccess($response, 'update Cloudflare DNS record');
            $result = $response->json('result');

            return is_array($result) ? $result : [];
        }

        $response = $this->request('post', '/zones/'.$zoneId.'/dns_records', [
            'type' => 'A',
            'name' => $fqdn,
            'content' => $ipv4,
            'ttl' => 120,
            'proxied' => false,
        ]);
        $this->assertApiSuccess($response, 'create Cloudflare DNS record');
        $result = $response->json('result');

        return is_array($result) ? $result : [];
    }

    public function deleteDnsRecord(string $zoneName, string $recordId): void
    {
        $zoneId = $this->findZoneId($zoneName);
        if ($zoneId === null) {
            return;
        }

        if ($recordId === '') {
            return;
        }

        $response = $this->request('delete', '/zones/'.$zoneId.'/dns_records/'.$recordId);
        if ($response->status() === 404) {
            return;
        }
        $this->assertApiSuccess($response, 'delete Cloudflare DNS record');
    }

    /**
     * @return array<string, mixed>
     */
    public function upsertCnameRecord(string $zoneName, string $relativeRecordName, string $targetHost): array
    {
        $zoneId = $this->findZoneId($zoneName);
        if ($zoneId === null) {
            throw new \RuntimeException('Zone ['.$zoneName.'] was not found in this Cloudflare account. Add it to the API token’s Zone Resources, or use a token from the account that owns that zone.');
        }

        $fqdn = $this->fqdn($zoneName, $relativeRecordName);
        $target = rtrim(strtolower(trim($targetHost)), '.');
        $existing = $this->findCnameRecord($zoneName, $fqdn);

        if ($existing !== null) {
            $recordId = (string) ($existing['id'] ?? '');
            if ($recordId === '') {
                throw new \RuntimeException('Cloudflare returned a CNAME record without an id.');
            }

            $response = $this->request('put', '/zones/'.$zoneId.'/dns_records/'.$recordId, [
                'type' => 'CNAME',
                'name' => $fqdn,
                'content' => $target,
                'ttl' => 120,
                'proxied' => true,
            ]);
            $this->assertApiSuccess($response, 'update Cloudflare CNAME record');
            $result = $response->json('result');

            return is_array($result) ? $result : [];
        }

        $response = $this->request('post', '/zones/'.$zoneId.'/dns_records', [
            'type' => 'CNAME',
            'name' => $fqdn,
            'content' => $target,
            'ttl' => 120,
            'proxied' => true,
        ]);
        $this->assertApiSuccess($response, 'create Cloudflare CNAME record');
        $result = $response->json('result');

        return is_array($result) ? $result : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findCnameRecord(string $zoneName, string $fqdn): ?array
    {
        $zoneId = $this->findZoneId($zoneName);
        if ($zoneId === null) {
            return null;
        }

        $response = $this->request('get', '/zones/'.$zoneId.'/dns_records', [
            'type' => 'CNAME',
            'name' => strtolower($fqdn),
        ]);
        $this->assertApiSuccess($response, 'list Cloudflare DNS records');
        $results = $response->json('result');
        if (! is_array($results) || $results === []) {
            return null;
        }

        foreach ($results as $row) {
            if (is_array($row) && strtoupper((string) ($row['type'] ?? '')) === 'CNAME') {
                return $row;
            }
        }

        return null;
    }

    /**
     * List DNS records of a given type in the zone, optionally filtered by exact
     * name. Returns the raw Cloudflare record rows (empty when the zone isn't in
     * this account). Used to pre-flight mail auth records (SPF/DKIM/DMARC).
     *
     * @return list<array<string, mixed>>
     */
    public function listDnsRecords(string $zoneName, string $type, ?string $name = null): array
    {
        $zoneId = $this->findZoneId($zoneName);
        if ($zoneId === null) {
            return [];
        }

        $query = ['type' => strtoupper($type), 'per_page' => 100];
        if ($name !== null && $name !== '') {
            $query['name'] = strtolower($name);
        }

        $response = $this->request('get', '/zones/'.$zoneId.'/dns_records', $query);
        $this->assertApiSuccess($response, 'list Cloudflare DNS records');
        $results = $response->json('result');
        if (! is_array($results)) {
            return [];
        }

        return array_values(array_filter($results, 'is_array'));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findARecord(string $zoneId, string $fqdn): ?array
    {
        $response = $this->request('get', '/zones/'.$zoneId.'/dns_records', [
            'type' => 'A',
            'name' => strtolower($fqdn),
        ]);
        $this->assertApiSuccess($response, 'list Cloudflare DNS records');
        $results = $response->json('result');
        if (! is_array($results) || $results === []) {
            return null;
        }

        foreach ($results as $row) {
            if (is_array($row) && strtoupper((string) ($row['type'] ?? '')) === 'A') {
                return $row;
            }
        }

        return null;
    }

    private function fqdn(string $zone, string $relativeName): string
    {
        $zone = strtolower(trim($zone));
        $relativeName = trim($relativeName);
        $lower = strtolower($relativeName);
        if ($lower === '') {
            return $zone;
        }
        if (str_ends_with($lower, '.'.$zone)) {
            return $lower;
        }

        return $lower.'.'.$zone;
    }

    /**
     * @param  array<string, mixed>  $queryOrBody
     */
    private function request(string $method, string $path, array $queryOrBody = []): Response
    {
        $url = self::BASE.$path;
        $client = Http::withToken($this->bearerToken)->acceptJson();

        return match (strtolower($method)) {
            'get' => $client->get($url, $queryOrBody),
            'post' => $client->asJson()->post($url, $queryOrBody),
            'put' => $client->asJson()->put($url, $queryOrBody),
            'delete' => $client->delete($url),
            default => throw new \InvalidArgumentException("Unsupported HTTP method: {$method}"),
        };
    }

    private function assertApiSuccess(Response $response, string $action): void
    {
        if ($response->successful()) {
            $json = $response->json();
            if (is_array($json) && array_key_exists('success', $json) && $json['success'] === false) {
                $errors = $json['errors'] ?? [];
                $msg = is_array($errors) && $errors !== [] ? json_encode($errors) : $response->body();

                throw new \RuntimeException("Failed to {$action}: {$msg}");
            }

            return;
        }

        $message = $response->json('errors.0.message')
            ?? $response->json('message')
            ?? $response->body()
            ?: $response->reason();

        // A token that can read zones but hits an auth/permission wall on records
        // is almost always missing Zone:DNS:Edit — say so rather than the bare
        // "Authentication error" Cloudflare returns.
        $code = (int) ($response->json('errors.0.code') ?? 0);
        if (in_array($code, [10000, 9109], true) || stripos((string) $message, 'authentication') !== false || $response->status() === 403) {
            $message .= ' — the API token needs the Zone:DNS:Edit permission for this zone.';
        }

        throw new \RuntimeException("Failed to {$action}: {$message}");
    }
}
