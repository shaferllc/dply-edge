<?php

declare(strict_types=1);

namespace App\Services\Concerns;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesHetznerNetworks
{
    /**
     * Create a new Hetzner private network and return its ID.
     *
     * @param  list<int|string>  $serverIds  Provider server IDs to attach immediately
     */
    /**
     * @param  list<string>  $zones  Network zones to add subnets for (e.g. ['eu-central', 'us-east']).
     *                               At least one is required — Hetzner will not assign private IPs without a subnet.
     */
    public function createNetwork(string $name, string $ipRange = '10.0.0.0/8', array $zones = ['eu-central']): int
    {
        if (empty($zones)) {
            $zones = ['eu-central'];
        }

        $subnets = array_values(array_map(static fn (string $zone) => [
            'type' => 'cloud',
            'network_zone' => $zone,
            'ip_range' => $ipRange,
        ], array_unique($zones)));

        $body = [
            'name' => $name,
            'ip_range' => $ipRange,
            'subnets' => $subnets,
        ];

        $response = $this->request('post', '/networks', $body);
        $this->assertSuccess($response, 'create network');

        $data = $response->json();
        $networkId = (int) ($data['network']['id'] ?? 0);
        if ($networkId === 0) {
            throw new \RuntimeException('Hetzner API did not return network id.');
        }

        return $networkId;
    }

    /**
     * Attach a server to a Hetzner private network after creation.
     * The API is async; callers that need the assigned IP should re-fetch
     * getInstance() after a short delay.
     */
    public function attachServerToNetwork(int $serverId, int $networkId): void
    {
        $response = $this->request('post', "/servers/{$serverId}/actions/attach_to_network", [
            'network' => $networkId,
        ]);
        $this->assertSuccess($response, 'attach server to network');
    }

    public function detachServerFromNetwork(int $serverId, int $networkId): void
    {
        $response = $this->request('post', "/servers/{$serverId}/actions/detach_from_network", [
            'network' => $networkId,
        ]);
        $this->assertSuccess($response, 'detach server from network');
    }

    /**
     * Map a Hetzner location code to its network zone.
     * Used when creating subnets so the zone matches the server's region.
     */
    public static function networkZoneForRegion(string $region): string
    {
        return match (strtolower($region)) {
            'ash' => 'us-east',
            'hil' => 'us-west',
            'sin' => 'ap-southeast',
            default => 'eu-central', // fsn1, nbg1, hel1
        };
    }

    /**
     * Get a single private network by ID, including its routes.
     *
     * @return array{id:int,name:string,ip_range:string,routes:list<array{destination:string,gateway:string}>}
     */
    public function getNetwork(int $id): array
    {
        $response = $this->request('get', "/networks/{$id}");
        $this->assertSuccess($response, 'get network');

        $network = $response->json()['network'] ?? null;
        if (! $network) {
            throw new \RuntimeException('Hetzner API did not return network.');
        }

        return [
            'id' => (int) $network['id'],
            'name' => (string) ($network['name'] ?? ''),
            'ip_range' => (string) ($network['ip_range'] ?? ''),
            'routes' => array_map(static fn ($r) => [
                'destination' => (string) ($r['destination'] ?? ''),
                'gateway' => (string) ($r['gateway'] ?? ''),
            ], $network['routes'] ?? []),
        ];
    }

    /**
     * Add a static route to a private network.
     * destination — CIDR the route covers (e.g. "192.168.1.0/24")
     * gateway     — IP of the server on the network that forwards the traffic
     */
    public function addNetworkRoute(int $networkId, string $destination, string $gateway): void
    {
        $response = $this->request('post', "/networks/{$networkId}/actions/add_route", [
            'destination' => $destination,
            'gateway' => $gateway,
        ]);
        $this->assertSuccess($response, 'add network route');
    }

    /**
     * Remove a static route from a private network.
     */
    public function deleteNetworkRoute(int $networkId, string $destination, string $gateway): void
    {
        $response = $this->request('post', "/networks/{$networkId}/actions/delete_route", [
            'destination' => $destination,
            'gateway' => $gateway,
        ]);
        $this->assertSuccess($response, 'delete network route');
    }

    /**
     * List private networks available in this account.
     *
     * @return array<int, array{id: int, name: string, ip_range: string}>
     */
    public function listNetworks(): array
    {
        $response = $this->request('get', '/networks');
        $this->assertSuccess($response, 'list networks');
        $data = $response->json();

        return array_map(static fn ($n) => [
            'id' => (int) ($n['id'] ?? 0),
            'name' => (string) ($n['name'] ?? ''),
            'ip_range' => (string) ($n['ip_range'] ?? ''),
        ], $data['networks'] ?? []);
    }
}
