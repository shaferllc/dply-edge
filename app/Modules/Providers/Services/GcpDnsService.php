<?php

declare(strict_types=1);

namespace App\Modules\Providers\Services;

use App\Models\ProviderCredential;
use App\Support\Cloud\GcpAccessToken;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class GcpDnsService
{
    private const BASE_URL = 'https://dns.googleapis.com/dns/v1';

    /**
     * @var list<string>
     */
    private const SCOPES = ['https://www.googleapis.com/auth/cloud-platform'];

    private GcpAccessToken $accessToken;

    public function __construct(ProviderCredential $credential)
    {
        $this->accessToken = GcpAccessToken::fromCredential($credential);
    }

    public function validateCredentials(): void
    {
        $this->request('get', sprintf('/projects/%s/managedZones', rawurlencode($this->projectId())), ['maxResults' => 1]);
    }

    public function zoneExists(string $zoneName): bool
    {
        return $this->findManagedZone($zoneName) !== null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findManagedZone(string $zoneName): ?array
    {
        $zoneName = strtolower(trim($zoneName));
        if ($zoneName === '') {
            return null;
        }

        $response = $this->request('get', sprintf('/projects/%s/managedZones', rawurlencode($this->projectId())), ['maxResults' => 200]);
        $zones = $response->json('managedZones');
        if (! is_array($zones)) {
            return null;
        }

        foreach ($zones as $zone) {
            if (! is_array($zone)) {
                continue;
            }
            $dnsName = strtolower(rtrim((string) ($zone['dnsName'] ?? ''), '.'));
            if ($dnsName === $zoneName) {
                return $zone;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function upsertRecord(
        string $zoneName,
        string $type,
        string $recordName,
        string $value,
        int $ttl = 60
    ): array {
        $zone = $this->findManagedZone($zoneName);
        if ($zone === null) {
            throw new \RuntimeException(sprintf('GCP Cloud DNS zone not found: %s', $zoneName));
        }

        $managedZone = (string) ($zone['name'] ?? '');
        if ($managedZone === '') {
            throw new \RuntimeException('GCP Cloud DNS zone response did not include a zone name.');
        }

        $fqdn = self::normalizeRecordName($recordName, $zoneName);
        $recordType = strtoupper(trim($type));
        $current = $this->findRecordSet($managedZone, $fqdn, $recordType);

        $addition = [
            'name' => $fqdn,
            'type' => $recordType,
            'ttl' => max(30, $ttl),
            'rrdatas' => [$value],
        ];

        $changes = ['additions' => [$addition]];
        if ($current !== null) {
            $changes['deletions'] = [$current];
        }

        $response = $this->request(
            'post',
            sprintf('/projects/%s/managedZones/%s/changes', rawurlencode($this->projectId()), rawurlencode($managedZone)),
            [],
            $changes
        );

        $changeId = (string) ($response->json('id', ''));
        $recordId = $this->recordId($managedZone, $fqdn, $recordType);
        if ($changeId !== '') {
            $recordId .= '#'.$changeId;
        }

        return [
            'id' => $recordId,
            'type' => $recordType,
            'name' => $fqdn,
            'value' => $value,
        ];
    }

    public function deleteRecord(string $zoneName, string $recordId): void
    {
        if ($recordId === '') {
            return;
        }

        [$managedZone, $fqdn, $type] = $this->parseRecordId($recordId);
        if ($managedZone === '' || $fqdn === '' || $type === '') {
            return;
        }

        $existing = $this->findRecordSet($managedZone, $fqdn, $type);
        if ($existing === null) {
            return;
        }

        $this->request(
            'post',
            sprintf('/projects/%s/managedZones/%s/changes', rawurlencode($this->projectId()), rawurlencode($managedZone)),
            [],
            ['deletions' => [$existing]]
        );
    }

    public static function normalizeRecordName(string $recordName, string $zoneName): string
    {
        $recordName = strtolower(trim($recordName));
        $zoneName = strtolower(trim($zoneName));

        if ($recordName === '' || $recordName === '@' || $recordName === $zoneName) {
            return rtrim($zoneName, '.').'.';
        }

        if (str_ends_with($recordName, '.'.$zoneName)) {
            return rtrim($recordName, '.').'.';
        }

        return sprintf('%s.%s.', trim($recordName, '.'), rtrim($zoneName, '.'));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findRecordSet(string $managedZone, string $fqdn, string $type): ?array
    {
        $response = $this->request(
            'get',
            sprintf('/projects/%s/managedZones/%s/rrsets', rawurlencode($this->projectId()), rawurlencode($managedZone)),
            [
                'name' => $fqdn,
                'type' => $type,
                'maxResults' => 100,
            ]
        );

        $rrsets = $response->json('rrsets');
        if (! is_array($rrsets)) {
            return null;
        }

        foreach ($rrsets as $rrset) {
            if (! is_array($rrset)) {
                continue;
            }
            if (strtolower((string) ($rrset['name'] ?? '')) !== strtolower($fqdn)) {
                continue;
            }
            if (strtoupper((string) ($rrset['type'] ?? '')) !== strtoupper($type)) {
                continue;
            }

            return $rrset;
        }

        return null;
    }

    private function projectId(): string
    {
        return $this->accessToken->projectId();
    }

    private function recordId(string $managedZone, string $fqdn, string $type): string
    {
        return sprintf('%s|%s|%s', $managedZone, strtolower($fqdn), strtoupper($type));
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private function parseRecordId(string $recordId): array
    {
        $id = explode('#', $recordId, 2)[0];
        $parts = explode('|', $id, 3);
        if (count($parts) !== 3) {
            return ['', '', ''];
        }

        return [$parts[0], $parts[1], $parts[2]];
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $body
     */
    private function request(string $method, string $path, array $query = [], array $body = []): Response
    {
        $response = $this->rawRequest($method, $path, $query, $body);
        $this->assertSuccess($response, sprintf('%s %s', strtoupper($method), $path));

        return $response;
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $body
     */
    private function rawRequest(string $method, string $path, array $query = [], array $body = []): Response
    {
        $request = Http::withToken($this->accessToken->token(self::SCOPES))
            ->acceptJson()
            ->contentType('application/json');

        $url = self::BASE_URL.$path;
        $method = strtolower($method);

        return match ($method) {
            'get' => $request->get($url, $query),
            'post' => $request->post($url, $body),
            default => throw new \InvalidArgumentException('Unsupported GCP DNS request method: '.$method),
        };
    }

    private function assertSuccess(Response $response, string $action): void
    {
        if ($response->successful()) {
            return;
        }

        $message = $response->json('error.message')
            ?? $response->json('error.errors.0.message')
            ?? $response->body();
        throw new \RuntimeException(sprintf('GCP Cloud DNS API failed to %s: %s', $action, trim((string) $message)));
    }
}
