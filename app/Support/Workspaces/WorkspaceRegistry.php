<?php

declare(strict_types=1);

namespace App\Support\Workspaces;

use App\Models\Site;
use App\Models\Workspace;
use App\Policies\SitePolicy;

/**
 * Request-scoped memo for a site's {@see Workspace}, keyed by workspace id.
 *
 * Site authorization ({@see SitePolicy::update()}) lazy-loads
 * `$site->workspace` and then `$workspace->organization` for every Site
 * instance it checks — and a single render authorizes the same site as several
 * distinct model instances (the page site, the deploy sidebar, sync peers, the
 * command palette). Each pair of PK lookups ran afresh. Resolving through one
 * shared Workspace instance per id collapses the `workspaces` SELECT to one and,
 * because the `organization` relation then caches on that shared instance, the
 * `organizations` SELECT too. Bound `scoped`, so it never outlives the
 * request/job that built it.
 */
final class WorkspaceRegistry
{
    /** @var array<string, Workspace> */
    private array $cache = [];

    public function for(Site $site): ?Workspace
    {
        if ($site->workspace_id === null) {
            return null;
        }

        $id = (string) $site->workspace_id;
        if (isset($this->cache[$id])) {
            return $this->cache[$id];
        }

        // Respect an already eager-loaded relation so we never add a query the
        // caller had avoided — and prime the memo from it for everyone else.
        if ($site->relationLoaded('workspace') && $site->workspace !== null) {
            return $this->cache[$id] = $site->workspace;
        }

        return $this->find($id);
    }

    /**
     * The same memo, keyed straight off an id, for callers holding something
     * other than a Site.
     *
     * The workspace sidebar prints the project name from `$server->workspace` —
     * a second belongsTo, on a different model instance, for the row the
     * SitePolicy had just fetched — so it re-SELECTed `workspaces` on every
     * workspace page. Going through here makes it a memo hit.
     */
    public function find(?string $id): ?Workspace
    {
        if ($id === null || $id === '') {
            return null;
        }

        if (isset($this->cache[$id])) {
            return $this->cache[$id];
        }

        $workspace = Workspace::find($id);

        return $workspace !== null ? $this->cache[$id] = $workspace : null;
    }

    public function forget(string $workspaceId): void
    {
        unset($this->cache[$workspaceId]);
    }
}
