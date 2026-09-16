<?php

declare(strict_types=1);

namespace App\Services\Concerns;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesHetznerFirewall
{
    /**
     * Find a Cloud Firewall by exact name. Hetzner permits duplicate names, so
     * this returns the first exact match — fine for our per-server naming
     * (`dply-<server id>`), and makes provision-job retries idempotent.
     *
     * @return array<string,mixed>|null
     */
    public function findFirewallByName(string $name): ?array
    {
        $response = $this->request('get', '/firewalls', ['name' => $name]);
        $this->assertSuccess($response, 'list firewalls');

        foreach (($response->json('firewalls') ?? []) as $firewall) {
            if (($firewall['name'] ?? null) === $name) {
                return $firewall;
            }
        }

        return null;
    }

    /**
     * Create a Cloud Firewall with the given inbound rules. Returns its id.
     *
     * @param  list<array<string,mixed>>  $rules
     */
    public function createFirewall(string $name, array $rules): int
    {
        $response = $this->request('post', '/firewalls', [
            'name' => $name,
            'rules' => $rules,
        ]);
        $this->assertSuccess($response, 'create firewall');

        $id = $response->json('firewall.id');
        if ($id === null) {
            throw new \RuntimeException('Hetzner API did not return firewall id.');
        }

        return (int) $id;
    }

    /**
     * Replace the rule set on an existing firewall (idempotent on retries).
     *
     * @param  list<array<string,mixed>>  $rules
     */
    public function setFirewallRules(int $firewallId, array $rules): void
    {
        $response = $this->request('post', "/firewalls/{$firewallId}/actions/set_rules", [
            'rules' => $rules,
        ]);
        $this->assertSuccess($response, 'set firewall rules');
    }

    /**
     * Attach a firewall to an existing server. New servers attach atomically via
     * createInstance(firewallIds:) instead; this covers after-the-fact backfill.
     */
    public function applyFirewallToServer(int $firewallId, int $serverId): void
    {
        $response = $this->request('post', "/firewalls/{$firewallId}/actions/apply_to_resources", [
            'apply_to' => [[
                'type' => 'server',
                'server' => ['id' => $serverId],
            ]],
        ]);
        $this->assertSuccess($response, 'apply firewall to server');
    }

    /**
     * Delete a Cloud Firewall. A 404 (already gone) is treated as success.
     */
    public function deleteFirewall(int $firewallId): void
    {
        $response = $this->request('delete', "/firewalls/{$firewallId}");
        if ($response->status() === 404) {
            return;
        }
        $this->assertSuccess($response, 'delete firewall');
    }
}
