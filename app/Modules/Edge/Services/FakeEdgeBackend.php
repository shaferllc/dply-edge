<?php

declare(strict_types=1);

namespace App\Modules\Edge\Services;

use App\Contracts\EdgeBackend;
use App\Models\EdgeDeployment;
use App\Models\Site;
use App\Modules\Edge\Support\FakeEdgeProvision;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

/**
 * Local/test backend — stores artifacts on disk and host map in cache.
 */
class FakeEdgeBackend implements EdgeBackend
{
    public function providerKey(): string
    {
        return 'dply_edge';
    }

    /** @return array<string, mixed> */
    public function publishDeployment(EdgeDeployment $deployment, Site $site, string $localArtifactDir): array
    {
        $dest = $this->artifactRoot($deployment->storage_prefix);
        File::ensureDirectoryExists($dest);
        File::copyDirectory($localArtifactDir, $dest);

        return $this->writeHostMap($deployment, $site);
    }

    /** @return array<string, mixed> */
    public function republishDeployment(EdgeDeployment $deployment, Site $site): array
    {
        return $this->writeHostMap($deployment, $site);
    }

    /**
     * @return array{live_url: string, cf_kv_version: int}
     */
    private function writeHostMap(EdgeDeployment $deployment, Site $site): array
    {
        $hostname = $site->edgeHostname();
        $routing = $this->routingPayload($deployment, $site);
        $map = $this->hostMap();
        $map[$hostname] = $routing;

        foreach ($this->readyCustomHostnames($site) as $customHost) {
            $map[$customHost] = $routing;
        }
        Cache::put($this->hostMapKey(), $map, now()->addDay());

        return [
            'live_url' => 'https://'.$hostname,
            'cf_kv_version' => (int) $deployment->cf_kv_version + 1,
        ];
    }

    public function unpublish(Site $site): void
    {
        $map = $this->hostMap();
        unset($map[$site->edgeHostname()]);
        foreach ($this->readyCustomHostnames($site) as $customHost) {
            unset($map[$customHost]);
        }
        Cache::put($this->hostMapKey(), $map, now()->addDay());

        $deployments = $site->edgeDeployments()->get();
        foreach ($deployments as $deployment) {
            File::deleteDirectory($this->artifactRoot($deployment->storage_prefix));
        }
    }

    /** @return array<string, mixed> */
    /**
     * @return array<int, array<string, string>>
     */
    public function attachDomain(Site $site, string $hostname): array
    {
        $entry = app(EdgeCustomDomainProvisioner::class)->provision($site, $hostname);
        if ($entry === null) {
            return [];
        }

        return [
            [
                'name' => strtolower(trim($hostname)),
                'type' => 'CNAME',
                'value' => (string) ($entry['cname_target'] ?? $site->edgeHostname()),
                'status' => (string) ($entry['dns_status'] ?? 'pending'),
            ],
        ];
    }

    public function detachDomain(Site $site, string $hostname): void
    {
        app(EdgeCustomDomainProvisioner::class)->remove($site, $hostname);
    }

    public function inspect(Site $site): array
    {
        $meta = $site->edgeMeta();
        $phase = match ($site->status) {
            Site::STATUS_EDGE_ACTIVE => 'ACTIVE',
            Site::STATUS_EDGE_PROVISIONING => 'BUILDING',
            Site::STATUS_EDGE_FAILED => 'FAILED',
            default => 'UNKNOWN',
        };

        return [
            'phase' => $phase,
            'live_url' => $site->edgeLiveUrl(),
            'active_deployment_id' => is_string($meta['active_deployment_id'] ?? null) ? $meta['active_deployment_id'] : null,
        ];
    }

    public function supportsSsr(): bool
    {
        return false;
    }

    public function localFilePath(EdgeDeployment $deployment, string $path): ?string
    {
        $file = $this->artifactRoot($deployment->storage_prefix).'/'.ltrim($path, '/');
        if (! is_file($file)) {
            return null;
        }

        return $file;
    }

    public function resolveActiveDeployment(Site $site): ?EdgeDeployment
    {
        $activeId = $site->edgeMeta()['active_deployment_id'] ?? null;
        if (! is_string($activeId) || $activeId === '') {
            return $site->edgeDeployments()->where('status', EdgeDeployment::STATUS_LIVE)->latest()->first();
        }

        return EdgeDeployment::query()->find($activeId);
    }

    private function artifactRoot(string $prefix): string
    {
        return rtrim(FakeEdgeProvision::storageRoot(), '/').'/'.trim($prefix, '/');
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function hostMap(): array
    {
        return Cache::get($this->hostMapKey(), []);
    }

    private function hostMapKey(): string
    {
        return 'edge:fake:host-map';
    }

    /**
     * @return array<string, mixed>
     */
    private function routingPayload(EdgeDeployment $deployment, Site $site): array
    {
        $edgeMeta = $site->edgeMeta();
        $routing = is_array($edgeMeta['routing'] ?? null) ? $edgeMeta['routing'] : [];
        $isPreview = ! empty($edgeMeta['preview_parent_site_id']);
        $widgetMeta = is_array($edgeMeta['comment_widget'] ?? null) ? $edgeMeta['comment_widget'] : [];
        $widgetEnabled = $isPreview && (bool) ($widgetMeta['enabled'] ?? false);
        if ($isPreview && ! $widgetEnabled) {
            $parentId = $edgeMeta['preview_parent_site_id'] ?? null;
            if (is_string($parentId)) {
                $parent = Site::query()->find($parentId);
                $parentMeta = $parent?->edgeMeta() ?? [];
                $parentWidget = is_array($parentMeta['comment_widget'] ?? null) ? $parentMeta['comment_widget'] : [];
                if ((bool) ($parentWidget['enabled'] ?? false)) {
                    $widgetEnabled = true;
                    $widgetMeta = array_merge($parentWidget, $widgetMeta);
                }
            }
        }

        $payload = [
            'storage_prefix' => $deployment->storage_prefix,
            'deployment_id' => $deployment->id,
            'spa_fallback' => (bool) ($routing['spa_fallback'] ?? true),
            'headers' => is_array($routing['headers'] ?? null) ? $routing['headers'] : [],
            'is_preview' => $isPreview,
            'comment_widget_enabled' => $widgetEnabled,
        ];

        if ($widgetEnabled) {
            $token = is_string($widgetMeta['token'] ?? null) ? trim((string) $widgetMeta['token']) : '';
            if ($token !== '') {
                $payload['comment_widget_token'] = $token;
            }
            $apiBase = rtrim((string) config('app.url'), '/');
            if ($apiBase !== '') {
                $payload['comment_widget_api_base'] = $apiBase;
            }
        }

        $images = is_array($edgeMeta['images'] ?? null) ? $edgeMeta['images'] : [];
        $imageSecret = is_string($images['signing_secret'] ?? null) ? trim((string) $images['signing_secret']) : '';
        if ($imageSecret !== '') {
            $payload['image_signing_secret'] = $imageSecret;
            $allowed = is_array($images['allowed_hosts'] ?? null) ? $images['allowed_hosts'] : [];
            $payload['image_allowed_hosts'] = array_values(array_filter(array_map(
                fn ($host) => is_string($host) && $host !== '' ? strtolower($host) : null,
                $allowed,
            )));
        }

        if (($edgeMeta['runtime_mode'] ?? 'static') === 'hybrid') {
            $origin = is_array($edgeMeta['origin'] ?? null) ? $edgeMeta['origin'] : [];
            $originUrl = trim((string) ($origin['url'] ?? ''));
            if ($originUrl !== '') {
                $payload['origin_url'] = $originUrl;
                $routes = is_array($origin['routes'] ?? null) ? $origin['routes'] : [];
                $payload['origin_routes'] = array_values(array_filter(array_map(
                    fn ($route) => is_string($route) ? $route : null,
                    $routes,
                )));
                $authSecret = is_string($origin['auth_secret'] ?? null) ? trim((string) $origin['auth_secret']) : '';
                if ($authSecret !== '') {
                    $payload['origin_auth_secret'] = $authSecret;
                }
                // Parity with EdgeHostMapPublisher so local dev exercises the
                // same worker path as production.
                $accessId = is_string($origin['access_client_id'] ?? null) ? trim((string) $origin['access_client_id']) : '';
                $accessSecret = is_string($origin['access_client_secret'] ?? null) ? trim((string) $origin['access_client_secret']) : '';
                if ($accessId !== '' && $accessSecret !== '') {
                    $payload['origin_access_client_id'] = $accessId;
                    $payload['origin_access_client_secret'] = $accessSecret;
                }
                $failover = is_string($origin['failover_html'] ?? null) ? (string) $origin['failover_html'] : '';
                if ($failover !== '') {
                    $payload['origin_failover_html'] = $failover;
                }
            }
        }

        return $payload;
    }

    /**
     * @return list<string>
     */
    private function readyCustomHostnames(Site $site): array
    {
        $routing = is_array($site->edgeMeta()['routing'] ?? null) ? $site->edgeMeta()['routing'] : [];
        $domains = is_array($routing['custom_domains'] ?? null) ? $routing['custom_domains'] : [];
        $hosts = [];
        foreach ($domains as $hostname => $info) {
            if (! is_string($hostname) || $hostname === '') {
                continue;
            }
            if (is_array($info) && ($info['dns_status'] ?? null) !== 'ready') {
                continue;
            }
            $hosts[] = strtolower($hostname);
        }

        return $hosts;
    }
}
