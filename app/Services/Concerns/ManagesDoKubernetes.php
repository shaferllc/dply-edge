<?php

declare(strict_types=1);

namespace App\Services\Concerns;

use Illuminate\Support\Facades\Cache;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesDoKubernetes
{
    /**
     * List managed DOKS clusters in this account. Same caching shape as regions/sizes.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getKubernetesClusters(): array
    {
        return $this->cachedCatalogList('do_kubernetes_clusters', '/kubernetes/clusters', 'kubernetes_clusters');
    }

    /**
     * Fetch a single DOKS cluster (status, node_pools with per-node statuses,
     * ha, version, region). Bypasses the list-cache because the poller needs
     * fresh data on every call. Returns null when the cluster has been deleted
     * out from under us (404) so the caller can stop polling cleanly.
     *
     * @return array<string, mixed>|null
     */
    public function getKubernetesCluster(string $clusterId): ?array
    {
        $response = $this->request('get', '/kubernetes/clusters/'.$clusterId);
        if ($response->status() === 404) {
            return null;
        }
        $this->assertSuccess($response, 'get kubernetes cluster');
        $data = $response->json();
        $cluster = $data['kubernetes_cluster'] ?? null;

        return is_array($cluster) ? $cluster : null;
    }

    /**
     * Pull the YAML kubeconfig for a cluster — bearer-token credentials inside,
     * caller is responsible for encrypting at rest. Only useful once the cluster
     * has reached state=running (DO returns 503 / empty during provisioning).
     */
    public function getKubernetesClusterKubeconfig(string $clusterId): string
    {
        $response = $this->request('get', '/kubernetes/clusters/'.$clusterId.'/kubeconfig');
        $this->assertSuccess($response, 'get kubernetes cluster kubeconfig');

        return $response->body();
    }

    /**
     * Tear down a DOKS cluster the user provisioned through dply. DigitalOcean
     * deletes the cluster + node pools but NOT attached load balancers / block
     * storage (per their docs) — those linger on the bill until separately
     * removed. Returns true on 204, false on 404 (already gone).
     */
    public function deleteKubernetesCluster(string $clusterId): bool
    {
        $response = $this->request('delete', '/kubernetes/clusters/'.$clusterId);
        if ($response->status() === 404) {
            Cache::forget('do_kubernetes_clusters:'.sha1($this->token));

            return false;
        }
        $this->assertSuccess($response, 'delete kubernetes cluster');
        Cache::forget('do_kubernetes_clusters:'.sha1($this->token));

        return true;
    }

    /**
     * Read DO's published Kubernetes options (supported versions, regions, sizes
     * for DOKS specifically). The "versions" array is what we use to populate
     * the version dropdown on the create-cluster form — DO usually publishes
     * 3-4 supported minor versions with one flagged as default/recommended.
     *
     * @return array<string, mixed>
     */
    public function getKubernetesOptions(): array
    {
        $cacheKey = 'do_kubernetes_options:'.sha1($this->token);
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $response = $this->request('get', '/kubernetes/options');
        $this->assertSuccess($response, 'list kubernetes options');
        $data = $response->json();
        $options = is_array($data['options'] ?? null) ? $data['options'] : [];

        Cache::put($cacheKey, $options, now()->addMinutes(60));

        return $options;
    }

    /**
     * Provision a new DOKS cluster. DO returns the cluster shell immediately
     * (with status.state="provisioning"); the actual node pool VMs take 5-10
     * minutes to come up. Callers should treat the returned cluster as
     * pending until status.state="running".
     *
     * Bypasses the cluster-list cache on success so a subsequent
     * getKubernetesClusters() call doesn't return the pre-create snapshot.
     *
     * @return array<string, mixed>
     */
    public function createKubernetesCluster(
        string $name,
        string $region,
        string $nodeSize,
        int $nodeCount,
        bool $ha = false,
        ?string $version = null,
        string $nodePoolName = 'default-pool',
    ): array {
        // DigitalOcean's create-cluster endpoint requires an explicit version
        // slug — passing nothing (or the literal "latest") trips the API into
        // "invalid version slug" / VersionFeatureDockerVpcBugFixed errors.
        // When the caller didn't specify one, fetch the published options and
        // use the first slug (DO orders them newest-first).
        $versionSlug = is_string($version) ? trim($version) : '';
        if ($versionSlug === '') {
            $versionSlug = $this->resolveLatestKubernetesVersionSlug();
        }

        $body = [
            'name' => $name,
            'region' => $region,
            'version' => $versionSlug,
            'ha' => $ha,
            'node_pools' => [[
                'size' => $nodeSize,
                'count' => $nodeCount,
                'name' => $nodePoolName,
            ]],
        ];

        $response = $this->request('post', '/kubernetes/clusters', $body);
        $this->assertSuccess($response, 'create kubernetes cluster');
        $data = $response->json();
        $cluster = $data['kubernetes_cluster'] ?? $data;
        if (! is_array($cluster) || empty($cluster)) {
            throw new \RuntimeException('DigitalOcean API did not return a kubernetes cluster.');
        }

        Cache::forget('do_kubernetes_clusters:'.sha1($this->token));

        return $cluster;
    }

    /**
     * Look up the newest published DOKS version slug from /kubernetes/options.
     * Used when the caller didn't pin a specific version on create.
     */
    private function resolveLatestKubernetesVersionSlug(): string
    {
        $options = $this->getKubernetesOptions();
        $versions = is_array($options['versions'] ?? null) ? $options['versions'] : [];
        foreach ($versions as $version) {
            if (! is_array($version)) {
                continue;
            }
            $slug = (string) ($version['slug'] ?? '');
            if ($slug !== '') {
                return $slug;
            }
        }

        throw new \RuntimeException('DigitalOcean returned no available Kubernetes versions; cannot create a cluster without a version slug.');
    }
}
