<?php

declare(strict_types=1);

namespace App\Modules\Edge\Services;

use App\Models\EdgeDeployment;
use App\Models\Site;
use App\Modules\Edge\Support\EdgePlatformCredentials;
use App\Modules\Edge\Support\EdgeWranglerConfigGenerator;
use App\Modules\Edge\Support\FakeEdgeProvision;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use App\Modules\Providers\Cloudflare\EdgeCloudflareClient;

/**
 * One-shot platform setup for optional Edge delivery features:
 * hybrid origin cache KV + Cloudflare Image Resizing + per-site image opt.
 */
class EdgeDeliveryFeaturesEnsurer
{
    public const CACHE_KV_TITLE = 'dply-edge-cache';

    public function __construct(
        private readonly EdgeHostMapPublisher $hostMapPublisher,
        private readonly EdgeAccessGate $accessGate,
    ) {}

    /**
     * @return array{
     *     cache_kv_namespace_id: string,
     *     cache_kv_created: bool,
     *     image_zones: list<array{ok: bool, zone: string, detail: string}>,
     *     sites_enabled: list<string>,
     *     worker_deployed: bool,
     *     access_gates_republished: int,
     * }
     */
    public function ensurePlatform(bool $deployWorker = true): array
    {
        if (FakeEdgeProvision::enabled()) {
            throw new \RuntimeException('Disable DPLY_FAKE_EDGE before ensuring production delivery features.');
        }

        $client = EdgeCloudflareClient::fromConfig();
        $configuredCacheId = trim((string) config('edge.cloudflare.cache_kv_namespace_id', ''));
        $existingCacheId = $configuredCacheId !== ''
            ? $configuredCacheId
            : $client->kvNamespaceIdByTitle(self::CACHE_KV_TITLE);

        $cacheCreated = false;
        if ($existingCacheId === '') {
            $existingCacheId = $client->ensureKvNamespace(self::CACHE_KV_TITLE);
            $cacheCreated = true;
        }

        $imageZones = $this->ensureImageResizingOnWorkerZones($client);
        $sitesEnabled = $this->enableImageOptimizationOnEdgeSites();
        $workerDeployed = false;
        $accessGatesRepublished = 0;

        if ($deployWorker) {
            $exitCode = Artisan::call('edge:worker:deploy');
            if ($exitCode !== 0) {
                throw new \RuntimeException(trim(Artisan::output()) ?: 'edge:worker:deploy failed.');
            }
            $workerDeployed = true;
            $accessGatesRepublished = $this->accessGate->republishAllProtectedSites();
        }

        return [
            'cache_kv_namespace_id' => $existingCacheId,
            'cache_kv_created' => $cacheCreated,
            'image_zones' => $imageZones,
            'sites_enabled' => $sitesEnabled,
            'worker_deployed' => $workerDeployed,
            'access_gates_republished' => $accessGatesRepublished,
        ];
    }

    /**
     * @return list<array{ok: bool, zone: string, detail: string}>
     */
    private function ensureImageResizingOnWorkerZones(EdgeCloudflareClient $client): array
    {
        $zones = [];
        foreach (EdgePlatformCredentials::workerRoutes() as $pattern) {
            $zone = EdgeWranglerConfigGenerator::zoneNameForRoute(
                $pattern,
                (string) config('edge.cloudflare.worker_zone_name'),
            );
            if ($zone === '' || isset($zones[$zone])) {
                continue;
            }
            $zones[$zone] = true;
        }

        $results = [];
        foreach (array_keys($zones) as $zoneName) {
            $result = $client->ensureImageResizingEnabled($zoneName);
            $results[] = [
                'ok' => (bool) $result['ok'],
                'zone' => (string) $result['zone'],
                'detail' => (string) $result['detail'],
            ];
        }

        return $results;
    }

    /**
     * @return list<string> Site slugs that were enabled or refreshed.
     */
    private function enableImageOptimizationOnEdgeSites(): array
    {
        $enabled = [];

        Site::query()
            ->whereNotNull('edge_backend')
            ->where('status', 'edge_active')
            ->orderBy('id')
            ->each(function (Site $site) use (&$enabled): void {
                if (! $site->usesEdgeRuntime()) {
                    return;
                }

                $hostname = $site->edgeHostname();
                if ($hostname === '') {
                    return;
                }

                $edge = $site->edgeMeta();
                $previousImages = is_array($edge['images'] ?? null) ? $edge['images'] : [];
                $allowed = $this->defaultImageAllowedHosts($site, $hostname, $edge);

                $newImages = [
                    'signing_secret' => is_string($previousImages['signing_secret'] ?? null) && $previousImages['signing_secret'] !== ''
                        ? $previousImages['signing_secret']
                        : Str::random(48),
                    'allowed_hosts' => $allowed,
                ];

                $site->mergeEdgeMeta(['images' => $newImages]);
                $site->save();

                $activeId = $site->fresh()->edgeMeta()['active_deployment_id'] ?? null;
                if (is_string($activeId) && $activeId !== '') {
                    $deployment = EdgeDeployment::query()->find($activeId);
                    if ($deployment !== null && $deployment->status === EdgeDeployment::STATUS_LIVE) {
                        $this->hostMapPublisher->publish($site->fresh(), $deployment);
                    }
                }

                $enabled[] = (string) $site->slug;
            });

        return $enabled;
    }

    /**
     * @param  array<string, mixed>  $edgeMeta
     * @return list<string>
     */
    private function defaultImageAllowedHosts(Site $site, string $edgeHostname, array $edgeMeta): array
    {
        $hosts = [
            strtolower($edgeHostname),
            'images.unsplash.com',
            'picsum.photos',
        ];

        $origin = is_array($edgeMeta['origin'] ?? null) ? $edgeMeta['origin'] : [];
        $originUrl = is_string($origin['url'] ?? null) ? trim($origin['url']) : '';
        if ($originUrl !== '') {
            $originHost = strtolower((string) parse_url($originUrl, PHP_URL_HOST));
            if ($originHost !== '') {
                $hosts[] = $originHost;
            }
        }

        $existing = is_array($edgeMeta['images']['allowed_hosts'] ?? null)
            ? $edgeMeta['images']['allowed_hosts']
            : [];
        foreach ($existing as $host) {
            if (is_string($host) && $host !== '') {
                $hosts[] = strtolower($host);
            }
        }

        return array_values(array_unique(array_filter($hosts)));
    }
}
