<?php

declare(strict_types=1);

namespace App\Modules\Edge\Services;

use App\Models\Site;
use App\Support\Http\PublicOutboundUrl;
use App\Support\Http\UnsafeOutboundUrlException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Throwable;

/**
 * Origin healthcheck for hybrid Edge sites.
 *
 * Run before flipping KV to point at a new deployment so unhealthy
 * origins do not start receiving Worker-proxied traffic. The check
 * issues a GET (small, less likely to be rejected than HEAD by SSR
 * frameworks) against `origin_url + healthcheck_path`, attaches the
 * site's auth secret as `X-Dply-Origin-Auth`, and accepts any 2xx /
 * 3xx response — origins commonly redirect or return cached HTML at
 * the root, neither of which means "unhealthy".
 *
 * Operator-controlled origin URLs are resolved and rejected when they
 * point at private, loopback, link-local, or metadata addresses, and
 * the fetch never follows redirects.
 */
class OriginHealthcheckRunner
{
    public function __construct(
        private readonly HttpFactory $http,
    ) {}

    /**
     * @return array{ok: bool, status: int, message: string}
     */
    public function run(Site $site): array
    {
        $edge = $site->edgeMeta();
        $origin = is_array($edge['origin'] ?? null) ? $edge['origin'] : [];
        $originUrl = trim((string) ($origin['url'] ?? ''));
        if ($originUrl === '') {
            return [
                'ok' => false,
                'status' => 0,
                'message' => 'No origin URL configured.',
            ];
        }

        $path = trim((string) ($origin['healthcheck_path'] ?? '/')) ?: '/';
        if ($path[0] !== '/') {
            $path = '/'.$path;
        }
        $target = rtrim($originUrl, '/').$path;

        try {
            $safe = PublicOutboundUrl::parse($target);
        } catch (UnsafeOutboundUrlException) {
            return [
                'ok' => false,
                'status' => 0,
                'message' => 'Origin URL is not allowed.',
            ];
        }

        $timeout = (int) config('edge.origin_healthcheck.timeout_seconds', 10);
        $tries = max(1, (int) config('edge.origin_healthcheck.retries', 3));
        $waitMs = max(0, (int) config('edge.origin_healthcheck.retry_wait_ms', 1500));

        $authSecret = is_string($origin['auth_secret'] ?? null) ? trim((string) $origin['auth_secret']) : '';
        $headers = [
            'User-Agent' => 'dply-edge-healthcheck/1.0',
            'Accept' => '*/*',
        ];
        if ($authSecret !== '') {
            $headers['X-Dply-Origin-Auth'] = $authSecret;
        }
        // Match what the Worker sends, or an Access-protected origin reports
        // unhealthy here while serving traffic perfectly well.
        $accessId = is_string($origin['access_client_id'] ?? null) ? trim((string) $origin['access_client_id']) : '';
        $accessSecret = is_string($origin['access_client_secret'] ?? null) ? trim((string) $origin['access_client_secret']) : '';
        if ($accessId !== '' && $accessSecret !== '') {
            $headers['CF-Access-Client-Id'] = $accessId;
            $headers['CF-Access-Client-Secret'] = $accessSecret;
        }

        try {
            $response = $this->http
                ->withHeaders($headers)
                ->timeout($timeout)
                ->retry($tries, $waitMs, throw: false)
                ->withOptions($safe->httpClientOptions())
                ->get($safe->url);
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'status' => 0,
                'message' => sprintf('Origin healthcheck error: %s', $e->getMessage()),
            ];
        }

        $status = (int) $response->status();
        if ($status >= 200 && $status < 400) {
            return [
                'ok' => true,
                'status' => $status,
                'message' => sprintf('Origin healthy (HTTP %d).', $status),
            ];
        }

        return [
            'ok' => false,
            'status' => $status,
            'message' => sprintf('Origin returned HTTP %d at %s.', $status, $path),
        ];
    }
}
