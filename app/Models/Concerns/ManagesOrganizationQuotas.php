<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Enums\QuotaSurface;
use Illuminate\Support\Collection;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesOrganizationQuotas
{
    /**
     * Per-request memo for {@see serverIds()}.
     *
     * @var Collection<int, string>|null
     */
    private ?Collection $serverIdsMemo = null;

    /**
     * The org's ceiling for one product surface, or null when unlimited.
     *
     * Paid plans are uncapped here: sites are unlimited, subject only to the
     * hidden fair-use cap (CreateEdgeSite). Everyone else gets the Free
     * allowance; beta status grants nothing (ruling r-jnv0r3qf1xk49kmc).
     */
    public function quotaLimit(QuotaSurface $surface): ?int
    {
        if ($this->onAnyPaidPlan()) {
            return null;
        }

        return $this->currentSubscriptionPlan()[$surface->planConfigKey()];
    }

    /**
     * How much of every surface's ceiling the org is currently consuming,
     * keyed by {@see QuotaSurface} value. Preview deployments (Edge/Cloud) are
     * scratch clones of a parent and never consume quota.
     *
     * Deliberately un-memoized: callers routinely create a site and re-ask in
     * the same request (and tests do it around ->refresh()), so a cached tally
     * would answer with the pre-write count.
     *
     * @return array<string, int>
     */
    public function quotaUsageBySurface(): array
    {
        $tally = [];
        foreach (QuotaSurface::cases() as $surface) {
            $tally[$surface->value] = 0;
        }

        foreach ($this->sites()->with('server')->get() as $site) {
            if ($site->isEdgePreview() || $site->isCloudPreview()) {
                continue;
            }

            $tally[QuotaSurface::forSite($site)->value]++;
        }

        return $tally;
    }

    /**
     * Count consuming one surface's ceiling.
     */
    public function quotaUsage(QuotaSurface $surface): int
    {
        return $this->quotaUsageBySurface()[$surface->value];
    }

    /**
     * True when the org has reached the ceiling for this surface.
     */
    public function quotaReached(QuotaSurface $surface): bool
    {
        $limit = $this->quotaLimit($surface);

        return $limit !== null && $this->quotaUsage($surface) >= $limit;
    }

    /**
     * Whether the org may create another thing on this surface.
     */
    public function canCreateOnSurface(QuotaSurface $surface): bool
    {
        return ! $this->quotaReached($surface);
    }

    /**
     * Human-readable ceiling for a surface (e.g. "10", "Unlimited").
     */
    public function quotaLimitDisplay(QuotaSurface $surface): string
    {
        $limit = $this->quotaLimit($surface);

        return $limit === null ? 'Unlimited' : (string) $limit;
    }

    /**
     * Friendly upgrade prompt shown when a surface's ceiling is blocking.
     *
     * Reads the effective ceiling rather than the raw plan value.
     */
    public function quotaLimitMessage(QuotaSurface $surface): string
    {
        $limit = $this->quotaLimit($surface);

        if ($limit === null) {
            return '';
        }

        // Only unsubscribed orgs reach here — quotaLimit() is null once paying.
        return sprintf(
            'This plan includes %d %s. Choose a plan on the organization billing page: every plan includes unlimited sites.',
            $limit,
            trans_choice($surface->nounKey(), $limit),
        );
    }

    /**
     * The org's machine-site ceiling, or null when unlimited.
     *
     * Machine sites only (VM + Docker/Kubernetes) since the 2026-08-18 split —
     * Edge, Cloud and functions have their own ceilings. Kept as a named method
     * because plan-summary surfaces read "sites" specifically.
     */
    public function planSiteLimit(): ?int
    {
        return $this->quotaLimit(QuotaSurface::Site);
    }

    /**
     * Number of machine sites counting against the site ceiling.
     */
    public function quotaCountedSiteCount(): int
    {
        return $this->quotaUsage(QuotaSurface::Site);
    }

    /**
     * True when the org has reached its machine-site ceiling.
     */
    public function siteLimitReached(): bool
    {
        return $this->quotaReached(QuotaSurface::Site);
    }

    /**
     * Friendly upgrade prompt shown when site creation is blocked.
     */
    public function siteLimitMessage(): string
    {
        return $this->quotaLimitMessage(QuotaSurface::Site);
    }

    /**
     * IDs of every server owned by this org, memoized for the request.
     *
     * @return Collection<int, string>
     */
    public function serverIds(): Collection
    {
        return $this->serverIdsMemo ??= $this->servers()->pluck('id');
    }

    /**
     * Maximum number of servers allowed — unlimited. dply-edge has no BYO
     * servers; the only Server rows are the owner records minted per Edge site.
     */
    public function maxServers(): int
    {
        return PHP_INT_MAX;
    }

    /**
     * Maximum machine sites allowed on the org's current plan. Returns
     * PHP_INT_MAX for the unlimited (Business / null) ceiling so callers can
     * compare numerically.
     */
    public function maxSites(): int
    {
        return $this->planSiteLimit() ?? PHP_INT_MAX;
    }

    /**
     * Whether the organization can create another site on a real machine.
     * Edge / Cloud / function ceilings are separate — ask
     * {@see canCreateOnSurface()} with the matching {@see QuotaSurface}.
     */
    public function canCreateSite(): bool
    {
        return $this->canCreateOnSurface(QuotaSurface::Site);
    }

    /**
     * Human-readable server cap for the current plan (e.g. "3", "Unlimited").
     */
    public function maxServersDisplay(): string
    {
        $m = $this->maxServers();

        return $m >= PHP_INT_MAX ? 'Unlimited' : (string) $m;
    }

    /**
     * Human-readable machine-site cap for the current plan (e.g. "10",
     * "Unlimited").
     */
    public function maxSitesDisplay(): string
    {
        return $this->quotaLimitDisplay(QuotaSurface::Site);
    }
}
