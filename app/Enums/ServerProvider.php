<?php

namespace App\Enums;


enum ServerProvider: string
{
    case DigitalOcean = 'digitalocean';
    case Hetzner = 'hetzner';
    case Linode = 'linode';
    case Vultr = 'vultr';
    case UpCloud = 'upcloud';
    case Ovh = 'ovh';
    case Aws = 'aws';
    case Cloudflare = 'cloudflare';
    case Gcp = 'gcp';
    case Azure = 'azure';
    case Oracle = 'oracle';
    case Custom = 'custom';
    case Gandi = 'gandi';
    case Namecheap = 'namecheap';
    case VercelDns = 'vercel_dns';
    case Ploi = 'ploi';
    case Forge = 'forge';

    /**
     * Human-readable label for UI.
     */
    public function label(): string
    {
        return match ($this) {
            self::DigitalOcean => 'DigitalOcean',
            self::Hetzner => 'Hetzner',
            self::Linode => 'Linode',
            self::Vultr => 'Vultr',
            self::UpCloud => 'UpCloud',
            self::Ovh => 'OVH',
            self::Aws => 'AWS',
            self::Cloudflare => 'Cloudflare',
            self::Gcp => 'GCP',
            self::Azure => 'Azure',
            self::Oracle => 'Oracle Cloud',
            self::Custom => 'Custom',
            self::Gandi => 'Gandi',
            self::Namecheap => 'Namecheap',
            self::VercelDns => 'Vercel DNS',
            self::Ploi => 'Ploi',
            self::Forge => 'Laravel Forge',
        };
    }

    /**
     * Whether this provider can be used for compute / server provisioning. Mirrors the
     * `hasFullSupport()` semantics for the credential UI surface — exposed separately so
     * the credentials page can filter by capability without overloading "full support."
     */
    public function supportsCompute(): bool
    {
        return $this->hasFullSupport();
    }

    /**
     * Whether this provider can be used for DNS automation (site DNS settings, DNS-01,
     * preview-hostname provisioning). DigitalOcean and AWS are dual-purpose; Cloudflare
     * and the stub providers are DNS-only.
     */
    public function supportsDns(): bool
    {
        return match ($this) {
            self::DigitalOcean,
            self::Hetzner,
            self::Linode,
            self::Vultr,
            self::Cloudflare,
            self::Aws,
            self::Gcp,
            self::Azure,
            self::Gandi,
            self::Namecheap,
            self::VercelDns => true,
            self::Oracle => false,
            default => false,
        };
    }

    /**
     * Whether this provider offers a CDN / edge network Dply can put in front of a
     * site. A subset of the DNS providers — Cloudflare's CDN and Vercel's Edge
     * Network qualify; pure registrars / authoritative-DNS hosts do not.
     */
    public function supportsCdn(): bool
    {
        return match ($this) {
            self::Cloudflare,
            self::VercelDns => true,
            default => false,
        };
    }

    /**
     * Whether this provider is a source for inventory imports (existing fleets that
     * dply can read sites/servers from and migrate). Distinct from compute/DNS —
     * import providers don't host anything; dply only talks to their APIs to read
     * the user's existing state and orchestrate a one-way move to dply-managed servers.
     */
    public function supportsImport(): bool
    {
        return match ($this) {
            self::Ploi, self::Forge => true,
            default => false,
        };
    }

    /**
     * Whether this provider's credential satisfies a managed container backend
     * (the "cloud apps" surface). DO uses one PAT for Droplets + Apps; AWS's
     * App Runner has its own credential row and isn't covered here.
     */
    public function supportsAppPlatform(): bool
    {
        return match ($this) {
            self::DigitalOcean => true,
            default => false,
        };
    }

    /**
     * Capability tags for badge rendering on credential rows.
     *
     * @return list<string>
     */
    public function capabilities(): array
    {
        $caps = [];
        if ($this->supportsCompute()) {
            $caps[] = 'compute';
        }
        if ($this->supportsDns()) {
            $caps[] = 'dns';
        }
        if ($this->supportsCdn()) {
            $caps[] = 'cdn';
        }
        if ($this->supportsAppPlatform()) {
            $caps[] = 'app_platform';
        }
        if ($this->supportsImport()) {
            $caps[] = 'import';
        }

        return $caps;
    }

    /**
     * Provider keys that can manage DNS for sites. Canonical taxonomy lives here so the
     * UI, the DNS provider factory, and the credential model all read from one place.
     *
     * @return list<string>
     */
    public static function dnsProviderKeys(): array
    {
        return array_values(array_map(
            fn (self $p) => $p->value,
            array_filter(self::cases(), fn (self $p) => $p->supportsDns())
        ));
    }

    /**
     * Provider keys that offer a CDN / edge network.
     *
     * @return list<string>
     */
    public static function cdnProviderKeys(): array
    {
        return array_values(array_map(
            fn (self $p) => $p->value,
            array_filter(self::cases(), fn (self $p) => $p->supportsCdn())
        ));
    }

    /**
     * Provider keys that can be used for compute / server provisioning.
     *
     * @return list<string>
     */
    public static function computeProviderKeys(): array
    {
        return array_values(array_map(
            fn (self $p) => $p->value,
            array_filter(self::cases(), fn (self $p) => $p->supportsCompute())
        ));
    }

    /**
     * Provider keys that can be used as inventory-import sources.
     *
     * @return list<string>
     */
    public static function importProviderKeys(): array
    {
        return array_values(array_map(
            fn (self $p) => $p->value,
            array_filter(self::cases(), fn (self $p) => $p->supportsImport())
        ));
    }

    /**
     * Whether Dply can re-query this provider's API for a server's private /
     * internal networking IP after creation. Only providers whose service class
     * exposes a private-IP reader qualify (DigitalOcean VPC, Hetzner private_net,
     * Vultr internal_ip / VPC subnet, Linode 192.168/16 private address).
     * Gates the "Refresh" affordance on the connection settings card.
     */
    public function supportsPrivateIpLookup(): bool
    {
        return match ($this) {
            self::DigitalOcean,
            self::Hetzner,
            self::Vultr,
            self::Linode => true,
            default => false,
        };
    }

    /**
     * Whether Dply can capture a full-disk image / snapshot of a server through
     * this provider's API. Only providers whose service class exposes the
     * create-image + poll-action methods qualify (DigitalOcean snapshotDroplet,
     * Hetzner createImageFromServer, Vultr createSnapshot, Linode
     * createImageFromDisk). Gates the "Create image" affordance on the Snapshots
     * workspace; other providers render a "not available" state.
     */
    public function supportsImageSnapshots(): bool
    {
        return match ($this) {
            self::DigitalOcean,
            self::Hetzner,
            self::Vultr,
            self::Linode => true,
            default => false,
        };
    }

    /**
     * Whether Dply can snapshot attached block-storage volumes through this
     * provider's API. No provider service wraps the volume APIs yet, so this is
     * uniformly false — the Snapshots workspace Volumes tab shows a "coming soon"
     * state until the volume plumbing (Phase 3) lands.
     */
    public function supportsVolumeSnapshots(): bool
    {
        return false;
    }

    /**
     * Provider's published per-GB/month price for storing a server image /
     * snapshot, in the provider's billing currency. Surfaces an at-a-glance
     * monthly cost estimate on the Snapshots → images table; the image lives on
     * the user's own cloud account, so this is informational only (Dply takes no
     * cut). null when the provider doesn't meter images or the rate is unknown.
     *
     * Rates (verified 2026-06): DigitalOcean snapshots $0.06/GiB/mo; Hetzner
     * snapshots €0.0119/GB/mo; Vultr snapshots $0.05/GB/mo; Linode custom images
     * $0.10/GB/mo. Vultr bills the *compressed* snapshot size —
     * {@see ServerImageProvider} stores `compressed_size` as
     * the image's bytes so this estimate lines up with the actual bill.
     *
     * @return array{rate: float, currency: string}|null
     */
    public function imageSnapshotRatePerGbMonth(): ?array
    {
        return match ($this) {
            self::DigitalOcean => ['rate' => 0.06, 'currency' => 'USD'],
            self::Hetzner => ['rate' => 0.0119, 'currency' => 'EUR'],
            self::Vultr => ['rate' => 0.05, 'currency' => 'USD'],
            self::Linode => ['rate' => 0.10, 'currency' => 'USD'],
            default => null,
        };
    }

    /**
     * Whether this provider has full support: service class, provision/poll jobs,
     * create tab, and destroy handling. Otherwise only credentials are stored.
     */
    public function hasFullSupport(): bool
    {
        return match ($this) {
            self::DigitalOcean,
            self::Hetzner,
            self::Linode,
            self::Vultr,
            self::UpCloud,
            self::Ovh,
            self::Aws,
            self::Azure,
            self::Oracle => true,
            self::Cloudflare => false,
            default => false,
        };
    }

    /**
     * Providers that accept credentials only (no create/destroy yet).
     *
     * @return list<self>
     */
    public static function credentialOnly(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $p) => ! $p->hasFullSupport() && $p !== self::Custom
        ));
    }

    /**
     * All provider values (for validation, etc.).
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Provider values allowed for provider_credentials (excludes Custom).
     *
     * @return list<string>
     */
    public static function valuesForCredentials(): array
    {
        return array_values(array_map(
            fn (self $p) => $p->value,
            array_filter(self::cases(), fn (self $p) => $p !== self::Custom)
        ));
    }
}
