<?php

declare(strict_types=1);

namespace App\Services\Concerns;

use Illuminate\Support\Facades\Cache;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesDoCatalog
{
    /**
     * Get available regions.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getRegions(): array
    {
        return $this->cachedCatalogList('do_regions', '/regions', 'regions');
    }

    /**
     * Get available sizes (plans).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getSizes(): array
    {
        return $this->cachedCatalogList('do_sizes', '/sizes', 'sizes');
    }

    /**
     * Cache regions/sizes responses per token. The wizard renders these on every
     * step and they don't change often — a 10 minute cache keeps the page fast
     * even when the DO API is slow, and bounded HTTP timeouts (in request())
     * keep the worst-case render under ~10s instead of stalling for 30s+.
     *
     * @return array<int, array<string, mixed>>
     */
    private function cachedCatalogList(string $kind, string $path, string $primaryKey): array
    {
        $cacheKey = $kind.':'.sha1($this->token);
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        // DO paginates these lists (default per_page=20), and /sizes alone has
        // 100+ plans — a single unpaginated GET silently dropped every size
        // beyond the 20 cheapest (breaking catalog pricing and the create
        // wizard's size picker for mid/large plans). Request the max page size
        // and follow pages until the list is exhausted.
        $items = [];
        $page = 1;
        do {
            $response = $this->request('get', $path, ['per_page' => 200, 'page' => $page]);
            $this->assertSuccess($response, 'list '.$primaryKey);
            $data = $response->json();
            $batch = $data[$primaryKey] ?? $data['data'] ?? [];
            $batch = is_array($batch) ? $batch : [];
            $items = array_merge($items, $batch);

            $hasNext = filled($data['links']['pages']['next'] ?? null);
            $page++;
        } while ($hasNext && $batch !== [] && $page <= 10);

        Cache::put($cacheKey, $items, now()->addMinutes(10));

        return $items;
    }

    /**
     * Drop the cached regions/sizes lists for this token — used by callers
     * that miss a lookup (e.g. a just-resized plan) and need a fresh fetch
     * before concluding the item doesn't exist.
     */
    public function forgetCatalogCaches(): void
    {
        Cache::forget('do_regions:'.sha1($this->token));
        Cache::forget('do_sizes:'.sha1($this->token));
    }

    /**
     * List VPCs in the account, optionally filtered by region.
     *
     * @return array<int, array{id: string, name: string, region: string, ip_range: string}>
     */
    public function listVpcs(?string $region = null): array
    {
        $response = $this->request('get', '/vpcs');
        $this->assertSuccess($response, 'list vpcs');
        $data = $response->json();
        $vpcs = $data['vpcs'] ?? [];
        if (! is_array($vpcs)) {
            return [];
        }

        $out = [];
        foreach ($vpcs as $v) {
            if ($region !== null && ($v['region'] ?? '') !== $region) {
                continue;
            }
            $out[] = [
                'id' => (string) ($v['id'] ?? ''),
                'name' => (string) ($v['name'] ?? ''),
                'region' => (string) ($v['region'] ?? ''),
                'ip_range' => (string) ($v['ip_range'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * Validate token with a lightweight account endpoint.
     */
    public function validateToken(): void
    {
        $response = $this->request('get', '/account');
        $this->assertSuccess($response, 'validate token');
    }

    /**
     * Get available images (distributions, snapshots).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getImages(): array
    {
        $response = $this->request('get', '/images');
        $this->assertSuccess($response, 'list images');
        $data = $response->json();
        $images = $data['images'] ?? $data['data'] ?? [];

        return is_array($images) ? $images : [];
    }
}
