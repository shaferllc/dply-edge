<?php

declare(strict_types=1);

namespace App\Services\Concerns;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesHetznerDns
{
    /**
     * Whether a DNS zone exists in this Hetzner Cloud project.
     */
    public function zoneExists(string $zoneName): bool
    {
        return $this->findZone($zoneName) !== null;
    }

    /**
     * Create a Hetzner DNS zone, or return the existing one if it's already
     * registered under this project. Idempotent. Used by the testing-zone
     * auto-provision path so the operator doesn't have to pre-create the
     * Dply-owned testing zones (e.g. on-dply.cc) in their Hetzner project.
     *
     * @return array<string, mixed>
     */
    public function createZone(string $zoneName): array
    {
        $zoneName = strtolower(trim($zoneName));
        if ($zoneName === '') {
            throw new \RuntimeException('Zone name is required.');
        }

        $existing = $this->findZone($zoneName);
        if ($existing !== null) {
            return $existing;
        }

        // Hetzner DNS requires a `mode` on create — `primary` for zones
        // where Hetzner is authoritative (the case for Dply-owned testing
        // zones), `secondary` for slave zones pulled from another master.
        $response = $this->request('post', '/zones', [
            'name' => $zoneName,
            'mode' => 'primary',
        ]);
        $this->assertSuccess($response, 'create zone');

        $created = $response->json('zone');
        if (! is_array($created)) {
            $created = $this->findZone($zoneName);
        }

        return is_array($created) ? $created : ['name' => $zoneName];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findZone(string $zoneName): ?array
    {
        $zoneName = strtolower(trim($zoneName));
        if ($zoneName === '') {
            return null;
        }

        $response = $this->request('get', '/zones', ['name' => $zoneName]);
        $this->assertSuccess($response, 'list zones');

        foreach ($response->json('zones') ?? [] as $zone) {
            if (! is_array($zone)) {
                continue;
            }

            $name = strtolower((string) ($zone['name'] ?? ''));
            if ($name === $zoneName) {
                return $zone;
            }
        }

        return null;
    }

    /**
     * Create or replace an A/AAAA-style RRset and return the RRset payload.
     *
     * @return array<string, mixed>
     */
    public function upsertZoneRecord(
        string $zoneName,
        string $type,
        string $recordName,
        string $value,
        int $ttl = 60
    ): array {
        $type = strtoupper(trim($type));
        $rrName = self::normalizeRrsetName($recordName, $zoneName);
        $zoneKey = rawurlencode($zoneName);
        $rrPath = rawurlencode($rrName).'/'.$type;

        $existing = $this->getZoneRrset($zoneName, $rrName, $type);
        if ($existing !== null) {
            $response = $this->request(
                'post',
                "/zones/{$zoneKey}/rrsets/{$rrPath}/actions/set_records",
                [
                    'records' => [
                        ['value' => $value],
                    ],
                ]
            );
            $this->assertSuccess($response, 'set zone record');

            return $this->getZoneRrset($zoneName, $rrName, $type) ?? $existing;
        }

        $response = $this->request('post', "/zones/{$zoneKey}/rrsets", [
            'name' => $rrName,
            'type' => $type,
            'ttl' => $ttl,
            'records' => [
                ['value' => $value],
            ],
        ]);
        $this->assertSuccess($response, 'create zone record');

        $rrset = $response->json('rrset');
        if (! is_array($rrset) || $rrset === []) {
            throw new \RuntimeException('Hetzner API did not return an RRset.');
        }

        return $rrset;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getZoneRrset(string $zoneName, string $recordName, string $type): ?array
    {
        $type = strtoupper(trim($type));
        $rrName = self::normalizeRrsetName($recordName, $zoneName);
        $zoneKey = rawurlencode($zoneName);
        $rrPath = rawurlencode($rrName).'/'.$type;

        $response = $this->request('get', "/zones/{$zoneKey}/rrsets/{$rrPath}");

        if ($response->status() === 404) {
            return null;
        }

        $this->assertSuccess($response, 'get zone record');

        $rrset = $response->json('rrset');

        return is_array($rrset) ? $rrset : null;
    }

    public function deleteZoneRrset(string $zoneName, string $recordName, string $type): void
    {
        $type = strtoupper(trim($type));
        $rrName = self::normalizeRrsetName($recordName, $zoneName);
        $zoneKey = rawurlencode($zoneName);
        $rrPath = rawurlencode($rrName).'/'.$type;

        $response = $this->request('delete', "/zones/{$zoneKey}/rrsets/{$rrPath}");
        if ($response->status() === 404) {
            return;
        }

        $this->assertSuccess($response, 'delete zone record');
    }

    /**
     * Map dply relative record names to Hetzner RRset names (@ for apex).
     */
    public static function normalizeRrsetName(string $recordName, string $zoneName): string
    {
        $recordName = strtolower(trim($recordName));
        $zoneName = strtolower(trim($zoneName));

        if ($recordName === '' || $recordName === '@' || $recordName === $zoneName) {
            return '@';
        }

        if (str_ends_with($recordName, '.'.$zoneName)) {
            $recordName = substr($recordName, 0, -1 * (strlen($zoneName) + 1));
        }

        return $recordName !== '' ? $recordName : '@';
    }
}
