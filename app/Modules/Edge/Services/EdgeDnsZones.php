<?php

declare(strict_types=1);

namespace App\Modules\Edge\Services;

use App\Models\EdgeDnsZone;
use App\Models\Organization;
use App\Models\Site;
use App\Modules\Edge\Support\FakeEdgeProvision;
use App\Modules\Providers\Cloudflare\EdgeCloudflareClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * dply-run DNS. A customer's domain becomes a zone in dply's Cloudflare
 * account; once they point its nameservers at us it turns active and
 * hostnames under it attach with no records to copy.
 *
 * Only an ACTIVE zone proves ownership — anyone can add any name as a
 * pending zone — so nothing is provisioned from a pending one.
 *
 * Fake mode (DPLY_FAKE_EDGE): no Cloudflare calls; a zone activates on its
 * first check and records live in the cache, so the flow runs locally.
 */
final class EdgeDnsZones
{
    public static function enabled(): bool
    {
        return (bool) config('edge.dns.enabled', false);
    }

    /** The zone name for a hostname: its registrable domain. */
    public static function zoneNameFor(string $hostname): string
    {
        // ponytail: last-two-labels, so example.co.uk becomes co.uk and
        // Cloudflare rejects it; use a public-suffix list if that bites.
        return Site::deriveRegistrableDomain(EdgeCustomDomainProvisioner::normalizeHostname($hostname));
    }

    public function add(Organization $organization, string $hostname, ?string $userId = null): EdgeDnsZone
    {
        if (! self::enabled()) {
            throw new RuntimeException(__('dply-run DNS is not available yet.'));
        }

        $name = self::zoneNameFor($hostname);
        $existing = EdgeDnsZone::query()->where('name', $name)->first();
        if ($existing !== null) {
            if ($existing->organization_id !== $organization->id) {
                throw new RuntimeException(__('dply already runs DNS for :name for another organization. Contact support if you own it.', ['name' => $name]));
            }

            return $existing;
        }

        if (FakeEdgeProvision::enabled()) {
            return EdgeDnsZone::query()->create([
                'organization_id' => $organization->id,
                'name' => $name,
                'cloudflare_zone_id' => 'fake-'.Str::lower(Str::random(24)),
                'status' => EdgeDnsZone::STATUS_PENDING,
                'name_servers' => array_values((array) config('edge.dns.fake_nameservers')),
                'original_name_servers' => ['ns1.example-registrar.test', 'ns2.example-registrar.test'],
                'created_by' => $userId,
            ]);
        }

        $client = $this->client();
        $zone = $client->createZone($name);
        $zoneId = (string) ($zone['id'] ?? '');
        if ($zoneId === '') {
            throw new RuntimeException(__('Cloudflare did not return a zone for :name.', ['name' => $name]));
        }

        $nameServers = array_values((array) ($zone['name_servers'] ?? []));
        $set = config('edge.dns.nameserver_set');
        if ($set !== null) {
            // Branded nameservers (ns1.dply.io …). The zone keeps Cloudflare's
            // pair if the set isn't configured on the account yet.
            try {
                $client->useAccountNameserverSet($zoneId, (int) $set);
                $nameServers = array_values((array) ($client->getZone($zoneId)['name_servers'] ?? $nameServers));
            } catch (Throwable $e) {
                Log::warning('Edge DNS: account custom nameservers not applied.', ['zone' => $name, 'error' => $e->getMessage()]);
            }
        }

        try {
            $client->triggerDnsScan($zoneId);
        } catch (Throwable $e) {
            Log::info('Edge DNS: record scan did not start.', ['zone' => $name, 'error' => $e->getMessage()]);
        }

        return EdgeDnsZone::query()->create([
            'organization_id' => $organization->id,
            'name' => $name,
            'cloudflare_zone_id' => $zoneId,
            'status' => EdgeDnsZone::STATUS_PENDING,
            'name_servers' => $nameServers,
            'original_name_servers' => array_values((array) ($zone['original_name_servers'] ?? [])),
            'created_by' => $userId,
        ]);
    }

    /** Re-read the zone's status. Returns true when it just became active. */
    public function refresh(EdgeDnsZone $zone, bool $nudge = false): bool
    {
        if ($zone->isActive()) {
            return false;
        }

        $status = EdgeDnsZone::STATUS_PENDING;
        if (FakeEdgeProvision::enabled()) {
            $status = EdgeDnsZone::STATUS_ACTIVE;
        } else {
            $client = $this->client();
            if ($nudge) {
                $client->requestZoneActivationCheck((string) $zone->cloudflare_zone_id);
            }
            $status = (string) ($client->getZone((string) $zone->cloudflare_zone_id)['status'] ?? EdgeDnsZone::STATUS_PENDING);
        }

        $active = $status === EdgeDnsZone::STATUS_ACTIVE;
        $zone->forceFill([
            'status' => $active ? EdgeDnsZone::STATUS_ACTIVE : EdgeDnsZone::STATUS_PENDING,
            'activated_at' => $active ? now() : null,
            'last_checked_at' => now(),
        ])->save();

        return $active;
    }

    /**
     * Records Cloudflare's scan found that aren't in the zone yet.
     *
     * @return list<array{id: string, type: string, name: string, content: string, priority: ?int}>
     */
    public function scannedRecords(EdgeDnsZone $zone): array
    {
        if ($zone->records_reviewed) {
            return [];
        }

        $rows = FakeEdgeProvision::enabled()
            ? [
                ['id' => 'mx1', 'type' => 'MX', 'name' => $zone->name, 'content' => 'mx1.example-mail.test', 'priority' => 10],
                ['id' => 'spf', 'type' => 'TXT', 'name' => $zone->name, 'content' => 'v=spf1 include:example-mail.test ~all'],
            ]
            : $this->client()->scannedDnsRecords((string) $zone->cloudflare_zone_id);

        return array_map(self::shape(...), $rows);
    }

    /**
     * Accept the scanned records whose ids are in $keep, reject the rest.
     *
     * @param  list<string>  $keep
     */
    public function reviewScanned(EdgeDnsZone $zone, array $keep): void
    {
        if (! FakeEdgeProvision::enabled()) {
            $rows = $this->client()->scannedDnsRecords((string) $zone->cloudflare_zone_id);
            $body = static fn (array $r): array => array_filter([
                'type' => $r['type'] ?? null,
                'name' => $r['name'] ?? null,
                'content' => $r['content'] ?? null,
                'ttl' => $r['ttl'] ?? 1,
                'proxied' => $r['proxied'] ?? null,
                'priority' => $r['priority'] ?? null,
            ], static fn ($v): bool => $v !== null);
            $accepts = $rejects = [];
            foreach ($rows as $row) {
                if (in_array((string) ($row['id'] ?? ''), $keep, true)) {
                    $accepts[] = $body($row);
                } else {
                    $rejects[] = $body($row);
                }
            }
            $this->client()->reviewScannedDnsRecords((string) $zone->cloudflare_zone_id, $accepts, $rejects);
        } else {
            foreach ($this->scannedRecords($zone) as $row) {
                if (in_array($row['id'], $keep, true)) {
                    $this->addRecord($zone, $row['type'], $row['name'], $row['content'], $row['priority']);
                }
            }
        }

        $zone->forceFill(['records_reviewed' => true])->save();
    }

    /**
     * Records in the zone, dply's own and the customer's.
     *
     * @return list<array{id: string, type: string, name: string, content: string, priority: ?int}>
     */
    public function records(EdgeDnsZone $zone): array
    {
        $rows = FakeEdgeProvision::enabled()
            ? array_values((array) Cache::get(self::fakeKey($zone), []))
            : $this->client()->listZoneDnsRecords((string) $zone->cloudflare_zone_id);

        return array_map(self::shape(...), $rows);
    }

    public function addRecord(EdgeDnsZone $zone, string $type, string $name, string $content, ?int $priority = null): void
    {
        $type = strtoupper($type);
        $name = trim(strtolower($name));
        $fqdn = ($name === '' || $name === '@') ? $zone->name : (str_ends_with($name, '.'.$zone->name) || $name === $zone->name ? $name : $name.'.'.$zone->name);
        $record = array_filter([
            'type' => $type,
            'name' => $fqdn,
            'content' => trim($content),
            'ttl' => 1,
            'priority' => $type === 'MX' ? ($priority ?? 10) : null,
        ], static fn ($v): bool => $v !== null);

        if (FakeEdgeProvision::enabled()) {
            $rows = (array) Cache::get(self::fakeKey($zone), []);
            $rows[] = ['id' => Str::lower(Str::random(12)), ...$record];
            Cache::forever(self::fakeKey($zone), $rows);

            return;
        }

        $this->client()->createZoneDnsRecord((string) $zone->cloudflare_zone_id, $record);
    }

    public function deleteRecord(EdgeDnsZone $zone, string $recordId): void
    {
        if (FakeEdgeProvision::enabled()) {
            Cache::forever(self::fakeKey($zone), array_values(array_filter(
                (array) Cache::get(self::fakeKey($zone), []),
                static fn (array $r): bool => ($r['id'] ?? '') !== $recordId,
            )));

            return;
        }

        $this->client()->deleteZoneDnsRecord((string) $zone->cloudflare_zone_id, $recordId);
    }

    /** Point $hostname at $target with a proxied CNAME in the zone (idempotent). */
    public function pointHostname(EdgeDnsZone $zone, string $hostname, string $target): void
    {
        $existing = collect($this->records($zone))->first(
            fn (array $r): bool => $r['name'] === $hostname && in_array($r['type'], ['CNAME', 'A', 'AAAA'], true),
        );
        if ($existing !== null && $existing['type'] === 'CNAME' && $existing['content'] === $target) {
            return;
        }
        if ($existing !== null) {
            $this->deleteRecord($zone, $existing['id']);
        }

        if (FakeEdgeProvision::enabled()) {
            $this->addRecord($zone, 'CNAME', $hostname, $target);

            return;
        }

        $this->client()->createZoneDnsRecord((string) $zone->cloudflare_zone_id, [
            'type' => 'CNAME', 'name' => $hostname, 'content' => $target, 'ttl' => 1, 'proxied' => true,
        ]);
    }

    public function unpointHostname(EdgeDnsZone $zone, string $hostname): void
    {
        foreach ($this->records($zone) as $record) {
            if ($record['name'] === $hostname && $record['type'] === 'CNAME') {
                $this->deleteRecord($zone, $record['id']);
            }
        }
    }

    public function remove(EdgeDnsZone $zone): void
    {
        if (! FakeEdgeProvision::enabled() && $zone->cloudflare_zone_id !== null) {
            $this->client()->deleteZone($zone->cloudflare_zone_id);
        }
        Cache::forget(self::fakeKey($zone));
        $zone->delete();
    }

    /** The org's zone that covers $hostname (longest name wins), active only unless asked. */
    public function zoneFor(string $organizationId, string $hostname, bool $activeOnly = true): ?EdgeDnsZone
    {
        $hostname = EdgeCustomDomainProvisioner::normalizeHostname($hostname);

        return EdgeDnsZone::query()
            ->where('organization_id', $organizationId)
            ->when($activeOnly, fn ($q) => $q->where('status', EdgeDnsZone::STATUS_ACTIVE))
            ->get()
            ->filter(fn (EdgeDnsZone $z): bool => $z->covers($hostname))
            ->sortByDesc(fn (EdgeDnsZone $z): int => strlen($z->name))
            ->first();
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{id: string, type: string, name: string, content: string, priority: ?int}
     */
    private static function shape(array $row): array
    {
        return [
            'id' => (string) ($row['id'] ?? ''),
            'type' => strtoupper((string) ($row['type'] ?? '')),
            'name' => strtolower((string) ($row['name'] ?? '')),
            'content' => (string) ($row['content'] ?? ''),
            'priority' => isset($row['priority']) ? (int) $row['priority'] : null,
        ];
    }

    private static function fakeKey(EdgeDnsZone $zone): string
    {
        return 'edge-fake-dns-records.'.$zone->id;
    }

    private function client(): EdgeCloudflareClient
    {
        return EdgeCloudflareClient::fromConfig();
    }
}
