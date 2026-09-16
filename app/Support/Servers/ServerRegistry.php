<?php

declare(strict_types=1);

namespace App\Support\Servers;

use App\Models\Server;
use App\Models\Site;
use App\Support\Workspaces\WorkspaceRegistry;
use Illuminate\Support\Collection;

/**
 * Request-scoped memo for a site's {@see Server}, keyed by server id — the
 * `servers` twin of {@see WorkspaceRegistry}.
 *
 * A single site-workspace render resolves the same site through several
 * independent paths (the platform panel, sync peers, the command palette), and
 * each one eager-loads `->with('server')` to dodge its own N+1. Those are
 * separate queries against separate Site instances, so one render issued the
 * identical `select * from servers where id in (?)` three times. Routing the
 * hydration through here keeps every caller's N+1 protection while collapsing
 * the repeats to a single SELECT — and because the callers then share one
 * Server instance, relations cached on it (organization, credentials) are
 * shared too.
 *
 * Bound `scoped`, so it never outlives the request/job that built it.
 */
final class ServerRegistry
{
    /** @var array<string, Server> */
    private array $cache = [];

    public function find(?string $id): ?Server
    {
        if ($id === null || $id === '') {
            return null;
        }

        if (isset($this->cache[$id])) {
            return $this->cache[$id];
        }

        $server = Server::find($id);

        return $server !== null ? $this->cache[$id] = $server : null;
    }

    /**
     * Attach the shared Server to a site, priming the memo from an already
     * eager-loaded relation so we never add a query the caller had avoided.
     * Returns the server for callers that want it directly.
     */
    public function attachTo(?Site $site): ?Server
    {
        if ($site === null) {
            return null;
        }

        $id = $site->server_id === null ? '' : (string) $site->server_id;
        if ($id === '') {
            return null;
        }

        if ($site->relationLoaded('server') && $site->getRelation('server') instanceof Server) {
            /** @var Server $loaded */
            $loaded = $site->getRelation('server');

            return $this->cache[$id] ??= $loaded;
        }

        $server = $this->find($id);
        if ($server !== null) {
            $site->setRelation('server', $server);
        }

        return $server;
    }

    /**
     * Bulk form for a fetched collection — the drop-in for `->with('server')`.
     *
     * Ids already memoized cost nothing; the rest are fetched in ONE `whereIn`,
     * exactly as the eager load would have, so a collection of sites spanning
     * many servers is still a single query.
     *
     * @template TKey of array-key
     * @template TSite of Site
     * @template TCollection of Collection<TKey, TSite>
     *
     * @param  TCollection  $sites
     * @return TCollection
     */
    public function hydrate(Collection $sites): Collection
    {
        $missing = $sites
            ->reject(fn (Site $site): bool => $site->relationLoaded('server'))
            ->map(fn (Site $site): string => $site->server_id === null ? '' : (string) $site->server_id)
            ->filter(fn (string $id): bool => $id !== '' && ! isset($this->cache[$id]))
            ->unique()
            ->values();

        if ($missing->isNotEmpty()) {
            Server::query()
                ->whereIn('id', $missing->all())
                ->get()
                ->each(function (Server $server): void {
                    $this->cache[(string) $server->getKey()] = $server;
                });
        }

        $sites->each(fn (Site $site) => $this->attachTo($site));

        return $sites;
    }

    public function forget(string $serverId): void
    {
        unset($this->cache[$serverId]);
    }
}
