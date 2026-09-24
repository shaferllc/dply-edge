<?php

declare(strict_types=1);

namespace App\Modules\Edge\Services;

use App\Models\Site;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Log;

/**
 * Purge entries from the Worker EDGE_CACHE KV namespace.
 *
 * The Worker stores cached origin responses keyed by
 * `edge_cache:{site_id}:{path}` and a per-tag pointer at
 * `edge_cache_tag:{site_id}:{tag}` that references the most recently
 * cached key for that tag. {@see purgeByTag()} reads the pointer,
 * deletes the referenced cache entry, then deletes the pointer itself.
 *
 * {@see purgeAll()} lists this site's cache keys and tag pointers
 * (prefix + cursor, capped) and deletes them. It does not scan the
 * rest of the namespace.
 */
class EdgeCachePurger
{
    private const BASE = 'https://api.cloudflare.com/client/v4';

    public function __construct(
        private readonly HttpFactory $http,
        private readonly EdgeDeliveryContextResolver $contextResolver,
    ) {}

    /**
     * @return array{ok: bool, purged_keys: list<string>, message: string}
     */
    public function purgeByTag(Site $site, string $tag): array
    {
        $tag = trim($tag);
        if ($tag === '') {
            return ['ok' => false, 'purged_keys' => [], 'message' => 'Empty tag.'];
        }
        if (preg_match('/^[A-Za-z0-9._-]+$/', $tag) !== 1 || strlen($tag) > 128) {
            return ['ok' => false, 'purged_keys' => [], 'message' => 'Invalid tag — letters, digits, dot, dash, underscore only.'];
        }

        $context = $this->contextResolver->forSite($site);
        if ($context->cacheKvNamespaceId === '') {
            return ['ok' => false, 'purged_keys' => [], 'message' => 'Edge cache namespace not configured for this site.'];
        }

        $siteId = (string) $site->id;
        $tagKey = "edge_cache_tag:{$siteId}:{$tag}";

        // 1) Read the tag pointer to find the cache key it references.
        $tagResp = $this->http
            ->withToken($context->apiToken)
            ->timeout(10)
            ->get($this->kvValueUrl($context->accountId, $context->cacheKvNamespaceId, $tagKey));

        if ($tagResp->status() === 404 || trim($tagResp->body()) === '') {
            return ['ok' => true, 'purged_keys' => [], 'message' => 'No cache entries tagged with that value.'];
        }
        if (! $tagResp->successful()) {
            Log::warning('EdgeCachePurger: tag pointer fetch failed', ['site' => $siteId, 'tag' => $tag, 'status' => $tagResp->status()]);

            return ['ok' => false, 'purged_keys' => [], 'message' => "Cloudflare KV read failed (HTTP {$tagResp->status()})."];
        }

        $cacheKey = trim($tagResp->body());

        // 2) Delete the cached response entry, then the tag pointer.
        $purged = [];
        foreach ([$cacheKey, $tagKey] as $key) {
            $del = $this->http
                ->withToken($context->apiToken)
                ->timeout(10)
                ->delete($this->kvValueUrl($context->accountId, $context->cacheKvNamespaceId, $key));
            if ($del->successful() || $del->status() === 404) {
                $purged[] = $key;
            } else {
                Log::warning('EdgeCachePurger: delete failed', ['site' => $siteId, 'key' => $key, 'status' => $del->status()]);
            }
        }

        return [
            'ok' => true,
            'purged_keys' => $purged,
            'message' => sprintf('Purged %d entries for tag "%s".', count($purged), $tag),
        ];
    }

    /**
     * Cached paths for this site, capped so the page does not scan the namespace.
     *
     * @return array{ok: bool, entries: list<array{path: string, expires_at: int|null}>, message: string}
     */
    public function listEntries(Site $site, int $limit = 100): array
    {
        $context = $this->contextResolver->forSite($site);
        if ($context->cacheKvNamespaceId === '') {
            return ['ok' => false, 'entries' => [], 'message' => 'Edge cache namespace not configured for this site.'];
        }

        $limit = max(1, min(100, $limit));
        $prefix = 'edge_cache:'.((string) $site->id).':';
        $response = $this->http
            ->withToken($context->apiToken)
            ->timeout(10)
            ->get($this->kvKeysUrl($context->accountId, $context->cacheKvNamespaceId), [
                'prefix' => $prefix,
                'limit' => $limit,
            ]);

        if (! $response->successful()) {
            Log::warning('EdgeCachePurger: list failed', ['site' => $site->id, 'status' => $response->status()]);

            return ['ok' => false, 'entries' => [], 'message' => "Cloudflare KV list failed (HTTP {$response->status()})."];
        }

        $rows = $response->json('result');
        $entries = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $name = (string) ($row['name'] ?? '');
            if (! str_starts_with($name, $prefix)) {
                continue;
            }
            $expiration = $row['expiration'] ?? null;
            $entries[] = [
                'path' => substr($name, strlen($prefix)) ?: '/',
                'expires_at' => is_numeric($expiration) ? (int) $expiration : null,
            ];
        }

        return ['ok' => true, 'entries' => $entries, 'message' => ''];
    }

    /**
     * Purge cache entries for a specific path (ISR on-demand
     * revalidation, P53). Deletes the direct `edge_cache:{site}:{path}`
     * KV key — the next request for that path re-fetches from origin
     * and re-warms cache.
     *
     * @param  array<string, mixed>  $paths
     * @return array{ok: bool, purged_keys: list<string>, message: string}
     */
    public function purgeByPaths(Site $site, array $paths): array
    {
        $paths = array_values(array_unique(array_filter(array_map(
            fn ($p): string => is_string($p) ? trim($p) : '',
            $paths,
        ), fn (string $p): bool => $p !== '')));

        if ($paths === []) {
            return ['ok' => false, 'purged_keys' => [], 'message' => 'No paths supplied.'];
        }
        foreach ($paths as $p) {
            if (strlen($p) > 2048 || preg_match('/[\r\n\t]/', $p) === 1) {
                return ['ok' => false, 'purged_keys' => [], 'message' => 'Invalid path: '.$p];
            }
        }

        $context = $this->contextResolver->forSite($site);
        if ($context->cacheKvNamespaceId === '') {
            return ['ok' => false, 'purged_keys' => [], 'message' => 'Edge cache namespace not configured for this site.'];
        }

        $siteId = (string) $site->id;
        $purged = [];
        foreach ($paths as $path) {
            $normalized = '/'.ltrim($path, '/');
            $key = "edge_cache:{$siteId}:{$normalized}";
            $del = $this->http
                ->withToken($context->apiToken)
                ->timeout(10)
                ->delete($this->kvValueUrl($context->accountId, $context->cacheKvNamespaceId, $key));
            if ($del->successful() || $del->status() === 404) {
                $purged[] = $key;
            } else {
                Log::warning('EdgeCachePurger: path delete failed', ['site' => $siteId, 'key' => $key, 'status' => $del->status()]);
            }
        }

        return [
            'ok' => true,
            'purged_keys' => $purged,
            'message' => sprintf('Purged %d of %d path(s).', count($purged), count($paths)),
        ];
    }

    /**
     * Delete every stored copy and tag pointer for this site.
     *
     * @return array{ok: bool, purged_keys: list<string>, message: string}
     */
    public function purgeAll(Site $site): array
    {
        $context = $this->contextResolver->forSite($site);
        if ($context->cacheKvNamespaceId === '') {
            return ['ok' => false, 'purged_keys' => [], 'message' => 'Edge cache namespace not configured for this site.'];
        }

        $siteId = (string) $site->id;
        $keys = [];
        foreach (["edge_cache:{$siteId}:", "edge_cache_tag:{$siteId}:"] as $prefix) {
            $listed = $this->listKeys($context->accountId, $context->apiToken, $context->cacheKvNamespaceId, $prefix);
            if ($listed === null) {
                return ['ok' => false, 'purged_keys' => [], 'message' => 'Cloudflare KV list failed.'];
            }
            array_push($keys, ...$listed);
        }

        $keys = array_values(array_unique($keys));
        if ($keys === []) {
            return ['ok' => true, 'purged_keys' => [], 'message' => 'Nothing stored to clear.'];
        }

        $purged = [];
        foreach ($keys as $key) {
            $del = $this->http
                ->withToken($context->apiToken)
                ->timeout(10)
                ->delete($this->kvValueUrl($context->accountId, $context->cacheKvNamespaceId, $key));
            if ($del->successful() || $del->status() === 404) {
                $purged[] = $key;
            } else {
                Log::warning('EdgeCachePurger: clear failed', ['site' => $siteId, 'key' => $key, 'status' => $del->status()]);
            }
        }

        return [
            'ok' => count($purged) === count($keys),
            'purged_keys' => $purged,
            'message' => sprintf('Cleared %d stored %s.', count($purged), count($purged) === 1 ? 'copy' : 'copies'),
        ];
    }

    /**
     * @return list<string>|null
     */
    private function listKeys(string $accountId, string $apiToken, string $namespaceId, string $prefix): ?array
    {
        $keys = [];
        $cursor = null;
        for ($page = 0; $page < 5; $page++) {
            $query = ['prefix' => $prefix, 'limit' => 100];
            if (is_string($cursor) && $cursor !== '') {
                $query['cursor'] = $cursor;
            }
            $response = $this->http
                ->withToken($apiToken)
                ->timeout(10)
                ->get($this->kvKeysUrl($accountId, $namespaceId), $query);
            if (! $response->successful()) {
                Log::warning('EdgeCachePurger: list failed', ['prefix' => $prefix, 'status' => $response->status()]);

                return null;
            }
            foreach (is_array($response->json('result')) ? $response->json('result') : [] as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $name = (string) ($row['name'] ?? '');
                if (str_starts_with($name, $prefix)) {
                    $keys[] = $name;
                }
            }
            $next = $response->json('result_info.cursor');
            if (! is_string($next) || $next === '' || $next === $cursor) {
                break;
            }
            $cursor = $next;
        }

        return $keys;
    }

    private function kvKeysUrl(string $accountId, string $namespaceId): string
    {
        return self::BASE
            .'/accounts/'.$accountId
            .'/storage/kv/namespaces/'.$namespaceId
            .'/keys';
    }

    private function kvValueUrl(string $accountId, string $namespaceId, string $key): string
    {
        return self::BASE
            .'/accounts/'.$accountId
            .'/storage/kv/namespaces/'.$namespaceId
            .'/values/'.rawurlencode($key);
    }
}
