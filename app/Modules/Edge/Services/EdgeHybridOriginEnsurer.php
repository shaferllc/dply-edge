<?php

declare(strict_types=1);

namespace App\Modules\Edge\Services;

use App\Models\EdgeDeployment;
use App\Models\Site;
use Illuminate\Support\Str;

/**
 * Ensures hybrid Edge sites have complete origin hardening metadata (A1–A6)
 * and republishes the Worker host map.
 */
class EdgeHybridOriginEnsurer
{
    /** @var list<string> */
    public const DEFAULT_PROXY_ROUTES = ['/_next/data/*', '/api/*'];

    public function __construct(
        private readonly EdgeHostMapPublisher $hostMapPublisher,
        private readonly OriginHealthcheckRunner $healthcheckRunner,
    ) {}

    /**
     * @return list<array{slug: string, updated: bool, healthcheck: array{ok: bool, status: int, message: string}}>
     */
    public function ensureAllHybridSites(): array
    {
        $results = [];

        Site::query()
            ->whereNotNull('edge_backend')
            ->where('status', 'edge_active')
            ->orderBy('id')
            ->each(function (Site $site) use (&$results): void {
                if (! $site->usesEdgeRuntime()) {
                    return;
                }

                $edge = $site->edgeMeta();
                if (($edge['runtime_mode'] ?? 'static') !== 'hybrid') {
                    return;
                }

                $origin = is_array($edge['origin'] ?? null) ? $edge['origin'] : [];
                $originUrl = trim((string) ($origin['url'] ?? ''));
                if ($originUrl === '') {
                    return;
                }

                $normalized = $this->normalizeOrigin($origin);
                $updated = $normalized !== $origin;

                if ($updated) {
                    $site->mergeEdgeMeta(['origin' => $normalized]);
                    $site->save();
                }

                $this->republishHostMap($site->fresh());

                $results[] = [
                    'slug' => (string) $site->slug,
                    'updated' => $updated,
                    'healthcheck' => $this->healthcheckRunner->run($site->fresh()),
                ];
            });

        return $results;
    }

    /**
     * Convert a static Edge site to hybrid with default origin hardening fields.
     */
    public function convertStaticSite(Site $site, string $originUrl): Site
    {
        if (! $site->usesEdgeRuntime()) {
            throw new \InvalidArgumentException('Site is not an Edge site.');
        }

        $edge = $site->edgeMeta();
        if (($edge['runtime_mode'] ?? 'static') === 'hybrid') {
            throw new \InvalidArgumentException('Site is already hybrid.');
        }

        $originUrl = trim($originUrl);
        if ($originUrl === '' || filter_var($originUrl, FILTER_VALIDATE_URL) === false) {
            throw new \InvalidArgumentException('Origin URL must be a valid http(s) URL.');
        }

        $site->mergeEdgeMeta([
            'runtime_mode' => 'hybrid',
            'origin' => $this->normalizeOrigin([
                'url' => $originUrl,
                'cloud_site_id' => null,
                'managed' => false,
                'routes' => self::DEFAULT_PROXY_ROUTES,
                'healthcheck_path' => '/',
                'failover_html' => null,
            ]),
        ]);
        // Secret goes to the encrypted column, never into meta.
        if ($site->edgeOriginSecret('auth_secret') === null) {
            $site->mergeEdgeOriginSecrets(['auth_secret' => Str::random(48)]);
        }
        $site->save();

        $this->republishHostMap($site->fresh());

        return $site->fresh();
    }

    /**
     * @param  array<string, mixed>  $origin
     * @return array<string, mixed>
     */
    private function normalizeOrigin(array $origin): array
    {
        $routes = is_array($origin['routes'] ?? null) ? $origin['routes'] : [];
        $routes = array_values(array_filter(array_map(
            fn ($route) => is_string($route) ? trim($route) : '',
            $routes,
        )));

        if ($routes === []) {
            $routes = self::DEFAULT_PROXY_ROUTES;
        } else {
            foreach (self::DEFAULT_PROXY_ROUTES as $defaultRoute) {
                if (! in_array($defaultRoute, $routes, true)) {
                    $routes[] = $defaultRoute;
                }
            }
        }

        $healthPath = trim((string) ($origin['healthcheck_path'] ?? '/')) ?: '/';
        $failover = is_string($origin['failover_html'] ?? null) ? trim($origin['failover_html']) : '';

        return [
            'url' => trim((string) ($origin['url'] ?? '')),
            'cloud_site_id' => $origin['cloud_site_id'] ?? null,
            'managed' => (bool) ($origin['managed'] ?? false),
            'routes' => $routes,
            'healthcheck_path' => $healthPath[0] === '/' ? $healthPath : '/'.$healthPath,
            'failover_html' => $failover !== '' ? $failover : null,
        ];
    }

    private function republishHostMap(Site $site): void
    {
        $activeId = $site->edgeMeta()['active_deployment_id'] ?? null;
        if (! is_string($activeId) || $activeId === '') {
            return;
        }

        $deployment = EdgeDeployment::query()->find($activeId);
        if ($deployment === null || $deployment->status !== EdgeDeployment::STATUS_LIVE) {
            return;
        }

        $this->hostMapPublisher->publish($site, $deployment);
    }
}
