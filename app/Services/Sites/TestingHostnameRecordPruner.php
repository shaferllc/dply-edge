<?php

declare(strict_types=1);

namespace App\Services\Sites;

use App\Models\Site;
use App\Models\SiteDomain;
use App\Modules\Providers\Services\DigitalOceanService;

class TestingHostnameRecordPruner
{
    public function __construct(
        private readonly TestingHostnameProvisioner $provisioner,
        private ?DigitalOceanService $digitalOcean = null,
    ) {}

    /**
     * @return list<array{
     *     zone: string,
     *     hostname: string,
     *     record_id: int,
     *     record_type: string,
     *     record_name: string,
     *     record_data: string
     * }>
     */
    public function staleRecords(): array
    {
        $managed = array_fill_keys($this->managedHostnames(), true);
        $stale = [];

        foreach ($this->provisioner->configuredDomains() as $zone) {
            foreach ($this->digitalOcean()->getDomainRecords($zone) as $record) {
                if (strtoupper((string) ($record['type'] ?? '')) !== 'A') {
                    continue;
                }

                $hostname = $this->hostnameForRecord($zone, $record);
                if ($hostname === null || isset($managed[$hostname])) {
                    continue;
                }

                $stale[] = [
                    'zone' => $zone,
                    'hostname' => $hostname,
                    'record_id' => (int) ($record['id'] ?? 0),
                    'record_type' => 'A',
                    'record_name' => (string) ($record['name'] ?? ''),
                    'record_data' => (string) ($record['data'] ?? ''),
                ];
            }
        }

        return $stale;
    }

    /**
     * @return list<string>
     */
    public function managedHostnames(): array
    {
        $zones = $this->provisioner->configuredDomains();
        if ($zones === []) {
            return [];
        }

        $fromMeta = Site::query()
            ->get(['meta'])
            ->map(fn (Site $site): string => strtolower(trim($site->testingHostname())))
            ->filter(fn (string $hostname): bool => $this->isTestingHostname($hostname, $zones));

        $fromDomains = SiteDomain::query()
            ->get(['hostname'])
            ->pluck('hostname')
            ->map(fn (?string $hostname): string => strtolower(trim((string) $hostname)))
            ->filter(fn (string $hostname): bool => $this->isTestingHostname($hostname, $zones));

        return $fromMeta
            ->merge($fromDomains)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array{zone: string, record_id: int}  $record
     */
    public function deleteRecord(array $record): void
    {
        if ($record['record_id'] <= 0) {
            return;
        }

        $this->digitalOcean()->deleteDomainRecord((string) $record['zone'], (int) $record['record_id']);
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function hostnameForRecord(string $zone, array $record): ?string
    {
        $name = strtolower(trim((string) ($record['name'] ?? '')));
        if ($name === '') {
            return null;
        }

        if ($name === '@') {
            return $zone;
        }

        return $name.'.'.$zone;
    }

    /**
     * @param  array<int, string>  $zones
     */
    private function isTestingHostname(string $hostname, array $zones): bool
    {
        if ($hostname === '') {
            return false;
        }

        foreach ($zones as $zone) {
            if ($hostname === $zone || str_ends_with($hostname, '.'.$zone)) {
                return true;
            }
        }

        return false;
    }

    private function digitalOcean(): DigitalOceanService
    {
        return $this->digitalOcean ??= new DigitalOceanService((string) config('services.digitalocean.token'));
    }
}
