<?php

declare(strict_types=1);

namespace App\Modules\Edge\Http\Middleware;

use App\Models\Site;
use App\Modules\Edge\Http\Controllers\EdgeStaticDevController;
use App\Modules\Edge\Services\FakeEdgeBackend;
use App\Modules\Edge\Support\FakeEdgeProvision;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * When fake-edge mode is on, serve Edge site hostnames from local artifacts.
 * Production Edge traffic is handled by the Cloudflare Worker.
 */
class ResolveEdgeCustomDomain
{
    private const HOST_MAP_CACHE_KEY = 'edge:custom-host-map';

    private const CACHE_TTL_SECONDS = 30;

    public function handle(Request $request, Closure $next): Response
    {
        if (! FakeEdgeProvision::enabled()) {
            return $next($request);
        }

        $host = strtolower($request->getHost());
        $appHost = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
        if ($host === '' || $host === $appHost) {
            return $next($request);
        }

        $siteId = self::hostMap()[$host] ?? null;
        if ($siteId === null) {
            if ($this->isPendingCustomDomain($host)) {
                return $this->pendingDnsResponse($host);
            }

            return $next($request);
        }

        $site = Site::find($siteId);
        if ($site === null || ! $site->usesEdgeRuntime()) {
            return $next($request);
        }

        if ($this->customDomainPending($host, $site)) {
            return $this->pendingDnsResponse($host);
        }

        $routing = $this->resolveRouting($host, $site);
        if ($routing === null) {
            return $next($request);
        }

        $request->attributes->set('edge.routing', $routing);

        $path = ltrim($request->path(), '/');

        return app(EdgeStaticDevController::class)->__invoke(
            $request,
            (string) $site->slug,
            $path !== '' ? $path : null,
        );
    }

    /** @return array<string, mixed>|null */
    private function resolveRouting(string $host, Site $site): ?array
    {
        $map = Cache::get('edge:fake:host-map', []);
        $routing = $map[$host] ?? null;

        if (is_array($routing) && ($routing['storage_prefix'] ?? '') !== '') {
            return $routing;
        }

        $backend = app(FakeEdgeBackend::class);
        $deployment = $backend->resolveActiveDeployment($site);
        if ($deployment === null) {
            return null;
        }

        $edgeRouting = is_array($site->edgeMeta()['routing'] ?? null) ? $site->edgeMeta()['routing'] : [];

        return [
            'storage_prefix' => $deployment->storage_prefix,
            'deployment_id' => $deployment->id,
            'spa_fallback' => (bool) ($edgeRouting['spa_fallback'] ?? true),
            'headers' => is_array($edgeRouting['headers'] ?? null) ? $edgeRouting['headers'] : [],
        ];
    }

    private function customDomainPending(string $host, Site $site): bool
    {
        $defaultHost = strtolower($site->edgeHostname());
        if ($host === $defaultHost) {
            return false;
        }

        $routing = is_array($site->edgeMeta()['routing'] ?? null) ? $site->edgeMeta()['routing'] : [];
        $domains = is_array($routing['custom_domains'] ?? null) ? $routing['custom_domains'] : [];
        $info = is_array($domains[$host] ?? null) ? $domains[$host] : null;

        return $info !== null && ($info['dns_status'] ?? null) === 'pending';
    }

    private function isPendingCustomDomain(string $host): bool
    {
        $sites = Site::query()
            ->whereNotNull('edge_backend')
            ->get(['id', 'meta']);

        foreach ($sites as $site) {
            if ($this->customDomainPending($host, $site)) {
                return true;
            }
        }

        return false;
    }

    private function pendingDnsResponse(string $host): Response
    {
        $body = '<!doctype html><html><head><meta charset="utf-8"><title>DNS pending</title></head>'
            .'<body style="font-family:system-ui,sans-serif;max-width:40rem;margin:4rem auto;padding:0 1rem;color:#1a1a1a">'
            .'<h1>DNS verification pending</h1>'
            .'<p><code>'.htmlspecialchars($host, ENT_QUOTES, 'UTF-8').'</code> is attached to an Edge site but DNS has not verified yet.</p>'
            .'<p>Point a CNAME at your Edge hostname, then use <strong>Verify DNS</strong> in the Domains workspace.</p>'
            .'</body></html>';

        return response($body, 503, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Retry-After' => '300',
        ]);
    }

    public static function invalidateHostMap(): void
    {
        Cache::forget(self::HOST_MAP_CACHE_KEY);
        Cache::forget('edge:fake:host-map');
    }

    /**
     * @return array<string, string>
     */
    public static function hostMap(): array
    {
        return Cache::remember(self::HOST_MAP_CACHE_KEY, self::CACHE_TTL_SECONDS, function (): array {
            $map = [];
            $sites = Site::query()
                ->whereNotNull('edge_backend')
                ->get(['id', 'slug', 'meta']);

            foreach ($sites as $site) {
                $hostname = $site->edgeHostname();
                if ($hostname !== '') {
                    $map[$hostname] = (string) $site->id;
                }

                $edge = is_array($site->meta['edge'] ?? null) ? $site->meta['edge'] : [];
                $routing = is_array($edge['routing'] ?? null) ? $edge['routing'] : [];
                $domains = is_array($routing['custom_domains'] ?? null) ? $routing['custom_domains'] : [];
                foreach ($domains as $customHost => $info) {
                    if (is_string($customHost) && $customHost !== '' && ($info['dns_status'] ?? null) === 'ready') {
                        $map[strtolower($customHost)] = (string) $site->id;
                    }
                }
            }

            return $map;
        });
    }
}
