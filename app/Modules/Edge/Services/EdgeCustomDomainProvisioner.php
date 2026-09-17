<?php

declare(strict_types=1);

namespace App\Modules\Edge\Services;

use App\Models\EdgeDeployment;
use App\Models\ProviderCredential;
use App\Models\Site;
use App\Modules\Edge\Http\Middleware\ResolveEdgeCustomDomain;
use App\Modules\Edge\Support\FakeEdgeProvision;
use App\Modules\Notifications\Services\NotificationPublisher;
use App\Modules\Providers\Cloudflare\CloudflareDnsService;
use App\Modules\Providers\Cloudflare\EdgeCloudflareClient;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Provision DNS for custom hostnames on Edge sites — manual CNAME verification
 * or optional auto-provision via org Cloudflare DNS credentials — plus Phase 3b
 * Custom Hostnames (SSL for SaaS) on managed `dply_edge` delivery.
 */
final class EdgeCustomDomainProvisioner
{
    public function __construct(
        private readonly EdgeHostMapPublisher $hostMapPublisher,
        private readonly EdgeDeliveryContextResolver $contextResolver,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function provision(Site $site, string $hostname): ?array
    {
        $hostname = strtolower(trim($hostname));
        if ($hostname === '') {
            return null;
        }

        // Tier allowance: custom_domains_per_site (re-provisioning an attached
        // hostname never counts as a new one).
        $routing = is_array($site->edgeMeta()['routing'] ?? null) ? $site->edgeMeta()['routing'] : [];
        $attached = is_array($routing['custom_domains'] ?? null) ? $routing['custom_domains'] : [];
        $limit = $site->organization?->tierAllowances()['custom_domains_per_site'] ?? null;
        if ($limit !== null && ! isset($attached[$hostname]) && count($attached) >= (int) $limit) {
            throw new RuntimeException(trans_choice(
                '{1} Your plan includes :count custom domain per site. Upgrade to Pro for up to 100.|[2,*] Your plan includes :count custom domains per site.',
                (int) $limit,
            ));
        }

        $edgeHost = $this->cnameTargetFor($site);
        if ($edgeHost === '') {
            return $this->updateEntry($site, $hostname, [
                'mode' => 'manual',
                'dns_status' => 'pending',
                'cname_target' => '',
                'error' => __('No Edge hostname configured yet — complete the first deploy first.'),
            ]);
        }

        $activeId = $site->edgeMeta()['active_deployment_id'] ?? null;
        if (! is_string($activeId) || $activeId === '') {
            return $this->updateEntry($site, $hostname, [
                'mode' => 'manual',
                'dns_status' => 'pending',
                'cname_target' => $edgeHost,
                'error' => __('No active deployment — deploy the site before attaching a custom domain.'),
            ]);
        }

        $credential = $this->findCloudflareCredentialForZone($site, $hostname);
        if ($credential === null) {
            $entry = $this->updateEntry($site, $hostname, [
                'mode' => 'manual',
                'dns_status' => 'pending',
                'cname_target' => $edgeHost,
                'analytics_zone' => Site::deriveRegistrableDomain($hostname),
                'attached_at' => now()->toIso8601String(),
                'error' => null,
            ]);

            return $this->ensureCustomHostname($site->fresh(), $hostname, $entry);
        }

        $zone = $this->findOwnedCloudflareZone($credential, $hostname);
        if ($zone === null) {
            $entry = $this->updateEntry($site, $hostname, [
                'mode' => 'manual',
                'dns_status' => 'pending',
                'cname_target' => $edgeHost,
                'analytics_zone' => Site::deriveRegistrableDomain($hostname),
                'attached_at' => now()->toIso8601String(),
                'error' => null,
            ]);

            return $this->ensureCustomHostname($site->fresh(), $hostname, $entry);
        }

        $recordName = (string) Str::beforeLast($hostname, '.'.$zone);
        if ($recordName === '' || $recordName === $hostname) {
            $recordName = '@';
        }

        try {
            $dns = new CloudflareDnsService($credential);
            $dns->upsertCnameRecord($zone, $recordName, $edgeHost);

            $entry = $this->updateEntry($site, $hostname, [
                'mode' => 'auto',
                'dns_status' => 'ready',
                'cname_target' => $edgeHost,
                'zone' => $zone,
                'analytics_zone' => $zone,
                'record_name' => $recordName,
                'attached_at' => now()->toIso8601String(),
                'verified_at' => now()->toIso8601String(),
                'error' => null,
            ]);

            $this->publishReadyHostname($site->fresh(), $hostname);

            return $this->ensureCustomHostname($site->fresh(), $hostname, $entry);
        } catch (Throwable $e) {
            Log::warning('Edge custom-domain auto provisioning failed.', [
                'site_id' => $site->id,
                'hostname' => $hostname,
                'error' => $e->getMessage(),
            ]);

            return $this->updateEntry($site, $hostname, [
                'mode' => 'auto',
                'dns_status' => 'failed',
                'cname_target' => $edgeHost,
                'zone' => $zone,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function verify(Site $site, string $hostname): ?array
    {
        $hostname = strtolower(trim($hostname));
        if ($hostname === '') {
            return null;
        }

        $edgeHost = $this->cnameTargetFor($site);
        if ($edgeHost === '') {
            return $this->updateEntry($site, $hostname, [
                'dns_status' => 'pending',
                'error' => __('No Edge hostname configured yet — complete the first deploy first.'),
            ]);
        }

        $records = @dns_get_record($hostname, DNS_CNAME | DNS_A);
        if (! is_array($records) || $records === []) {
            return $this->updateEntry($site, $hostname, [
                'dns_status' => 'failed',
                'cname_target' => $edgeHost,
                'error' => __('No DNS records found for :hostname. Publish the CNAME and wait for propagation.', ['hostname' => $hostname]),
            ]);
        }

        $resolved = [];
        foreach ($records as $record) {
            $type = strtoupper((string) ($record['type'] ?? ''));
            if ($type === 'CNAME') {
                $resolved[] = strtolower(rtrim((string) ($record['target'] ?? ''), '.'));
            } elseif ($type === 'A') {
                $resolved[] = (string) ($record['ip'] ?? '');
            }
        }
        $resolved = array_filter($resolved);
        $expected = strtolower(rtrim($edgeHost, '.'));
        $matches = in_array($expected, $resolved, true);

        // Also accept CNAME → site edge hostname when UI shows a fallback origin override.
        if (! $matches) {
            $siteHost = strtolower(rtrim((string) $site->edgeHostname(), '.'));
            if ($siteHost !== '' && $siteHost !== $expected) {
                $matches = in_array($siteHost, $resolved, true);
            }
        }

        $entry = $this->updateEntry($site, $hostname, [
            'dns_status' => $matches ? 'ready' : 'failed',
            'cname_target' => $edgeHost,
            'verified_at' => now()->toIso8601String(),
            'error' => $matches
                ? null
                : __('Hostname resolves to :actual, expected :expected.', [
                    'actual' => implode(', ', $resolved),
                    'expected' => $expected,
                ]),
        ]);

        if ($matches) {
            $this->publishReadyHostname($site->fresh(), $hostname);
            $entry = $this->ensureCustomHostname($site->fresh(), $hostname, $entry);
            $entry = $this->syncCustomHostnameSsl($site->fresh(), $hostname) ?? $entry;
        }

        // P9b: notify subscribers when verification flips state.
        // Wrapped in try/catch — notification failures must not bubble
        // out of the verify path; the caller treats this as the source
        // of truth for the DNS row's status.
        try {
            $eventKey = $matches ? 'edge.domain.verified' : 'edge.domain.failing';
            $title = $matches
                ? sprintf('%s verified on %s', $hostname, $site->name)
                : sprintf('%s failing verification on %s', $hostname, $site->name);
            app(NotificationPublisher::class)->publish(
                eventKey: $eventKey,
                subject: $site->fresh(),
                title: $title,
                body: $entry['error'] ?? null,
                url: route('sites.show', ['server' => $site->server_id, 'site' => $site->id, 'section' => 'edge-routing', 'tab' => 'domains']),
                metadata: [
                    'hostname' => $hostname,
                    'expected_cname' => $expected,
                    'resolved' => $resolved,
                    'ssl_status' => $entry['ssl_status'] ?? null,
                ],
            );
        } catch (Throwable) {
            // Notification publish is best-effort.
        }

        return $entry;
    }

    /**
     * Poll Cloudflare for a pending Custom Hostname SSL status.
     *
     * @return array<string, mixed>|null
     */
    public function syncCustomHostnameSsl(Site $site, string $hostname): ?array
    {
        $hostname = strtolower(trim($hostname));
        if ($hostname === '' || ! $this->shouldUseCustomHostnames($site)) {
            return null;
        }

        $meta = $site->edgeMeta();
        $routing = is_array($meta['routing'] ?? null) ? $meta['routing'] : [];
        $domains = is_array($routing['custom_domains'] ?? null) ? $routing['custom_domains'] : [];
        $entry = is_array($domains[$hostname] ?? null) ? $domains[$hostname] : null;
        if ($entry === null) {
            return null;
        }

        if (FakeEdgeProvision::enabled()) {
            if (($entry['ssl_status'] ?? null) === 'active') {
                return $entry;
            }

            return $this->updateEntry($site, $hostname, [
                'ssl_status' => 'active',
                'ssl_error' => null,
                'ssl_synced_at' => now()->toIso8601String(),
            ]);
        }

        $customHostnameId = (string) ($entry['cf_custom_hostname_id'] ?? '');
        if ($customHostnameId === '') {
            return $this->ensureCustomHostname($site, $hostname, $entry);
        }

        try {
            $client = $this->platformClient();
            $zoneId = $this->platformZoneId($client);
            if ($zoneId === null) {
                return $this->updateEntry($site, $hostname, [
                    'ssl_status' => 'failed',
                    'ssl_error' => __('Managed Edge zone is not configured for Custom Hostnames.'),
                ]);
            }

            $remote = $client->getCustomHostname($zoneId, $customHostnameId);

            return $this->updateEntry($site, $hostname, $this->sslFieldsFromRemote($remote));
        } catch (Throwable $e) {
            Log::info('Edge custom-hostname SSL sync failed (non-fatal).', [
                'site_id' => $site->id,
                'hostname' => $hostname,
                'error' => $e->getMessage(),
            ]);

            return $this->updateEntry($site, $hostname, [
                'ssl_status' => 'failed',
                'ssl_error' => $e->getMessage(),
                'ssl_synced_at' => now()->toIso8601String(),
            ]);
        }
    }

    public function remove(Site $site, string $hostname): void
    {
        $hostname = strtolower(trim($hostname));
        $meta = $site->edgeMeta();
        $routing = is_array($meta['routing'] ?? null) ? $meta['routing'] : [];
        $domains = is_array($routing['custom_domains'] ?? null) ? $routing['custom_domains'] : [];

        $removed = $domains[$hostname] ?? null;
        unset($domains[$hostname]);

        $routing['custom_domains'] = $domains;
        $meta['routing'] = $routing;
        $site->update(['meta' => array_merge(is_array($site->meta) ? $site->meta : [], ['edge' => $meta])]);

        try {
            $this->hostMapPublisher->unpublishHostname($site, $hostname, $this->contextResolver->forSite($site));
        } catch (Throwable $e) {
            Log::info('Edge custom-domain KV cleanup failed (non-fatal).', [
                'site_id' => $site->id,
                'hostname' => $hostname,
                'error' => $e->getMessage(),
            ]);
        }

        ResolveEdgeCustomDomain::invalidateHostMap();

        if (is_array($removed)) {
            $this->deleteCustomHostnameRemote($site, $hostname, $removed);

            if (($removed['mode'] ?? null) === 'auto') {
                $this->removeAutoDnsRecord($site, $hostname, $removed);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $patch
     * @return array<string, mixed>
     */
    private function updateEntry(Site $site, string $hostname, array $patch): array
    {
        $meta = $site->edgeMeta();
        $routing = is_array($meta['routing'] ?? null) ? $meta['routing'] : [];
        $domains = is_array($routing['custom_domains'] ?? null) ? $routing['custom_domains'] : [];

        $existing = is_array($domains[$hostname] ?? null) ? $domains[$hostname] : [];
        $domains[$hostname] = array_merge($existing, ['hostname' => $hostname], $patch);

        $routing['custom_domains'] = $domains;
        $meta['routing'] = $routing;
        $site->update(['meta' => array_merge(is_array($site->meta) ? $site->meta : [], ['edge' => $meta])]);

        ResolveEdgeCustomDomain::invalidateHostMap();

        return $domains[$hostname];
    }

    private function publishReadyHostname(Site $site, string $hostname): void
    {
        $activeId = $site->edgeMeta()['active_deployment_id'] ?? null;
        if (! is_string($activeId) || $activeId === '') {
            throw new RuntimeException('No active deployment to attach domain to.');
        }

        $deployment = EdgeDeployment::query()->findOrFail($activeId);
        $context = $this->contextResolver->forSite($site);
        $this->hostMapPublisher->publishHostname($site, $deployment, $hostname, $context);
        ResolveEdgeCustomDomain::invalidateHostMap();
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private function ensureCustomHostname(Site $site, string $hostname, array $entry): array
    {
        if (! $this->shouldUseCustomHostnames($site)) {
            return $entry;
        }

        if (FakeEdgeProvision::enabled()) {
            return $this->updateEntry($site, $hostname, [
                'ssl_status' => 'active',
                'ssl_error' => null,
                'cf_custom_hostname_id' => (string) ($entry['cf_custom_hostname_id'] ?? 'fake-'.$hostname),
                'ownership_verification' => null,
                'ssl_synced_at' => now()->toIso8601String(),
            ]);
        }

        $existingId = (string) ($entry['cf_custom_hostname_id'] ?? '');
        if ($existingId !== '' && ($entry['ssl_status'] ?? null) === 'active') {
            return $entry;
        }

        try {
            $client = $this->platformClient();
            $zoneId = $this->platformZoneId($client);
            if ($zoneId === null) {
                return $this->updateEntry($site, $hostname, [
                    'ssl_status' => 'failed',
                    'ssl_error' => __('Managed Edge zone is not configured for Custom Hostnames.'),
                ]);
            }

            $remote = null;
            if ($existingId !== '') {
                try {
                    $remote = $client->getCustomHostname($zoneId, $existingId);
                } catch (Throwable) {
                    $remote = null;
                }
            }

            if ($remote === null) {
                $remote = $client->findCustomHostnameByHostname($zoneId, $hostname);
            }

            if ($remote === null) {
                $remote = $client->createCustomHostname($zoneId, $hostname);
            }

            return $this->updateEntry($site, $hostname, array_merge(
                [
                    'cf_custom_hostname_id' => (string) ($remote['id'] ?? ''),
                ],
                $this->sslFieldsFromRemote($remote),
            ));
        } catch (Throwable $e) {
            Log::warning('Edge Custom Hostname create failed.', [
                'site_id' => $site->id,
                'hostname' => $hostname,
                'error' => $e->getMessage(),
            ]);

            return $this->updateEntry($site, $hostname, [
                'ssl_status' => 'failed',
                'ssl_error' => $e->getMessage(),
                'ssl_synced_at' => now()->toIso8601String(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $remote
     * @return array<string, mixed>
     */
    private function sslFieldsFromRemote(array $remote): array
    {
        $ssl = is_array($remote['ssl'] ?? null) ? $remote['ssl'] : [];
        $rawStatus = strtolower((string) ($ssl['status'] ?? $remote['status'] ?? 'pending'));
        $sslStatus = match (true) {
            in_array($rawStatus, ['active', 'active_redeploying'], true) => 'active',
            in_array($rawStatus, ['expired', 'deleted', 'moved'], true) => 'failed',
            str_contains($rawStatus, 'fail') || str_contains($rawStatus, 'error') => 'failed',
            default => 'pending',
        };

        $ownership = null;
        if (is_array($remote['ownership_verification'] ?? null)) {
            $ov = $remote['ownership_verification'];
            $ownership = [
                'type' => (string) ($ov['type'] ?? 'txt'),
                'name' => (string) ($ov['name'] ?? ''),
                'value' => (string) ($ov['value'] ?? ''),
            ];
        }

        $sslError = null;
        if ($sslStatus === 'failed') {
            $sslError = (string) ($ssl['validation_errors'][0]['message'] ?? $remote['verification_errors'][0] ?? __('Certificate issuance failed.'));
        }

        return [
            'ssl_status' => $sslStatus,
            'ssl_raw_status' => $rawStatus,
            'ssl_error' => $sslError,
            'ownership_verification' => $ownership,
            'ssl_synced_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function deleteCustomHostnameRemote(Site $site, string $hostname, array $entry): void
    {
        if (! $this->shouldUseCustomHostnames($site) || FakeEdgeProvision::enabled()) {
            return;
        }

        $customHostnameId = (string) ($entry['cf_custom_hostname_id'] ?? '');
        if ($customHostnameId === '' || str_starts_with($customHostnameId, 'fake-')) {
            return;
        }

        try {
            $client = $this->platformClient();
            $zoneId = $this->platformZoneId($client);
            if ($zoneId === null) {
                return;
            }

            $client->deleteCustomHostname($zoneId, $customHostnameId);
        } catch (Throwable $e) {
            Log::info('Edge Custom Hostname delete failed (non-fatal).', [
                'site_id' => $site->id,
                'hostname' => $hostname,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function shouldUseCustomHostnames(Site $site): bool
    {
        if (! filter_var(config('edge.custom_hostnames.enabled', true), FILTER_VALIDATE_BOOLEAN)) {
            return false;
        }

        // BYO Cloudflare zones terminate TLS on the customer zone (orange cloud).
        return (string) ($site->edge_backend ?? '') !== 'org_cloudflare';
    }

    private function cnameTargetFor(Site $site): string
    {
        $fallback = trim((string) config('edge.custom_hostnames.fallback_origin', ''));
        if ($fallback !== '' && $this->shouldUseCustomHostnames($site)) {
            return strtolower(rtrim($fallback, '.'));
        }

        return (string) $site->edgeHostname();
    }

    private function platformClient(): EdgeCloudflareClient
    {
        // Always use platform credentials — Custom Hostnames live on the
        // managed worker zone, never on a BYO org Cloudflare account.
        return EdgeCloudflareClient::fromConfig();
    }

    private function platformZoneId(EdgeCloudflareClient $client): ?string
    {
        $zoneName = trim((string) config('edge.cloudflare.worker_zone_name'));
        if ($zoneName === '') {
            return null;
        }

        return $client->activeZoneId($zoneName);
    }

    private function findCloudflareCredentialForZone(Site $site, string $hostname): ?ProviderCredential
    {
        $labels = explode('.', $hostname);
        for ($i = 1; $i <= count($labels) - 2; $i++) {
            $zone = implode('.', array_slice($labels, $i));
            $credential = ProviderCredential::query()
                ->where('organization_id', $site->organization_id)
                ->where('provider', 'cloudflare')
                ->orderBy('name')
                ->get()
                ->first(function (ProviderCredential $cred) use ($zone): bool {
                    try {
                        return (new CloudflareDnsService($cred))->zoneExists($zone);
                    } catch (Throwable) {
                        return false;
                    }
                });

            if ($credential !== null) {
                return $credential;
            }
        }

        return null;
    }

    private function findOwnedCloudflareZone(ProviderCredential $credential, string $hostname): ?string
    {
        $labels = explode('.', $hostname);
        for ($i = 1; $i <= count($labels) - 2; $i++) {
            $candidate = implode('.', array_slice($labels, $i));
            try {
                if ((new CloudflareDnsService($credential))->zoneExists($candidate)) {
                    return $candidate;
                }
            } catch (Throwable) {
                continue;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function removeAutoDnsRecord(Site $site, string $hostname, array $entry): void
    {
        $zone = (string) ($entry['zone'] ?? '');
        $recordName = (string) ($entry['record_name'] ?? '');
        if ($zone === '' || $recordName === '') {
            return;
        }

        $credential = $this->findCloudflareCredentialForZone($site, $hostname);
        if ($credential === null) {
            return;
        }

        try {
            $dns = new CloudflareDnsService($credential);
            $fqdn = $recordName === '@' ? $zone : strtolower($recordName).'.'.$zone;
            $record = $dns->findCnameRecord($zone, $fqdn);
            if ($record !== null && isset($record['id'])) {
                $dns->deleteDnsRecord($zone, (string) $record['id']);
            }
        } catch (Throwable $e) {
            Log::info('Edge custom-domain Cloudflare record cleanup failed (non-fatal).', [
                'site_id' => $site->id,
                'hostname' => $hostname,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
