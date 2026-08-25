<?php

namespace App\Services\Sites;

use App\Models\ProviderCredential;
use App\Models\Site;
use App\Models\SitePreviewDomain;
use App\Modules\Providers\Cloudflare\CloudflareDnsService;
use App\Modules\Providers\Namecheap\NamecheapDnsService;
use App\Modules\Providers\Services\DigitalOceanService;
use App\Services\Sites\Dns\SiteDnsProviderFactory;
use App\Support\Preview\UnifiedPreviewHostname;
use App\Support\TestingDomains;
use Illuminate\Support\Str;

class TestingHostnameProvisioner
{
    public function provision(Site $site): ?SitePreviewDomain
    {
        $site->loadMissing(['server', 'previewDomains', 'organization', 'dnsProviderCredential']);

        if (! $this->isEnabledForSite($site)) {
            $this->storeResult($site, [
                'status' => 'skipped',
                'reason' => 'disabled',
            ]);

            return null;
        }

        $serverIp = trim((string) ($site->server->ip_address ?? ''));
        if ($serverIp === '') {
            $this->storeResult($site, [
                'status' => 'skipped',
                'reason' => 'missing_server_ip',
            ]);

            return null;
        }

        // Testing hostnames live on Dply-owned Namecheap zones
        // (config/product/testing_domains.php).
        $routing = $this->resolveTestingProviderForSite($site);
        $dnsProviderKey = $routing['provider'];
        $dnsProvider = $routing['dns_provider'];
        $pool = $routing['pool'];

        $zone = $this->chooseZoneFromPool($site, $pool);
        $hostname = $this->buildHostname($site, $zone);
        $recordName = $this->relativeRecordName($hostname, $zone);

        if ($dnsProviderKey === 'cloudflare') {
            $token = TestingDomains::cloudflareApiTokenForZone($zone);
            if ($token === '') {
                throw new \RuntimeException('Dply has no Cloudflare API token that can see zone ['.$zone.'].');
            }
            $dnsProvider = SiteDnsProviderFactory::forCloudflareAppConfigToken($token);
        }

        try {
            $record = $dnsProvider->upsertRecord($zone, 'A', $recordName, $serverIp);

            SitePreviewDomain::query()
                ->where('site_id', $site->id)
                ->where('hostname', '!=', $hostname)
                ->update(['is_primary' => false]);

            $domain = SitePreviewDomain::query()->updateOrCreate([
                'site_id' => $site->id,
                'hostname' => $hostname,
            ], [
                'label' => 'Managed preview',
                'zone' => $zone,
                'record_name' => $recordName,
                'provider_type' => $dnsProviderKey,
                'provider_record_id' => (string) ($record['id'] ?? ''),
                'record_type' => 'A',
                'record_data' => $serverIp,
                'dns_status' => 'ready',
                'ssl_status' => 'none',
                'is_primary' => true,
                'auto_ssl' => true,
                'https_redirect' => true,
                'managed_by_dply' => true,
                'last_dns_checked_at' => now(),
                'meta' => [
                    'provisioned_at' => now()->toIso8601String(),
                ],
            ]);

            $this->storeResult($site, [
                'status' => 'ready',
                'hostname' => $hostname,
                'zone' => $zone,
                'record_name' => $recordName,
                // Keep the provider's id verbatim — DigitalOcean returns an int,
                // but Hetzner/Cloudflare return a string (Hetzner: "<name>/<TYPE>").
                // Casting to int (the old behaviour) collapsed those to 0, which
                // made the delete path unable to find — and therefore unable to
                // remove — the record when the site was torn down.
                'record_id' => $record['id'] ?? null,
                'record_type' => 'A',
                'record_data' => $serverIp,
                'provisioned_at' => now()->toIso8601String(),
                'credential_source' => $this->credentialSourceForSite($site),
            ]);

            return $domain;
        } catch (\Throwable $e) {
            $this->storeResult($site, [
                'status' => 'failed',
                'reason' => 'provider_error',
                'hostname' => $hostname,
                'zone' => $zone,
                'record_name' => $recordName,
                'record_data' => $serverIp,
                'error' => $e->getMessage(),
                'failed_at' => now()->toIso8601String(),
            ]);

            return null;
        }
    }

    /**
     * Provision a managed testing-domain hostname for a single tenant so the app
     * can be reached as that tenant (e.g. acme-worker-1-ab12cd.on-dply.cc) before
     * the customer points their real DNS. Mirrors {@see provision()} but stores
     * the result on the tenant row's meta instead of a SitePreviewDomain. The
     * caller must re-apply the webserver config afterwards so the new hostname
     * lands in the vhost server_name (it's already in {@see Site::webserverHostnames()}).
     *
     * Idempotent: re-running reuses the tenant's existing hostname/zone.
     */
    /**
     * Mint an ADDITIONAL managed preview hostname (non-primary) on a dply-owned
     * zone — the "Add preview URL" action. Same managed DNS provisioning as the
     * primary {@see provision()}, but with a unique random hostname and without
     * demoting the existing primary, so a site can carry several share URLs.
     */
    public function provisionAdditional(Site $site, ?string $label = null): ?SitePreviewDomain
    {
        $site->loadMissing(['server', 'previewDomains', 'organization', 'dnsProviderCredential']);

        if (! $this->isEnabledForSite($site)) {
            return null;
        }

        $serverIp = trim((string) ($site->server->ip_address ?? ''));
        if ($serverIp === '') {
            return null;
        }

        $routing = $this->resolveTestingProviderForSite($site);
        $dnsProviderKey = $routing['provider'];
        $dnsProvider = $routing['dns_provider'];
        $pool = $routing['pool'];

        $zone = $this->chooseZoneFromPool($site, $pool);
        $hostname = $this->buildAdditionalHostname($site, $zone);
        $recordName = $this->relativeRecordName($hostname, $zone);

        if ($dnsProviderKey === 'cloudflare') {
            $token = TestingDomains::cloudflareApiTokenForZone($zone);
            if ($token === '') {
                return null;
            }
            $dnsProvider = SiteDnsProviderFactory::forCloudflareAppConfigToken($token);
        }

        try {
            $record = $dnsProvider->upsertRecord($zone, 'A', $recordName, $serverIp);

            return SitePreviewDomain::query()->create([
                'site_id' => $site->id,
                'hostname' => $hostname,
                'label' => $label !== null && trim($label) !== '' ? trim($label) : 'Preview URL',
                'zone' => $zone,
                'record_name' => $recordName,
                'provider_type' => $dnsProviderKey,
                'provider_record_id' => (string) ($record['id'] ?? ''),
                'record_type' => 'A',
                'record_data' => $serverIp,
                'dns_status' => 'ready',
                'ssl_status' => 'none',
                'is_primary' => false,
                'auto_ssl' => true,
                'https_redirect' => true,
                'managed_by_dply' => true,
                'last_dns_checked_at' => now(),
                'meta' => [
                    'provisioned_at' => now()->toIso8601String(),
                ],
            ]);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * A unique managed hostname for an additional preview URL — the per-site
     * canonical name is deterministic (one per site), so additional ones get a
     * short random suffix and are checked for collisions before use.
     */
    private function buildAdditionalHostname(Site $site, string $zone): string
    {
        $base = Str::slug($site->slug !== '' ? $site->slug : $site->name);
        $base = trim($base, '-');
        $base = $base !== '' ? $base : 'site';

        for ($attempt = 0; $attempt < 6; $attempt++) {
            $suffix = Str::lower(Str::random(6));
            $label = rtrim(Str::limit($base.'-'.$suffix, 63, ''), '-');
            $candidate = $label.'.'.$zone;

            if (! SitePreviewDomain::query()->where('hostname', $candidate)->exists()) {
                return $candidate;
            }
        }

        return rtrim(Str::limit($base.'-'.Str::lower(Str::random(10)), 63, ''), '-').'.'.$zone;
    }

    /**
     * Best-effort removal of a single managed preview domain's provider DNS record
     * (using the row's own stored zone + record id), so removing an added preview
     * URL doesn't orphan its A record. Failures are swallowed — the row removal
     * proceeds regardless, and an orphaned record is harmless beyond clutter.
     */
    public function deleteManagedPreviewRecord(Site $site, SitePreviewDomain $domain): void
    {
        $recordId = trim((string) ($domain->provider_record_id ?? ''));
        $zone = trim((string) ($domain->zone ?? ''));
        if (! (bool) $domain->managed_by_dply || $recordId === '' || $recordId === '0' || $zone === '') {
            return;
        }

        try {
            $routing = $this->resolveTestingProviderForSite($site);
            $routing['dns_provider']->deleteRecord($zone, $recordId);
        } catch (\Throwable $e) {
            // best-effort — leave the row removal to proceed.
        }
    }

    private function normalizedSiteDnsZone(Site $site): ?string
    {
        $z = strtolower(trim((string) ($site->dns_zone ?? '')));

        return $z !== '' ? $z : null;
    }

    public function chooseZone(Site $site): string
    {
        $domains = $this->configuredDomains();
        if ($domains === []) {
            throw new \RuntimeException('No testing domains are configured.');
        }

        $domains = app(UnifiedPreviewHostname::class)->orderedTestingZones($domains);

        $strategy = (string) config('services.digitalocean.testing_domain_strategy', 'deterministic');

        return match ($strategy) {
            'random' => $domains[array_rand($domains)],
            default => $domains[$this->deterministicIndex($site, count($domains))],
        };
    }

    public function buildHostname(Site $site, string $zone): string
    {
        $hostnames = app(UnifiedPreviewHostname::class);
        if ($hostnames->enabled()) {
            return $hostnames->canonicalHostname($site, $zone);
        }

        return $this->legacyBuildHostname($site, $zone);
    }

    private function legacyBuildHostname(Site $site, string $zone): string
    {
        $base = Str::slug($site->slug !== '' ? $site->slug : $site->name);
        $base = trim($base, '-');
        $base = $base !== '' ? $base : 'site';

        $suffixSource = $site->id ?: ($site->server_id ?: $site->name);
        $suffix = Str::lower(substr(sha1((string) $suffixSource), 0, 8));
        $label = Str::limit($base.'-'.$suffix, 63, '');
        $label = rtrim($label, '-');

        return $label.'.'.$zone;
    }

    public function isEnabledForSite(Site $site): bool
    {
        if (! (bool) config('services.digitalocean.auto_testing_hostname_enabled')) {
            return false;
        }

        if (! $this->hasAvailableToken()) {
            return false;
        }

        if ($this->normalizedSiteDnsZone($site) !== null) {
            return true;
        }

        // True if any provider pool has at least one zone configured.
        return TestingDomains::vm() !== [];
    }

    public function delete(Site $site): void
    {
        $site->loadMissing(['server', 'previewDomains', 'dnsProviderCredential']);

        $testingMeta = is_array($site->meta['testing_hostname'] ?? null) ? $site->meta['testing_hostname'] : [];
        $hostname = strtolower(trim((string) ($testingMeta['hostname'] ?? $site->testingHostname())));
        if ($hostname === '') {
            return;
        }

        $zone = is_string($testingMeta['zone'] ?? null) && $testingMeta['zone'] !== ''
            ? (string) $testingMeta['zone']
            : null;
        if ($zone === null || trim($zone) === '') {
            $zone = $this->normalizedSiteDnsZone($site);
        }
        if ($zone === null || trim($zone) === '') {
            $preview = $site->primaryPreviewDomain();
            $z = is_string($preview?->zone) ? trim($preview->zone) : '';
            $zone = $z !== '' ? strtolower($z) : null;
        }
        if ($zone === null || trim($zone) === '') {
            $zone = $this->configuredZoneForHostname($hostname);
        }
        if ($zone === null || ! $this->hasAvailableToken()) {
            return;
        }

        $recordName = is_string($testingMeta['record_name'] ?? null) && $testingMeta['record_name'] !== ''
            ? (string) $testingMeta['record_name']
            : $this->relativeRecordName($hostname, $zone);
        $serverIp = trim((string) ($testingMeta['record_data'] ?? $site->server->ip_address ?? ''));

        $previewRow = $site->previewDomains()->where('hostname', $hostname)->first();
        $providerType = is_string($previewRow?->provider_type) && $previewRow->provider_type !== ''
            ? $previewRow->provider_type
            : ($site->dnsAutomationCredential()->provider ?? 'digitalocean');

        if ($providerType === 'namecheap') {
            $recordId = (string) ($testingMeta['record_id'] ?? '');
            if ($recordId === '' || $recordId === '0') {
                $recordId = (string) ($previewRow->provider_record_id ?? '');
            }
            if ($recordId === '' || ! NamecheapDnsService::isConfigured()) {
                return;
            }
            NamecheapDnsService::fromAppConfig()->deleteDnsRecord($zone, $recordId);
        } elseif ($providerType === 'cloudflare') {
            $site->loadMissing('dnsProviderCredential');
            $credential = $site->dnsProviderCredential;
            if ($credential === null || $credential->provider !== 'cloudflare') {
                $credential = ProviderCredential::query()
                    ->where('organization_id', $site->organization_id)
                    ->where('provider', 'cloudflare')
                    ->latest('updated_at')
                    ->first();
            }
            $recordId = (string) ($testingMeta['record_id'] ?? '');
            if ($recordId === '' || $recordId === '0') {
                $recordId = (string) ($previewRow->provider_record_id ?? '');
            }
            if ($recordId === '') {
                return;
            }
            $cloudflareAuth = $credential ?? TestingDomains::cloudflareApiToken();
            if ($cloudflareAuth === '') {
                return;
            }
            (new CloudflareDnsService($cloudflareAuth))->deleteDnsRecord($zone, $recordId);
        } elseif (in_array($providerType, ['hetzner', 'linode', 'vultr', 'aws', 'gcp', 'azure'], true)) {
            $credential = $site->dnsAutomationCredential();
            if ($credential === null || $credential->provider !== $providerType) {
                return;
            }
            // Prefer the preview row's stored provider_record_id: the meta
            // record_id was historically int-cast to 0 for string-id providers
            // (Hetzner/Cloudflare), so trust it only when it's a non-empty,
            // non-"0" value and otherwise fall back to the row.
            $recordId = (string) ($testingMeta['record_id'] ?? '');
            if ($recordId === '' || $recordId === '0') {
                $recordId = (string) ($previewRow->provider_record_id ?? '');
            }
            if ($recordId === '') {
                return;
            }
            SiteDnsProviderFactory::forCredential($credential)->deleteRecord($zone, $recordId);
        } else {
            $service = new DigitalOceanService($this->tokenForSite($site));
            $recordId = (int) ($testingMeta['record_id'] ?? 0);

            if ($recordId <= 0) {
                $record = $service->findDomainRecord($zone, 'A', $recordName, $serverIp !== '' ? $serverIp : null);
                $recordId = (int) ($record['id'] ?? 0);
            }

            if ($recordId > 0) {
                $service->deleteDomainRecord($zone, $recordId);
            }
        }

        $site->previewDomains()
            ->where('hostname', $hostname)
            ->delete();
    }

    /**
     * @return list<string>
     */
    /** @return array<int, string> */
    public function configuredDomains(): array
    {
        $domains = config('services.digitalocean.testing_domains', TestingDomains::vm());

        $normalized = collect(is_array($domains) ? $domains : [])
            ->filter(fn (mixed $domain): bool => is_string($domain) && trim($domain) !== '')
            ->map(fn (string $domain): string => strtolower(trim($domain)))
            ->unique()
            ->values()
            ->all();

        return $normalized !== [] ? $normalized : TestingDomains::vm();
    }

    private function relativeRecordName(string $hostname, string $zone): string
    {
        return (string) Str::beforeLast($hostname, '.'.$zone);
    }

    private function deterministicIndex(Site $site, int $count): int
    {
        $key = (string) ($site->id ?: ($site->slug !== '' ? $site->slug : $site->name));

        return abs(crc32($key)) % $count;
    }

    /**
     * Public routing summary for the operator credential that controls a site's
     * testing zone — used by the wildcard-certificate issuer to drive certbot
     * DNS-01 hooks against the right provider with the right token. Mirrors the
     * credential resolution in {@see resolveTestingProviderForSite()} and folds
     * in the app-level DigitalOcean token fallback so callers always get a
     * usable token when one is available.
     */
    public function testingDnsRoutingForSite(Site $site): array
    {
        $site->loadMissing(['server', 'organization', 'dnsProviderCredential']);

        $routing = $this->resolveTestingProviderForSite($site);
        $credential = $routing['credential'];

        $token = $credential?->getApiToken();
        if (! is_string($token) || trim($token) === '') {
            $token = match ($routing['provider']) {
                'digitalocean' => trim((string) config('services.digitalocean.token')),
                'namecheap' => trim((string) config('services.namecheap.api_key', '')),
                'cloudflare' => TestingDomains::cloudflareApiToken(),
                default => '',
            };
        }

        return [
            'provider' => $routing['provider'],
            'credential' => $credential,
            'token' => (trim($token)),
        ];
    }

    /**
     * Testing hostnames mint on dply-owned zones from
     * config/product/testing_domains.php. Customer DNS credentials cannot
     * write those zones — Hetzner/Vultr org tokens used to steal the write
     * and create a non-authoritative zone. Cloudflare is first; Namecheap
     * and DigitalOcean remain fallbacks.
     */
    private function resolveTestingProviderForSite(Site $site): array
    {
        $pool = TestingDomains::vm();
        if ($pool === []) {
            throw new \RuntimeException('Dply has no testing-hostname zones configured. Add them to config/product/testing_domains.php.');
        }

        $cloudflareToken = TestingDomains::cloudflareApiToken();
        if ($cloudflareToken !== '') {
            return [
                'provider' => 'cloudflare',
                'dns_provider' => SiteDnsProviderFactory::forCloudflareAppConfigToken($cloudflareToken),
                'pool' => $pool,
                'credential' => null,
            ];
        }

        $cloudflareCredential = $site->organization_id
            ? ProviderCredential::query()
                ->where('organization_id', $site->organization_id)
                ->where('provider', 'cloudflare')
                ->latest('updated_at')
                ->first()
            : null;

        if ($cloudflareCredential !== null) {
            return [
                'provider' => 'cloudflare',
                'dns_provider' => SiteDnsProviderFactory::forCredential($cloudflareCredential),
                'pool' => $pool,
                'credential' => $cloudflareCredential,
            ];
        }

        if (NamecheapDnsService::isConfigured()) {
            return [
                'provider' => 'namecheap',
                'dns_provider' => SiteDnsProviderFactory::forNamecheapAppConfig(),
                'pool' => $pool,
                'credential' => null,
            ];
        }

        $doPool = $this->configuredDomainsForProvider('digitalocean');
        if ($doPool === []) {
            $doPool = $pool;
        }

        $doCredential = $site->organization_id
            ? ProviderCredential::query()
                ->where('organization_id', $site->organization_id)
                ->where('provider', 'digitalocean')
                ->latest('updated_at')
                ->first()
            : null;

        if ($doCredential !== null) {
            return [
                'provider' => 'digitalocean',
                'dns_provider' => SiteDnsProviderFactory::forCredential($doCredential),
                'pool' => $doPool,
                'credential' => $doCredential,
            ];
        }

        $doToken = trim((string) config('services.digitalocean.token'));
        if ($doToken === '') {
            throw new \RuntimeException('Dply needs a connected DigitalOcean credential (or services.digitalocean.token) to create testing hostnames.');
        }

        return [
            'provider' => 'digitalocean',
            'dns_provider' => SiteDnsProviderFactory::forDigitalOceanAppConfigToken($doToken),
            'pool' => $doPool,
            'credential' => null,
        ];
    }

    /**
     * Per-provider testing-zone pool from config. Reads the new
     * services.dply.testing_domains.<provider> map, with DigitalOcean
     * folding in the legacy services.digitalocean.testing_domains list
     * so existing setups keep working without env changes.
     *
     * @return list<string>
     */
    public function configuredDomainsForProvider(string $provider): array
    {
        $provider = strtolower(trim($provider));
        $map = config('services.dply.testing_domains', []);
        $list = is_array($map) && is_array($map[$provider] ?? null) ? $map[$provider] : [];

        if ($provider === 'digitalocean') {
            $list = array_merge($list, $this->configuredDomains());
        }

        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $v): string => is_string($v) ? strtolower(trim($v)) : '',
            $list,
        ))));
    }

    /**
     * Same selection strategy as {@see chooseZone()} but against an arbitrary
     * zone list — used after the per-provider pool is resolved.
     *
     * @param  list<string>  $pool
     */
    private function chooseZoneFromPool(Site $site, array $pool): string
    {
        if ($pool === []) {
            throw new \RuntimeException('No testing zones configured for the resolved DNS provider.');
        }

        $preferred = TestingDomains::vmApex();
        if ($preferred !== '' && in_array($preferred, $pool, true)) {
            return $preferred;
        }

        $ordered = app(UnifiedPreviewHostname::class)->orderedTestingZones($pool);
        $strategy = (string) config('services.digitalocean.testing_domain_strategy', 'deterministic');

        return match ($strategy) {
            'random' => $ordered[array_rand($ordered)],
            default => $ordered[$this->deterministicIndex($site, count($ordered))],
        };
    }

    private function configuredZoneForHostname(string $hostname): ?string
    {
        return TestingDomains::zoneForHost($hostname);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function storeResult(Site $site, array $payload): void
    {
        $meta = is_array($site->meta) ? $site->meta : [];
        $meta['testing_hostname'] = $payload;

        $site->forceFill(['meta' => $meta])->save();
        $site->setAttribute('meta', $meta);
    }

    private function hasAvailableToken(): bool
    {
        if (TestingDomains::cloudflareIsConfigured() || NamecheapDnsService::isConfigured()) {
            return true;
        }

        if (trim((string) config('services.digitalocean.token')) !== '') {
            return true;
        }

        return ProviderCredential::query()
            ->whereIn('provider', ProviderCredential::dnsAutomationProviderKeys())
            ->whereNotNull('organization_id')
            ->exists();
    }

    /**
     * DigitalOcean DNS delete path (preview rows created with app-level DO token use DO API).
     */
    private function tokenForSite(Site $site): string
    {
        $credential = $site->dnsAutomationCredential();
        if ($credential !== null && $credential->provider === 'digitalocean') {
            $token = $credential->getApiToken();
            if (is_string($token) && $token !== '') {
                return $token;
            }
        }

        $token = trim((string) config('services.digitalocean.token'));
        if ($token === '') {
            throw new \RuntimeException('DigitalOcean preview DNS requires an organization credential or app-level token.');
        }

        return $token;
    }

    private function credentialSourceForSite(Site $site): string
    {
        $routing = $this->resolveTestingProviderForSite($site);
        $credential = $routing['credential'] ?? null;
        if (! $credential instanceof ProviderCredential) {
            return TestingDomains::cloudflareIsConfigured() || NamecheapDnsService::isConfigured() || trim((string) config('services.digitalocean.token')) !== ''
                ? 'app_config'
                : 'none';
        }

        if ($site->dns_provider_credential_id && $credential->id === $site->dns_provider_credential_id) {
            return 'site_credential';
        }

        return 'organization_credential';
    }
}
