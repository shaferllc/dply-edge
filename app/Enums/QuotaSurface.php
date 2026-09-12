<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\Server;
use App\Models\Site;

/**
 * The product surfaces that carry their own plan ceiling.
 *
 * dply-edge only mints Edge sites, so `Edge` is the ceiling that matters (Free
 * orgs: `plans.free.max_edge_apps`; any paid subscription is uncapped). `Site`
 * is the fallback for a row whose host is not an Edge delivery host.
 */
enum QuotaSurface: string
{
    case Site = 'site';
    case Edge = 'edge';

    /**
     * Which surface a site's usage counts against, decided by the host row.
     * Managed-product sites are identified by the logical host they hang off,
     * not by their own columns.
     */
    public static function forSite(Site $site): self
    {
        $server = $site->server;

        if ($server === null) {
            return self::Site;
        }

        // Driven off hostKinds() rather than a parallel match, so a surface
        // can never classify one way and count the other.
        $hostKind = $server->hostKind();

        foreach (self::cases() as $surface) {
            $kinds = $surface->hostKinds();

            if ($kinds !== null && in_array($hostKind, $kinds, true)) {
                // Classification here reads the SERVER's host kind, but the Edge
                // index lists sites by the site's own columns (edge_backend /
                // runtime_profile). Where the two disagree a row counts against
                // the Edge ceiling while never appearing in the list, so the org
                // is blocked by apps it can neither see nor delete — observed
                // 2026-08-30, when four nginx/caddy rows read as "3 of 3 Edge
                // apps" on an org whose Apps page showed the empty state.
                //
                // countsAsEdgeApp() is the shared definition, matching the scope
                // the index queries with, so the two cannot disagree again.
                if ($surface === self::Edge && ! $site->countsAsEdgeApp()) {
                    return self::Site;
                }

                return $surface;
            }
        }

        return self::Site;
    }

    /**
     * Which surface a new thing created ON this host would consume. Used by
     * create gates, which run before any Site row exists.
     */
    public static function forServer(Server $server): self
    {
        $hostKind = $server->hostKind();

        foreach (self::cases() as $surface) {
            $kinds = $surface->hostKinds();

            if ($kinds !== null && in_array($hostKind, $kinds, true)) {
                return $surface;
            }
        }

        return self::Site;
    }

    /**
     * Key under `subscription.standard.plans.<plan>` holding this ceiling.
     */
    public function planConfigKey(): string
    {
        return match ($this) {
            self::Site => 'max_sites',
            self::Edge => 'max_edge_apps',
        };
    }

    /**
     * Key under `subscription.standard.beta` holding the beta envelope for
     * this surface. Beta caps replace plan ceilings until cutover.
     */
    public function betaConfigKey(): string
    {
        return match ($this) {
            self::Site => 'sites',
            self::Edge => 'edge_apps',
        };
    }

    /**
     * Fallback beta ceiling when the config key is absent.
     */
    public function betaDefault(): int
    {
        return match ($this) {
            self::Site => 25,
            self::Edge => 25,
        };
    }

    /**
     * `singular|plural` for trans_choice, e.g. "2 Edge apps".
     */
    public function nounKey(): string
    {
        return match ($this) {
            self::Site => 'site|sites',
            self::Edge => 'Edge app|Edge apps',
        };
    }

    public function noun(int $count = 1): string
    {
        return trans_choice($this->nounKey(), $count);
    }

    /**
     * How the surface is named in headings, e.g. "Edge app limit reached".
     */
    public function label(): string
    {
        return match ($this) {
            self::Site => 'site',
            self::Edge => 'Edge app',
        };
    }

    /**
     * Host kinds a surface's quota counts, or null when it doesn't count hosts.
     * dply-edge only mints edge delivery hosts — the machine + FaaS kinds left
     * with the VM platform.
     *
     * @return list<string>|null
     */
    public function hostKinds(): ?array
    {
        return $this === self::Edge ? [Server::HOST_KIND_DPLY_EDGE] : null;
    }
}
