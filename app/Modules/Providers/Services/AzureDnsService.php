<?php

declare(strict_types=1);

namespace App\Modules\Providers\Services;

use App\Models\ProviderCredential;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use App\Support\Cloud\AzureAccessToken;

class AzureDnsService
{
    private string $subscriptionId;

    public function __construct(
        private readonly ProviderCredential $credential,
    ) {
        $this->subscriptionId = AzureAccessToken::credentials($credential)['subscription_id'];
    }

    public function validateCredentials(): void
    {
        $response = $this->request('GET', '/providers/Microsoft.Network/dnszones', [
            'api-version' => '2018-05-01',
            '$top' => 1,
        ]);

        $this->assertSuccess($response, 'validate credentials');
    }

    public function zoneExists(string $zoneName): bool
    {
        return $this->findZone($zoneName) !== null;
    }

    /**
     * @return array{id:string,name:string,resource_group:string}|null
     */
    public function findZone(string $zoneName): ?array
    {
        $zoneName = strtolower(trim($zoneName));
        if ($zoneName === '') {
            return null;
        }

        $response = $this->request('GET', '/providers/Microsoft.Network/dnszones', [
            'api-version' => '2018-05-01',
        ]);
        $this->assertSuccess($response, 'list DNS zones');

        $zones = $response->json('value');
        if (! is_array($zones)) {
            return null;
        }

        foreach ($zones as $zone) {
            if (! is_array($zone)) {
                continue;
            }
            $name = strtolower(trim((string) ($zone['name'] ?? '')));
            if ($name !== $zoneName) {
                continue;
            }
            $id = (string) ($zone['id'] ?? '');
            $resourceGroup = $this->resourceGroupFromAzureId($id);
            if ($id === '' || $resourceGroup === null) {
                continue;
            }

            return [
                'id' => $id,
                'name' => $name,
                'resource_group' => $resourceGroup,
            ];
        }

        return null;
    }

    /**
     * @return array<string,mixed>
     */
    public function upsertRecord(string $zoneName, string $type, string $recordName, string $value, int $ttl = 60): array
    {
        $zone = $this->findZone($zoneName);
        if ($zone === null) {
            throw new \RuntimeException('Azure DNS zone not found: '.$zoneName);
        }

        $type = strtoupper(trim($type));
        if ($type !== 'A') {
            throw new \InvalidArgumentException('Azure DNS service currently supports A records only.');
        }

        $name = self::normalizeRecordName($recordName, $zoneName);
        $path = $this->recordSetPath($zone['resource_group'], $zoneName, $type, $name);
        $response = $this->request('PUT', $path, ['api-version' => '2018-05-01'], [
            'properties' => [
                'TTL' => max(60, $ttl),
                'ARecords' => [['ipv4Address' => $value]],
            ],
        ]);
        $this->assertSuccess($response, 'upsert DNS record');

        $id = (string) ($response->json('id') ?? '');
        if ($id === '') {
            $id = $zone['resource_group'].'|'.$zoneName.'|'.$type.'|'.$name;
        }

        return [
            'id' => $id,
            'name' => $name,
            'type' => $type,
            'value' => $value,
        ];
    }

    public function deleteRecordById(string $recordSetId): void
    {
        $recordSetId = trim($recordSetId);
        if ($recordSetId === '') {
            return;
        }

        if (str_starts_with($recordSetId, '/subscriptions/')) {
            $response = $this->requestAbsolute('DELETE', $recordSetId, ['api-version' => '2018-05-01']);
            if ($response->status() === 404) {
                return;
            }
            $this->assertSuccess($response, 'delete DNS record');

            return;
        }

        $parts = explode('|', $recordSetId);
        if (count($parts) !== 4) {
            return;
        }
        [$resourceGroup, $zoneName, $type, $name] = $parts;
        $path = $this->recordSetPath($resourceGroup, $zoneName, $type, $name);
        $response = $this->request('DELETE', $path, ['api-version' => '2018-05-01']);
        if ($response->status() === 404) {
            return;
        }
        $this->assertSuccess($response, 'delete DNS record');
    }

    public static function normalizeRecordName(string $recordName, string $zoneName): string
    {
        $recordName = strtolower(trim($recordName));
        $zoneName = strtolower(trim($zoneName));

        if ($recordName === '' || $recordName === '@' || ($zoneName !== '' && $recordName === $zoneName)) {
            return '@';
        }

        if ($zoneName !== '' && str_ends_with($recordName, '.'.$zoneName)) {
            $recordName = substr($recordName, 0, -1 * (strlen($zoneName) + 1));
        }

        return $recordName === '' ? '@' : $recordName;
    }

    private function recordSetPath(string $resourceGroup, string $zoneName, string $type, string $recordName): string
    {
        return '/resourceGroups/'.rawurlencode($resourceGroup)
            .'/providers/Microsoft.Network/dnszones/'.rawurlencode($zoneName)
            .'/'.$type.'/'.rawurlencode($recordName);
    }

    /**
     * @param  array<string, mixed> $query
     * @param  array<string, mixed> $body
     */
    private function request(string $method, string $path, array $query = [], array $body = []): Response
    {
        $base = 'https://management.azure.com/subscriptions/'.$this->subscriptionId;

        return $this->requestAbsolute($method, $base.$path, $query, $body);
    }

    /**
     * @param  array<string, mixed> $query
     * @param  array<string, mixed> $body
     */
    private function requestAbsolute(string $method, string $url, array $query = [], array $body = []): Response
    {
        $token = AzureAccessToken::bearerToken($this->credential);
        $request = Http::withToken($token)
            ->acceptJson()
            ->contentType('application/json');

        return match (strtoupper($method)) {
            'GET' => $request->get($url, $query),
            'PUT' => $request->put($url.(empty($query) ? '' : '?'.http_build_query($query)), $body),
            'DELETE' => $request->delete($url, $query),
            default => throw new \InvalidArgumentException('Unsupported Azure DNS method: '.$method),
        };
    }

    private function assertSuccess(Response $response, string $action): void
    {
        if ($response->successful() || $response->status() === 202) {
            return;
        }

        $message = trim((string) ($response->json('error.message') ?? $response->json('message') ?? $response->body()));
        throw new \RuntimeException('Azure DNS API failed to '.$action.': '.$message);
    }

    private function resourceGroupFromAzureId(string $id): ?string
    {
        if (preg_match('#/resourceGroups/([^/]+)/#i', $id, $matches) !== 1) {
            return null;
        }

        return urldecode($matches[1]);
    }
}
