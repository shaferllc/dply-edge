<?php

declare(strict_types=1);

namespace App\Modules\Edge\Services;

use App\Models\Site;
use App\Modules\Edge\Support\EdgeTestingDomains;
use App\Modules\Edge\Support\FakeEdgeProvision;
use App\Modules\Providers\Cloudflare\EdgeCloudflareClient;
use App\Modules\Providers\Services\DigitalOceanService;
use App\Services\Sites\Dns\SiteDnsProviderFactory;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Ensures an Edge site's default delivery hostname resolves when the zone is
 * managed outside Cloudflare Worker routes (e.g. DigitalOcean DNS). When the
 * platform Worker zone matches the hostname apex, wildcard routes cover all
 * subdomains and this becomes a no-op.
 */
final class EdgeTestingHostnameProvisioner
{
    public function provision(Site $site): ?string
    {
        if (FakeEdgeProvision::enabled()) {
            return null;
        }

        if ($site->edge_backend === 'org_cloudflare') {
            return null;
        }

        $host = strtolower($site->edgeHostname());
        $zone = EdgeTestingDomains::zoneForHost($host);
        if ($zone === null) {
            return null;
        }

        $recordName = (string) Str::beforeLast($host, '.'.$zone);
        if ($recordName === '' || $recordName === $host) {
            return null;
        }

        $workerZone = strtolower(trim((string) config('edge.cloudflare.worker_zone_name')));
        if ($workerZone !== '' && $zone === $workerZone) {
            $zoneOnCloudflare = EdgeCloudflareClient::fromConfig()->activeZoneId($workerZone) !== null;
            if ($zoneOnCloudflare) {
                $this->store($site, [
                    'status' => 'ready',
                    'hostname' => $host,
                    'zone' => $zone,
                    'covered_by_worker_routes' => true,
                    'provisioned_at' => now()->toIso8601String(),
                ]);

                return 'DNS: covered by Cloudflare Worker routes on *.'.$zone;
            }
        }

        if ($workerZone !== '' && $zone === $workerZone) {
            $this->store($site, [
                'status' => 'pending',
                'hostname' => $host,
                'zone' => $zone,
                'reason' => 'worker_zone_not_on_cloudflare',
            ]);

            return 'DNS: pending — add '.$zone.' to Cloudflare and deploy Worker routes.';
        }

        $token = trim((string) config('services.digitalocean.token'));
        if ($token === '') {
            $this->store($site, [
                'status' => 'skipped',
                'hostname' => $host,
                'zone' => $zone,
                'reason' => 'missing_token',
            ]);

            return 'DNS: skipped — no app-level DigitalOcean token for Edge testing zone.';
        }

        [$type, $value] = $this->recordTarget($zone);

        try {
            $wildcard = $this->wildcardCovering($token, $zone);
            if ($wildcard !== null) {
                $this->store($site, [
                    'status' => 'ready',
                    'hostname' => $host,
                    'zone' => $zone,
                    'record_name' => $recordName,
                    'record_type' => strtoupper((string) ($wildcard['type'] ?? '')),
                    'record_data' => (string) ($wildcard['data'] ?? ''),
                    'covered_by_wildcard' => true,
                    'provisioned_at' => now()->toIso8601String(),
                ]);

                return 'DNS: covered by *.'.$zone.' '.($wildcard['type'] ?? '').' '.($wildcard['data'] ?? '');
            }

            $record = SiteDnsProviderFactory::forDigitalOceanAppConfigToken($token)
                ->upsertRecord($zone, $type, $recordName, $value);

            $this->store($site, [
                'status' => 'ready',
                'hostname' => $host,
                'zone' => $zone,
                'record_name' => $recordName,
                'record_id' => $record['id'] ?? null,
                'record_type' => $type,
                'record_data' => $value,
                'provisioned_at' => now()->toIso8601String(),
            ]);

            return 'DNS: '.$host.' → '.$type.' '.$value;
        } catch (\Throwable $e) {
            Log::warning('Edge testing hostname DNS provisioning failed.', [
                'site_id' => $site->id,
                'hostname' => $host,
                'error' => $e->getMessage(),
            ]);

            $this->store($site, [
                'status' => 'failed',
                'hostname' => $host,
                'zone' => $zone,
                'record_name' => $recordName,
                'error' => $e->getMessage(),
                'failed_at' => now()->toIso8601String(),
            ]);

            return 'DNS: failed — '.$e->getMessage();
        }
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function recordTarget(string $zone): array
    {
        $target = trim((string) config('edge.testing_dns_target'));
        if ($target === '') {
            return ['CNAME', rtrim($zone, '.').'.'];
        }

        return filter_var($target, FILTER_VALIDATE_IP) !== false
            ? ['A', $target]
            : ['CNAME', rtrim($target, '.').'.'];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function wildcardCovering(string $token, string $zone): ?array
    {
        $do = new DigitalOceanService($token);
        foreach ($do->getDomainRecords($zone) as $record) {
            $name = trim((string) ($record['name'] ?? ''));
            $type = strtoupper((string) ($record['type'] ?? ''));
            if ($name === '*' && in_array($type, ['A', 'AAAA', 'CNAME'], true)) {
                return $record;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function store(Site $site, array $payload): void
    {
        $meta = $site->edgeMeta();
        $routing = is_array($meta['routing'] ?? null) ? $meta['routing'] : [];
        $routing['testing_dns'] = $payload;
        $meta['routing'] = $routing;

        $site->update([
            'meta' => array_merge(is_array($site->meta) ? $site->meta : [], ['edge' => $meta]),
        ]);
    }
}
