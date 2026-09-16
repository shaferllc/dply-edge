<?php

declare(strict_types=1);

namespace App\Models\Concerns\Site;

use App\Jobs\DetectSiteCloudflareTlsJob;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\SitePreviewDomain;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;

/**
 * Extracted from {@see Site}. Composed back into the model via `use`.
 *
 * @property array<string, mixed> $meta
 * @property ?string $server_id
 * @property ?SiteDomain $primaryDomainCache
 * @property bool $primaryDomainResolved
 * @property-read \Illuminate\Database\Eloquent\Collection<int, SiteDomain> $domains
 * @property-read \Illuminate\Database\Eloquent\Collection<int, SitePreviewDomain> $previewDomains
 * @property-read \Illuminate\Database\Eloquent\Collection<int, SiteDomainAlias> $domainAliases
 */
trait ResolvesSiteHostnames
{
    /** Memoized result of the lazy-load path in primaryDomain(). */
    private ?SiteDomain $primaryDomainCache = null;

    private bool $primaryDomainResolved = false;

    public function primaryDomain(): ?SiteDomain
    {
        // Avoid re-querying when callers have already eager-loaded `domains`
        // (Settings::render() does this) — the in-memory collection is the same
        // source of truth for is_primary/first.
        if ($this->relationLoaded('domains')) {
            return $this->domains->firstWhere('is_primary', true) ?? $this->domains->first();
        }

        // primaryDomain() is hit repeatedly per request (blade views, Site::url(),
        // service classes). Memoize so the lazy path queries at most once.
        if ($this->primaryDomainResolved) {
            return $this->primaryDomainCache;
        }

        $this->primaryDomainResolved = true;

        // Order is_primary descending so the primary domain wins, falling back
        // to any domain — one query instead of a where + a separate fallback.
        return $this->primaryDomainCache = $this->domains()
            ->orderByDesc('is_primary')
            ->first();
    }

    /**
     * Drop the memoized primaryDomain() result. Call this after creating or
     * re-prioritising a SiteDomain on an in-memory Site instance that may have
     * already resolved primaryDomain() — e.g. the scaffold pipelines, which
     * read primaryDomain() in a later step than the one that creates it.
     */
    public function flushPrimaryDomainCache(): void
    {
        $this->primaryDomainCache = null;
        $this->primaryDomainResolved = false;
    }

    public function testingHostname(): string
    {
        $previewDomain = $this->primaryPreviewDomain();
        if ($previewDomain) {
            return (string) $previewDomain->hostname;
        }

        $meta = $this->meta ?? [];

        return (string) ($meta['testing_hostname']['hostname'] ?? '');
    }

    public function testingHostnameStatus(): ?string
    {
        $previewDomain = $this->primaryPreviewDomain();
        if ($previewDomain) {
            return $previewDomain->dns_status;
        }

        $meta = $this->meta ?? [];
        $status = $meta['testing_hostname']['status'] ?? null;

        return is_string($status) ? $status : null;
    }

    /**
     * Last Cloudflare-edge TLS probe result (see {@see DetectSiteCloudflareTlsJob}).
     *
     * @return array<string, mixed>
     */
    public function cloudflareTlsMeta(): array
    {
        $meta = $this->meta ?? [];

        return is_array($meta['cloudflare_tls'] ?? null) ? $meta['cloudflare_tls'] : [];
    }

    /**
     * True when the last probe found this site's primary domain fronted by
     * Cloudflare's edge (TLS terminated there) — so dply needn't issue or renew
     * an origin certificate for it.
     */
    public function cloudflareTerminatesTls(): bool
    {
        return (bool) ($this->cloudflareTlsMeta()['terminating'] ?? false);
    }

    public function cloudflareTlsCheckedAt(): ?string
    {
        $at = $this->cloudflareTlsMeta()['checked_at'] ?? null;

        return is_string($at) && $at !== '' ? $at : null;
    }

    /**
     * Persist a Cloudflare-edge TLS probe result into meta.
     */
    public function setCloudflareTlsResult(bool $terminating, string $hostname, ?string $server, ?string $cfRay): void
    {
        $meta = $this->meta ?? [];
        $meta['cloudflare_tls'] = [
            'terminating' => $terminating,
            'hostname' => $hostname,
            'server' => $server,
            'cf_ray' => $cfRay,
            'checked_at' => now()->toIso8601String(),
        ];
        $this->update(['meta' => $meta]);
    }

    public function primaryPreviewDomain(): ?SitePreviewDomain
    {
        $this->loadMissing('previewDomains');

        return $this->previewDomains->firstWhere('is_primary', true)
            ?? $this->previewDomains->first();
    }

    /**
     * The dply-managed testing zone this site's preview hostname lives on
     * (e.g. on-dply.com), or null when the site has no testing hostname.
     * Reads the primary preview domain's zone first, falling back to the
     * provisioner's stored meta['testing_hostname']['zone'].
     */
    public function testingZone(): ?string
    {
        $previewZone = $this->primaryPreviewDomain()?->zone;
        if (is_string($previewZone) && trim($previewZone) !== '') {
            return strtolower(trim($previewZone));
        }

        $meta = $this->meta ?? [];
        $zone = $meta['testing_hostname']['zone'] ?? null;

        return is_string($zone) && trim($zone) !== '' ? strtolower(trim($zone)) : null;
    }

    /** @return Collection<int, non-empty-string> */
    public function sslDomainHostnames(): Collection
    {
        $previewDomains = $this->relationLoaded('previewDomains')
            ? $this->previewDomains
            : $this->previewDomains()->get();
        $primaryPreview = $previewDomains->firstWhere('is_primary', true) ?? $previewDomains->first();
        if ($primaryPreview !== null && $primaryPreview->hostname !== '') {
            return collect([$primaryPreview->hostname]);
        }

        $domains = $this->relationLoaded('domains')
            ? $this->domains
            : $this->domains()->get();

        $testingHostname = $this->testingHostname();
        if ($testingHostname !== '' && $domains->contains('hostname', $testingHostname)) {
            return collect([$testingHostname]);
        }

        return $domains->pluck('hostname')->filter()->unique()->values();
    }

    /**
     * @return list<string>
     */
    public function customerDomainHostnames(): array
    {
        $domains = $this->relationLoaded('domains')
            ? $this->domains
            : $this->domains()->get();

        return $domains->pluck('hostname')
            ->filter(fn (mixed $hostname): bool => is_string($hostname) && trim($hostname) !== '')
            ->map(fn (string $hostname): string => strtolower(trim($hostname)))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    public function aliasHostnames(): array
    {
        $aliases = $this->relationLoaded('domainAliases')
            ? $this->domainAliases
            : $this->domainAliases()->get();

        return $aliases->pluck('hostname')
            ->filter(fn (mixed $hostname): bool => is_string($hostname) && trim($hostname) !== '')
            ->map(fn (string $hostname): string => strtolower(trim($hostname)))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Customer domains plus domain aliases for automatic customer-scope certificate issuance (e.g. bulk “issue SSL”).
     *
     * @return list<string>
     */
    public function sslIssuanceHostnames(): array
    {
        return collect($this->customerDomainHostnames())
            ->merge($this->aliasHostnames())
            ->unique()
            ->values()
            ->all();
    }

    /**
     * This site's own dply testing hostname (the provisioned `<hash>.on-dply.com`
     * stored in meta.testing_hostname). It must be in the vhost's server_name or
     * nginx 502s when the site is reached by that hostname before real DNS — the
     * whole point of a testing hostname. Distinct from {@see tenantTestingHostnames()},
     * which covers multi-tenant domains.
     *
     * @return array<int, string>
     */
    public function ownTestingHostnames(): array
    {
        $testing = ($this->meta ?? [])['testing_hostname'] ?? null;
        if (! is_array($testing)) {
            return [];
        }

        $hostname = strtolower(trim((string) ($testing['hostname'] ?? '')));
        if ($hostname === '' || ($testing['status'] ?? null) !== 'ready') {
            return [];
        }

        return [$hostname];
    }

    /**
     * Hostnames issued by {@see TestingHostnameProvisioner}. Stored on
     * SitePreviewDomain (not SiteDomain) — without this in the webserver
     * server_name list, freshly-provisioned testing URLs fall through to
     * the default nginx server and serve a bare 404.
     *
     * @return list<string>
     */
    public function previewHostnames(): array
    {
        $previewDomains = $this->relationLoaded('previewDomains')
            ? $this->previewDomains
            : $this->previewDomains()->get();

        return $previewDomains->pluck('hostname')
            ->filter(fn (mixed $hostname): bool => is_string($hostname) && trim($hostname) !== '')
            ->map(fn (string $hostname): string => strtolower(trim($hostname)))
            ->unique()
            ->values()
            ->all();
    }
}
