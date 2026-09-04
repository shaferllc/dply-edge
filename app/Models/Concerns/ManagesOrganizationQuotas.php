<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Enums\QuotaSurface;
use App\Models\Server;
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
     * Beta orgs use the beta envelope instead of the plan tier.
     *
     * Each surface has its own ceiling — see {@see QuotaSurface}. Filling up on
     * Edge apps must not block a VM site.
     */
    public function quotaLimit(QuotaSurface $surface): ?int
    {
        if ($this->isBeta()) {
            return max(1, (int) config(
                'subscription.standard.beta.'.$surface->betaConfigKey(),
                $surface->betaDefault(),
            ));
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
     * Reads the effective ceiling rather than the raw plan value, so a beta org
     * is told its actual beta envelope instead of the plan number it is not
     * currently subject to.
     */
    public function quotaLimitMessage(QuotaSurface $surface): string
    {
        $limit = $this->quotaLimit($surface);

        if ($limit === null) {
            return '';
        }

        if ($this->isBeta()) {
            return sprintf(
                'The closed beta allows %d %s per organization. Contact us to raise your limit.',
                $limit,
                trans_choice($surface->nounKey(), $limit),
            );
        }

        // "Add a server to move up to the next plan" outlived the VM platform: the
        // 2026-08-25 Edge cut removed provisioning entirely, and a Server row is now
        // minted automatically per Edge site, so there is no add-server flow for a
        // user to follow. The upgrade path is the organization's subscription page.
        return sprintf(
            'Your %s plan includes %d %s. Upgrade the organization plan to raise this limit, or contact us.',
            $this->currentSubscriptionPlan()['label'],
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
     * The org's current plan-tier server ceiling, or null when unlimited
     * (Business). This is the per-tier ALLOTMENT shown in the UI — distinct from
     * {@see maxServers()}, which is the creation gate and is intentionally
     * uncapped because adding a server simply bumps the usage-based tier.
     */
    public function planServerLimit(): ?int
    {
        // Beta orgs are bounded by the BYO envelope, not the plan tier.
        if ($this->isBeta()) {
            return $this->betaByoServerLimit();
        }

        return $this->currentSubscriptionPlan()['max_servers'];
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
     * BYO VMs that count against the beta BYO ceiling (excludes the free managed
     * box and managed-product logical hosts).
     */
    public function byoServerCount(): int
    {
        return $this->servers()
            ->where('hosting_backend', Server::HOSTING_BACKEND_BYO)
            ->get()
            ->reject(fn (Server $server) => $server->isManagedProductHost())
            ->count();
    }

    /**
     * dply-managed VMs the org currently holds (the free-CX22 grant counter).
     */
    public function managedServerCount(): int
    {
        return $this->servers()
            ->where('hosting_backend', Server::HOSTING_BACKEND_DPLY)
            ->get()
            ->filter(fn (Server $server) => $server->isManagedVm())
            ->count();
    }

    /**
     * Whether the org can provision another free dply-managed server. During
     * beta this enforces the single-CX22 grant; outside beta managed servers
     * aren't capped here (availability is gated by the surface flag + platform
     * config at the create flow).
     */
    public function canCreateManagedServer(): bool
    {
        if (! $this->isBeta()) {
            return true;
        }

        return $this->managedServerCount() < $this->betaManagedServerLimit();
    }

    /**
     * Maximum number of BYO servers allowed. Unlimited under the Standard model
     * — trial-state gating handles the cash-burning abuse case — but bounded for
     * beta orgs by the beta envelope.
     */
    public function maxServers(): int
    {
        return $this->isBeta() ? $this->betaByoServerLimit() : PHP_INT_MAX;
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
     * Whether the organization can create another server (under limit).
     */
    public function canCreateServer(): bool
    {
        // Beta orgs are bounded by the BYO envelope (the free managed box is
        // counted separately via canCreateManagedServer); otherwise unlimited.
        if ($this->isBeta()) {
            return $this->byoServerCount() < $this->maxServers();
        }

        return $this->servers()->count() < $this->maxServers();
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
