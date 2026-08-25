<?php

declare(strict_types=1);

namespace App\Modules\Providers\Services;

use App\Models\ProviderCredential;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use App\Support\Cloud\AzureAccessToken;

class AzureComputeService
{
    private string $subscriptionId;

    public function __construct(
        private readonly ProviderCredential $credential,
    ) {
        $this->subscriptionId = AzureAccessToken::credentials($credential)['subscription_id'];
    }

    /**
     * @return list<array{id:string,name:string}>
     */
    public static function defaultLocations(): array
    {
        return [
            ['id' => 'eastus', 'name' => 'East US'],
            ['id' => 'eastus2', 'name' => 'East US 2'],
            ['id' => 'westus2', 'name' => 'West US 2'],
            ['id' => 'centralus', 'name' => 'Central US'],
            ['id' => 'northeurope', 'name' => 'North Europe'],
            ['id' => 'westeurope', 'name' => 'West Europe'],
            ['id' => 'uksouth', 'name' => 'UK South'],
            ['id' => 'southeastasia', 'name' => 'Southeast Asia'],
        ];
    }

    /**
     * @return list<array{id:string,name:string,memory_mb?:int|null,vcpus?:int|null}>
     */
    public static function defaultVmSizes(): array
    {
        return [
            ['id' => 'Standard_B1s', 'name' => 'Standard_B1s (1 vCPU, 1 GB)', 'memory_mb' => 1024, 'vcpus' => 1],
            ['id' => 'Standard_B1ms', 'name' => 'Standard_B1ms (1 vCPU, 2 GB)', 'memory_mb' => 2048, 'vcpus' => 1],
            ['id' => 'Standard_B2s', 'name' => 'Standard_B2s (2 vCPU, 4 GB)', 'memory_mb' => 4096, 'vcpus' => 2],
            ['id' => 'Standard_D2s_v5', 'name' => 'Standard_D2s_v5 (2 vCPU, 8 GB)', 'memory_mb' => 8192, 'vcpus' => 2],
            ['id' => 'Standard_D4s_v5', 'name' => 'Standard_D4s_v5 (4 vCPU, 16 GB)', 'memory_mb' => 16384, 'vcpus' => 4],
        ];
    }

    public function validateCredentials(): void
    {
        $response = $this->request('GET', '/locations', query: [
            'api-version' => '2022-12-01',
        ]);

        $this->assertSuccess($response, 'validate credentials');
    }

    /**
     * @return list<array{id:string,name:string}>
     */
    public function listLocations(): array
    {
        $response = $this->request('GET', '/locations', query: [
            'api-version' => '2022-12-01',
        ]);
        $this->assertSuccess($response, 'list locations');

        $items = $response->json('value');
        if (! is_array($items) || $items === []) {
            return self::defaultLocations();
        }

        $locations = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $id = trim((string) ($item['name'] ?? ''));
            if ($id === '') {
                continue;
            }
            $locations[] = [
                'id' => $id,
                'name' => trim((string) ($item['displayName'] ?? $id)),
            ];
        }

        return $locations !== [] ? $locations : self::defaultLocations();
    }

    public function listVmSizes(string $location): array
    {
        $location = trim($location);
        if ($location === '') {
            return self::defaultVmSizes();
        }

        $response = $this->request('GET', '/providers/Microsoft.Compute/locations/'.rawurlencode($location).'/vmSizes', query: [
            'api-version' => '2021-07-01',
        ]);

        if (! $response->successful()) {
            return self::defaultVmSizes();
        }

        $items = $response->json('value');
        if (! is_array($items) || $items === []) {
            return self::defaultVmSizes();
        }

        $sizes = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $name = trim((string) ($item['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $vcpus = (int) ($item['numberOfCores'] ?? 0);
            $memoryMb = (int) ($item['memoryInMB'] ?? 0);
            $sizes[] = [
                'id' => $name,
                'name' => sprintf(
                    '%s (%d vCPU, %d GB)',
                    $name,
                    max(1, $vcpus),
                    max(1, (int) round($memoryMb / 1024))
                ),
                'memory_mb' => $memoryMb > 0 ? $memoryMb : null,
                'vcpus' => $vcpus > 0 ? $vcpus : null,
            ];
        }

        return $sizes !== [] ? $sizes : self::defaultVmSizes();
    }

    /**
     * One created VM's resource ids — not a list. The previous list<> wrapper
     * hid every offset from callers (ProvisionAzureServerJob reads vm_id /
     * nic_id / pip_id straight off this).
     *
     * @param  array<string, mixed> $tags  Resource tags, e.g. ProviderResourceTags::labels()
     * @return array{vm_id: string, nic_id: string, pip_id: string}
     */
    public function createLinuxVm(
        string $resourceGroup,
        string $location,
        string $vmName,
        string $size,
        string $adminUsername,
        string $sshPublicKey,
        array $tags = [],
    ): array {
        $resourceGroup = trim($resourceGroup);
        if ($resourceGroup === '') {
            throw new \InvalidArgumentException('Azure resource group is required.');
        }
        $location = trim($location);
        $vmName = trim($vmName);
        $size = trim($size);
        $adminUsername = trim($adminUsername);
        $sshPublicKey = trim($sshPublicKey);

        if ($location === '' || $vmName === '' || $size === '' || $adminUsername === '' || $sshPublicKey === '') {
            throw new \InvalidArgumentException('Azure VM create requires location, vm name, size, admin username, and SSH public key.');
        }

        $suffix = Str::lower(Str::random(6));
        $pipName = Str::limit($vmName.'-pip-'.$suffix, 80, '');
        $nicName = Str::limit($vmName.'-nic-'.$suffix, 80, '');

        // Every resource in the trio carries the same tags — the NIC and public
        // IP outlive a deleted VM in Azure, so they need the identity tag too or
        // they become untraceable leftovers in the resource group.
        $tags = array_map(static fn (mixed $v): string => (string) $v, $tags);

        $pipPath = $this->resourcePath($resourceGroup, 'Microsoft.Network/publicIPAddresses', $pipName);
        $pipResponse = $this->request('PUT', $pipPath, array_filter([
            'location' => $location,
            'tags' => $tags,
            'sku' => ['name' => 'Standard'],
            'properties' => [
                'publicIPAllocationMethod' => 'Static',
                'publicIPAddressVersion' => 'IPv4',
            ],
        ]), ['api-version' => '2023-09-01']);
        $this->assertSuccess($pipResponse, 'create public IP');
        $pipId = (string) ($pipResponse->json('id') ?? '');
        if ($pipId === '') {
            throw new \RuntimeException('Azure did not return public IP resource id.');
        }

        $nicPath = $this->resourcePath($resourceGroup, 'Microsoft.Network/networkInterfaces', $nicName);
        $nicResponse = $this->request('PUT', $nicPath, array_filter([
            'location' => $location,
            'tags' => $tags,
            'properties' => [
                'ipConfigurations' => [[
                    'name' => 'ipconfig1',
                    'properties' => [
                        'privateIPAllocationMethod' => 'Dynamic',
                        'publicIPAddress' => ['id' => $pipId],
                    ],
                ]],
            ],
        ]), ['api-version' => '2023-09-01']);
        $this->assertSuccess($nicResponse, 'create network interface');
        $nicId = (string) ($nicResponse->json('id') ?? '');
        if ($nicId === '') {
            throw new \RuntimeException('Azure did not return network interface resource id.');
        }

        $vmPath = $this->resourcePath($resourceGroup, 'Microsoft.Compute/virtualMachines', $vmName);
        $vmResponse = $this->request('PUT', $vmPath, array_filter([
            'location' => $location,
            'tags' => $tags,
            'properties' => [
                'hardwareProfile' => [
                    'vmSize' => $size,
                ],
                'storageProfile' => [
                    'imageReference' => [
                        'publisher' => (string) config('services.azure.image_publisher', 'Canonical'),
                        'offer' => (string) config('services.azure.image_offer', 'ubuntu-24_04-lts'),
                        'sku' => (string) config('services.azure.image_sku', 'server'),
                        'version' => (string) config('services.azure.image_version', 'latest'),
                    ],
                    'osDisk' => [
                        'createOption' => 'FromImage',
                        'managedDisk' => [
                            'storageAccountType' => (string) config('services.azure.os_disk_type', 'Standard_LRS'),
                        ],
                    ],
                ],
                'osProfile' => [
                    'computerName' => $vmName,
                    'adminUsername' => $adminUsername,
                    'linuxConfiguration' => [
                        'disablePasswordAuthentication' => true,
                        'ssh' => [
                            'publicKeys' => [[
                                'path' => '/home/'.$adminUsername.'/.ssh/authorized_keys',
                                'keyData' => $sshPublicKey,
                            ]],
                        ],
                    ],
                ],
                'networkProfile' => [
                    'networkInterfaces' => [[
                        'id' => $nicId,
                        'properties' => ['primary' => true],
                    ]],
                ],
            ],
        ]), ['api-version' => '2023-09-01']);
        $this->assertSuccess($vmResponse, 'create virtual machine');
        $vmId = (string) ($vmResponse->json('id') ?? '');
        if ($vmId === '') {
            throw new \RuntimeException('Azure did not return virtual machine resource id.');
        }

        return [
            'vm_id' => $vmId,
            'nic_id' => $nicId,
            'pip_id' => $pipId,
        ];
    }

    public function getVmPublicIp(string $resourceGroup, string $publicIpName): ?string
    {
        $response = $this->request('GET', $this->resourcePath($resourceGroup, 'Microsoft.Network/publicIPAddresses', $publicIpName), query: [
            'api-version' => '2023-09-01',
        ]);
        $this->assertSuccess($response, 'fetch VM public IP');

        $ip = trim((string) ($response->json('properties.ipAddress') ?? ''));

        return $ip !== '' ? $ip : null;
    }

    public function deleteVm(string $resourceGroup, string $vmName): void
    {
        $response = $this->request(
            'DELETE',
            $this->resourcePath($resourceGroup, 'Microsoft.Compute/virtualMachines', $vmName),
            query: ['api-version' => '2023-09-01']
        );

        if ($response->status() === 404) {
            return;
        }

        $this->assertSuccess($response, 'delete virtual machine');
    }

    private function resourcePath(string $resourceGroup, string $resourceType, string $resourceName): string
    {
        return '/resourceGroups/'.rawurlencode($resourceGroup).'/providers/'.$resourceType.'/'.rawurlencode($resourceName);
    }

    /**
     * @param  array<string, mixed> $body
     * @param  array<string, mixed> $query
     */
    private function request(string $method, string $path, array $body = [], array $query = []): Response
    {
        $token = AzureAccessToken::bearerToken($this->credential);
        $url = 'https://management.azure.com/subscriptions/'.$this->subscriptionId.$path;

        $request = Http::withToken($token)
            ->acceptJson()
            ->contentType('application/json');

        return match (strtoupper($method)) {
            'GET' => $request->get($url, $query),
            'PUT' => $request->put($url.(empty($query) ? '' : '?'.http_build_query($query)), $body),
            'DELETE' => $request->delete($url, $query),
            default => throw new \InvalidArgumentException('Unsupported Azure method: '.$method),
        };
    }

    private function assertSuccess(Response $response, string $action): void
    {
        if ($response->successful() || $response->status() === 202) {
            return;
        }

        $message = trim((string) ($response->json('error.message') ?? $response->json('message') ?? $response->body()));
        throw new \RuntimeException('Azure API failed to '.$action.': '.$message);
    }
}
