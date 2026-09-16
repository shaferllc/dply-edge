<?php

declare(strict_types=1);

namespace App\Models\Concerns\Site;

use App\Models\Site;
use App\Modules\Edge\Support\EdgeRepoRoot;
use App\Modules\Edge\Support\EdgeTestingDomains;
use App\Support\Preview\UnifiedPreviewHostname;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Extracted from {@see Site}. Composed back into the model via `use`.
 *
 * @property array<string, mixed> $meta
 * @property string $edge_backend
 * @property ?string $container_backend
 * @property string $id
 * @property string $name
 * @property string $slug
 */
trait ManagesEdgeHosting
{
    public function usesOrgCloudflareEdge(): bool
    {
        return $this->edge_backend === 'org_cloudflare';
    }

    public function edgeBackendLabel(): string
    {
        return match ($this->edge_backend) {
            'org_cloudflare' => __('Your connected account'),
            'dply_edge' => __('Managed Edge'),
            default => (string) ($this->edge_backend ?: __('Unknown')),
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function edgeMeta(): array
    {
        $meta = $this->meta ?? [];

        /** @var mixed $edge */
        $edge = $meta['edge'] ?? null;
        if (! is_array($edge)) {
            $edge = [];
        }

        return $edge;
    }

    public function edgeLiveUrl(): ?string
    {
        $url = $this->edgeMeta()['live_url'] ?? null;

        return is_string($url) && $url !== '' ? $url : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function edgeGuardrail(): ?array
    {
        $guardrail = $this->edgeMeta()['guardrail'] ?? null;

        return is_array($guardrail) && $guardrail !== [] ? $guardrail : null;
    }

    /**
     * Merge a new guardrail meta blob into edgeMeta and persist. Returns the
     * previous state ('ok'|'warn'|'over') so the caller can detect a
     * transition before/after the write (used by the evaluator cron to
     * decide whether to fan out a notification).
     *
     * @param  array<string, mixed>  $guardrail
     */
    public function updateEdgeGuardrail(array $guardrail): ?string
    {
        $previous = $this->edgeGuardrail()['state'] ?? null;

        $meta = $this->meta ?? [];
        /** @var mixed $edge */
        $edge = $meta['edge'] ?? null;
        if (! is_array($edge)) {
            $edge = [];
        }
        $edge['guardrail'] = $guardrail;
        $meta['edge'] = $edge;

        $this->update(['meta' => $meta]);

        return is_string($previous) ? $previous : null;
    }

    public function edgeHostname(): string
    {
        $routing = is_array($this->edgeMeta()['routing'] ?? null) ? $this->edgeMeta()['routing'] : [];
        $hostname = trim((string) ($routing['hostname'] ?? ''));
        if ($hostname !== '') {
            return strtolower($hostname);
        }

        $liveUrl = $this->edgeLiveUrl();
        if ($liveUrl !== null) {
            $host = parse_url($liveUrl, PHP_URL_HOST);
            if (is_string($host) && $host !== '') {
                return strtolower($host);
            }
        }

        $hostnames = app(UnifiedPreviewHostname::class);
        if ($hostnames->enabled()) {
            return $hostnames->canonicalHostname($this);
        }

        $testingDomain = EdgeTestingDomains::defaultApex();
        $slug = (string) ($this->slug ?: Str::slug((string) $this->name)) ?: 'site';
        $suffix = substr(strtolower((string) $this->id), -6);

        return strtolower($slug.'-'.$suffix.'.'.$testingDomain);
    }

    public function edgeRepoRoot(): string
    {
        $source = is_array($this->edgeMeta()['source'] ?? null) ? $this->edgeMeta()['source'] : [];
        $repoRoot = is_string($source['repo_root'] ?? null) ? $source['repo_root'] : '';

        return EdgeRepoRoot::normalize($repoRoot);
    }

    /**
     * Hostnames that receive CDN traffic for this Edge site (default delivery + custom domains).
     *
     * @return list<string>
     */
    public function edgeUsageHostnames(): array
    {
        return array_keys($this->edgeUsageHostnameZones());
    }

    /**
     * @return array<string, string> hostname => Cloudflare zone apex for analytics
     */
    public function edgeUsageHostnameZones(): array
    {
        if (! $this->usesEdgeRuntime()) {
            return [];
        }

        $zones = [];
        $primary = strtolower(trim($this->edgeHostname()));
        if ($primary !== '') {
            $zones[$primary] = $this->edgeAnalyticsZoneForHostname($primary) ?? $primary;
        }

        $routing = is_array($this->edgeMeta()['routing'] ?? null) ? $this->edgeMeta()['routing'] : [];
        $customDomains = is_array($routing['custom_domains'] ?? null) ? $routing['custom_domains'] : [];

        foreach ($customDomains as $key => $domain) {
            $hostname = is_string($key) && $key !== ''
                ? strtolower(trim($key))
                : strtolower(trim((string) (is_array($domain) ? ($domain['hostname'] ?? '') : '')));

            if ($hostname === '') {
                continue;
            }

            $zones[$hostname] = $this->edgeAnalyticsZoneForHostname($hostname, is_array($domain) ? $domain : []);
        }

        return $zones;
    }

    /**
     * @param  array<string, mixed>  $customDomainEntry
     */
    public function edgeAnalyticsZoneForHostname(string $hostname, array $customDomainEntry = []): ?string
    {
        $hostname = strtolower(trim($hostname));
        if ($hostname === '') {
            return null;
        }

        $routing = is_array($this->edgeMeta()['routing'] ?? null) ? $this->edgeMeta()['routing'] : [];
        $customDomains = is_array($routing['custom_domains'] ?? null) ? $routing['custom_domains'] : [];
        $entry = $customDomainEntry !== []
            ? $customDomainEntry
            : (is_array($customDomains[$hostname] ?? null) ? $customDomains[$hostname] : []);

        foreach (['analytics_zone', 'zone'] as $key) {
            $zone = strtolower(trim((string) ($entry[$key] ?? '')));
            if ($zone !== '') {
                return $zone;
            }
        }

        return EdgeTestingDomains::analyticsZoneForHost($hostname)
            ?? self::deriveRegistrableDomain($hostname);
    }

    public static function deriveRegistrableDomain(string $hostname): string
    {
        $labels = explode('.', strtolower(trim($hostname)));
        if (count($labels) < 2) {
            return strtolower(trim($hostname));
        }

        return implode('.', array_slice($labels, -2));
    }

    public function isEdgePreview(): bool
    {
        $parentId = $this->edgeMeta()['preview_parent_site_id'] ?? null;

        return is_string($parentId) && $parentId !== '';
    }

    public function isDplyCloudSite(): bool
    {
        return $this->container_backend === 'dply_cloud';
    }

    /**
     * True when this site is a managed Cloud container app
     * (DO App Platform / App Runner / dply_cloud), not a BYO VM site.
     *
     * Null and empty string both mean "not cloud" — factories and
     * PHP/VM sites leave container_backend unset (null), so callers
     * must not rely on `=== ''` alone.
     */
    public function isCloudContainerSite(): bool
    {
        return is_string($this->container_backend) && $this->container_backend !== '';
    }

    /**
     * Which product this site is, as one word: `vm`, `edge`, `cloud`, or
     * `serverless`.
     *
     * Every kind is the same Site row differing by attributes, which is exactly
     * why callers keep re-deriving this from `edge_backend` /
     * `container_backend` / the runtime profile. API payloads expose it so the
     * CLI (`dply sites`) and any other client can say what a site IS without
     * knowing the column layout.
     */
    public function siteKind(): string
    {
        if ($this->usesFunctionsRuntime()) {
            return 'serverless';
        }

        if (is_string($this->edge_backend) && $this->edge_backend !== '') {
            return 'edge';
        }

        if ($this->isCloudContainerSite()) {
            return 'cloud';
        }

        return 'vm';
    }

    /**
     * Whether this row is an Edge app for listing AND for quota purposes.
     *
     * One definition, two forms: this predicate and {@see scopeEdgeListed()}.
     * The Edge index and the Edge quota used to derive "is this an Edge app?"
     * differently — the index from these two columns, the quota from the
     * SERVER's host kind — so a row could count against the ceiling while never
     * appearing in the list, blocking an org with apps it could not see or
     * delete. Change both forms together, or the two drift apart again.
     */
    public function countsAsEdgeApp(): bool
    {
        return (is_string($this->edge_backend) && $this->edge_backend !== '')
            || ($this->meta['runtime_profile'] ?? null) === 'edge_web';
    }

    /**
     * Query form of {@see countsAsEdgeApp()}.
     *
     * @param  Builder<Site>  $query
     */
    public function scopeEdgeListed(Builder $query): void
    {
        $query->where(function (Builder $q): void {
            $q->whereNotNull('edge_backend')
                ->orWhere('meta->runtime_profile', 'edge_web');
        });
    }

    public function isCloudPreview(): bool
    {
        $container = is_array($this->meta['container'] ?? null) ? $this->meta['container'] : [];
        $parentId = $container['preview_parent_site_id'] ?? null;

        return is_string($parentId) && $parentId !== '';
    }

    public function edgeGithubHookUrl(): string
    {
        return route('hooks.edge.github', ['site' => $this->id]);
    }

    /**
     * Rough monthly cost estimate for the container site, in USD.
     * Based on backend × size_tier × instance_count, using public
     * list pricing as of 2026-05. Returns 0 for non-container sites.
     *
     * Not authoritative — used as a "ballpark" surface in the
     * dashboard / CLI so operators can compare fleets without
     * digging into the cloud billing console.
     */
    public function estimatedMonthlyCostUsd(): int
    {
        if ($this->container_backend === null || $this->container_backend === '') {
            return 0;
        }
        $meta = $this->meta ?? [];
        $tier = (string) ($meta['container']['size_tier'] ?? 'small');
        $instances = is_int($meta['container']['instance_count'] ?? null)
            ? (int) $meta['container']['instance_count']
            : 1;

        // Per-instance pricing rough estimates. DO's instance_size_slug
        // pricing is monthly + flat; App Runner is per-vCPU-hour for
        // active time so this is more uncertain (we assume active 24/7).
        $perInstance = match ($this->container_backend) {
            'digitalocean_app_platform' => match ($tier) {
                'medium' => 10,
                'large' => 25,
                'xlarge' => 50,
                default => 5,
            },
            'aws_app_runner' => match ($tier) {
                'medium' => 50,
                'large' => 100,
                'xlarge' => 200,
                default => 25,
            },
            default => 0,
        };

        return $perInstance * max(1, $instances);
    }

    public function containerLiveUrl(): ?string
    {
        $meta = $this->meta ?? [];

        // Prefer the branded dply subdomain (e.g. `acme-api-x4k2p.on-dply.cloud`)
        // once it's attached as a PRIMARY domain on the backend. While the
        // domain is still pending validation/cert issuance, fall back to the
        // backend's default ingress (e.g. `*.ondigitalocean.app`) so users
        // can hit the app immediately.
        $subdomain = $meta['container']['dply_subdomain'] ?? null;
        $subdomainAttached = (bool) ($meta['container']['dply_subdomain_attached'] ?? false);
        if ($subdomainAttached && is_string($subdomain) && $subdomain !== '') {
            return 'https://'.$subdomain;
        }

        $url = $meta['container']['live_url'] ?? null;

        return is_string($url) && $url !== '' ? $url : null;
    }

    /**
     * Generate a stable, brand-canonical hostname for a cloud site
     * (e.g. `acme-api-x4k2p.on-dply.cloud`). All dply cloud apps land
     * under `on-dply.cloud` regardless of which backend (DO App
     * Platform / AWS App Runner) actually runs them, so the public
     * URL stays consistent if we migrate a site between backends.
     */
    public static function generateDplyCloudSubdomain(string $name, string $id): string
    {
        $slug = strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', $name));
        $slug = trim($slug, '-');
        $slug = $slug === '' ? 'app' : substr($slug, 0, 32);
        $shortId = strtolower(substr($id, -5));

        return $slug.'-'.$shortId.'.on-dply.cloud';
    }

    /**
     * @param  array<string, mixed>  $patch
     */
    public function mergeEdgeMeta(array $patch): void
    {
        $meta = $this->meta ?? [];
        /** @var mixed $current */
        $current = $meta['edge'] ?? null;
        $meta['edge'] = array_merge(is_array($current) ? $current : [], $patch);
        $this->meta = $meta;
    }
}
