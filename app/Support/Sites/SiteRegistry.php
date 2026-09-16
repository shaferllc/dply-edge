<?php

declare(strict_types=1);

namespace App\Support\Sites;

use App\Models\Site;
use App\Support\Servers\ServerRegistry;
use App\Support\Workspaces\WorkspaceRegistry;

/**
 * Request-scoped memo for a {@see Site} by id — the `sites` twin of
 * {@see WorkspaceRegistry} and
 * {@see ServerRegistry}.
 *
 * The serverless workspace stacks several sibling Livewire panels (platform,
 * database, cache, background, rollback) on one page, and each resolved its own
 * `Site::findOrFail($this->siteId)`. Same row, one render, five SELECTs.
 *
 * Safe to memo because the panels that genuinely need post-write state ask for
 * it explicitly with `->fresh()`, which never consults this cache. Write paths
 * that replace the row outright should {@see forget()} it.
 *
 * Bound `scoped`, so it never outlives the request/job that built it.
 */
final class SiteRegistry
{
    /** @var array<string, Site> */
    private array $cache = [];

    public function findOrFail(string $id): Site
    {
        if (isset($this->cache[$id])) {
            return $this->cache[$id];
        }

        return $this->cache[$id] = Site::findOrFail($id);
    }

    public function find(?string $id): ?Site
    {
        if ($id === null || $id === '') {
            return null;
        }

        if (isset($this->cache[$id])) {
            return $this->cache[$id];
        }

        $site = Site::find($id);

        return $site !== null ? $this->cache[$id] = $site : null;
    }

    /** Prime the memo from a Site the caller already holds. */
    public function remember(Site $site): Site
    {
        return $this->cache[(string) $site->getKey()] = $site;
    }

    public function forget(string $siteId): void
    {
        unset($this->cache[$siteId]);
    }
}
